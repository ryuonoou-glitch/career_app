<?php
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/recommendation_scoring.php';

requireLogin();

$student = getCurrentStudent();
$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);

$recommendations = [];
$latestAssessmentId = 0;
$preferredRegion = '1';
$allAssessments = [];
$MAX_ASSESSMENTS = 2;

if ($student) {
    // Get ALL completed assessments for this student (for history panel)
    $histStmt = $mysqli->prepare("
        SELECT id, completed_at, status
        FROM student_assessments
        WHERE student_id = ? AND status = 'completed'
        ORDER BY completed_at ASC, id ASC
    ");
    if ($histStmt) {
        $histStmt->bind_param("i", $student['id']);
        $histStmt->execute();
        $histResult = $histStmt->get_result();
        while ($row = $histResult->fetch_assoc()) {
            $allAssessments[] = $row;
        }
        $histStmt->close();
    }

    // For each attempt, get top recommendation
    foreach ($allAssessments as &$attempt) {
        $topStmt = $mysqli->prepare("
            SELECT c.course_name, r.match_percentage
            FROM recommendations r
            JOIN courses c ON r.course_id = c.id
            WHERE r.assessment_id = ?
            ORDER BY r.rank ASC
            LIMIT 1
        ");
        if ($topStmt) {
            $topStmt->bind_param("i", $attempt['id']);
            $topStmt->execute();
            $topRow = $topStmt->get_result()->fetch_assoc();
            $attempt['top_course'] = $topRow['course_name'] ?? null;
            $attempt['top_match'] = $topRow['match_percentage'] ?? null;
            $topStmt->close();
        }
    }
    unset($attempt);

    // Get the latest completed assessment
    $latestAssessment = !empty($allAssessments) ? end($allAssessments) : null;
    if ($latestAssessment) {
        $latestAssessmentId = $latestAssessment['id'];
    }

    // --- Attempt switcher: allow viewing a specific past attempt ---
    $viewingAttemptId = $latestAssessmentId; // default: latest
    $isViewingOldAttempt = false;
    $viewingAttemptNum = $totalAttempts = count($allAssessments);

    $requestedAttemptId = (int)($_GET['attempt_id'] ?? 0);
    if ($requestedAttemptId > 0) {
        // Security: ensure this attempt belongs to this student
        foreach ($allAssessments as $idx => $att) {
            if ($att['id'] === $requestedAttemptId) {
                $viewingAttemptId = $requestedAttemptId;
                $viewingAttemptNum = $idx + 1;
                $isViewingOldAttempt = ($requestedAttemptId !== $latestAssessmentId);
                break;
            }
        }
    }

    // Get preferred region from the assessment being viewed
    if ($viewingAttemptId > 0) {
        $regionStmt = $mysqli->prepare("SELECT preferred_region FROM student_assessments WHERE id = ? LIMIT 1");
        if ($regionStmt) {
            $regionStmt->bind_param("i", $viewingAttemptId);
            $regionStmt->execute();
            $regionRow = $regionStmt->get_result()->fetch_assoc();
            $preferredRegion = !empty($regionRow['preferred_region']) ? $regionRow['preferred_region'] : '1';
            $regionStmt->close();
        }
    }

    // Read selected region filter
    $selectedRegion = $_GET['filter_region'] ?? $preferredRegion;
    if (empty($selectedRegion) || !in_array((string)$selectedRegion, ['1', '2', '3', '4', '5', '6', 'All'], true)) {
        $selectedRegion = !empty($preferredRegion) ? $preferredRegion : '1';
    }

    if ($viewingAttemptId > 0) {
        $scoredCourses = calculateCourseScores($mysqli, $viewingAttemptId);
        $confidenceData = checkRecommendationConfidence($scoredCourses);

        $stmt = $mysqli->prepare("
            SELECT c.*, r.match_percentage, r.explanation, r.rank
            FROM recommendations r
            JOIN courses c ON r.course_id = c.id
            WHERE r.assessment_id = ?
            ORDER BY r.rank ASC, r.match_percentage DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $viewingAttemptId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['breakdown'] = null;
            foreach ($scoredCourses as $sc) {
                if ($sc['course_id'] == $row['id']) {
                    $row['breakdown'] = $sc['breakdown'];
                    $row['match_percentage'] = $sc['match_percentage'];
                    break;
                }
            }
            $recommendations[] = $row;
        }
        $stmt->close();
        
        if (!empty($recommendations)) {
            foreach ($allAssessments as &$att) {
                if ($att['id'] == $viewingAttemptId) {
                    $att['top_match'] = $recommendations[0]['match_percentage'];
                    break;
                }
            }
            unset($att);
        }

        $recommendations = getJobRecommendationsForCourses($mysqli, $recommendations);
        $recommendations = getSchoolRecommendationsForCourses($mysqli, $recommendations, $selectedRegion, $viewingAttemptId);
    }
}

// Helper: ordinal suffix
function ordinal($n) {
    $n = (int)$n;
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

$totalAttempts       = count($allAssessments);
$hasReachedLimit     = $totalAttempts >= $MAX_ASSESSMENTS;
$topCourse           = $recommendations[0] ?? null;
$otherCourses        = array_slice($recommendations, 1);
$viewingAttemptId    = $viewingAttemptId    ?? 0;
$viewingAttemptNum   = $viewingAttemptNum   ?? 0;
$isViewingOldAttempt = $isViewingOldAttempt ?? false;
$latestAssessment    = $latestAssessment    ?? null;

// Build district label helper
function districtLabel($id) {
    if ($id === 'All') return 'All districts';
    $suffixes = ['1'=>'st','2'=>'nd','3'=>'rd'];
    $s = $suffixes[$id] ?? 'th';
    return $id . $s . ' District';
}

// Helper: initials from school name
function rcInitials($name) {
    $words = preg_split('/\s+/', trim($name));
    $init  = '';
    foreach ($words as $w) {
        if (preg_match('/^[A-Za-z]/', $w)) $init .= strtoupper($w[0]);
        if (strlen($init) >= 3) break;
    }
    return $init ?: strtoupper(substr($name, 0, 2));
}
function rcAvatarColor($str) {
    $colors = ['#1d4ed8','#7c3aed','#be185d','#0f766e','#b45309',
               '#1e40af','#6d28d9','#9f1239','#047857','#92400e'];
    return $colors[abs(crc32($str) % count($colors))];
}

// Collect all schools from all recommendations for the Schools tab
$tabAllSchools = [];
$tabCourses = [];

foreach ($recommendations as $rec) {
    $cName = $rec['course_name'] ?? '';
    if ($cName && !in_array($cName, $tabCourses)) {
        $tabCourses[] = $cName;
    }
    
    // Combine in-district and alternative schools since we need them all for the filter tab
    $recSchools = array_merge($rec['in_district_schools'] ?? [], $rec['alternative_schools'] ?? []);
    
    foreach ($recSchools as $school) {
        $sid = (int)($school['id'] ?? 0);
        if (!isset($tabAllSchools[$sid])) {
            $school['recommended_course'] = $cName; // Keep the highest rank course for primary display
            $school['courses'] = [$cName]; // Array to hold all courses this school offers
            $school['course_match_percentage'] = $rec['match_percentage'];
            $tabAllSchools[$sid] = $school;
        } else {
            if (!in_array($cName, $tabAllSchools[$sid]['courses'])) {
                $tabAllSchools[$sid]['courses'][] = $cName;
            }
        }
    }
}
$tabAllSchools = array_values($tabAllSchools);

// Unique districts for filter
$tabDistricts = [];
foreach ($tabAllSchools as $s) {
    $d = $s['district_name'] ?? '';
    if ($d && !in_array($d, $tabDistricts)) $tabDistricts[] = $d;
}
sort($tabDistricts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recommended Courses - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css">
    <style>
        /* ── Page header ─────────────────────────────── */
        .rc-page-header { text-align:center; padding:2rem 0 1.5rem; }
        .rc-page-header h2 { font-size:1.75rem; font-weight:700; color:#f8fafc; margin:0 0 6px; }
        .rc-page-header p  { font-size:0.95rem; color:#94a3b8; margin:0; }

        /* ── Info cards row ──────────────────────────── */
        .rc-info-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem; }
        @media(max-width:700px){ .rc-info-row { grid-template-columns:1fr; } }

        .rc-info-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:1.25rem 1.5rem;
        }
        .rc-info-card-title { font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; margin:0 0 0.6rem; }
        .rc-info-card-status-row { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
        .rc-status-dot { width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .rc-status-dot.green { background:#16a34a; }
        .rc-status-dot.amber { background:#d97706; }
        .rc-status-dot i { font-size:0.6rem; color:#fff; }
        .rc-attempt-label { font-size:1rem; font-weight:600; color:#22c55e; }
        .rc-attempt-label.amber { color:#f59e0b; }
        .rc-attempt-date { font-size:0.8rem; color:#64748b; margin:0 0 0.75rem; }
        .rc-attempt-note { font-size:0.8rem; color:#64748b; margin:0; }
        .rc-compare-btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 14px; background:transparent;
            border:1px solid rgba(255,255,255,0.15); border-radius:8px;
            color:#cbd5e1; font-size:0.8rem; cursor:pointer; text-decoration:none;
            transition:background 0.2s;
        }
        .rc-compare-btn:hover { background:rgba(255,255,255,0.06); }


        .rc-profile-text { font-size:0.875rem; color:#cbd5e1; line-height:1.6; margin:0; }

        /* ── Main grid ───────────────────────────────── */
        .rc-main-grid { display:grid; grid-template-columns:1fr; gap:1.25rem; margin-bottom:1.5rem; }

        /* ── Top recommendation card ─────────────────── */
        .rc-top-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:1.5rem;
        }
        .rc-top-label { display:flex; align-items:center; gap:8px; margin-bottom:1rem; }
        .rc-top-label i { color:#f59e0b; font-size:1rem; }
        .rc-top-label span { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#f59e0b; }

        .rc-course-title { font-size:1.5rem; font-weight:700; color:#f8fafc; margin:0 0 0.75rem; }

        .rc-score-row { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:0.6rem; }
        .rc-rank-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 12px; border-radius:20px;
            background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.3);
            color:#22c55e; font-size:0.78rem; font-weight:600;
        }
        .rc-rank-badge i { font-size:0.65rem; }
        .rc-fit-score-block { text-align:right; }
        .rc-fit-score-label { font-size:0.72rem; color:#94a3b8; display:block; margin-bottom:1px; }
        .rc-fit-score-value { font-size:1.75rem; font-weight:700; color:#f59e0b; line-height:1; }

        .rc-progress-bar { height:6px; background:rgba(255,255,255,0.08); border-radius:3px; margin-bottom:1rem; overflow:hidden; }
        .rc-progress-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#f59e0b,#fb923c); transition:width 0.6s ease; }

        .rc-course-desc { font-size:0.875rem; color:#94a3b8; line-height:1.6; margin:0 0 1rem; }

        /* Job tags */
        .rc-job-tags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:1.25rem; }
        .rc-job-tag {
            padding:5px 14px; border-radius:20px;
            border:1px solid rgba(255,255,255,0.12);
            color:#cbd5e1; font-size:0.8rem; cursor:help;
        }

        /* Schools list */
        .rc-schools-label {
            font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em;
            color:#64748b; margin:0 0 0.75rem;
        }
        .rc-school-row {
            display:flex; align-items:center; justify-content:space-between;
            padding:0.65rem 0.9rem; background:rgba(15,23,42,0.4);
            border:1px solid rgba(255,255,255,0.06); border-radius:10px;
            margin-bottom:0.5rem; gap:8px;
        }
        .rc-school-row-left { display:flex; align-items:center; gap:10px; }
        .rc-school-icon {
            width:32px; height:32px; border-radius:8px;
            background:rgba(255,255,255,0.04);
            display:flex; align-items:center; justify-content:center;
            color:#f59e0b; font-size:0.9rem; flex-shrink:0;
        }
        .rc-school-icon img { width:100%; height:100%; object-fit:cover; border-radius:8px; }
        .rc-school-name { font-size:0.875rem; font-weight:600; color:#f1f5f9; }
        .rc-school-location { font-size:0.75rem; color:#64748b; }
        .rc-view-btn {
            padding:4px 14px; border-radius:7px;
            border:1px solid rgba(255,255,255,0.12);
            background:transparent; color:#cbd5e1;
            font-size:0.78rem; cursor:pointer; text-decoration:none;
            flex-shrink:0; transition:background 0.2s;
        }
        .rc-view-btn:hover { background:rgba(255,255,255,0.06); }
        .rc-view-all-btn {
            display:flex; align-items:center; justify-content:center; gap:8px;
            width:100%; padding:0.75rem; margin-top:0.5rem;
            background:#1d4ed8; border:none; border-radius:10px;
            color:#fff; font-size:0.875rem; font-weight:600;
            cursor:pointer; text-decoration:none; transition:background 0.2s;
        }
        .rc-view-all-btn:hover { background:#2563eb; }

        /* ── History sidebar card ─────────────────────── */
        .rc-history-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:1.25rem;
        }
        .rc-history-title { display:flex; align-items:center; gap:8px; margin-bottom:1rem; }
        .rc-history-title i { color:#94a3b8; font-size:0.9rem; }
        .rc-history-title span { font-size:0.95rem; font-weight:600; color:#f8fafc; }

        .rc-attempt-item {
            border:1px solid rgba(255,255,255,0.07);
            border-radius:10px; padding:0.9rem 1rem;
            margin-bottom:0.75rem;
        }
        .rc-attempt-item.latest { border-color:rgba(99,102,241,0.3); background:rgba(99,102,241,0.04); }

        .rc-attempt-item-header { display:flex; align-items:center; gap:8px; margin-bottom:3px; }
        .rc-attempt-item-label { font-size:0.875rem; font-weight:600; }
        .rc-attempt-item-label.latest { color:#818cf8; }
        .rc-attempt-item-date { font-size:0.75rem; color:#64748b; margin:0 0 0.6rem; }
        .rc-attempt-top-label { font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; }
        .rc-attempt-course-name { font-size:0.875rem; font-weight:600; color:#60a5fa; margin:2px 0 6px; }
        .rc-match-pill {
            display:inline-block; padding:3px 12px; border-radius:20px;
            background:#f59e0b; color:#0f172a; font-size:0.78rem; font-weight:700;
        }

        .rc-limit-note {
            display:flex; align-items:flex-start; gap:10px;
            padding:0.9rem 1rem; margin-top:0.25rem;
            background:rgba(99,102,241,0.06); border:1px solid rgba(99,102,241,0.15);
            border-radius:10px;
        }
        .rc-limit-note i { color:#818cf8; font-size:1rem; margin-top:2px; flex-shrink:0; }
        .rc-limit-note p { font-size:0.8rem; color:#94a3b8; margin:0; line-height:1.5; }

        /* ── Other courses ───────────────────────────── */
        .rc-other-section { background:#111827; border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; }
        .rc-other-title { font-size:1rem; font-weight:700; color:#f8fafc; margin:0 0 1rem; }

        .rc-other-row {
            display:flex; align-items:center; gap:1rem;
            padding:0.9rem 0; cursor:pointer;
            border-bottom:1px solid rgba(255,255,255,0.05);
            text-decoration:none;
        }
        .rc-other-row:last-of-type { border-bottom:none; }
        .rc-other-row:hover .rc-other-name { color:#818cf8; }

        .rc-rank-num {
            width:32px; height:32px; border-radius:50%;
            background:rgba(255,255,255,0.06);
            display:flex; align-items:center; justify-content:center;
            font-size:0.85rem; font-weight:700; color:#cbd5e1; flex-shrink:0;
        }
        .rc-other-info { flex:1; min-width:0; }
        .rc-other-name { font-size:0.9rem; font-weight:600; color:#f1f5f9; margin:0 0 2px; }
        .rc-other-desc { font-size:0.78rem; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        .rc-other-score-block { text-align:right; flex-shrink:0; }
        .rc-other-score-val { font-size:1rem; font-weight:700; color:#f59e0b; display:block; }
        .rc-other-score-lbl { font-size:0.7rem; color:#64748b; }
        .rc-other-bar { width:80px; height:4px; background:rgba(255,255,255,0.08); border-radius:2px; margin-top:4px; overflow:hidden; }
        .rc-other-bar-fill { height:100%; border-radius:2px; background:linear-gradient(90deg,#f59e0b,#fb923c); }

        .rc-other-arrow { color:#475569; font-size:0.8rem; flex-shrink:0; }

        /* Expand/collapsed rows */
        .rc-other-row.hidden-row { display:none; }
        .rc-show-more-btn {
            display:flex; align-items:center; justify-content:center; gap:6px;
            width:100%; padding:0.65rem; margin-top:0.75rem;
            background:transparent; border:none;
            color:#94a3b8; font-size:0.85rem; cursor:pointer;
            transition:color 0.2s;
        }
        .rc-show-more-btn:hover { color:#f8fafc; }

        /* ── Low confidence banner ───────────────────── */
        .rc-confidence-banner {
            display:flex; align-items:flex-start; gap:14px;
            background:rgba(245,158,11,0.08);
            border:1px solid rgba(245,158,11,0.25);
            border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.25rem;
        }
        .rc-confidence-banner i { color:#f59e0b; font-size:1.2rem; margin-top:2px; }
        .rc-confidence-banner h4 { margin:0 0 4px; color:#f8fafc; font-size:0.95rem; }
        .rc-confidence-banner p  { margin:0; color:#cbd5e1; font-size:0.85rem; line-height:1.5; }

        /* ── Footer note ─────────────────────────────── */
        .rc-footer-note { display:flex; align-items:center; gap:8px; padding:0 0 1.5rem; }
        .rc-footer-note i { color:#475569; font-size:0.8rem; flex-shrink:0; }
        .rc-footer-note span { font-size:0.78rem; color:#475569; }

        /* ── Empty state ─────────────────────────────── */
        .rc-empty { text-align:center; padding:4rem 1rem; color:#64748b; }
        .rc-empty i { font-size:3rem; margin-bottom:1rem; display:block; }
        .rc-empty h3 { color:#94a3b8; margin:0 0 8px; }
        .rc-empty a { margin-top:1rem; }

        /* ── Viewing old attempt banner ──────────────── */
        .rc-viewing-banner {
            display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
            background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.3);
            border-radius:12px; padding:0.85rem 1.25rem; margin-bottom:1.25rem;
        }
        .rc-viewing-banner-left { display:flex; align-items:center; gap:10px; }
        .rc-viewing-banner-left i { color:#818cf8; font-size:1rem; }
        .rc-viewing-banner-left span { color:#c7d2fe; font-size:0.875rem; }
        .rc-back-to-latest {
            display:inline-flex; align-items:center; gap:6px;
            padding:5px 14px; border-radius:8px;
            background:rgba(99,102,241,0.2); border:1px solid rgba(99,102,241,0.4);
            color:#a5b4fc; font-size:0.8rem; text-decoration:none; flex-shrink:0;
            transition:background 0.2s;
        }
        .rc-back-to-latest:hover { background:rgba(99,102,241,0.35); }

        /* ── Clickable history items ─────────────────── */
        a.rc-attempt-item {
            display:block; text-decoration:none;
            transition:border-color 0.2s, background 0.2s;
            cursor:pointer;
        }
        a.rc-attempt-item:hover { border-color:rgba(99,102,241,0.4); background:rgba(99,102,241,0.06); }
        a.rc-attempt-item.viewing { border-color:rgba(99,102,241,0.5); background:rgba(99,102,241,0.1); }

        /* ── Course detail modal ─────────────────────── */
        #rcCourseModal {
            border:none; background:transparent;
            padding:0; max-width:100%; width:100%;
            max-height:100%; height:100%;
            position:fixed; inset:0;
            overflow:hidden;
        }
        /* Only flex-center the panel when the dialog is actually open */
        #rcCourseModal[open] {
            display:flex; align-items:center; justify-content:center;
        }
        #rcCourseModal::backdrop {
            background:rgba(0,0,0,0.72);
            backdrop-filter:blur(4px);
            -webkit-backdrop-filter:blur(4px);
        }
        .rcm-panel {
            background:#111827;
            border:1px solid rgba(255,255,255,0.1);
            border-radius:18px;
            width:100%; max-width:640px;
            max-height:90dvh;
            overflow-y:auto;
            padding:0;
            position:relative;
            animation:rcmSlideUp 0.25s cubic-bezier(0.34,1.56,0.64,1) both;
            scrollbar-width:thin;
            scrollbar-color:rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6);
        }
        @keyframes rcmSlideUp {
            from { opacity:0; transform:translateY(24px) scale(0.97); }
            to   { opacity:1; transform:translateY(0)    scale(1); }
        }
        @media(max-width:640px) {
            #rcCourseModal { align-items:flex-end; }
            .rcm-panel {
                border-radius:18px 18px 0 0;
                max-height:92dvh;
                animation:rcmSlideUpMobile 0.28s cubic-bezier(0.34,1.12,0.64,1) both;
            }
            @keyframes rcmSlideUpMobile {
                from { opacity:0; transform:translateY(100%); }
                to   { opacity:1; transform:translateY(0); }
            }
        }

        /* Modal header */
        .rcm-header {
            display:flex; align-items:flex-start; justify-content:space-between;
            gap:12px; padding:1.4rem 1.5rem 0;
            position:sticky; top:0;
            background:#111827;
            border-radius:18px 18px 0 0;
            z-index:1;
        }
        .rcm-close {
            width:34px; height:34px; border-radius:50%;
            background:rgba(255,255,255,0.06);
            border:1px solid rgba(255,255,255,0.1);
            color:#94a3b8; font-size:0.9rem;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; flex-shrink:0;
            transition:background 0.2s, color 0.2s;
        }
        .rcm-close:hover { background:rgba(255,255,255,0.12); color:#f1f5f9; }

        /* Modal body */
        .rcm-body { padding:1rem 1.5rem 1.5rem; }

        /* Why score button */
        .rcm-why-btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:6px 16px; border-radius:20px;
            background:rgba(99,102,241,0.12);
            border:1px solid rgba(99,102,241,0.3);
            color:#a5b4fc; font-size:0.82rem; font-weight:600;
            cursor:pointer; margin-bottom:1rem;
            transition:background 0.2s, border-color 0.2s;
            width:auto;
        }
        .rcm-why-btn:hover { background:rgba(99,102,241,0.2); border-color:rgba(99,102,241,0.5); }
        .rcm-why-btn i { font-size:0.72rem; transition:transform 0.25s; }
        .rcm-why-btn.open i.rcm-chevron { transform:rotate(180deg); }

        /* Why score content */
        .rcm-why-content {
            display:none;
            background:rgba(99,102,241,0.06);
            border:1px solid rgba(99,102,241,0.18);
            border-radius:10px; padding:0.9rem 1rem;
            margin-bottom:1rem;
        }
        .rcm-why-content p {
            font-size:0.84rem; color:#cbd5e1; line-height:1.65; margin:0;
        }
        .rcm-why-content.visible { display:block; }

        /* Modal divider */
        .rcm-divider {
            border:none; border-top:1px solid rgba(255,255,255,0.07);
            margin:1rem 0;
        }

        /* Section label inside modal */
        .rcm-section-label {
            font-size:0.72rem; font-weight:700; text-transform:uppercase;
            letter-spacing:0.06em; color:#64748b; margin:0 0 0.75rem;
        }

        /* No schools note */
        .rcm-no-schools {
            font-size:0.85rem; color:#64748b; font-style:italic; margin:0;
        }

        /* ── Score breakdown bars (inside modal) ────────── */
        .rcm-breakdown-block {
            background:rgba(15,23,42,0.5);
            border:1px solid rgba(255,255,255,0.07);
            border-radius:12px;
            padding:1rem 1.1rem;
            margin-bottom:1rem;
        }
        .rcm-breakdown-title {
            font-size:0.7rem; font-weight:700; text-transform:uppercase;
            letter-spacing:0.07em; color:#64748b; margin:0 0 0.85rem;
            display:flex; align-items:center; gap:6px;
        }
        .rcm-breakdown-title i { font-size:0.65rem; }
        .rcm-bar-row { margin-bottom:0.7rem; }
        .rcm-bar-row:last-child { margin-bottom:0; }
        .rcm-bar-meta {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:4px;
        }
        .rcm-bar-label { font-size:0.78rem; color:#cbd5e1; font-weight:500; }
        .rcm-bar-pct   { font-size:0.78rem; font-weight:700; color:#f59e0b; }
        .rcm-bar-track {
            height:6px; background:rgba(255,255,255,0.07);
            border-radius:3px; overflow:hidden;
        }
        .rcm-bar-fill {
            height:100%; border-radius:3px;
            transition:width 0.5s ease;
        }
        .rcm-bar-fill.career      { background:linear-gradient(90deg,#f59e0b,#fb923c); }
        .rcm-bar-fill.personality { background:linear-gradient(90deg,#8b5cf6,#a78bfa); }
        .rcm-bar-fill.strand      { background:linear-gradient(90deg,#10b981,#34d399); }
        .rcm-bar-fill.cluster     { background:linear-gradient(90deg,#3b82f6,#60a5fa); }

        /* ── Tabs ────────────────────────────────────────── */
        .rc-tabs {
            display:flex; gap:0;
            background:#0c1526;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:5px;
            margin-bottom:1.75rem;
        }
        .rc-tab-btn {
            flex:1; display:flex; align-items:center; justify-content:center; gap:9px;
            padding:0.85rem 1.5rem;
            border:none; border-radius:10px;
            background:transparent;
            color:#64748b; font-size:0.95rem; font-weight:600;
            cursor:pointer; transition:all 0.22s ease;
            font-family:inherit;
        }
        .rc-tab-btn i { font-size:1rem; }
        .rc-tab-btn:hover { color:#cbd5e1; background:rgba(255,255,255,0.04); }
        .rc-tab-btn.active {
            background:linear-gradient(135deg,#1e3a8a,#1d4ed8);
            color:#fff;
            box-shadow:0 4px 16px rgba(29,78,216,0.35);
        }
        .rc-tab-panel { display:none; }
        .rc-tab-panel.active { display:block; }
        @media(max-width:540px) {
            .rc-tab-btn { font-size:0.82rem; padding:0.75rem 0.75rem; }
            .rc-tab-btn i { display:none; }
        }

        /* ── Course cards grid ────────────────────────────── */
        .rcc-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
            gap:1.1rem;
            margin-bottom:1.5rem;
        }
        @media(max-width:600px){ .rcc-grid { grid-template-columns:1fr; } }

        .rcc-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:1.3rem;
            display:flex; flex-direction:column; gap:0;
            transition:border-color 0.2s, transform 0.2s, box-shadow 0.2s;
            cursor:default;
        }
        .rcc-card:hover {
            border-color:rgba(99,102,241,0.35);
            transform:translateY(-2px);
            box-shadow:0 8px 28px rgba(0,0,0,0.4);
        }
        .rcc-card.rank-1 { border-color:rgba(245,158,11,0.35); }

        .rcc-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }
        .rcc-rank-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 11px; border-radius:20px;
            font-size:0.73rem; font-weight:700;
        }
        .rcc-rank-badge.gold { background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4); color:#f59e0b; }
        .rcc-rank-badge.silver { background:rgba(148,163,184,0.1); border:1px solid rgba(148,163,184,0.25); color:#94a3b8; }

        .rcc-score-label { font-size:0.68rem; color:#64748b; display:block; text-align:right; }
        .rcc-score-value { font-size:1.35rem; font-weight:800; color:#f59e0b; display:block; text-align:right; line-height:1; }

        .rcc-title { font-size:1.05rem; font-weight:700; color:#f8fafc; margin:0 0 0.5rem; line-height:1.3; }

        .rcc-bar { height:5px; background:rgba(255,255,255,0.07); border-radius:3px; margin-bottom:0.85rem; overflow:hidden; }
        .rcc-bar-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#f59e0b,#fb923c); }

        .rcc-desc { font-size:0.81rem; color:#94a3b8; line-height:1.55; margin:0 0 0.85rem; flex:1; }

        .rcc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:0.9rem; }
        .rcc-chip {
            padding:3px 11px; border-radius:20px;
            border:1px solid rgba(255,255,255,0.1);
            color:#cbd5e1; font-size:0.75rem;
        }

        .rcc-breakdown-btn {
            display:flex; align-items:center; justify-content:center; gap:7px;
            width:100%; padding:0.6rem;
            background:rgba(99,102,241,0.1);
            border:1px solid rgba(99,102,241,0.25);
            border-radius:9px;
            color:#a5b4fc; font-size:0.82rem; font-weight:600;
            cursor:pointer; transition:background 0.2s, border-color 0.2s;
            margin-top:auto;
        }
        .rcc-breakdown-btn:hover { background:rgba(99,102,241,0.2); border-color:rgba(99,102,241,0.45); }

        /* ── Schools tab ───────────────────────────────────── */
        .rcs-filter-bar {
            display:flex; align-items:center; gap:10px; flex-wrap:wrap;
            margin-bottom:1.25rem;
        }
        .rcs-search-wrap { position:relative; flex:1; min-width:180px; }
        .rcs-search-wrap i {
            position:absolute; left:0.9rem; top:50%; transform:translateY(-50%);
            color:#64748b; font-size:0.85rem; pointer-events:none;
        }
        .rcs-search-wrap input {
            width:100%; padding:0.65rem 1rem 0.65rem 2.6rem;
            background:#0f172a; border:1px solid rgba(255,255,255,0.1);
            border-radius:10px; color:#f1f5f9; font-size:0.875rem;
            outline:none; font-family:inherit; box-sizing:border-box;
            transition:border-color 0.2s;
        }
        .rcs-search-wrap input:focus { border-color:rgba(99,102,241,0.5); }
        .rcs-search-wrap input::placeholder { color:#475569; }
        .rcs-filter-select {
            padding:0.6rem 2.2rem 0.6rem 0.9rem;
            background:#0f172a url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='%2394a3b8'%3E%3Cpath d='M6 8L0 0h12z'/%3E%3C/svg%3E") no-repeat right 0.7rem center;
            border:1px solid rgba(255,255,255,0.1); border-radius:10px;
            color:#f1f5f9; font-size:0.875rem; cursor:pointer;
            outline:none; font-family:inherit; appearance:none; -webkit-appearance:none;
            transition:border-color 0.2s;
        }
        .rcs-filter-select:focus { border-color:rgba(99,102,241,0.5); }
        #rcsCountChip { font-size:0.78rem; color:#94a3b8; white-space:nowrap; }

        .rcs-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(230px, 1fr));
            gap:1rem;
            margin-bottom:1.5rem;
        }
        @media(max-width:500px){ .rcs-grid { grid-template-columns:1fr; } }

        .rcs-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.07);
            border-radius:14px;
            padding:1.2rem;
            display:flex; flex-direction:column; gap:0;
            transition:border-color 0.2s, transform 0.2s, box-shadow 0.2s;
        }
        .rcs-card:hover {
            border-color:rgba(99,102,241,0.35);
            transform:translateY(-2px);
            box-shadow:0 6px 20px rgba(0,0,0,0.35);
        }
        .rcs-card-header { display:flex; align-items:center; gap:12px; margin-bottom:0.85rem; }
        .rcs-avatar {
            width:46px; height:46px; border-radius:12px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
            font-size:0.85rem; font-weight:800; color:#fff; overflow:hidden;
        }
        .rcs-avatar img { width:100%; height:100%; object-fit:cover; border-radius:12px; }
        .rcs-card-name { font-size:0.9rem; font-weight:700; color:#f8fafc; line-height:1.3; }
        .rcs-location { display:flex; align-items:center; gap:5px; font-size:0.75rem; color:#64748b; margin-bottom:0.6rem; }
        .rcs-location i { font-size:0.68rem; color:#475569; }
        .rcs-match-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:3px 11px; border-radius:20px;
            background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.3);
            color:#f59e0b; font-size:0.75rem; font-weight:700;
            margin-bottom:0.6rem;
        }
        .rcs-course-label { font-size:0.68rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; margin:0 0 3px; }
        .rcs-course-name  { font-size:0.82rem; font-weight:600; color:#f1f5f9; margin:0 0 0.85rem; }
        .rcs-view-btn {
            display:flex; align-items:center; justify-content:center; gap:6px;
            width:100%; padding:0.55rem;
            background:#1d4ed8; border:none; border-radius:9px;
            color:#fff; font-size:0.8rem; font-weight:600;
            text-decoration:none; cursor:pointer; transition:background 0.2s;
            margin-top:auto;
        }
        .rcs-view-btn:hover { background:#2563eb; }
        .rcs-view-btn i { font-size:0.72rem; }
        .rcs-no-match { display:none; grid-column:1/-1; text-align:center; padding:3rem 1rem; color:#64748b; }
        .rcs-no-match i { font-size:2.5rem; margin-bottom:0.75rem; display:block; }
        .rcs-no-match h3 { color:#94a3b8; margin:0 0 6px; }
        .rcs-empty { text-align:center; padding:3rem 1rem; color:#64748b; }
        .rcs-empty i { font-size:2.5rem; margin-bottom:0.75rem; display:block; }

        /* ── Kept from before (modal, info cards, etc.) ─────── */
        /* Other course row — make clickable role clear */
        .rc-other-row { cursor:pointer; }
        .rc-other-row:hover { background:rgba(255,255,255,0.02); border-radius:10px; }
        .rc-other-row:hover .rc-other-arrow { color:#818cf8; }

        /* ── AI Chatbot ────────────────────────────────────────────────── */
        .ai-chatbot-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        /* Ambient subtle pulse on FAB */
        @keyframes aiFabGlow {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.45), 0 8px 24px rgba(245, 158, 11, 0.35); }
            70% { box-shadow: 0 0 0 12px rgba(245, 158, 11, 0), 0 8px 24px rgba(245, 158, 11, 0.35); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0), 0 8px 24px rgba(245, 158, 11, 0.35); }
        }

        @keyframes aiBadgePulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        .ai-chatbot-fab {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-size: 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.26s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.26s cubic-bezier(0.16, 1, 0.3, 1), background 0.2s;
            position: relative;
            animation: aiFabGlow 3.5s infinite;
            outline: none;
            -webkit-tap-highlight-color: transparent;
        }
        .ai-chatbot-fab:hover {
            transform: scale(1.08) translateY(-2px);
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%);
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.25), 0 12px 30px rgba(245, 158, 11, 0.55);
        }
        .ai-chatbot-fab:active {
            transform: scale(0.94);
        }
        .ai-chatbot-fab .ai-fab-badge {
            position: absolute;
            top: 1px;
            right: 1px;
            width: 14px;
            height: 14px;
            background: #10b981;
            border: 2.5px solid #0f172a;
            border-radius: 50%;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.7);
            animation: aiBadgePulse 2.5s infinite ease-in-out;
        }

        /* Chat Window */
        .ai-chatbot-window {
            position: absolute;
            bottom: 74px;
            right: 0;
            width: 400px;
            height: 590px;
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 96px);
            background: rgba(15, 23, 42, 0.96);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05), 0 0 40px rgba(245, 158, 11, 0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.26s cubic-bezier(0.16, 1, 0.3, 1), transform 0.26s cubic-bezier(0.16, 1, 0.3, 1), width 0.3s cubic-bezier(0.16, 1, 0.3, 1), height 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateY(18px) scale(0.96);
            transform-origin: bottom right;
            z-index: 1000;
        }
        .ai-chatbot-window.active {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        /* Expanded Mode (via resize button on desktop) */
        .ai-chatbot-window.expanded {
            width: min(720px, calc(100vw - 48px));
            height: min(750px, calc(100vh - 96px));
        }

        /* Header */
        .ai-chatbot-header {
            position: relative;
            background: rgba(15, 23, 42, 0.98);
            backdrop-filter: blur(14px);
            padding: 13px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            user-select: none;
            flex-shrink: 0;
        }
        /* Top accent line */
        .ai-chatbot-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #f59e0b, #fbbf24, #ea580c);
        }
        .ai-chatbot-title-box {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .ai-chatbot-avatar {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.22), rgba(217, 119, 6, 0.12));
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            box-shadow: 0 0 14px rgba(245, 158, 11, 0.22);
        }
        .ai-chatbot-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .ai-chatbot-name {
            color: #f8fafc;
            font-weight: 700;
            font-size: 0.96rem;
            line-height: 1.2;
            letter-spacing: -0.01em;
        }
        .ai-chatbot-subtitle {
            color: #94a3b8;
            font-size: 0.74rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ai-chatbot-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.8);
            display: inline-block;
            flex-shrink: 0;
        }

        /* Header Controls */
        .ai-chatbot-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ai-chatbot-action {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.92rem;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            outline: none;
        }
        .ai-chatbot-action:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.18);
            transform: translateY(-1px);
        }
        .ai-chatbot-action:active {
            transform: scale(0.92);
        }
        .ai-chatbot-action.danger:hover {
            color: #fca5a5;
            background: rgba(239, 68, 68, 0.18);
            border-color: rgba(239, 68, 68, 0.35);
        }

        /* Message Area */
        .ai-chatbot-messages {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: #090e1c;
            scrollbar-width: thin;
            scrollbar-color: rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6);
        }
        .ai-chatbot-messages::-webkit-scrollbar {
            width: 5px;
        }
        .ai-chatbot-messages::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 999px;
        }
        .ai-chatbot-messages::-webkit-scrollbar-thumb {
            background: rgba(251, 191, 36, 0.35);
            border-radius: 999px;
        }
        .ai-chatbot-messages::-webkit-scrollbar-thumb:hover {
            background: rgba(251, 191, 36, 0.7);
        }

        /* Welcome / Empty State */
        .ai-chatbot-welcome {
            margin: auto 0;
            padding: 18px 12px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            animation: aiMsgFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .ai-welcome-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.25), rgba(217, 119, 6, 0.15));
            border: 1.5px solid rgba(245, 158, 11, 0.4);
            color: #fbbf24;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 0 20px rgba(245, 158, 11, 0.2);
        }
        .ai-welcome-title {
            color: #f8fafc;
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0 0 6px;
            letter-spacing: -0.01em;
        }
        .ai-welcome-desc {
            color: #94a3b8;
            font-size: 0.82rem;
            line-height: 1.55;
            margin: 0 0 16px;
            max-width: 320px;
        }
        .ai-welcome-chips {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%;
            max-width: 330px;
        }
        .ai-chip {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 9px 13px;
            color: #cbd5e1;
            font-size: 0.82rem;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 9px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            outline: none;
            width: 100%;
            box-sizing: border-box;
        }
        .ai-chip i {
            color: #f59e0b;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .ai-chip:hover {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.35);
            color: #f8fafc;
            transform: translateY(-1px);
        }
        .ai-chip:active {
            transform: scale(0.98);
        }

        /* Messages */
        .ai-msg {
            max-width: 86%;
            padding: 11px 15px;
            border-radius: 16px;
            font-size: 0.91rem;
            line-height: 1.6;
            word-break: break-word;
            animation: aiMsgFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes aiMsgFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .ai-msg-bot {
            background: #162032;
            color: #e2e8f0;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-left: 2.5px solid rgba(245, 158, 11, 0.6);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.22);
        }
        .ai-msg-bot strong {
            color: #ffffff;
            font-weight: 600;
        }
        .ai-msg-bot em {
            color: #cbd5e1;
            font-style: italic;
        }
        .ai-msg-bot .ai-msg-list-item {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            margin: 5px 0;
        }
        .ai-msg-bot .ai-msg-bullet {
            color: #f59e0b;
            font-size: 0.95rem;
            line-height: 1.5;
            flex-shrink: 0;
        }
        .ai-msg-user {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.32);
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .ai-msg-error {
            background: rgba(239, 68, 68, 0.14);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-left: 3px solid #ef4444;
            border-bottom-left-radius: 4px;
        }

        /* Input Area */
        .ai-chatbot-input-area {
            padding: 12px 14px;
            background: #0f172a;
            display: flex;
            gap: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            align-items: flex-end;
            flex-shrink: 0;
        }
        .ai-chatbot-input-wrap {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
        }
        .ai-chatbot-input-area textarea {
            width: 100%;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 13px;
            padding: 10px 14px;
            color: #f8fafc;
            font-family: inherit;
            font-size: 0.92rem;
            line-height: 22px;
            height: 44px;
            min-height: 44px;
            max-height: 115px;
            resize: none;
            outline: none;
            overflow-y: hidden;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
            scrollbar-width: none;
            -webkit-appearance: none;
            appearance: none;
        }
        .ai-chatbot-input-area textarea::-webkit-scrollbar {
            display: none;
        }
        .ai-chatbot-input-area textarea:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
        }
        .ai-chatbot-input-area textarea::placeholder {
            color: #64748b;
        }
        .ai-chatbot-send {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 1rem;
            outline: none;
        }
        .ai-chatbot-send:hover:not(:disabled) {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.5);
        }
        .ai-chatbot-send:active:not(:disabled) {
            transform: scale(0.93);
        }
        .ai-chatbot-send:disabled {
            background: #334155;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* Typing indicator */
        .ai-typing {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 6px 8px;
        }
        .ai-typing-dot {
            width: 7px;
            height: 7px;
            background: #f59e0b;
            border-radius: 50%;
            animation: aiTyping 1.4s infinite ease-in-out both;
        }
        .ai-typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .ai-typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes aiTyping {
            0%, 80%, 100% { transform: scale(0.4); opacity: 0.35; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* Confirmation Modal */
        .ai-chatbot-confirm-modal {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(8, 13, 26, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease;
            z-index: 20;
            padding: 20px;
        }
        .ai-chatbot-confirm-modal.active {
            opacity: 1;
            pointer-events: auto;
        }
        .ai-chatbot-confirm-content {
            background: #1a2436;
            padding: 24px 22px;
            border-radius: 18px;
            width: 100%;
            max-width: 320px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            text-align: center;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
            animation: aiConfirmPop 0.22s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes aiConfirmPop {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .ai-chatbot-confirm-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            border: 1px solid rgba(245, 158, 11, 0.35);
            box-shadow: 0 0 16px rgba(245, 158, 11, 0.2);
        }
        .ai-chatbot-confirm-content h3 {
            color: #f8fafc;
            font-size: 1.1rem;
            margin: 0 0 8px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .ai-chatbot-confirm-content p {
            color: #94a3b8;
            font-size: 0.85rem;
            margin: 0 0 20px;
            line-height: 1.55;
        }
        .ai-chatbot-confirm-actions {
            display: flex;
            gap: 10px;
        }
        .ai-chatbot-confirm-actions button {
            flex: 1;
            padding: 10px 14px;
            border-radius: 11px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            border: none;
            outline: none;
        }
        .ai-chatbot-btn-cancel {
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
        }
        .ai-chatbot-btn-cancel:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #f8fafc;
        }
        .ai-chatbot-btn-restart {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
        }
        .ai-chatbot-btn-restart:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.45);
        }

        /* ── Adaptive Mobile Layout (phones & touchscreens <= 640px) ───── */
        @media (max-width: 640px) {
            .ai-chatbot-container {
                position: fixed;
                bottom: 0;
                right: 0;
                left: 0;
                top: 0;
                pointer-events: none;
                z-index: 10000;
            }
            .ai-chatbot-container > * {
                pointer-events: auto;
            }
            .ai-chatbot-fab {
                position: fixed;
                bottom: max(18px, env(safe-area-inset-bottom, 18px));
                right: 18px;
                width: 54px;
                height: 54px;
                font-size: 1.45rem;
                z-index: 10000;
            }
            /* Window transforms to full-screen mobile view */
            .ai-chatbot-window {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100vw !important;
                height: 100% !important;
                height: 100dvh !important;
                max-width: 100vw !important;
                max-height: 100% !important;
                max-height: 100dvh !important;
                border-radius: 0 !important;
                border: none !important;
                transform: translateY(100%) !important;
                transform-origin: center bottom !important;
                display: flex !important;
                flex-direction: column !important;
                transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.22s ease !important;
                z-index: 10001 !important;
            }
            .ai-chatbot-window.active {
                transform: translateY(0) !important;
                pointer-events: auto !important;
                opacity: 1 !important;
            }
            /* Hide desktop window resize expand button on mobile */
            #aiChatbotResize {
                display: none !important;
            }
            .ai-chatbot-header {
                padding: max(14px, env(safe-area-inset-top, 14px)) 16px 14px 16px;
                border-radius: 0;
                flex-shrink: 0;
            }
            .ai-chatbot-action {
                width: 38px;
                height: 38px;
                font-size: 1.05rem;
                border-radius: 10px;
            }
            .ai-chatbot-messages {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
                padding: 14px 12px;
                gap: 12px;
                -webkit-overflow-scrolling: touch;
            }
            .ai-msg {
                max-width: 90%;
                font-size: 0.93rem;
                padding: 10px 14px;
            }
            .ai-chatbot-input-area {
                padding: 10px 12px;
                padding-bottom: max(12px, env(safe-area-inset-bottom, 12px));
                background: #0f172a;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
                flex-shrink: 0;
                position: sticky;
                bottom: 0;
                z-index: 20;
            }
            .ai-chatbot-input-area textarea {
                font-size: 16px !important; /* CRITICAL: Prevents iOS auto-zoom */
                line-height: 22px;
                height: 44px;
                min-height: 44px;
                max-height: 95px;
                padding: 10px 12px;
                overflow-y: hidden;
                color: #f8fafc !important;
                -webkit-text-fill-color: #f8fafc !important;
                caret-color: #f59e0b;
                background: #1e293b !important;
                border-color: rgba(255, 255, 255, 0.2);
                border-radius: 12px;
            }
            .ai-chatbot-send {
                width: 44px;
                height: 44px;
                border-radius: 12px;
            }
            .ai-chatbot-confirm-modal {
                padding: 16px;
            }
            .ai-chatbot-confirm-content {
                max-width: 92vw;
                padding: 22px 18px;
                border-radius: 18px;
            }
            .ai-chatbot-confirm-actions button {
                padding: 12px;
                font-size: 0.92rem;
            }
            .ai-welcome-desc {
                max-width: 100%;
            }
            .ai-welcome-chips {
                max-width: 100%;
            }
            .ai-chip {
                padding: 10px 13px;
                font-size: 0.83rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <?php echo getSystemLogo('logo-icon'); ?>
                <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Close sidebar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-home"></i><span>Dashboard</span></a>
            <a href="take_assessment.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i><span>Take Assessment</span></a>
            <a href="assessment_results.php" class="nav-item"><i class="fa-solid fa-chart-line"></i><span>Assessment Results</span></a>
            <a href="recommended_courses.php" class="nav-item active"><i class="fa-solid fa-graduation-cap"></i><span>Recommendations</span></a>
            <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
            <a href="logout.php?role=student" class="nav-item logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </nav>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <header class="top-bar">
            <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fa-solid fa-bars"></i></button>
            <div class="page-title"><h1>Recommendations</h1></div>
            <div class="top-bar-actions">
                <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                <div class="user-profile">
                    <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar">
                    <div class="user-dropdown"><span class="user-name"><?php echo $studentName; ?></span></div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="dashboard-content">

            <?php if (empty($recommendations)): ?>
            <!-- Empty state -->
            <div class="rc-empty">
                <i class="fa-solid fa-book-open"></i>
                <h3>No Courses Recommended Yet</h3>
                <p>Take an assessment to get personalized course recommendations.</p>
                <a href="take_assessment.php" class="btn btn-primary" style="margin-top:1rem;">Take Assessment</a>
            </div>
            <?php else: ?>




            <!-- Viewing old attempt banner -->
            <?php if ($isViewingOldAttempt): ?>
            <div class="rc-viewing-banner">
                <div class="rc-viewing-banner-left">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>You are viewing <strong style="color:#f8fafc;">Attempt <?php echo $viewingAttemptNum; ?></strong> recommendations &mdash; not your latest results.</span>
                </div>
                <a href="recommended_courses.php" class="rc-back-to-latest">
                    <i class="fa-solid fa-arrow-left"></i> Back to Latest
                </a>
            </div>
            <?php endif; ?>

            <!-- ── Disclaimer Banner ────────────────────────── -->
            <div style="display:flex;align-items:flex-start;gap:14px;background:rgba(59,130,246,0.07);border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
                <i class="fa-solid fa-circle-info" style="color:#60a5fa;font-size:1.1rem;margin-top:2px;flex-shrink:0;"></i>
                <p style="margin:0;color:#94a3b8;font-size:0.875rem;line-height:1.6;">
                    <strong style="color:#cbd5e1;">For guidance purposes only.</strong> The career recommendations generated by the system serve as guidance references only and are not intended as definitive career directives. You are <strong style="color:#cbd5e1;">not required to follow the recommended courses</strong>. This assessment is designed to help you reflect on your interests and explore possible career paths, not to make final decisions on your behalf.
                </p>
            </div>

            <!-- ── Tab Navigation ───────────────────────────── -->
            <div class="rc-tabs" role="tablist">
                <button class="rc-tab-btn active" id="tabBtnCourses" role="tab"
                        aria-selected="true" aria-controls="tabPanelCourses"
                        onclick="rcSwitchTab('courses')">
                    <i class="fa-solid fa-graduation-cap"></i> Recommended Courses
                </button>
                <button class="rc-tab-btn" id="tabBtnSchools" role="tab"
                        aria-selected="false" aria-controls="tabPanelSchools"
                        onclick="rcSwitchTab('schools')">
                    <i class="fa-solid fa-school"></i> Recommended Schools
                </button>
            </div>

            <!-- ═══════════════════════════════════════════════
                 TAB 1: RECOMMENDED COURSES
            ════════════════════════════════════════════════ -->
            <div class="rc-tab-panel active" id="tabPanelCourses" role="tabpanel">

                <!-- Low confidence warning (courses tab only) -->
                <?php if (!empty($confidenceData) && $confidenceData['is_low_confidence']): ?>
                <div class="rc-confidence-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div>
                        <h4>Broad Interests Detected</h4>
                        <p><?php echo htmlspecialchars($confidenceData['message']); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Info cards row -->
                <div class="rc-info-row">
                    <!-- Assessment Status -->
                    <div class="rc-info-card">
                        <p class="rc-info-card-title">Assessment Status</p>
                        <?php if ($latestAssessment): ?>
                            <div class="rc-info-card-status-row">
                                <div class="rc-status-dot green"><i class="fa-solid fa-check"></i></div>
                                <span class="rc-attempt-label <?php echo $isViewingOldAttempt ? 'amber' : ''; ?>">
                                    Attempt <?php echo $viewingAttemptNum; ?> of <?php echo $MAX_ASSESSMENTS; ?>
                                    <?php echo $isViewingOldAttempt ? '(Viewing)' : '(Latest)'; ?>
                                </span>
                            </div>
                            <p class="rc-attempt-date">
                                Completed on: <?php
                                    $viewedAssessment = $allAssessments[$viewingAttemptNum - 1] ?? $latestAssessment;
                                    echo date('F j, Y \a\t g:i A', strtotime($viewedAssessment['completed_at']));
                                ?>
                            </p>
                            <p class="rc-attempt-date" style="margin-top:4px;">
                                <?php
                                    $rawDistrict = $preferredRegion ?? '';
                                    if (empty($rawDistrict)) {
                                        $districtDisplay = 'Not Selected';
                                    } elseif (strtolower($rawDistrict) === 'all') {
                                        $districtDisplay = 'All Districts';
                                    } else {
                                        $parts = array_map('trim', explode(',', $rawDistrict));
                                        $labels = array_map(function($id) { return districtLabel($id); }, $parts);
                                        $districtDisplay = implode(', ', $labels);
                                    }
                                ?>
                                <span style="color:#94a3b8;">Preferred District(s):</span>
                                <span style="color:#c7d2fe; font-weight:500;"><?php echo htmlspecialchars($districtDisplay); ?></span>
                            </p>
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                <p class="rc-attempt-note">
                                    <?php echo $isViewingOldAttempt
                                        ? 'You are viewing an older set of recommendations.'
                                        : 'These recommendations are based on your latest assessment.'; ?>
                                </p>
                                <?php if ($totalAttempts > 1 && !$isViewingOldAttempt): ?>
                                <a href="recommended_courses.php?attempt_id=<?php echo $allAssessments[0]['id']; ?>#courses" class="rc-compare-btn">
                                    <i class="fa-solid fa-right-left"></i> Compare Attempt 1
                                </a>
                                <?php elseif ($isViewingOldAttempt): ?>
                                <a href="recommended_courses.php#courses" class="rc-compare-btn" style="border-color:rgba(99,102,241,0.4); color:#a5b4fc;">
                                    <i class="fa-solid fa-arrow-right"></i> View Latest
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="rc-attempt-note" style="color:#64748b;">No completed assessments yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Career Profile -->
                    <div class="rc-info-card" style="border-left:3px solid #f59e0b;">
                        <p class="rc-info-card-title" style="display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-circle-info" style="color:#f59e0b;"></i>
                            Your Career Profile
                        </p>
                        <p class="rc-profile-text">
                            Your assessment indicates strengths in several career fields.
                            The courses below are ranked according to how closely they match your interests and assessment results.
                        </p>
                    </div>
                </div>

                <!-- Main grid (Top Course only) -->
                <div class="rc-main-grid">

                    <!-- Top Recommendation Card -->
                    <div class="rc-top-card">
                        <div class="rc-top-label">
                            <i class="fa-solid fa-trophy"></i>
                            <span>Top Recommendation</span>
                        </div>

                        <div class="rc-score-row">
                            <div>
                                <h3 class="rc-course-title"><?php echo htmlspecialchars($topCourse['course_name']); ?></h3>
                                <div class="rc-rank-badge"><i class="fa-solid fa-check"></i> Rank #1</div>
                            </div>
                            <div class="rc-fit-score-block">
                                <span class="rc-fit-score-label">Career Fit Score</span>
                                <span class="rc-fit-score-value"><?php echo number_format($topCourse['match_percentage'], 1); ?>%</span>
                            </div>
                        </div>

                        <div class="rc-progress-bar" style="margin-top:0.75rem; margin-bottom:0.75rem;">
                            <div class="rc-progress-fill" style="width:<?php echo min(100, (float)$topCourse['match_percentage']); ?>%;"></div>
                        </div>

                        <?php if (!empty($topCourse['breakdown'])): ?>
                        <button class="rcm-why-btn" id="topBreakdownBtn"
                                data-course-id="top"
                                style="margin-bottom:1rem;">
                            <i class="fa-solid fa-chart-bar"></i>
                            View Score Breakdown
                            <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;"></i>
                        </button>
                        <?php endif; ?>

                        <?php if (!empty($topCourse['description'])): ?>
                        <p class="rc-course-desc"><?php echo htmlspecialchars($topCourse['description']); ?></p>
                        <?php endif; ?>

                        <!-- Job tags -->
                        <?php $topJobs = $topCourse['jobs'] ?? []; if (!empty($topJobs)): ?>
                        <div class="rc-job-tags">
                            <?php foreach ($topJobs as $job): ?>
                            <span class="rc-job-tag" title="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                                <?php echo htmlspecialchars($job['job_title']); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Schools offering top course -->
                        <?php 
                        $inDistrictSchools = $topCourse['in_district_schools'] ?? [];
                        $altSchools = $topCourse['alternative_schools'] ?? [];
                        $hasRegion = !empty($topCourse['has_region']);
                        $selRegion = $topCourse['selected_region'] ?? '';
                        $courseName = $topCourse['course_name'] ?? 'this course';
                        
                        $displaySchools = [];
                        $isAlternative = false;
                        
                        if (!empty($inDistrictSchools)) {
                            $displaySchools = array_slice($inDistrictSchools, 0, 5);
                        } elseif (!empty($altSchools)) {
                            $displaySchools = array_slice($altSchools, 0, 5);
                            $isAlternative = true;
                        }
                        ?>

                        <?php if (!empty($inDistrictSchools) || !$hasRegion): ?>
                            <p class="rc-schools-label">Schools offering this course</p>
                        <?php elseif ($isAlternative): ?>
                            <p style="color:#f87171; font-size:0.85rem; font-style:italic; margin-bottom: 0.5rem; background: rgba(248, 113, 113, 0.1); padding: 0.5rem; border-radius: 6px;">
                                <?php $lbl = function_exists('formatDistrictLabel') ? formatDistrictLabel($selRegion) : htmlspecialchars($selRegion); ?>
                                <i class="fa-solid fa-circle-info"></i> No schools in <?php echo $lbl; ?> offer <?php echo htmlspecialchars($courseName); ?>.
                            </p>
                            <p class="rc-schools-label">Alternative Schools in Pangasinan</p>
                        <?php else: ?>
                            <p class="rc-schools-label">Schools offering this course</p>
                        <?php endif; ?>

                        <?php if (!empty($displaySchools)): ?>
                            <?php foreach ($displaySchools as $school): ?>
                            <div class="rc-school-row">
                                <div class="rc-school-row-left">
                                    <div class="rc-school-icon">
                                        <?php if (!empty($school['logo']) && file_exists($school['logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($school['logo']); ?>" alt="">
                                        <?php else: ?>
                                            <i class="fa-solid fa-school"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="rc-school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                                        <div class="rc-school-location"><?php echo htmlspecialchars($school['city'] ?? ($school['address'] ?? '')); ?><?php if (!empty($school['province'])): ?>, <?php echo htmlspecialchars($school['province']); ?><?php endif; ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($school['website'])): ?>
                                <a href="<?php echo htmlspecialchars($school['website']); ?>" target="_blank" class="rc-view-btn">View</a>
                                <?php else: ?>
                                <span class="rc-view-btn" style="opacity:0.4;cursor:default;">View</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <a href="#schools" onclick="rcSwitchTab('schools')" class="rc-view-all-btn">
                                <i class="fa-solid fa-school"></i>
                                View All Schools Offering This Course
                            </a>
                        <?php else: ?>
                            <p style="color:#64748b; font-size:0.85rem; font-style:italic;">
                                <?php echo nl2br(htmlspecialchars($topCourse['school_recommendations']['message'] ?? 'No schools currently listed for this course.')); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                </div><!-- /.rc-main-grid -->

                <!-- Other Recommended Courses list section -->
                <?php if (!empty($otherCourses)): ?>
                <div class="rc-other-section">
                    <h3 class="rc-other-title">Other Recommended Courses</h3>

                    <?php foreach ($otherCourses as $i => $course):
                        $rowClass = $i >= 2 ? 'rc-other-row hidden-row' : 'rc-other-row';
                        $courseIdAttr = (int)($course['id'] ?? $course['course_id'] ?? 0);
                    ?>
                    <div class="<?php echo $rowClass; ?>"
                         data-expandable="<?php echo $i >= 2 ? 'true' : 'false'; ?>"
                         data-course-id="<?php echo $courseIdAttr; ?>"
                         role="button"
                         tabindex="0"
                         aria-label="View details for <?php echo htmlspecialchars($course['course_name']); ?>">
                        <div class="rc-rank-num"><?php echo (int)$course['rank']; ?></div>
                        <div class="rc-other-info">
                            <div class="rc-other-name"><?php echo htmlspecialchars($course['course_name']); ?></div>
                            <div class="rc-other-desc"><?php echo htmlspecialchars($course['description'] ?? ''); ?></div>
                        </div>
                        <div class="rc-other-score-block">
                            <span class="rc-other-score-val"><?php echo number_format($course['match_percentage'], 1); ?>%</span>
                            <span class="rc-other-score-lbl">Career Fit Score</span>
                            <div class="rc-other-bar">
                                <div class="rc-other-bar-fill" style="width:<?php echo min(100, (float)$course['match_percentage']); ?>%;"></div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right rc-other-arrow"></i>
                    </div>
                    <?php endforeach; ?>

                    <?php if (count($otherCourses) > 2): ?>
                    <button class="rc-show-more-btn" id="showMoreBtn" onclick="toggleMoreCourses()">
                        View More Courses <i class="fa-solid fa-chevron-down" id="showMoreIcon"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

             
            </div><!-- /#tabPanelCourses -->

            <!-- ═══════════════════════════════════════════════
                 TAB 2: RECOMMENDED SCHOOLS
            ════════════════════════════════════════════════ -->
            <div class="rc-tab-panel" id="tabPanelSchools" role="tabpanel">

                <!-- Info cards row (Assessment Status for Schools Tab) -->
                <div class="rc-info-row" style="grid-template-columns: 1fr; margin-bottom:1.5rem;">
                    <div class="rc-info-card">
                        <p class="rc-info-card-title">Assessment Status</p>
                        <?php if ($latestAssessment): ?>
                            <div class="rc-info-card-status-row">
                                <div class="rc-status-dot green"><i class="fa-solid fa-check"></i></div>
                                <span class="rc-attempt-label <?php echo $isViewingOldAttempt ? 'amber' : ''; ?>">
                                    Attempt <?php echo $viewingAttemptNum; ?> of <?php echo $MAX_ASSESSMENTS; ?>
                                    <?php echo $isViewingOldAttempt ? '(Viewing)' : '(Latest)'; ?>
                                </span>
                            </div>
                            <p class="rc-attempt-date">
                                Completed on: <?php
                                    $viewedAssessment = $allAssessments[$viewingAttemptNum - 1] ?? $latestAssessment;
                                    echo date('F j, Y \a\t g:i A', strtotime($viewedAssessment['completed_at']));
                                ?>
                            </p>
                            <p class="rc-attempt-date" style="margin-top:4px;">
                                <?php
                                    $rawDistrict2 = $preferredRegion ?? '';
                                    if (empty($rawDistrict2)) {
                                        $districtDisplay2 = 'Not Selected';
                                    } elseif (strtolower($rawDistrict2) === 'all') {
                                        $districtDisplay2 = 'All Districts';
                                    } else {
                                        $parts2 = array_map('trim', explode(',', $rawDistrict2));
                                        $labels2 = array_map(function($id) { return districtLabel($id); }, $parts2);
                                        $districtDisplay2 = implode(', ', $labels2);
                                    }
                                ?>
                                <span style="color:#94a3b8;">Preferred District(s):</span>
                                <span style="color:#c7d2fe; font-weight:500;"><?php echo htmlspecialchars($districtDisplay2); ?></span>
                            </p>
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                <p class="rc-attempt-note">
                                    <?php echo $isViewingOldAttempt
                                        ? 'You are viewing an older set of school recommendations.'
                                        : 'These school recommendations are based on your latest assessment.'; ?>
                                </p>
                                <?php if ($totalAttempts > 1 && !$isViewingOldAttempt): ?>
                                <a href="recommended_courses.php?attempt_id=<?php echo $allAssessments[0]['id']; ?>#schools" class="rc-compare-btn">
                                    <i class="fa-solid fa-right-left"></i> Compare Attempt 1
                                </a>
                                <?php elseif ($isViewingOldAttempt): ?>
                                <a href="recommended_courses.php#schools" class="rc-compare-btn" style="border-color:rgba(99,102,241,0.4); color:#a5b4fc;">
                                    <i class="fa-solid fa-arrow-right"></i> View Latest
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="rc-attempt-note" style="color:#64748b;">No completed assessments yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filter bar -->
                <div class="rcs-filter-bar">
                    <div class="rcs-search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="rcsSearch" placeholder="Search school name or location...">
                    </div>
                    <select id="rcsCourse" class="rcs-filter-select">
                        <option value="all">All Recommended Courses</option>
                        <?php foreach ($tabCourses as $c): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($c)); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="rcsDistrict" class="rcs-filter-select">
                        <option value="all">All Districts</option>
                        <?php foreach ($tabDistricts as $d): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($d)); ?>"><?php echo htmlspecialchars($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="rcsCountChip"></span>
                </div>

                <!-- Schools grid -->
                <?php if (empty($tabAllSchools)): ?>
                <div class="rcs-empty">
                    <i class="fa-solid fa-school"></i>
                    <p style="color:#64748b;">No schools found for your recommended courses.</p>
                </div>
                <?php else: ?>
                <div class="rcs-grid" id="rcsGrid">
                    <div class="rcs-no-match" id="rcsNoMatch">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <h3>No schools match your search</h3>
                        <p>Try different keywords or clear the filter.</p>
                    </div>
                    <div class="rcs-alt-message" id="rcsAltMessage" style="display:none; grid-column: 1 / -1;">
                        <p id="rcsAltText" style="color:#f87171; font-size:0.95rem; font-style:italic; margin-bottom: 0.5rem; background: rgba(248, 113, 113, 0.1); padding: 0.75rem; border-radius: 6px;">
                            <i class="fa-solid fa-circle-info"></i> No schools in <span id="rcsAltDistrict"></span> offer <span id="rcsAltCourse"></span>.
                        </p>
                        <p class="rcm-section-label" style="margin-top: 1rem;"><i class="fa-solid fa-school" style="margin-right:5px;"></i>Alternative Schools in Pangasinan</p>
                    </div>
                    <?php foreach ($tabAllSchools as $s):
                        $sName     = $s['name'] ?? '';
                        $sCity     = $s['city'] ?? ($s['address'] ?? '');
                        $sDistrict = strtolower($s['district_name'] ?? '');
                        $sInit     = rcInitials($sName);
                        $sBg       = rcAvatarColor($sName);
                        
                        $sCourse   = $s['recommended_course'] ?? '';
                        $sMatch    = (float)($s['course_match_percentage'] ?? 0);
                        
                        $sCoursesArr = $s['courses'] ?? [];
                        $sCoursesJson = htmlspecialchars(json_encode(array_map('strtolower', $sCoursesArr)));
                        $sWebsite  = $s['website'] ?? '';
                    ?>
                    <div class="rcs-card"
                         data-name="<?php echo htmlspecialchars(strtolower($sName)); ?>"
                         data-location="<?php echo htmlspecialchars(strtolower($sCity)); ?>"
                         data-district="<?php echo htmlspecialchars($sDistrict); ?>"
                         data-courses="<?php echo $sCoursesJson; ?>">
                        <div class="rcs-card-header">
                            <div class="rcs-avatar" style="background:<?php echo $sBg; ?>;">
                                <?php if (!empty($s['logo']) && file_exists($s['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($s['logo']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($sInit); ?>
                                <?php endif; ?>
                            </div>
                            <div class="rcs-card-name"><?php echo htmlspecialchars($sName); ?></div>
                        </div>

                        <!-- Location -->
                        <?php 
                        $fullAddress = [];
                        if (!empty($s['address'])) $fullAddress[] = $s['address'];
                        if (!empty($s['city'])) $fullAddress[] = $s['city'];
                        if (!empty($s['province'])) $fullAddress[] = ucwords(strtolower($s['province']));
                        $fullAddressStr = implode(', ', $fullAddress);
                        if (empty($fullAddressStr) && $sCity) {
                            $fullAddressStr = $sCity;
                        }
                        ?>
                        <?php if ($fullAddressStr): ?>
                        <div class="rcs-location" style="display: flex; gap: 0.5rem; color: #94a3b8; font-size: 0.85rem; line-height: 1.4; margin-bottom: 0.5rem; align-items: flex-start;">
                            <i class="fa-solid fa-location-dot" style="margin-top: 0.2rem;"></i>
                            <span><?php echo htmlspecialchars($fullAddressStr); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- School Type -->
                        <?php if (!empty($s['type'])): ?>
                        <div class="rcs-type" style="display: inline-flex; align-items: center; background: rgba(59, 130, 246, 0.1); color: #60a5fa; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.8rem;">
                            <i class="fa-solid fa-building-columns" style="margin-right: 0.35rem;"></i> <?php echo htmlspecialchars($s['type']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="rcs-match-badge">
                            <i class="fa-solid fa-check-circle"></i>
                            <?php echo number_format($sMatch, 1); ?>% Career Fit
                        </div>

                        <?php if ($sCourse): ?>
                        <p class="rcs-course-label">Recommended Course</p>
                        <p class="rcs-course-name"><?php echo htmlspecialchars($sCourse); ?></p>
                        <?php endif; ?>

                        <?php if ($sWebsite): ?>
                        <a href="<?php echo htmlspecialchars($sWebsite); ?>" target="_blank" rel="noopener" class="rcs-view-btn">
                            View School <i class="fa-solid fa-chevron-right"></i>
                        </a>
                        <?php else: ?>
                        <span class="rcs-view-btn" style="opacity:0.35;cursor:default;">
                            View School <i class="fa-solid fa-chevron-right"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div><!-- /#rcsGrid -->
                <?php endif; ?>

            </div><!-- /#tabPanelSchools -->

            <?php
            /* ── Hidden modal payload for TOP course ────────────────── */
            if ($topCourse && $topCourse['breakdown']):
                $tId      = (int)($topCourse['id'] ?? $topCourse['course_id'] ?? 0);
                $tJobs    = $topCourse['jobs']    ?? [];
                $tSchools = $topCourse['schools'] ?? [];
                $tExpl    = trim($topCourse['explanation'] ?? '');
                $tPct     = (float)$topCourse['match_percentage'];
                $tDesc    = $topCourse['description'] ?? '';
                $tClusterPct = round((float)($topCourse['breakdown']['cluster_alignment'] ?? 0), 1);
                $tSkillsPct  = round((float)($topCourse['breakdown']['skills_part'] ?? 0), 1);
                $tCareerPct  = round((float)($topCourse['breakdown']['career_part'] ?? 0), 1);
                $tPersonPct  = round((float)($topCourse['breakdown']['personality_part'] ?? 0), 1);
                $tStrandPct  = round((float)($topCourse['breakdown']['strand_part'] ?? 0), 1);
            ?>
            <div class="rc-modal-payload" data-course-id="top" style="display:none;" aria-hidden="true">

                <div class="rcm-header">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <span class="rc-rank-badge"><i class="fa-solid fa-trophy"></i> Top Recommendation</span>
                        </div>
                        <h3 style="font-size:1.3rem;font-weight:700;color:#f8fafc;margin:0 0 4px;"><?php echo htmlspecialchars($topCourse['course_name']); ?></h3>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:0.75rem;color:#94a3b8;">Career Fit Score</span>
                            <span style="font-size:1.5rem;font-weight:700;color:#f59e0b;line-height:1;"><?php echo number_format($tPct, 1); ?>%</span>
                        </div>
                    </div>
                    <button class="rcm-close" id="rcmClose" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="rcm-body">

                    <!-- Progress bar -->
                    <div class="rc-progress-bar" style="margin-bottom:1rem;">
                        <div class="rc-progress-fill" style="width:<?php echo min(100,$tPct); ?>%;"></div>
                    </div>

                    <!-- Score breakdown bars -->
                    <div class="rcm-breakdown-block">
                        <p class="rcm-breakdown-title">
                            <i class="fa-solid fa-chart-bar"></i> Score Breakdown
                        </p>

                        <div class="rcm-bar-row">
                            <div class="rcm-bar-meta">
                                <span class="rcm-bar-label">Cluster Alignment</span>
                                <span class="rcm-bar-pct"><?php echo $tClusterPct; ?>%</span>
                            </div>
                            <div class="rcm-bar-track">
                                <div class="rcm-bar-fill cluster" style="width:<?php echo min(100,$tClusterPct); ?>%;"></div>
                            </div>
                        </div>

                        <div class="rcm-bar-row">
                            <div class="rcm-bar-meta">
                                <span class="rcm-bar-label">Competency Match</span>
                                <span class="rcm-bar-pct"><?php echo $tSkillsPct; ?>%</span>
                            </div>
                            <div class="rcm-bar-track">
                                <div class="rcm-bar-fill" style="width:<?php echo min(100,$tSkillsPct); ?>%; background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                            </div>
                        </div>

                        <div class="rcm-bar-row">
                            <div class="rcm-bar-meta">
                                <span class="rcm-bar-label">Career Fit</span>
                                <span class="rcm-bar-pct"><?php echo $tCareerPct; ?>%</span>
                            </div>
                            <div class="rcm-bar-track">
                                <div class="rcm-bar-fill" style="width:<?php echo min(100,$tCareerPct); ?>%; background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                            </div>
                        </div>

                        <div class="rcm-bar-row">
                            <div class="rcm-bar-meta">
                                <span class="rcm-bar-label">Personality Match</span>
                                <span class="rcm-bar-pct"><?php echo $tPersonPct; ?>%</span>
                            </div>
                            <div class="rcm-bar-track">
                                <div class="rcm-bar-fill" style="width:<?php echo min(100,$tPersonPct); ?>%; background:linear-gradient(90deg,#10b981,#34d399);"></div>
                            </div>
                        </div>

                        <div class="rcm-bar-row">
                            <div class="rcm-bar-meta">
                                <span class="rcm-bar-label">Strand Alignment</span>
                                <span class="rcm-bar-pct"><?php echo $tStrandPct; ?>%</span>
                            </div>
                            <div class="rcm-bar-track">
                                <div class="rcm-bar-fill" style="width:<?php echo min(100,$tStrandPct); ?>%; background:linear-gradient(90deg,#8b5cf6,#a78bfa);"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Why this score? -->
                    <?php if (true): ?>
                    <button class="rcm-why-btn" onclick="rcmToggleWhy(this)">
                        <i class="fa-solid fa-circle-question"></i>
                        Why this score?
                        <i class="fa-solid fa-chevron-down rcm-chevron"></i>
                    </button>
                    <div class="rcm-why-content">
                        <?php echo generateScoreExplanation($tExpl, $tSkillsPct, $tCareerPct, $tPersonPct, $tStrandPct, $tPct); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Description -->
                    <?php if ($tDesc): ?>
                    <p class="rc-course-desc"><?php echo htmlspecialchars($tDesc); ?></p>
                    <?php endif; ?>

                    <!-- Possible careers -->
                    <?php if (!empty($tJobs)): ?>
                    <hr class="rcm-divider">
                    <p class="rcm-section-label"><i class="fa-solid fa-briefcase" style="margin-right:5px;"></i>Possible Careers</p>
                    <div class="rc-job-tags" style="margin-bottom:0;">
                        <?php foreach ($tJobs as $job): ?>
                        <span class="rc-job-tag" title="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                            <?php echo htmlspecialchars($job['job_title']); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Schools -->
                    <?php if (!empty($tSchools)): ?>
                    <hr class="rcm-divider">
                    <p class="rcm-section-label"><i class="fa-solid fa-school" style="margin-right:5px;"></i>Schools offering this course</p>
                    <?php foreach ($tSchools as $school): ?>
                    <div class="rc-school-row">
                        <div class="rc-school-row-left">
                            <div class="rc-school-icon">
                                <?php if (!empty($school['logo']) && file_exists($school['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($school['logo']); ?>" alt="">
                                <?php else: ?>
                                    <i class="fa-solid fa-school"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="rc-school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                                <div class="rc-school-location">
                                    <?php echo htmlspecialchars($school['city'] ?? ($school['address'] ?? '')); ?>
                                    <?php if (!empty($school['province'])): ?>, <?php echo htmlspecialchars($school['province']); ?><?php endif; ?>
                                </div>
                                <div class="rc-school-meta" style="display:flex; gap: 6px; margin-top: 5px;">
                                    <?php if (!empty($school['type'])): ?>
                                    <span style="font-size:0.65rem; background:rgba(59,130,246,0.1); color:#60a5fa; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-building-columns" style="margin-right:2px;"></i> <?php echo htmlspecialchars($school['type']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($school['district_name'])): ?>
                                    <span style="font-size:0.65rem; background:rgba(168,85,247,0.1); color:#c084fc; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-map-location-dot" style="margin-right:2px;"></i> <?php echo htmlspecialchars($school['district_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($school['website'])): ?>
                        <a href="<?php echo htmlspecialchars($school['website']); ?>" target="_blank" rel="noopener" class="rc-view-btn">View</a>
                        <?php else: ?>
                        <span class="rc-view-btn" style="opacity:0.4;cursor:default;">View</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <a href="#schools" onclick="rcSwitchTab('schools')" class="rc-view-all-btn" style="margin-top:0.75rem;">
                        <i class="fa-solid fa-school"></i>
                        View All Schools Offering This Course
                    </a>
                    <?php endif; ?>

                </div><!-- /.rcm-body -->
            </div><!-- /.rc-modal-payload top -->
            <?php endif; ?>



            <?php
            /* ── Hidden modal payloads (one per other course, pre-rendered) ─ */
            foreach ($otherCourses as $i => $course):
                $cRank = (int)($course['rank'] ?? ($i + 2));
                $isCounselorView = false;
                require 'includes/course_modal_template.php';
            endforeach; 
            ?>

            <!-- ── Course detail modal shell ──────────────── -->
            <dialog id="rcCourseModal" aria-modal="true" aria-labelledby="rcmTitle">
                <div class="rcm-panel" id="rcmPanel" role="document">
                    <div id="rcmContent"><!-- payload injected by JS --></div>
                </div>
            </dialog>

            <div class="rc-footer-note">
                <i class="fa-solid fa-circle-info"></i>
                <span>Scores represent how well each course matches your interests and assessment results.</span>
            </div>

            <?php endif; // end recommendations ?>
        </div>
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<script src="script.js"></script>
<script>
/* ── Show-more toggle ────────────────────────────────────────── */
function toggleMoreCourses() {
    const hidden = document.querySelectorAll('[data-expandable="true"]');
    const btn = document.getElementById('showMoreBtn');
    const isExpanded = btn.dataset.expanded === 'true';

    hidden.forEach(row => {
        row.style.display = isExpanded ? 'none' : 'flex';
    });

    if (isExpanded) {
        btn.innerHTML = 'View More Courses <i class="fa-solid fa-chevron-down" id="showMoreIcon"></i>';
        btn.dataset.expanded = 'false';
    } else {
        btn.innerHTML = 'View Less <i class="fa-solid fa-chevron-up" id="showMoreIcon"></i>';
        btn.dataset.expanded = 'true';
    }
}

/* ── Course detail modal ─────────────────────────────────────── */
(function () {
    const modal   = document.getElementById('rcCourseModal');
    const content = document.getElementById('rcmContent');
    if (!modal || !content) return;

    /* Open modal for any element with data-course-id:
       - .rc-other-row  (other courses list)
       - #topBreakdownBtn (top recommendation card button) */
    function openModalForId(id) {
        const payload = document.querySelector('.rc-modal-payload[data-course-id="' + id + '"]');
        if (!payload) return;

        /* Clone payload HTML into modal content area */
        content.innerHTML = payload.innerHTML;

        /* Re-wire close button rendered inside the cloned content */
        const closeBtn = content.querySelector('#rcmClose');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);

        // (The rcm-why-btn already has an inline onclick="rcmToggleWhy(this)" which is cloned, so we don't need to add another event listener here)

        modal.showModal();
        document.body.style.overflow = 'hidden';
    }

    /* Wire up other-course rows (click + keyboard) */
    document.querySelectorAll('.rc-other-row').forEach(row => {
        row.addEventListener('click', () => openModalForId(row.dataset.courseId));
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModalForId(row.dataset.courseId); }
        });
    });

    /* Wire up top-card "View Score Breakdown" button */
    const topBtn = document.getElementById('topBreakdownBtn');
    if (topBtn) {
        topBtn.addEventListener('click', () => openModalForId(topBtn.dataset.courseId));
    }

    /* Close: click on backdrop (the <dialog> element itself, outside the panel) */
    modal.addEventListener('click', function (e) {
        const panel = document.getElementById('rcmPanel');
        if (panel && !panel.contains(e.target)) closeModal();
    });

    /* Close: Escape key — <dialog> handles it natively but we still need to restore scroll */
    modal.addEventListener('cancel', function (e) {
        e.preventDefault(); // prevent default so we control the close
        closeModal();
    });

    function closeModal() {
        modal.close();
        document.body.style.overflow = '';
    }

    /* Expose globally for inline onclick fallback */
    window.closeModal = closeModal;
})();

/* ── Why-score toggle (also called from inline onclick in cloned HTML) ── */
function rcmToggleWhy(btn) {
    const whyBox = btn.nextElementSibling;
    if (!whyBox || !whyBox.classList.contains('rcm-why-content')) return;
    const isOpen = whyBox.classList.toggle('visible');
    btn.classList.toggle('open', isOpen);
    btn.querySelector('.rcm-chevron') && (btn.querySelector('.rcm-chevron').style.transform = isOpen ? 'rotate(180deg)' : '');
}

/* ── Tab switching ──────────────────────────────────────────── */
function rcSwitchTab(tab) {
    const panels = { courses: 'tabPanelCourses', schools: 'tabPanelSchools' };
    const btns   = { courses: 'tabBtnCourses',  schools: 'tabBtnSchools'  };
    Object.keys(panels).forEach(k => {
        const panel = document.getElementById(panels[k]);
        const btn   = document.getElementById(btns[k]);
        if (!panel || !btn) return;
        const active = (k === tab);
        panel.classList.toggle('active', active);
        btn.classList.toggle('active',  active);
        btn.setAttribute('aria-selected', active);
    });
    history.replaceState(null, '', '#' + tab);
    if (tab === 'schools' && window.rcsUpdateCount) window.rcsUpdateCount();
}

/* Restore tab from URL hash on load */
(function() {
    const hash = location.hash.replace('#', '');
    if (hash === 'schools') rcSwitchTab('schools');
})();

/* ── Schools tab: live search + district + course filter ──────────────── */
(function() {
    const searchEl   = document.getElementById('rcsSearch');
    const districtEl = document.getElementById('rcsDistrict');
    const courseEl   = document.getElementById('rcsCourse');
    const grid       = document.getElementById('rcsGrid');
    const noMatch    = document.getElementById('rcsNoMatch');
    const altMsg     = document.getElementById('rcsAltMessage');
    const altDistrict = document.getElementById('rcsAltDistrict');
    const altCourse  = document.getElementById('rcsAltCourse');
    
    if (!searchEl || !grid) return;

    function rcsFilter() {
        const q  = searchEl.value.toLowerCase().trim();
        const d  = districtEl ? districtEl.value : 'all';
        const c  = courseEl ? courseEl.value : 'all';
        
        let inDistrictMatches = 0;
        let cards = Array.from(grid.querySelectorAll('.rcs-card'));
        
        // First pass: Count how many schools match BOTH course and district
        cards.forEach(card => {
            const district = card.dataset.district || '';
            const courses = JSON.parse(card.dataset.courses || '[]');
            
            const matchD = (d === 'all') || district.includes(d);
            const matchC = (c === 'all') || courses.includes(c);
            
            if (matchD && matchC) {
                inDistrictMatches++;
            }
        });

        // Determine if we are in "Alternative Mode"
        // (Alternative Mode: A specific district and course are selected, but 0 schools match BOTH)
        const isAlternativeMode = (d !== 'all' && c !== 'all' && inDistrictMatches === 0);
        
        if (isAlternativeMode && altMsg) {
            altDistrict.textContent = districtEl.options[districtEl.selectedIndex].text;
            altCourse.textContent = courseEl.options[courseEl.selectedIndex].text;
            altMsg.style.display = 'block';
        } else if (altMsg) {
            altMsg.style.display = 'none';
        }

        let visible = 0;
        
        // Second pass: Actually show/hide cards
        cards.forEach(card => {
            const name     = card.dataset.name     || '';
            const location = card.dataset.location || '';
            const district = card.dataset.district || '';
            const courses  = JSON.parse(card.dataset.courses || '[]');
            
            const matchQ = !q || name.includes(q) || location.includes(q);
            const matchC = (c === 'all') || courses.includes(c);
            let matchD = (d === 'all') || district.includes(d);
            
            if (isAlternativeMode) {
                // In alternative mode, show schools that match the course and query, 
                // BUT EXCLUDE schools from the selected district (since it had 0 anyway, but to be strict).
                // Actually, if inDistrictMatches was 0, it means no schools in the district match the course, 
                // so we just show any school that matches the course. But to satisfy "exclude schools belonging to selected district",
                // we strictly check:
                matchD = !district.includes(d);
            }
            
            const show = matchQ && matchC && matchD;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        
        if (noMatch) noMatch.style.display = (visible === 0 && !isAlternativeMode) ? 'block' : 'none';
        rcsUpdateCount(visible);
    }

    searchEl.addEventListener('input', rcsFilter);
    if (districtEl) districtEl.addEventListener('change', rcsFilter);
    if (courseEl) courseEl.addEventListener('change', rcsFilter);

    function countVisible() {
        return grid.querySelectorAll('.rcs-card:not([style*="none"])').length;
    }
    window.rcsUpdateCount = function(n) {
        const chip = document.getElementById('rcsCountChip');
        if (!chip) return;
        const count = (n !== undefined) ? n : countVisible();
        chip.textContent = count + ' school' + (count !== 1 ? 's' : '');
    };
    rcsUpdateCount();
})();
</script>

<!-- Chatbot FAB & Window -->
<div id="aiChatbotContainer" class="ai-chatbot-container">
    <button id="aiChatbotFab" class="ai-chatbot-fab" aria-label="Open AI Assistant" title="Chat with Nando AI">
        <i class="fa-solid fa-robot"></i>
        <span class="ai-fab-badge"></span>
    </button>
    <div id="aiChatbotWindow" class="ai-chatbot-window">
        <div class="ai-chatbot-header">
            <div class="ai-chatbot-title-box">
                <div class="ai-chatbot-avatar">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div class="ai-chatbot-info">
                    <span class="ai-chatbot-name">Nando AI</span>
                    <span class="ai-chatbot-subtitle">
                        <span class="ai-chatbot-status-dot"></span> Online &bull; Career Guide
                    </span>
                </div>
            </div>
            <div class="ai-chatbot-controls">
                <button id="aiChatbotRefresh" class="ai-chatbot-action" title="Restart chat" aria-label="Restart chat"><i class="fa-solid fa-arrows-rotate"></i></button>
                <button id="aiChatbotResize" class="ai-chatbot-action" title="Expand window" aria-label="Expand window"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                <button id="aiChatbotClose" class="ai-chatbot-action danger" title="Close chat" aria-label="Close chat"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div id="aiChatbotMessages" class="ai-chatbot-messages">
        </div>
        <div class="ai-chatbot-input-area">
            <div class="ai-chatbot-input-wrap">
                <textarea id="aiChatbotInput" placeholder="Ask Nando AI a question..." rows="1"></textarea>
            </div>
            <button id="aiChatbotSend" class="ai-chatbot-send" title="Send message"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
        
        <!-- Confirmation Modal -->
        <div id="aiChatbotConfirmModal" class="ai-chatbot-confirm-modal">
            <div class="ai-chatbot-confirm-content">
                <div class="ai-chatbot-confirm-icon"><i class="fa-solid fa-arrows-rotate"></i></div>
                <h3>Start a new chat?</h3>
                <p>This will clear your current conversation with Nando AI, including anything you've shared. You can't undo this.</p>
                <div class="ai-chatbot-confirm-actions">
                    <button id="aiChatbotConfirmCancel" class="ai-chatbot-btn-cancel">Keep chatting</button>
                    <button id="aiChatbotConfirmRestart" class="ai-chatbot-btn-restart">Start new chat</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ── AI Chatbot Logic ───────────────────────────────────────── */
(function(){
    const fab = document.getElementById('aiChatbotFab');
    const win = document.getElementById('aiChatbotWindow');
    const closeBtn = document.getElementById('aiChatbotClose');
    const resizeBtn = document.getElementById('aiChatbotResize');
    const refreshBtn = document.getElementById('aiChatbotRefresh');
    const sendBtn = document.getElementById('aiChatbotSend');
    const input = document.getElementById('aiChatbotInput');
    const messages = document.getElementById('aiChatbotMessages');
    
    const storageKey = 'careerpath_chat_history_<?php echo $student['id'] ?? 'guest'; ?>';
    const resizeStorageKey = 'careerpath_chat_expanded';
    let history = []; // stores {role, content}

    // Restore expanded size preference
    if (localStorage.getItem(resizeStorageKey) === 'true') {
        win.classList.add('expanded');
        if (resizeBtn) {
            resizeBtn.innerHTML = '<i class="fa-solid fa-down-left-and-up-right-to-center"></i>';
            resizeBtn.title = 'Contract window';
        }
    }

    if (resizeBtn) {
        resizeBtn.addEventListener('click', () => {
            const isExpanded = win.classList.toggle('expanded');
            localStorage.setItem(resizeStorageKey, isExpanded ? 'true' : 'false');
            resizeBtn.innerHTML = isExpanded 
                ? '<i class="fa-solid fa-down-left-and-up-right-to-center"></i>' 
                : '<i class="fa-solid fa-up-right-and-down-left-from-center"></i>';
            resizeBtn.title = isExpanded ? 'Contract window' : 'Expand window';
            messages.scrollTop = messages.scrollHeight;
        });
    }

    // 1. Load history from localStorage on initialization
    try {
        const stored = localStorage.getItem(storageKey);
        if (stored) {
            const parsed = JSON.parse(stored);
            const ONE_DAY = 24 * 60 * 60 * 1000;
            
            if (parsed && parsed.timestamp && parsed.data) {
                if (Date.now() - parsed.timestamp > ONE_DAY) {
                    localStorage.removeItem(storageKey);
                } else {
                    history = parsed.data;
                }
            } else if (Array.isArray(parsed)) {
                history = parsed;
            }

            if (history.length > 0) {
                messages.innerHTML = '';
                history.forEach(msg => {
                    const role = msg.role === 'model' ? 'bot' : 'user';
                    appendMessage(role, msg.content);
                });
            } else {
                renderWelcome();
            }
        } else {
            renderWelcome();
        }
    } catch (e) {
        console.error('Failed to parse chatbot history:', e);
        renderWelcome();
    }

    function renderWelcome() {
        messages.innerHTML = `
            <div id="aiChatbotWelcome" class="ai-chatbot-welcome">
                <div class="ai-welcome-avatar">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <h4 class="ai-welcome-title">Hi there! I'm Nando AI 👋</h4>
                <p class="ai-welcome-desc">Your personal academic and career advisor. Ask me anything about your recommended courses, future careers, universities, or tips to reach your goals!</p>
                <div class="ai-welcome-chips">
                    <button type="button" class="ai-chip" data-prompt="Why is my top recommendation a great fit for me?">
                        <i class="fa-solid fa-sparkles"></i> Why my top recommendation?
                    </button>
                    <button type="button" class="ai-chip" data-prompt="What career opportunities can I pursue after graduation?">
                        <i class="fa-solid fa-briefcase"></i> Potential career paths
                    </button>
                    <button type="button" class="ai-chip" data-prompt="Which universities or colleges offer my recommended courses?">
                        <i class="fa-solid fa-school"></i> Partner universities
                    </button>
                </div>
            </div>
        `;
        bindChips();
    }

    function bindChips() {
        messages.querySelectorAll('.ai-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const promptText = chip.getAttribute('data-prompt');
                if (promptText) {
                    input.value = promptText;
                    adjustTextareaHeight();
                    sendMessage();
                }
            });
        });
    }

    function saveHistory() {
        try {
            const dataToSave = {
                timestamp: Date.now(),
                data: history
            };
            localStorage.setItem(storageKey, JSON.stringify(dataToSave));
        } catch (e) {
            console.error('Failed to save chatbot history:', e);
        }
    }

    if(!fab || !win) return;

    fab.addEventListener('click', () => {
        const isActive = win.classList.toggle('active');
        if (isActive) {
            messages.scrollTop = messages.scrollHeight;
            setTimeout(() => {
                input.focus();
                if (window.innerWidth <= 640 && window.visualViewport) {
                    win.style.height = `${window.visualViewport.height}px`;
                    win.style.top = `${window.visualViewport.offsetTop}px`;
                }
            }, 100);
        } else {
            win.style.height = '';
            win.style.top = '';
        }
    });

    closeBtn.addEventListener('click', () => {
        win.classList.remove('active');
        win.style.height = '';
        win.style.top = '';
    });

    // Mobile Virtual Keyboard Handling via visualViewport
    if (window.visualViewport) {
        const handleVisualViewport = () => {
            if (window.innerWidth <= 640 && win.classList.contains('active')) {
                win.style.height = `${window.visualViewport.height}px`;
                win.style.top = `${window.visualViewport.offsetTop}px`;
                messages.scrollTop = messages.scrollHeight;
            } else if (window.innerWidth > 640) {
                win.style.height = '';
                win.style.top = '';
            }
        };

        window.visualViewport.addEventListener('resize', handleVisualViewport);
        window.visualViewport.addEventListener('scroll', handleVisualViewport);
    }

    input.addEventListener('focus', () => {
        if (window.innerWidth <= 640) {
            setTimeout(() => {
                if (window.visualViewport) {
                    win.style.height = `${window.visualViewport.height}px`;
                    win.style.top = `${window.visualViewport.offsetTop}px`;
                }
                messages.scrollTop = messages.scrollHeight;
                input.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }, 150);
        }
    });
    
    const confirmModal = document.getElementById('aiChatbotConfirmModal');
    const confirmCancel = document.getElementById('aiChatbotConfirmCancel');
    const confirmRestart = document.getElementById('aiChatbotConfirmRestart');
    
    // 2. New Chat / Refresh
    refreshBtn.addEventListener('click', () => {
        confirmModal.classList.add('active');
    });

    confirmCancel.addEventListener('click', () => {
        confirmModal.classList.remove('active');
    });

    confirmRestart.addEventListener('click', () => {
        history = [];
        localStorage.removeItem(storageKey);
        messages.innerHTML = '';
        renderWelcome();
        confirmModal.classList.remove('active');
    });

    function formatMessageText(text) {
        if (!text) return '';
        // 1. Escape HTML
        let escaped = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        
        // 2. Bold formatting: **bold** or __bold__
        escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        escaped = escaped.replace(/__(.+?)__/g, '<strong>$1</strong>');
        
        // 3. Italic formatting: *italic* or _italic_
        escaped = escaped.replace(/\*([^\*\n]+?)\*/g, '<em>$1</em>');
        escaped = escaped.replace(/_([^_\n]+?)_/g, '<em>$1</em>');

        // 4. Line by line parsing for bullet points
        const lines = escaped.split('\n');
        const formatted = [];

        for (let i = 0; i < lines.length; i++) {
            const rawLine = lines[i];
            const trimmed = rawLine.trim();

            if (trimmed.startsWith('- ') || trimmed.startsWith('• ') || trimmed.startsWith('* ')) {
                const itemContent = trimmed.replace(/^[-•*]\s+/, '');
                formatted.push(`<div class="ai-msg-list-item"><span class="ai-msg-bullet">•</span><span>${itemContent}</span></div>`);
            } else {
                formatted.push(rawLine);
            }
        }

        return formatted.join('<br>').replace(/(<br>\s*){3,}/g, '<br><br>');
    }

    function appendMessage(role, text, isError = false) {
        const welcome = document.getElementById('aiChatbotWelcome');
        if (welcome) welcome.remove();

        const div = document.createElement('div');
        div.className = 'ai-msg ' + (role === 'user' ? 'ai-msg-user' : 'ai-msg-bot');
        if (isError) div.classList.add('ai-msg-error');
        
        div.innerHTML = formatMessageText(text);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    function appendTyping() {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-msg-bot ai-typing-indicator';
        div.innerHTML = '<div class="ai-typing"><div class="ai-typing-dot"></div><div class="ai-typing-dot"></div><div class="ai-typing-dot"></div></div>';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    async function sendMessage() {
        const text = input.value.trim();
        if (!text) return;
        
        // Append UI and store locally immediately
        appendMessage('user', text);
        history.push({ role: 'user', content: text });
        saveHistory();

        input.value = '';
        input.style.height = '44px';
        input.style.overflowY = 'hidden';
        sendBtn.disabled = true;
        
        // 3. Offline handling
        if (!navigator.onLine) {
            appendMessage('bot', "You're currently offline. Please check your internet connection.", true);
            sendBtn.disabled = false;
            input.focus();
            return;
        }

        const typingEl = appendTyping();

        try {
            // Send history excluding the message we just pushed (API handles the current message natively)
            const apiHistory = history.slice(0, -1);
            
            const res = await fetch('api/nando_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, history: apiHistory })
            });

            let data = null;
            const textResponse = await res.text();
            try {
                data = JSON.parse(textResponse);
            } catch (jsonErr) {
                console.error("Chatbot API response parse error:", jsonErr, "Raw server response:", textResponse);
                typingEl.remove();
                appendMessage('bot', 'The server returned an unexpected response. Please check server logs or verify your hosting settings.', true);
                sendBtn.disabled = false;
                input.focus();
                return;
            }
            
            typingEl.remove();
            
            if (data && data.error) {
                appendMessage('bot', data.error, true);
            } else if (data && data.reply) {
                appendMessage('bot', data.reply);
                history.push({ role: 'model', content: data.reply });
                saveHistory();
            } else {
                appendMessage('bot', 'Unable to get an answer from the assistant. Please try again.', true);
            }
        } catch (e) {
            typingEl.remove();
            console.error("Chatbot Fetch Exception:", e);
            appendMessage('bot', 'The AI took too long to respond or the server encountered an error. Please try again.', true);
        }
        sendBtn.disabled = false;
        input.focus();
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    function adjustTextareaHeight() {
        input.style.height = 'auto';
        const baseHeight = 44;
        const maxHeight = window.innerWidth <= 640 ? 95 : 115;
        const targetHeight = Math.min(Math.max(input.scrollHeight, baseHeight), maxHeight);
        input.style.height = targetHeight + 'px';
        input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
    }

    input.addEventListener('input', adjustTextareaHeight);
    adjustTextareaHeight();
    
    // 4. Logout interception to clear storage
    document.querySelectorAll('a[href*="logout.php"], .logout').forEach(link => {
        link.addEventListener('click', () => {
            localStorage.removeItem(storageKey);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
