<?php
/**
 * School Year Transition API
 * Handles preview and execution of the school year transition.
 *
 * POST actions:
 *   preview  — returns counts of affected students (read-only, no DB changes)
 *   execute  — runs full atomic transition
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../system_config.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/audit.php';

if (!defined('INTERNAL_TRANSITION_CALL')) {
    header('Content-Type: application/json');
}

// Auth guard
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    http_response_code(401);
    if (!defined('INTERNAL_TRANSITION_CALL')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    } else {
        return;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!defined('INTERNAL_TRANSITION_CALL')) {
        echo json_encode(['success' => false, 'message' => 'POST only.']);
        exit;
    } else {
        return;
    }
}

if (!defined('INTERNAL_TRANSITION_CALL')) {

}

$action  = trim($_POST['action'] ?? '');
$newYearId = (int)($_POST['new_year_id'] ?? 0);  // internal id in system_settings JSON

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a full JSON snapshot of one assessment row for archiving.
 */
function buildAssessmentSnapshot(mysqli $db, int $assessmentId): array {
    $snap = [];

    // Core assessment row
    $stmt = $db->prepare("SELECT * FROM student_assessments WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $snap['assessment'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Answers
    $stmt = $db->prepare("SELECT * FROM student_answers WHERE assessment_id = ?");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $snap['answers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Category scores
    $stmt = $db->prepare("SELECT * FROM category_scores WHERE assessment_id = ?");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $snap['category_scores'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Competency scores
    $stmt = $db->prepare("SELECT * FROM competency_scores WHERE assessment_id = ?");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $snap['competency_scores'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Recommendations
    $stmt = $db->prepare("SELECT * FROM recommendations WHERE assessment_id = ?");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $snap['recommendations'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $snap;
}

// ─────────────────────────────────────────────────────────────────────────────
// PREVIEW action – no DB changes, returns counts only
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'preview') {
    // Grade 12 students that will be deactivated (read-only)
    $g12Res = $mysqli->query(
        "SELECT COUNT(*) AS cnt FROM students
         WHERE grade_level = 'Grade 12'
           AND status IN ('active','pending','inactive')
           AND is_readonly = 0"
    );
    $g12Count = (int)($g12Res->fetch_assoc()['cnt'] ?? 0);

    // Grade 11 students that will be promoted
    $g11Res = $mysqli->query(
        "SELECT COUNT(*) AS cnt FROM students
         WHERE grade_level = 'Grade 11'
           AND status IN ('active','pending')"
    );
    $g11Count = (int)($g11Res->fetch_assoc()['cnt'] ?? 0);

    // How many Grade 11 students have at least one completed assessment to archive
    $archiveRes = $mysqli->query(
        "SELECT COUNT(DISTINCT sa.student_id) AS cnt
         FROM student_assessments sa
         JOIN students s ON s.id = sa.student_id
         WHERE s.grade_level = 'Grade 11'
           AND s.status IN ('active','pending')
           AND sa.status = 'completed'"
    );
    $archiveCount = (int)($archiveRes->fetch_assoc()['cnt'] ?? 0);

    echo json_encode([
        'success'       => true,
        'grade12_count' => $g12Count,
        'grade11_count' => $g11Count,
        'archive_count' => $archiveCount,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// EXECUTE action – runs the full atomic transition
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'execute') {
    if ($newYearId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid school year ID.']);
        exit;
    }

    // Resolve the target year label from system config
    $currentYears = $systemConfig['school_years'] ?? [];
    $selectedYear = '';
    foreach ($currentYears as $sy) {
        if ((int)($sy['id'] ?? 0) === $newYearId) {
            $selectedYear = (string)($sy['year'] ?? '');
            break;
        }
    }

    if ($selectedYear === '') {
        echo json_encode(['success' => false, 'message' => 'School year not found in configuration.']);
        exit;
    }

    // Prevent backward or lateral transitions
    $activeYearStr = getSystemConfig('school_year');
    $selectedStart = (int)substr($selectedYear, 0, 4);
    $activeStart = (int)substr($activeYearStr, 0, 4);

    if ($selectedStart <= $activeStart) {
        echo json_encode(['success' => false, 'message' => "Safety Lock: You cannot transition backwards to $selectedYear. To undo a transition, please use the Database Backup tool."]);
        exit;
    }

    // ── Resolve or create the matching school_years DB row ────────────────────
    $syDbId = null;
    $syRow = $mysqli->query("SELECT id FROM school_years WHERE year_label = '" . $mysqli->real_escape_string($selectedYear) . "' LIMIT 1");
    if ($syRow && $syRow->num_rows > 0) {
        $syDbId = (int)$syRow->fetch_assoc()['id'];
    } else {
        // Insert new school_years row
        $insStmt = $mysqli->prepare("INSERT INTO school_years (year_label, is_current, created_at) VALUES (?, 1, NOW())");
        $insStmt->bind_param('s', $selectedYear);
        $insStmt->execute();
        $syDbId = $mysqli->insert_id;
        $insStmt->close();
    }
    // Mark new year as current, unmark others
    $mysqli->query("UPDATE school_years SET is_current = 0");
    $mysqli->query("UPDATE school_years SET is_current = 1 WHERE id = " . (int)$syDbId);

    // ── Begin atomic transaction ──────────────────────────────────────────────
    $mysqli->begin_transaction();
    try {

        // ── Step 1: Archive Grade 11 completed assessments ────────────────────
        $g11Students = $mysqli->query(
            "SELECT id FROM students
             WHERE grade_level = 'Grade 11'
               AND status IN ('active','pending')"
        );

        $archiveStmt = $mysqli->prepare(
            "INSERT INTO archived_assessments
                (student_id, school_year_id, assessment_id, grade_level, archived_at, snapshot_json)
             VALUES (?, ?, ?, 'Grade 11', NOW(), ?)"
        );

        while ($student = $g11Students->fetch_assoc()) {
            $studentDbId = (int)$student['id'];

            // Fetch all completed assessments for this student
            $assStmt = $mysqli->prepare(
                "SELECT id FROM student_assessments
                 WHERE student_id = ?
                   AND status = 'completed'"
            );
            $assStmt->bind_param('i', $studentDbId);
            $assStmt->execute();
            $assessments = $assStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $assStmt->close();

            foreach ($assessments as $ass) {
                $assId    = (int)$ass['id'];
                $snapshot = json_encode(buildAssessmentSnapshot($mysqli, $assId), JSON_UNESCAPED_UNICODE);
                $archiveStmt->bind_param('iiis', $studentDbId, $syDbId, $assId, $snapshot);
                $archiveStmt->execute();
            }
        }
        $archiveStmt->close();

        // ── Step 2: Mark all currently non-readonly Grade 12 students as graduated ───
        // (Do this BEFORE promoting Grade 11 → 12 so we don't accidentally
        //  touch the freshly promoted students)
        $mysqli->query(
            "UPDATE students
             SET status = 'graduated', is_readonly = 1
             WHERE grade_level = 'Grade 12'
               AND is_readonly = 0"
        );

        // ── Step 3: Promote Grade 11 → Grade 12 ──────────────────────────────
        $mysqli->query(
            "UPDATE students
             SET grade_level = 'Grade 12',
                 school_year_id = " . (int)$syDbId . "
             WHERE grade_level = 'Grade 11'
               AND status IN ('active','pending')"
        );

        // ── Step 3b: Copy valid_student_ids for promoted students (preserves old record) ──
        $mysqli->query(
            "INSERT INTO valid_student_ids (student_id, school_year_id, grade_level, strand_code, is_registered, registered_student_id, created_at)
             SELECT v.student_id, s.school_year_id, s.grade_level, v.strand_code, v.is_registered, v.registered_student_id, NOW()
             FROM valid_student_ids v
             JOIN students s ON v.registered_student_id = s.id
             WHERE s.status IN ('active','pending') 
               AND s.grade_level = 'Grade 12' 
               AND s.school_year_id = " . (int)$syDbId . "
               AND v.id = (SELECT MAX(v2.id) FROM valid_student_ids v2 WHERE v2.registered_student_id = s.id)
               AND v.school_year_id != " . (int)$syDbId
        );

        // ── Step 4: Update system_settings (school_years JSON + school_year) ──
        foreach ($currentYears as &$sy) {
            if ((int)($sy['id'] ?? 0) === $newYearId) {
                $sy['status'] = 'current';
            } else {
                if (($sy['status'] ?? '') === 'current') {
                    $sy['status'] = 'archived';
                }
            }
        }
        unset($sy);

        $ok = setSystemConfig([
            'school_years' => $currentYears,
            'school_year'  => $selectedYear,
        ]);

        if (!$ok) {
            throw new Exception('Failed to update system configuration.');
        }

        // ── Step 5: Commit ────────────────────────────────────────────────────
        $mysqli->commit();

        // ── Step 6: Notification ──────────────────────────────────────────────
        notify_admin(
            'School Year Transition Complete',
            'SY ' . $selectedYear . ' is now active. Grade 12 accounts set to read-only; Grade 11 students promoted to Grade 12. Assessments archived.',
            'success',
            'settings.php'
        );

        // ── Step 7: Audit Log ─────────────────────────────────────────────────
        $adminId = $_SESSION['admin_id'] ?? 0;
        log_activity(
            $adminId,
            'admin',
            'transition_school_year',
            'system_settings',
            null,
            "Admin transitioned active school year to $selectedYear",
            $activeYearStr,
            $selectedYear
        );

        if (!defined('INTERNAL_TRANSITION_CALL')) {
            echo json_encode([
                'success' => true,
                'message' => 'School Year Transition completed successfully.',
                'year'    => $selectedYear,
            ]);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        if (!defined('INTERNAL_TRANSITION_CALL')) {
            echo json_encode([
                'success' => false,
                'message' => 'Transition failed and was rolled back: ' . $e->getMessage(),
            ]);
        } else {
            $apiResult = ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    if (!defined('INTERNAL_TRANSITION_CALL')) {
        exit;
    } else {
        return;
    }
}

// Fallback
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
