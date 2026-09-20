<?php
// Admin Assessment Results Page - Backend Added

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration
require_once 'config.php';
require_once 'system_config.php';

require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('view_assessment_results');

// Fetch school years for filter
$schoolYears = [];
$activeYearId = null;
$syRes = $mysqli->query("SELECT id, year_label, is_current FROM school_years ORDER BY year_label DESC");
if ($syRes) {
    while ($r = $syRes->fetch_assoc()) {
        $schoolYears[] = $r;
        if ($r['is_current'] == 1) {
            $activeYearId = (int)$r['id'];
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'get_assessment_details':
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            $isArchived = (isset($_POST['is_archived']) && $_POST['is_archived'] == '1');
            if ($assessmentId <= 0) {
                $response['message'] = 'Invalid assessment ID';
                echo json_encode($response);
                exit;
            }
            
            if ($isArchived) {
                $stmt = $mysqli->prepare("SELECT snapshot_json FROM archived_assessments WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $assessmentId);
                $stmt->execute();
                $res = $stmt->get_result();
                $arch = $res->fetch_assoc();
                $stmt->close();
                if ($arch) {
                    $snap = json_decode($arch['snapshot_json'], true);
                    $response['success'] = true;
                    // The snapshot already has 'assessment', 'category_scores', 'recommendations'
                    $response['assessment'] = $snap['assessment'] ?? [];
                    $response['category_scores'] = $snap['category_scores'] ?? [];
                    $response['recommendations'] = $snap['recommendations'] ?? [];
                    
                    // Fetch course metadata for recommendations
                    $courseIds = array_column($response['recommendations'], 'course_id');
                    if (!empty($courseIds)) {
                        $idsStr = implode(',', array_map('intval', $courseIds));
                        $cRes = $mysqli->query("
                            SELECT c.id, c.course_name, c.description AS course_desc, cl.name as cluster_name, cl.color as cluster_color, cl.icon as cluster_icon 
                            FROM courses c LEFT JOIN clusters cl ON c.cluster_id = cl.id 
                            WHERE c.id IN ($idsStr)
                        ");
                        $courseData = [];
                        if ($cRes) {
                            while ($cr = $cRes->fetch_assoc()) {
                                $courseData[$cr['id']] = $cr;
                            }
                        }
                        foreach ($response['recommendations'] as &$rec) {
                            $cid = $rec['course_id'];
                            if (isset($courseData[$cid])) {
                                $rec = array_merge($rec, $courseData[$cid]);
                            }
                            // Add explanation logic if absent
                            if (!isset($rec['explanation_html']) || empty($rec['explanation_html'])) {
                                $rec['explanation_html'] = '<div class="score-explanation"><p><strong>Archived Result:</strong> This recommendation was preserved from a previous school year.</p></div>';
                            }
                        }
                    }
                } else {
                    $response['message'] = 'Archived assessment not found';
                }
                echo json_encode($response);
                exit;
            }
            
            // Get assessment details with student info (including grade level and strand)
            $stmt = $mysqli->prepare("
                SELECT sa.*, s.first_name, s.middle_name, s.last_name, s.suffix, s.student_id, s.grade_level, s.email as student_email,
                       st.name as strand_name, st.code as strand_code,
                       (SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.grade_level = 'Grade 11' LIMIT 1) AS grade11_id,
                       COALESCE((SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.school_year_id = sa.school_year_id LIMIT 1), s.student_id) AS historical_id
                FROM student_assessments sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                WHERE sa.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $assessment = $result->fetch_assoc();
            $stmt->close();
            
            if ($assessment) {
                // Get category scores
                $scoreStmt = $mysqli->prepare("
                    SELECT category, score, percentage
                    FROM category_scores
                    WHERE assessment_id = ?
                    ORDER BY category
                ");
                $scoreStmt->bind_param('i', $assessmentId);
                $scoreStmt->execute();
                $categoryScores = $scoreStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $scoreStmt->close();

                // Get recommendations with full course & cluster metadata
                $recStmt = $mysqli->prepare("
                    SELECT r.rank, r.match_percentage, r.explanation, c.id as course_id, c.course_name, c.description as course_desc,
                           cl.name as cluster_name, cl.color as cluster_color, cl.icon as cluster_icon
                    FROM recommendations r
                    LEFT JOIN courses c ON r.course_id = c.id
                    LEFT JOIN clusters cl ON c.cluster_id = cl.id
                    WHERE r.assessment_id = ?
                    ORDER BY r.rank ASC, r.match_percentage DESC
                ");
                $recStmt->bind_param('i', $assessmentId);
                $recStmt->execute();
                $recommendations = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $recStmt->close();
                
                require_once 'includes/recommendation_scoring.php';
                $scoredCourses = calculateCourseScores($mysqli, $assessmentId);
                
                foreach ($recommendations as &$rec) {
                    $rec['explanation_html'] = '';
                    foreach ($scoredCourses as $sc) {
                        if ($sc['course_id'] == $rec['course_id']) {
                            $rec['match_percentage'] = $sc['match_percentage'];
                            $bd = $sc['breakdown'];
                            $rec['explanation_html'] = generateScoreExplanation(
                                $rec['explanation'] ?? '',
                                $bd['skills_part'],
                                $bd['career_part'],
                                $bd['personality_part'],
                                $bd['strand_part'],
                                $sc['match_percentage']
                            );
                            break;
                        }
                    }
                }
                unset($rec);
                
                $response['success'] = true;
                $response['assessment'] = $assessment;
                $response['category_scores'] = $categoryScores;
                $response['recommendations'] = $recommendations;
            } else {
                $response['message'] = 'Assessment not found';
            }
            echo json_encode($response);
            exit;

        case 'search_results':
            $query        = trim($_POST['query'] ?? '');
            $statusFilter = trim($_POST['status_filter'] ?? 'active');
            $reqYearId    = (int)($_POST['school_year_id'] ?? $activeYearId);
            $assmntStatus = trim($_POST['assessment_status'] ?? 'all');
            $rows         = [];
            $searchLike   = '%' . $query . '%';
            
            if ($reqYearId !== $activeYearId) {
                // If they specifically ask for abandoned, we just return empty array immediately
                // Because archived records are ALWAYS completed.
                if ($assmntStatus === 'abandoned') {
                    echo json_encode(['success' => true, 'rows' => []]);
                    exit;
                }

                // ARCHIVED DATA QUERY
                $aStmt = $mysqli->prepare("
                    SELECT 
                        aa.id AS archived_id,
                        aa.snapshot_json,
                        aa.archived_at,
                        s.status AS student_status,
                        TRIM(CONCAT(
                            s.first_name,
                            IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                            ' ',
                            s.last_name,
                            IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                        )) AS student_name,
                        s.student_id AS lrn,
                        st.name AS strand_name,
                        st.code AS strand_code
                    FROM archived_assessments aa
                    LEFT JOIN students s ON aa.student_id = s.id
                    LEFT JOIN strands st ON s.strand_id = st.id
                    WHERE aa.school_year_id = ?
                      AND (CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                           OR CONCAT(s.first_name, ' ', s.middle_name, ' ', s.last_name) LIKE ?
                           OR s.student_id LIKE ?)
                    ORDER BY aa.archived_at DESC
                    LIMIT 100
                ");
                $aStmt->bind_param('isss', $reqYearId, $searchLike, $searchLike, $searchLike);
                $aStmt->execute();
                $aResult = $aStmt->get_result();
                
                // Pre-fetch all courses to attach top recommendations
                $cRes = $mysqli->query("SELECT c.id, c.course_name, cl.name as cluster_name FROM courses c LEFT JOIN clusters cl ON c.cluster_id = cl.id");
                $courseDict = [];
                if ($cRes) {
                    while ($c = $cRes->fetch_assoc()) {
                        $courseDict[$c['id']] = $c;
                    }
                }
                
                while ($row = $aResult->fetch_assoc()) {
                    $snap = json_decode($row['snapshot_json'], true);
                    $assData = $snap['assessment'] ?? [];
                    $recs = $snap['recommendations'] ?? [];
                    
                    $topCategory = '—';
                    $topCourse = '—';
                    if (!empty($recs)) {
                        usort($recs, function($a, $b) {
                            if ($a['rank'] != $b['rank']) return $a['rank'] - $b['rank'];
                            return $b['match_percentage'] <=> $a['match_percentage'];
                        });
                        $topRec = $recs[0];
                        $cid = $topRec['course_id'] ?? 0;
                        if (isset($courseDict[$cid])) {
                            $topCourse = $courseDict[$cid]['course_name'];
                            $topCategory = $courseDict[$cid]['cluster_name'];
                        }
                    }
                    
                    $score = isset($assData['total_score']) ? round((float)$assData['total_score'], 1) : null;
                    
                    $rows[] = [
                        'id'             => $row['archived_id'],
                        'is_archived'    => true,
                        'student_name'   => $row['student_name'],
                        'student_status' => $row['student_status'] ?? 'active',
                        'lrn'            => $row['lrn'],
                        'strand'         => $row['strand_name'] ?? 'N/A',
                        'strand_code'    => $row['strand_code'] ?? '',
                        'status'         => 'completed',
                        'attempt_number' => 1,
                        'top_category'   => $topCategory,
                        'top_course'     => $topCourse,
                        'score'          => $score,
                        'date'           => $row['archived_at'],
                        'date_label'     => 'Archived',
                    ];
                }
                $aStmt->close();
                $response['success'] = true;
                $response['rows']    = $rows;
                echo json_encode($response);
                exit;
            }

            $statusCondition = "AND s.status = 'active'";
            if ($statusFilter === 'graduated') {
                $statusCondition = "AND s.status = 'graduated'";
            } elseif ($statusFilter === 'all') {
                $statusCondition = "AND s.status IN ('active', 'graduated')";
            }

            $assmntCondition = "sa.status IN ('completed','abandoned')";
            if ($assmntStatus === 'completed') {
                $assmntCondition = "sa.status = 'completed'";
            } elseif ($assmntStatus === 'abandoned') {
                $assmntCondition = "sa.status = 'abandoned'";
            }

            $sStmt = $mysqli->prepare("
                SELECT
                    sa.id,
                    sa.status,
                    sa.created_at  AS started_at,
                    sa.completed_at,
                    sa.total_score,
                    s.status       AS student_status,
                    TRIM(CONCAT(
                        s.first_name,
                        IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                        ' ',
                        s.last_name,
                        IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                    )) AS student_name,
                    s.student_id   AS lrn,
                    st.name        AS strand_name,
                    st.code        AS strand_code,
                    (SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.grade_level = 'Grade 11' LIMIT 1) AS grade11_id,
                    COALESCE((SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.school_year_id = sa.school_year_id LIMIT 1), s.student_id) AS historical_id,
                    (
                        SELECT COUNT(*)
                        FROM student_assessments sa2
                        WHERE sa2.student_id = sa.student_id
                          AND sa2.status IN ('completed','abandoned')
                          AND sa2.created_at <= sa.created_at
                    ) AS attempt_number
                FROM student_assessments sa
                LEFT JOIN students s  ON sa.student_id = s.id
                LEFT JOIN strands  st ON s.strand_id   = st.id
                WHERE $assmntCondition
                  $statusCondition
                  AND (CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                       OR CONCAT(s.first_name, ' ', s.middle_name, ' ', s.last_name) LIKE ?
                       OR s.student_id LIKE ?
                       OR EXISTS (SELECT 1 FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.student_id LIKE ?))
                ORDER BY sa.created_at DESC
                LIMIT 100
            ");
            $sStmt->bind_param('ssss', $searchLike, $searchLike, $searchLike, $searchLike);
            $sStmt->execute();
            $sResult = $sStmt->get_result();
            while ($row = $sResult->fetch_assoc()) {
                $topRec = null;
                if ($row['status'] === 'completed') {
                    $rStmt = $mysqli->prepare("
                        SELECT c.course_name, cl.name AS cluster_name
                        FROM recommendations r
                        LEFT JOIN courses  c  ON r.course_id  = c.id
                        LEFT JOIN clusters cl ON c.cluster_id = cl.id
                        WHERE r.assessment_id = ?
                        ORDER BY r.match_percentage DESC, r.rank ASC LIMIT 1
                    ");
                    $rStmt->bind_param('i', $row['id']);
                    $rStmt->execute();
                    $topRec = $rStmt->get_result()->fetch_assoc();
                    $rStmt->close();
                }
                $rows[] = [
                    'id'             => $row['id'],
                    'student_name'   => $row['student_name'],
                    'student_status' => $row['student_status'] ?? 'active',
                    'lrn'            => $row['lrn'],
                    'strand'         => $row['strand_name'] ?? 'N/A',
                    'strand_code'    => $row['strand_code']  ?? '',
                    'status'         => $row['status'],
                    'attempt_number' => (int)$row['attempt_number'],
                    'top_category'   => $topRec['cluster_name'] ?? '—',
                    'top_course'     => $topRec['course_name']  ?? '—',
                    'score'          => $row['total_score'] !== null ? round((float)$row['total_score'], 1) : null,
                    'date'           => $row['status'] === 'completed' ? $row['completed_at'] : $row['started_at'],
                    'date_label'     => $row['status'] === 'completed' ? 'Completed' : 'Started',
                ];
            }
            $sStmt->close();
            $response['success'] = true;
            $response['rows']    = $rows;
            echo json_encode($response);
            exit;
    }
}

// Unified Assessment History: completed + abandoned only, with per-student attempt number
// Defaults strictly to Active Enrolled students
$assessmentResults = [];
$result = $mysqli->query("
    SELECT
        sa.id,
        sa.student_id AS db_student_id,
        sa.status,
        sa.created_at  AS started_at,
        sa.completed_at,
        sa.total_score,
        s.status       AS student_status,
        TRIM(CONCAT(
            s.first_name,
            IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
            ' ',
            s.last_name,
            IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
        )) AS student_name,
        s.student_id   AS lrn,
        st.name        AS strand_name,
        st.code        AS strand_code,
        (SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.grade_level = 'Grade 11' LIMIT 1) AS grade11_id,
        COALESCE((SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.school_year_id = sa.school_year_id LIMIT 1), s.student_id) AS historical_id,
        (
            SELECT COUNT(*)
            FROM student_assessments sa2
            WHERE sa2.student_id  = sa.student_id
              AND sa2.status IN ('completed','abandoned')
              AND sa2.created_at <= sa.created_at
        ) AS attempt_number
    FROM student_assessments sa
    LEFT JOIN students s  ON sa.student_id = s.id
    LEFT JOIN strands  st ON s.strand_id   = st.id
    WHERE sa.status IN ('completed','abandoned')
      AND s.status = 'active'
    ORDER BY sa.created_at DESC
    LIMIT 100
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Only fetch top recommendation for completed rows
        $topRec = null;
        if ($row['status'] === 'completed') {
            $recStmt = $mysqli->prepare("
                SELECT c.course_name, cl.name AS cluster_name
                FROM recommendations r
                LEFT JOIN courses  c  ON r.course_id   = c.id
                LEFT JOIN clusters cl ON c.cluster_id  = cl.id
                WHERE r.assessment_id = ?
                ORDER BY r.match_percentage DESC, r.rank ASC
                LIMIT 1
            ");
            $recStmt->bind_param('i', $row['id']);
            $recStmt->execute();
            $topRec = $recStmt->get_result()->fetch_assoc();
            $recStmt->close();
        }

        $assessmentResults[] = [
            'id'             => $row['id'],
            'student_name'   => $row['student_name'],
            'lrn'            => $row['lrn'],
            'strand'         => $row['strand_name'] ?? 'N/A',
            'strand_code'    => $row['strand_code']  ?? '',
            'status'         => $row['status'],
            'attempt_number' => (int)$row['attempt_number'],
            'top_category'   => $topRec['cluster_name'] ?? '—',
            'top_course'     => $topRec['course_name']  ?? '—',
            'score'          => $row['total_score'] !== null ? round((float)$row['total_score'], 1) : null,
            'date'           => $row['status'] === 'completed'
                                    ? $row['completed_at']
                                    : $row['started_at'],
            'date_label'     => $row['status'] === 'completed' ? 'Completed' : 'Started',
        ];
    }
}

// Calculate strand distribution
$clusterDistribution = [];
$strandCounts = [];
$totalCompleted = count($assessmentResults);

foreach ($assessmentResults as $result) {
    $strand = $result['strand_code'] ?: 'Other';
    if (!isset($strandCounts[$strand])) {
        $strandCounts[$strand] = 0;
    }
    $strandCounts[$strand]++;
}

// Colors for different strands
$strandColors = [
    'STEM' => '#3b82f6',
    'ABM' => '#06b6d4',
    'HUMSS' => '#10b981',
    'TVL' => '#f59e0b',
    'GAS' => '#8b5cf6',
    'Other' => '#6b7280'
];

foreach ($strandCounts as $strand => $count) {
    $clusterDistribution[$strand] = [
        'count' => $count,
        'percentage' => $totalCompleted > 0 ? round(($count / $totalCompleted) * 100, 1) : 0,
        'color' => $strandColors[$strand] ?? '#6b7280'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment History - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── Redesigned Assessment Details Modal ── */
        #viewDetailsModal .modal-content {
            max-width: 840px !important;
            width: 95% !important;
            max-height: 90vh !important;
            border-radius: 18px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8) !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            padding: 0 !important;
        }

        .assessment-details-container {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* Hero Banner */
        .student-hero-banner {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.9) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .shb-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .shb-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            font-weight: 800;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
            flex-shrink: 0;
        }
        .shb-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .shb-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.2;
        }
        .shb-lrn {
            font-size: 0.85rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .shb-badges {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .shb-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.85rem;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            line-height: 1;
        }
        .shb-pill.grade {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .shb-pill.strand {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .shb-pill.stat-badge {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .shb-pill.stat-badge.completed {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .shb-pill.stat-badge.in-progress {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .shb-pill.stat-badge.abandoned {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* 4 Metrics Grid (2-Column Responsive) */
        .adm-metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.9rem;
        }
        @media (max-width: 640px) {
            .adm-metrics-grid {
                grid-template-columns: 1fr;
            }
        }
        .adm-metric-card {
            background: rgba(30, 41, 59, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 12px;
            padding: 1.1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s, border-color 0.2s;
        }
        .adm-metric-card:hover {
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        .amc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .amc-content {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
            flex: 1;
        }
        .amc-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
        }
        .amc-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #f8fafc;
            line-height: 1.35;
            word-break: break-word;
        }
        .amc-value.text-amber { color: #fbbf24; font-size: 1.35rem; }
        .amc-value.text-emerald { color: #34d399; }
        .amc-sub {
            font-size: 0.76rem;
            color: #64748b;
        }

        /* Section Cards */
        .adm-section-card {
            background: rgba(30, 41, 59, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
        }
        .adm-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .adm-section-header h4 {
            font-size: 0.98rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .adm-sec-badge {
            font-size: 0.75rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.05);
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
        }

        /* Enhanced Category Breakdown */
        .breakdown-list {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            padding: 0;
        }
        .breakdown-item {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin: 0;
        }
        .breakdown-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .breakdown-label {
            font-size: 0.88rem;
            color: #e2e8f0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .breakdown-value {
            font-size: 0.92rem;
            font-weight: 800;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.1);
            padding: 0.15rem 0.55rem;
            border-radius: 6px;
        }
        .breakdown-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            overflow: hidden;
        }
        .breakdown-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Ranked Recommendation Cards */
        .rec-courses-container {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }
        .rec-course-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 0.9rem 1.15rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.2s ease;
        }
        .rec-course-card:hover {
            background: rgba(30, 41, 59, 0.6);
            border-color: rgba(251, 191, 36, 0.2);
            transform: translateX(3px);
        }
        .rcc-left {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            min-width: 0;
        }
        .rcc-rank {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .rcc-rank.rank-1 {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            color: #0f172a;
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.35);
        }
        .rcc-rank.rank-2 {
            background: linear-gradient(135deg, #94a3b8, #64748b);
            color: #0f172a;
        }
        .rcc-rank.rank-3 {
            background: linear-gradient(135deg, #d97706, #92400e);
            color: #ffffff;
        }
        .rcc-rank.rank-other {
            background: rgba(255, 255, 255, 0.08);
            color: #94a3b8;
        }
        .rcc-details {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            min-width: 0;
        }
        .rcc-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
            line-height: 1.3;
        }
        .rcc-cluster {
            font-size: 0.78rem;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .rcc-cluster i {
            color: #fbbf24;
            font-size: 0.75rem;
        }
        .rcc-score-badge {
            font-size: 0.85rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            white-space: nowrap;
            flex-shrink: 0;
            background: rgba(16, 185, 129, 0.12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        /* ── Results Top Bar & Search Filter Compact Layout (Strict Single Row, No Scroll) ── */
        .results-top-bar {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            gap: 0.65rem !important;
            margin-bottom: 1.25rem !important;
        }

        .results-search-inline {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            gap: 0.45rem !important;
            padding: 0.5rem 0.75rem !important;
            flex: 1 !important;
            min-width: 0 !important;
            overflow: hidden !important;
            overflow-x: hidden !important;
        }

        .results-search-inline .search-input-wrapper {
            flex: 1 1 130px !important;
            min-width: 90px !important;
            position: relative !important;
        }

        .results-search-inline .search-input-wrapper input {
            width: 100% !important;
            height: 38px !important;
            padding: 0 0.6rem 0 2.1rem !important;
            font-size: 0.82rem !important;
            background: rgba(15, 23, 42, 0.5) !important;
            border: 1px solid rgba(148, 163, 184, 0.2) !important;
            border-radius: 8px !important;
            color: #f1f5f9 !important;
            box-sizing: border-box !important;
        }

        .results-search-inline .search-input-wrapper .search-icon {
            position: absolute !important;
            left: 0.75rem !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            font-size: 0.8rem !important;
            color: #64748b !important;
            pointer-events: none !important;
        }

        .results-search-inline select.filter-select {
            height: 38px !important;
            border-radius: 8px !important;
            background: rgba(15, 23, 42, 0.6) !important;
            color: #e2e8f0 !important;
            border: 1px solid rgba(251, 191, 36, 0.2) !important;
            padding: 0 0.35rem !important;
            font-size: 0.82rem !important;
            cursor: pointer !important;
            flex-shrink: 0 !important;
            box-sizing: border-box !important;
        }

        #schoolYearFilter {
            width: 116px !important;
        }

        #assessmentStatusFilter {
            width: 125px !important;
        }

        #statusFilter {
            width: 125px !important;
        }

        .results-search-inline .search-actions {
            display: flex !important;
            gap: 0.35rem !important;
            flex-shrink: 0 !important;
        }

        .results-search-inline .search-actions .btn-primary,
        .results-search-inline .search-actions .btn-secondary {
            height: 38px !important;
            padding: 0 0.75rem !important;
            font-size: 0.82rem !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            white-space: nowrap !important;
            border-radius: 8px !important;
        }

        .results-top-bar .export-wrapper {
            flex-shrink: 0 !important;
            margin-bottom: 0 !important;
            align-self: center !important;
        }

        .results-top-bar .export-wrapper .btn-primary {
            height: 38px !important;
            padding: 0 0.85rem !important;
            font-size: 0.82rem !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            white-space: nowrap !important;
            border-radius: 8px !important;
            width: auto !important;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Assessment History</h1>
                </div>
                <?php 
                // Get staff name & notifications
                $userName = get_staff_name();
                $staffId = get_staff_id();
                $userType = is_counselor() ? 'counselor' : 'admin';
                
                $notifications = [];
                $unreadCount = 0;
                $adminProfilePic = null;
                
                if (!is_counselor() && $staffId) {
                    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                    if ($profileStmt) {
                        $profileStmt->bind_param('i', $staffId);
                        $profileStmt->execute();
                        $profileResult = $profileStmt->get_result();
                        $adminData = $profileResult->fetch_assoc();
                        $adminProfilePic = $adminData['profile_picture'] ?? null;
                        $profileStmt->close();
                    }
                }
                
                if ($staffId) {
                    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
                    if ($countStmt) {
                        $countStmt->bind_param('is', $staffId, $userType);
                        $countStmt->execute();
                        $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                        $countStmt->close();
                    }
                    
                    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? ORDER BY created_at DESC LIMIT 10");
                    if ($notifStmt) {
                        $notifStmt->bind_param('is', $staffId, $userType);
                        $notifStmt->execute();
                        $result = $notifStmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $notifications[] = $row;
                        }
                        $notifStmt->close();
                    }
                }
                ?>
                <div class="top-bar-actions">
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <i class="fa-solid fa-bell"></i>
                            <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <a href="#" class="clear-all-notifs" onclick="clearAllNotifications(event)" style="color: #ef4444; font-size: 0.8rem; text-decoration: none;">Clear All</a>
                                    <?php if ($unreadCount > 0): ?>
                                    <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notification-list">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                                        <div class="notification-icon <?php echo $notif['type']; ?>">
                                            <i class="fa-solid <?php echo $notif['type'] === 'success' ? 'fa-check-circle' : ($notif['type'] === 'warning' ? 'fa-exclamation-triangle' : ($notif['type'] === 'error' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                            <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <span class="notification-time"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-notifications">
                                        <i class="fa-solid fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="user-profile" onclick="window.location.href='<?php echo is_counselor() ? 'counselor_profile.php' : 'settings.php'; ?>'" title="<?php echo is_counselor() ? 'My Profile' : 'Settings'; ?>">
                        <div class="user-avatar <?php echo is_counselor() ? 'counselor-avatar' : ''; ?>">
                            <?php if ($adminProfilePic && file_exists(__DIR__ . '/' . $adminProfilePic)): ?>
                                <img src="<?php echo htmlspecialchars($adminProfilePic); ?>" alt="Admin" class="avatar-img">
                            <?php elseif (is_counselor()): ?>
                                <i class="fa-solid fa-user-tie"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-user-shield"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                
                <!-- Top Bar with Search and Export -->
                <div class="page-header results-top-bar">
                    <div class="results-search-inline">
                        <div class="search-input-wrapper">
                            <i class="fa-solid fa-search search-icon"></i>
                            <input type="text" id="searchStudent" placeholder="Search student or ID...">
                        </div>
                        <select id="schoolYearFilter" class="filter-select">
                            <?php foreach($schoolYears as $sy): ?>
                                <option value="<?php echo $sy['id']; ?>" <?php echo $sy['is_current'] ? 'selected' : ''; ?>>SY <?php echo htmlspecialchars($sy['year_label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="assessmentStatusFilter" class="filter-select">
                            <option value="all" selected>All Assessments</option>
                            <option value="completed">Completed Only</option>
                            <option value="abandoned">Abandoned Only</option>
                        </select>
                        <select id="statusFilter" class="filter-select">
                            <option value="active" selected>Active Enrolled</option>
                            <option value="graduated">Graduated Alumni</option>
                            <option value="all">All Students</option>
                        </select>
                        <div class="search-actions">
                            <button class="btn-primary" id="searchBtn">
                                <i class="fa-solid fa-search"></i>
                                Search
                            </button>
                            <button class="btn-secondary" id="clearBtn">
                                <i class="fa-solid fa-eraser"></i>
                                Clear
                            </button>
                        </div>
                    </div>
                    <div class="export-wrapper">
                        <button class="btn-primary" id="exportPdfBtn">
                            <i class="fa-solid fa-file-pdf"></i>
                            Export to PDF
                        </button>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="table-section">
                    <div class="table-card">
                        <div class="table-header">
                            <h3><i class="fa-solid fa-list"></i> Assessment History</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table results-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Strand</th>
                                        <th style="text-align:center;">Attempt #</th>
                                        <th style="text-align:center;">Status</th>
                                        <th>Top Category</th>
                                        <th>Score</th>
                                        <th>Date</th>
                                        <th style="text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assessmentResults as $result): ?>
                                    <?php
                                        $statusCfg = [
                                            'completed' => [
                                                'label' => 'Completed',
                                                'style' => 'background:rgba(16,185,129,.15);color:#34d399;border:1px solid rgba(16,185,129,.3);',
                                            ],
                                            'abandoned' => [
                                                'label' => 'Abandoned',
                                                'style' => 'background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.25);',
                                            ],
                                        ];
                                        $sc          = $statusCfg[$result['status']] ?? ['label' => ucfirst($result['status']), 'style' => 'background:rgba(100,116,139,.15);color:#94a3b8;'];
                                        $scoreVal    = $result['score'] !== null ? number_format($result['score'], 1) : null;
                                        $dateStr     = $result['date'] ? date('M d, Y', strtotime($result['date'])) : '—';
                                        $strandLabel = !empty($result['strand_code']) ? $result['strand_code'] : $result['strand'];
                                    ?>
                                    <tr data-id="<?php echo $result['id']; ?>" data-student-status="<?php echo htmlspecialchars($result['student_status'] ?? 'active'); ?>">
                                        <td class="student-name" style="white-space:nowrap;font-weight:500;">
                                            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                                <span><?php echo htmlspecialchars($result['student_name']); ?></span>
                                                <?php if (($result['student_status'] ?? '') === 'graduated'): ?>
                                                    <span class="suggestion-status-badge graduated" style="font-size:0.75rem; padding:2px 8px;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="strand-badge strand-<?php echo strtolower($result['strand_code'] ?: $result['strand']); ?>">
                                                <?php echo htmlspecialchars($strandLabel); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(99,102,241,.15);color:#a5b4fc;font-weight:700;font-size:0.8rem;border:1px solid rgba(99,102,241,.3);">
                                                <?php echo $result['attempt_number']; ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span style="<?php echo $sc['style']; ?> padding:2px 8px;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap;">
                                                <?php echo $sc['label']; ?>
                                            </span>
                                        </td>
                                        <td style="max-width:140px;line-height:1.25;font-size:0.8rem;"><?php echo htmlspecialchars($result['top_category']); ?></td>
                                        <td>
                                            <?php if ($scoreVal !== null): ?>
                                            <div class="score-display">
                                                <span class="score-value"><?php echo $scoreVal; ?>%</span>
                                                <div class="score-bar">
                                                    <div class="score-fill" style="width:<?php echo min(100, $result['score']); ?>%"></div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span style="color:#475569;font-size:0.82rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td title="<?php echo $result['date_label']; ?>" style="white-space:nowrap;font-size:0.8rem;"><?php echo $dateStr; ?></td>
                                        <td style="text-align:center;">
                                            <?php if ($result['status'] === 'completed'): ?>
                                            <button class="btn-action view-details" data-id="<?php echo $result['id']; ?>" title="View Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <?php else: ?>
                                            <span title="No results to view for abandoned attempts" style="color:#334155;font-size:0.82rem;padding:0 6px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <?php include 'includes/assessment_details_modal.php'; ?>


    <script src="script.js"></script>
    <script src="admin.js"></script>
    <script>
        // Admin Assessment Results JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const viewDetailsModal = document.getElementById('viewDetailsModal');
            const closeDetailsModal = document.getElementById('closeDetailsModal');
            const closeDetailsBtn = document.getElementById('closeDetailsBtn');
            
            // Button elements
            const exportPdfBtn = document.getElementById('exportPdfBtn');
            const searchBtn = document.getElementById('searchBtn');
            
            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('admin_assessment_results.php', { method: 'POST', body: formData });
                return res.json();
            }
            const clearBtn = document.getElementById('clearBtn');
            const searchStudent = document.getElementById('searchStudent');
            
            // Export PDF button
            exportPdfBtn.addEventListener('click', function() {
                const q = (searchStudent?.value || '').trim();
                let url = 'api/export_pdf.php?type=assessment_results';
                if (q) {
                    url += '&search=' + encodeURIComponent(q);
                }
                window.location.href = url;
            });

            // Close modal handlers
            function closeModal() {
                viewDetailsModal.classList.remove('active');
            }
            if (closeDetailsModal) closeDetailsModal.addEventListener('click', closeModal);
            if (closeDetailsBtn) closeDetailsBtn.addEventListener('click', closeModal);
            const overlay = viewDetailsModal.querySelector('.modal-overlay');
            if (overlay) overlay.addEventListener('click', closeModal);

            // Attach view-details click listeners (called after initial render & after search re-renders rows)
            function attachViewDetailListeners() {
                document.querySelectorAll('.view-details').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        const assessmentId = this.getAttribute('data-id');
                        const isArchived = this.getAttribute('data-archived') === 'true' ? '1' : '0';
                        const fd = new FormData();
                        fd.append('action', 'get_assessment_details');
                        fd.append('assessment_id', assessmentId);
                        fd.append('is_archived', isArchived);
                        try {
                            const data = await apiPost(fd);
                            if (data.success && data.assessment) {
                                const assessment = data.assessment;
                                const categoryScores = data.category_scores || [];
                                const recommendations = data.recommendations || [];
                                
                                // Format Student Name (First + Middle Initial + Last + Suffix)
                                const firstInit = (assessment.first_name || 'S').charAt(0).toUpperCase();
                                const lastInit = (assessment.last_name || '').charAt(0).toUpperCase();
                                const middleInit = assessment.middle_name ? assessment.middle_name.trim().charAt(0).toUpperCase() + '.' : '';
                                const nameParts = [assessment.first_name, middleInit, assessment.last_name, assessment.suffix].filter(Boolean);
                                const fullName = nameParts.join(' ') || 'Student';
                                
                                // Avatar initials
                                const avatarEl = document.getElementById('detailAvatar');
                                if (avatarEl) avatarEl.textContent = firstInit + lastInit;

                                // Student Name & LRN
                                document.getElementById('detailStudentName').textContent = fullName;
                                let lrnDisplay = assessment.student_id || 'Not Assigned';
                                if (assessment.grade11_id && assessment.grade11_id !== assessment.student_id) {
                                    lrnDisplay += ` (Grade 11 ID: ${assessment.grade11_id})`;
                                }
                                document.getElementById('detailLrn').textContent = lrnDisplay;
                                
                                // Grade Level & Strand
                                const gradeText = assessment.grade_level ? (assessment.grade_level.includes('Grade') ? assessment.grade_level : 'Grade ' + assessment.grade_level) : 'Grade 11';
                                document.getElementById('detailGradeLevel').textContent = gradeText;
                                
                                const strandText = assessment.strand_name ? (assessment.strand_name + (assessment.strand_code ? ' (' + assessment.strand_code.toUpperCase() + ')' : '')) : 'N/A';
                                document.getElementById('detailStrand').textContent = strandText;

                                // Status Badge
                                const statusPill = document.getElementById('detailStatusPill');
                                const statusText = document.getElementById('detailStatusText');
                                const isCompleted = (assessment.status === 'completed');
                                if (statusText) statusText.textContent = isCompleted ? 'Completed' : ucfirst(assessment.status || 'In Progress');
                                if (statusPill) {
                                    statusPill.className = 'shb-pill stat-badge ' + (isCompleted ? 'completed' : 'in-progress');
                                    statusPill.innerHTML = isCompleted 
                                        ? '<i class="fa-solid fa-circle-check"></i> <span id="detailStatusText">Completed</span>'
                                        : '<i class="fa-solid fa-clock"></i> <span id="detailStatusText">' + (assessment.status || 'In Progress') + '</span>';
                                }

                                // Score Percentage
                                const scoreVal = (assessment.total_score != null ? parseFloat(assessment.total_score).toFixed(1) : '0.0') + '%';
                                document.getElementById('detailScore').textContent = scoreVal;

                                // Top Recommendation & Cluster
                                let topCourseName = '—';
                                let topClusterName = '—';
                                if (recommendations.length > 0) {
                                    topCourseName = recommendations[0].course_name || '—';
                                    topClusterName = recommendations[0].cluster_name || '—';
                                }
                                const topCourseEl = document.getElementById('detailTopCourse');
                                if (topCourseEl) {
                                    topCourseEl.textContent = topCourseName;
                                    topCourseEl.title = topCourseName;
                                }
                                const clusterEl = document.getElementById('detailCluster');
                                if (clusterEl) {
                                    clusterEl.textContent = topClusterName;
                                    clusterEl.title = topClusterName;
                                }

                                // Formatted Date & Time
                                const dateObj = assessment.completed_at ? new Date(assessment.completed_at) : (assessment.created_at ? new Date(assessment.created_at) : null);
                                if (dateObj && !isNaN(dateObj.getTime())) {
                                    document.getElementById('detailDate').textContent = dateObj.toLocaleDateString('en-US', { 
                                        month: 'long', 
                                        day: 'numeric', 
                                        year: 'numeric'
                                    });
                                    document.getElementById('detailTime').textContent = dateObj.toLocaleTimeString('en-US', {
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    });
                                } else {
                                    document.getElementById('detailDate').textContent = '—';
                                    document.getElementById('detailTime').textContent = '—';
                                }

                                // Render Category Breakdown
                                const breakdownList = document.getElementById('breakdownList');
                                const categoryConfigs = {
                                    career:      { fill: 'linear-gradient(90deg, #f59e0b, #fb923c)', label: 'Career Interest', icon: 'fa-briefcase' },
                                    personality: { fill: 'linear-gradient(90deg, #8b5cf6, #a78bfa)', label: 'Personality & Traits', icon: 'fa-brain' },
                                    skills:      { fill: 'linear-gradient(90deg, #10b981, #34d399)', label: 'Skills & Strengths', icon: 'fa-bolt' },
                                    strand:      { fill: 'linear-gradient(90deg, #3b82f6, #60a5fa)', label: 'Strand Alignment', icon: 'fa-compass' },
                                };

                                if (categoryScores.length > 0) {
                                    let html = '';
                                    categoryScores.forEach(score => {
                                        const key = (score.category || '').toLowerCase();
                                        const cfg = categoryConfigs[key] || { fill: 'linear-gradient(90deg, #64748b, #94a3b8)', label: score.category, icon: 'fa-chart-pie' };
                                        const percentage = score.percentage != null
                                            ? Math.min(100, Math.round(parseFloat(score.percentage)))
                                            : Math.min(100, Math.round(parseFloat(score.score || 0)));
                                        html += `
                                            <div class="breakdown-item">
                                                <div class="breakdown-meta">
                                                    <span class="breakdown-label">
                                                        <i class="fa-solid ${cfg.icon}" style="color:#fbbf24; font-size:0.85rem;"></i>
                                                        ${cfg.label}
                                                    </span>
                                                    <span class="breakdown-value">${percentage}% Match</span>
                                                </div>
                                                <div class="breakdown-bar">
                                                    <div class="breakdown-fill" style="width: ${percentage}%; background: ${cfg.fill};"></div>
                                                </div>
                                            </div>
                                        `;
                                    });
                                    breakdownList.innerHTML = html;
                                } else {
                                    breakdownList.innerHTML = '<p style="color:#64748b;font-size:0.875rem;">No category scores recorded for this assessment.</p>';
                                }

                                // Render Ranked Recommended Programs
                                const coursesWrap = document.getElementById('detailCoursesWrap');
                                const countBadge = document.getElementById('detailCourseCountBadge');
                                if (recommendations.length > 0) {
                                    if (countBadge) countBadge.textContent = recommendations.length + ' Recommended Programs';
                                    coursesWrap.innerHTML = recommendations.map((rec, i) => {
                                        const rank = rec.rank || (i + 1);
                                        const rankClass = rank === 1 ? 'rank-1' : (rank === 2 ? 'rank-2' : (rank === 3 ? 'rank-3' : 'rank-other'));
                                        const matchPct = rec.match_percentage != null ? parseFloat(rec.match_percentage).toFixed(1) : null;
                                        const clusterName = rec.cluster_name || 'Academic Program';
                                        return `
                                            <div class="rec-course-card" style="display:flex; flex-direction:column; gap:8px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <div class="rcc-left">
                                                        <span class="rcc-rank ${rankClass}">#${rank}</span>
                                                        <div class="rcc-details">
                                                            <h5 class="rcc-title">${escapeHtml(rec.course_name || '—')}</h5>
                                                            <span class="rcc-cluster">
                                                                <i class="fa-solid fa-layer-group"></i> ${escapeHtml(clusterName)}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    ${matchPct ? `<span class="rcc-score-badge"><i class="fa-solid fa-bullseye"></i> ${matchPct}%</span>` : ''}
                                                </div>
                                                ${rec.explanation_html ? `
                                                    <div style="margin-top:8px;">
                                                        ${rec.explanation_html}
                                                    </div>
                                                ` : ''}
                                            </div>
                                        `;
                                    }).join('');
                                } else {
                                    if (countBadge) countBadge.textContent = '0 Programs';
                                    coursesWrap.innerHTML = '<p style="color:#64748b; font-size:0.875rem; padding: 0.5rem 0;">No specific program recommendations generated yet.</p>';
                                }
                                
                                viewDetailsModal.classList.add('active');
                            } else {
                                alert(data.message || 'Failed to load assessment details');
                            }
                        } catch (e) {
                            alert('Error loading assessment details');
                        }
                    });
                });
            }

            function ucfirst(str) {
                if (!str) return '';
                return str.charAt(0).toUpperCase() + str.slice(1);
            }

            function escapeHtml(text) {
                if (!text) return '';
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return text.toString().replace(/[&<>"']/g, m => map[m]);
            }

            // Initial attach on page load
            attachViewDetailListeners();

            // Search functionality (server-side)
            const statusBadge = {
                completed: { label: 'Completed', style: 'background:rgba(16,185,129,.15);color:#34d399;border:1px solid rgba(16,185,129,.3);' },
                abandoned: { label: 'Abandoned', style: 'background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.25);' },
            };

            function renderRows(rows) {
                const tbody = document.querySelector('.results-table tbody');
                if (!rows || rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;"><i class="fa-solid fa-magnifying-glass" style="margin-right:8px;"></i>No results found.</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map(r => {
                    const sc          = statusBadge[r.status] || { label: r.status, style: 'background:rgba(100,116,139,.15);color:#94a3b8;' };
                    const score       = r.score !== null && r.score !== undefined ? parseFloat(r.score) : null;
                    const dateStr     = r.date
                        ? new Date(r.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        : '—';
                    const attempt     = r.attempt_number || '?';
                    const strandLabel = r.strand_code || r.strand || 'N/A';
                    const scoreHtml = score !== null
                        ? `<div class="score-display"><span class="score-value">${score.toFixed(1)}%</span><div class="score-bar"><div class="score-fill" style="width:${Math.min(score,100)}%"></div></div></div>`
                        : `<span style="color:#475569;font-size:0.82rem;">—</span>`;
                    const actionHtml = r.status === 'completed'
                        ? `<button class="btn-action view-details" data-id="${r.id}" data-archived="${r.is_archived ? 'true' : 'false'}" title="View Details"><i class="fa-solid fa-eye"></i></button>`
                        : `<span title="No results to view for abandoned attempts" style="color:#334155;font-size:0.82rem;padding:0 6px;">—</span>`;
                    const isGrad = (r.student_status === 'graduated');
                    const gradBadge = isGrad ? '<span class="suggestion-status-badge graduated" style="font-size:0.75rem; padding:2px 8px; margin-left:6px;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>' : '';

                    return `<tr data-id="${r.id}" data-student-status="${r.student_status || 'active'}">
                        <td class="student-name" style="white-space:nowrap;font-weight:500;">
                            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                <span>${r.student_name || '—'}</span>
                                ${gradBadge}
                            </div>
                        </td>
                        <td><span class="strand-badge strand-${(r.strand_code || r.strand || '').toLowerCase()}">${strandLabel}</span></td>
                        <td style="text-align:center;"><span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(99,102,241,.15);color:#a5b4fc;font-weight:700;font-size:0.8rem;border:1px solid rgba(99,102,241,.3);">${attempt}</span></td>
                        <td style="text-align:center;"><span style="${sc.style} padding:2px 8px;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap;">${sc.label}</span></td>
                        <td style="max-width:140px;line-height:1.25;font-size:0.8rem;">${r.top_category || '—'}</td>
                        <td>${scoreHtml}</td>
                        <td title="${r.date_label || ''}" style="white-space:nowrap;font-size:0.8rem;">${dateStr}</td>
                        <td style="text-align:center;">${actionHtml}</td>
                    </tr>`;
                }).join('');
                // re-attach click listeners on newly rendered rows
                attachViewDetailListeners();
            }


            async function doSearch() {
                const query = (searchStudent.value || '').trim();
                const statusVal = document.getElementById('statusFilter')?.value || 'active';
                const syVal = document.getElementById('schoolYearFilter')?.value || '';
                const assmntStatusVal = document.getElementById('assessmentStatusFilter')?.value || 'all';
                searchBtn.disabled = true;
                searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Searching...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'search_results');
                    fd.append('query', query);
                    fd.append('status_filter', statusVal);
                    fd.append('school_year_id', syVal);
                    fd.append('assessment_status', assmntStatusVal);
                    const data = await apiPost(fd);
                    if (data.success) {
                        renderRows(data.rows);
                    } else {
                        alert(data.message || 'Search failed');
                    }
                } catch(e) {
                    alert('Search error. Please try again.');
                } finally {
                    searchBtn.disabled = false;
                    searchBtn.innerHTML = '<i class="fa-solid fa-search"></i> Search';
                }
            }

            const statusFilter = document.getElementById('statusFilter');
            const syFilter = document.getElementById('schoolYearFilter');
            const assmntStatusFilter = document.getElementById('assessmentStatusFilter');
            if (statusFilter) statusFilter.addEventListener('change', doSearch);
            if (syFilter) syFilter.addEventListener('change', doSearch);
            if (assmntStatusFilter) assmntStatusFilter.addEventListener('change', doSearch);
            if (searchBtn) searchBtn.addEventListener('click', doSearch);
            searchStudent.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') doSearch();
            });

            // Clear search — reset to active and reload
            clearBtn.addEventListener('click', async function() {
                searchStudent.value = '';
                if (statusFilter) statusFilter.value = 'active';
                if (assmntStatusFilter) assmntStatusFilter.value = 'all';
                // Note: we might want to reset schoolYearFilter to activeYearId, but reloading the default state is handled by doSearch keeping current value unless forced.
                await doSearch();
            });
        });
    </script>
    <script>
        // Notification dropdown toggle
        document.getElementById('notificationBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            document.getElementById('notificationDropdown').style.display = 'none';
        });

        // Prevent dropdown close when clicking inside
        document.getElementById('notificationDropdown').addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Mark all notifications as read
        function clearAllNotifications(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.showConfirm === 'function') {
                window.showConfirm("Are you sure you want to clear all notifications?", "Clear Notifications", executeClear);
            } else if (confirm("Are you sure you want to clear all notifications?")) {
                executeClear();
            }
            
            function executeClear() {
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=clear_all'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const list = document.querySelector('.notification-list');
                        if (list) {
                            list.innerHTML = '<div class="no-notifications"><p>No notifications.</p></div>';
                        }
                        const badge = document.getElementById('notificationBadge');
                        if (badge) {
                            badge.textContent = '0';
                            badge.style.display = 'none';
                        }
                        const markAllBtn = document.querySelector('.mark-all-read');
                        if (markAllBtn) markAllBtn.style.display = 'none';
                    }
                });
            }
        }

        function markAllRead(e) {
            e.preventDefault();
            e.stopPropagation();
            
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read&caller_type=admin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    const badge = document.getElementById('notificationBadge'); if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
                    document.querySelector('.mark-all-read')?.remove();
                }
            });
        }

        // Mark single notification as read on click
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                if (this.classList.contains('unread')) {
                    const notifId = this.dataset.id;
                    
                    fetch('api/notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&id=' + notifId + '&caller_type=admin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.classList.remove('unread');
                            const badge = document.getElementById('notificationBadge');
                            const currentCount = parseInt(badge.textContent);
                            if (currentCount > 0) {
                                badge.textContent = currentCount - 1;
                            }
                        }
                    });
                }
            });
        });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
