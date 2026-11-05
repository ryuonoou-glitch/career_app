<?php
// Reports & Analytics Page - Enhanced Assessment & Historical Records Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';

// RBAC Auth check
require_once __DIR__ . '/includes/auth_rbac.php';
if (!has_permission('view_reports')) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    require_permission('view_reports');
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    // Helper: Build WHERE conditions & prepared statement params for filtering
    $buildFilterQuery = function($mysqli, $extraWhere = '') {
        $strandId    = (int)($_POST['strand_id'] ?? 0);
        $schoolYearId= (int)($_POST['school_year_id'] ?? 0);
        $searchTerm  = trim($_POST['search_term'] ?? '');
        $statusFilter= trim($_POST['status_filter'] ?? 'active');

        $whereParts = ["sa.status = 'completed'"];
        $params     = [];
        $types      = '';

        if ($statusFilter === 'active') {
            $whereParts[] = "s.status = 'active'";
        } elseif ($statusFilter === 'graduated') {
            $whereParts[] = "s.status = 'graduated'";
        } else {
            $whereParts[] = "s.status IN ('active', 'graduated')";
        }

        if ($searchTerm !== '') {
            $whereParts[] = "(CONCAT(s.first_name, ' ', COALESCE(s.last_name, '')) LIKE ? OR s.student_id LIKE ? OR EXISTS(SELECT 1 FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.student_id LIKE ?))";
            $likeTerm = '%' . $searchTerm . '%';
            $params[] = $likeTerm;
            $params[] = $likeTerm;
            $params[] = $likeTerm;
            $types .= 'sss';
        }

        if ($extraWhere !== '') {
            $whereParts[] = $extraWhere;
        }

        if ($strandId > 0) {
            $whereParts[] = "s.strand_id = ?";
            $params[] = $strandId;
            $types .= 'i';
        }

        if ($schoolYearId > 0) {
            $whereParts[] = "(sa.school_year_id = ? OR s.school_year_id = ?)";
            $params = array_merge($params, [$schoolYearId, $schoolYearId]);
            $types .= 'ii';
        }

        $whereSql = implode(' AND ', $whereParts);
        return [$whereSql, $types, $params];
    };

    switch ($_POST['action']) {
        case 'get_historical_records':
            $page     = max(1, (int)($_POST['page'] ?? 1));
            $perPage  = max(1, min(500, (int)($_POST['per_page'] ?? 200)));
            $offset   = ($page - 1) * $perPage;

            $sortBy    = $_POST['sort_by'] ?? 'completed_at';
            $sortOrder = strtoupper($_POST['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $sortMap = [
                'student_name'  => 'student_name',
                'school_id'     => 's.student_id',
                'strand_name'   => 'st.name',
                'completed_at'  => 'sa.completed_at',
                'overall_match' => 'sa.total_score',
                'top_course'    => 'top_course'
            ];
            $orderSql = ($sortMap[$sortBy] ?? 'sa.completed_at') . ' ' . $sortOrder;

            list($whereSql, $types, $params) = $buildFilterQuery($mysqli);

            // 1. Total records count
            $countSql = "
                SELECT COUNT(DISTINCT sa.id) AS total
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
            ";
            $countStmt = $mysqli->prepare($countSql);
            if ($types !== '') {
                $countStmt->bind_param($types, ...$params);
            }
            $countStmt->execute();
            $totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();

            $totalPages = max(1, (int)ceil($totalRecords / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;

            // 2. Paginated data fetch (retrieving filtered records up to 200/500 for client grouping)
            $dataSql = "
                SELECT sa.id AS assessment_id, sa.student_id AS student_db_id, sa.completed_at, sa.created_at, sa.total_score, sa.status,
                       CONCAT(
                           COALESCE(s.first_name, ''),
                           IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                           ' ', COALESCE(s.last_name, ''),
                           IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                       ) AS student_name,
                       s.student_id AS school_id, s.grade_level, s.status AS student_status,
                       st.name AS strand_name, st.code AS strand_code,
                       COALESCE(sy.id, 0) AS school_year_id,
                       COALESCE(sy.year_label, 'N/A') AS school_year,
                       COALESCE(sy.is_current, 0) AS is_current_year,
                       (SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.grade_level = 'Grade 11' LIMIT 1) AS grade11_id,
                       COALESCE((SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.school_year_id = sa.school_year_id LIMIT 1), s.student_id) AS historical_id,
                       (
                           SELECT c.course_name 
                           FROM recommendations r 
                           JOIN courses c ON r.course_id = c.id 
                           WHERE r.assessment_id = sa.id 
                           ORDER BY r.match_percentage DESC, r.rank ASC 
                           LIMIT 1
                       ) AS top_course
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY sa.id
                ORDER BY $orderSql
                LIMIT ?, ?
            ";

            $dataStmt = $mysqli->prepare($dataSql);
            $bindTypes  = $types . 'ii';
            $bindParams = array_merge($params, [$offset, $perPage]);
            $dataStmt->bind_param($bindTypes, ...$bindParams);
            $dataStmt->execute();
            $result = $dataStmt->get_result();
            $records = [];
            while ($row = $result->fetch_assoc()) {
                $row['total_score_fmt'] = round((float)($row['total_score'] ?? 0), 1) . '%';
                $row['formatted_date']  = $row['completed_at'] ? date('M d, Y', strtotime($row['completed_at'])) : 'N/A';
                $records[] = $row;
            }
            $dataStmt->close();

            // 3. KPI Statistics for the matching filter set
            $kpiSql = "
                SELECT 
                    COUNT(DISTINCT sa.student_id) AS total_students,
                    COUNT(DISTINCT sa.id) AS total_completed,
                    AVG(sa.total_score) AS avg_score
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
            ";
            $kpiStmt = $mysqli->prepare($kpiSql);
            if ($types !== '') {
                $kpiStmt->bind_param($types, ...$params);
            }
            $kpiStmt->execute();
            $kpiRes = $kpiStmt->get_result()->fetch_assoc();
            $kpiStmt->close();

            // KPI Accuracy
            $kpiAccSql = "
                SELECT 
                    COUNT(DISTINCT re.assessment_id) AS total_evaluated_assessments,
                    COUNT(re.id) AS total_evaluated,
                    SUM(CASE WHEN re.is_aligned = 1 THEN 1 ELSE 0 END) AS total_aligned
                FROM recommendation_evaluations re
                JOIN student_assessments sa ON re.assessment_id = sa.id
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
            ";
            $kpiAccStmt = $mysqli->prepare($kpiAccSql);
            if ($types !== '') { $kpiAccStmt->bind_param($types, ...$params); }
            $kpiAccStmt->execute();
            $kpiAccRes = $kpiAccStmt->get_result()->fetch_assoc();
            $kpiAccStmt->close();

            $totalEvalAssessments = (int)($kpiAccRes['total_evaluated_assessments'] ?? 0);
            $totalEvaluated = (int)($kpiAccRes['total_evaluated'] ?? 0);
            $totalAligned = (int)($kpiAccRes['total_aligned'] ?? 0);
            $totalNotAligned = $totalEvaluated - $totalAligned;
            $accuracyPct = $totalEvaluated > 0 ? round(($totalAligned / $totalEvaluated) * 100, 1) : 0;

            // Top recommended course for matching filter set
            $topCourseSql = "
                SELECT c.course_name, COUNT(*) as cnt
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                JOIN student_assessments sa ON r.assessment_id = sa.id
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY c.id, c.course_name
                ORDER BY cnt DESC
                LIMIT 1
            ";
            $topCourseStmt = $mysqli->prepare($topCourseSql);
            if ($types !== '') {
                $topCourseStmt->bind_param($types, ...$params);
            }
            $topCourseStmt->execute();
            $topCourseRes = $topCourseStmt->get_result()->fetch_assoc();
            $topCourseStmt->close();

            $summaryStats = [
                'total_students'          => (int)($kpiRes['total_students'] ?? 0),
                'total_completed'         => (int)($kpiRes['total_completed'] ?? 0),
                'avg_score'               => round((float)($kpiRes['avg_score'] ?? 0), 1) . '%',
                'most_recommended_course' => $topCourseRes['course_name'] ?? 'N/A',
                'accuracy_pct'            => $accuracyPct . '%',
                'accuracy_aligned'        => $totalAligned,
                'accuracy_evaluated'      => $totalEvaluated,
                'accuracy_assessments'    => $totalEvalAssessments,
                'accuracy_not_aligned'    => $totalNotAligned
            ];

            // 4. Chart Data Generation
            // Chart A: Top 5 Recommended Courses (Pie Chart)
            $chartTopCoursesSql = "
                SELECT c.course_name, COUNT(*) AS cnt
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                JOIN student_assessments sa ON r.assessment_id = sa.id
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY c.id, c.course_name
                ORDER BY cnt DESC
                LIMIT 5
            ";
            $tcStmt = $mysqli->prepare($chartTopCoursesSql);
            if ($types !== '') { $tcStmt->bind_param($types, ...$params); }
            $tcStmt->execute();
            $tcRes = $tcStmt->get_result();
            $chartTopCourses = ['labels' => [], 'data' => []];
            while ($r = $tcRes->fetch_assoc()) {
                $chartTopCourses['labels'][] = $r['course_name'];
                $chartTopCourses['data'][]   = (int)$r['cnt'];
            }
            $tcStmt->close();

            // Chart B: Strand Distribution (Doughnut Chart)
            $chartStrandSql = "
                SELECT COALESCE(st.code, 'N/A') AS strand_code, COALESCE(st.name, 'Unassigned') AS strand_name, COUNT(DISTINCT sa.student_id) AS cnt
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY st.id, strand_name, strand_code
                ORDER BY cnt DESC
            ";
            $csStmt = $mysqli->prepare($chartStrandSql);
            if ($types !== '') { $csStmt->bind_param($types, ...$params); }
            $csStmt->execute();
            $csRes = $csStmt->get_result();
            $chartStrand = ['labels' => [], 'data' => []];
            while ($r = $csRes->fetch_assoc()) {
                $chartStrand['labels'][] = $r['strand_code'] . ' - ' . $r['strand_name'];
                $chartStrand['data'][]   = (int)$r['cnt'];
            }
            $csStmt->close();

            // 5. School Years List for dynamic accordion panels
            $syListRes = $mysqli->query("SELECT id, year_label, is_current FROM school_years ORDER BY year_label DESC");
            $syList = [];
            if ($syListRes) {
                while ($sy = $syListRes->fetch_assoc()) {
                    $syList[] = $sy;
                }
            }

            $response = [
                'success'       => true,
                'records'       => $records,
                'school_years'  => $syList,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'page'          => $page,
                'summary_stats' => $summaryStats,
                'charts' => [
                    'top_courses' => $chartTopCourses,
                    'strands'     => $chartStrand
                ]
            ];
            break;

        case 'get_assessment_details':
        case 'get_student_details':
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            if ($assessmentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid assessment ID']);
                exit;
            }

            // Get assessment details with student info (including grade level and strand)
            $stmt = $mysqli->prepare("
                SELECT sa.*, s.first_name, s.middle_name, s.last_name, s.suffix, s.student_id, s.grade_level, s.email as student_email,
                       st.name as strand_name, st.code as strand_code,
                       COALESCE(sy.year_label, 'N/A') AS school_year,
                       (SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.grade_level = 'Grade 11' LIMIT 1) AS grade11_id,
                       COALESCE((SELECT v.student_id FROM valid_student_ids v WHERE v.registered_student_id = s.id AND v.school_year_id = sa.school_year_id LIMIT 1), s.student_id) AS historical_id
                FROM student_assessments sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE sa.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $assessment = $stmt->get_result()->fetch_assoc();
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

                $response = [
                    'success'         => true,
                    'assessment'      => $assessment,
                    'category_scores' => $categoryScores,
                    'recommendations' => $recommendations
                ];
            } else {
                $response = ['success' => false, 'message' => 'Assessment not found'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// ==================== PAGE DATA FETCHING (PHP LOAD) ====================

// Staff details & Notifications
$userName       = get_staff_name();
$staffId        = get_staff_id();
$staffRole      = get_staff_role();
$userType       = ($staffRole === 'counselor') ? 'counselor' : 'admin';
$adminProfilePic = null;
$notifications  = [];
$unreadCount    = 0;

if ($staffId) {
    if ($userType === 'admin') {
        $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
        if ($profileStmt) {
            $profileStmt->bind_param('i', $staffId);
            $profileStmt->execute();
            $adminData       = $profileStmt->get_result()->fetch_assoc();
            $adminProfilePic = $adminData['profile_picture'] ?? null;
            $profileStmt->close();
        }
    }

    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
    if ($countStmt) {
        $countStmt->bind_param('is', $staffId, $userType);
        $countStmt->execute();
        $unreadCount = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
        $countStmt->close();
    }

    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? ORDER BY created_at DESC LIMIT 10");
    if ($notifStmt) {
        $notifStmt->bind_param('is', $staffId, $userType);
        $notifStmt->execute();
        $notifResult = $notifStmt->get_result();
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notifStmt->close();
    }
}

// Filter options: Strands and School Years
$strandsRes = $mysqli->query("SELECT id, name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
$strands    = [];
if ($strandsRes) { while ($s = $strandsRes->fetch_assoc()) { $strands[] = $s; } }

$schoolYearsRes = $mysqli->query("SELECT id, year_label, is_current FROM school_years ORDER BY year_label DESC");
$schoolYears    = [];
if ($schoolYearsRes) { while ($sy = $schoolYearsRes->fetch_assoc()) { $schoolYears[] = $sy; } }

// Most Chosen Cluster — used by the 5th metric card
$mostChosenClusterResult = $mysqli->query("
    SELECT cl.name, COUNT(*) as count
    FROM recommendations r
    LEFT JOIN courses c ON r.course_id = c.id
    LEFT JOIN clusters cl ON c.cluster_id = cl.id
    WHERE cl.name IS NOT NULL
    GROUP BY cl.id, cl.name
    ORDER BY count DESC
    LIMIT 1
");
$mostChosenCluster = $mostChosenClusterResult->fetch_assoc()['name'] ?? 'N/A';

// Assessment Trends chart removed — insufficient historical data for a meaningful view
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ==================== REPORTS & ANALYTICS REDESIGN ==================== */
        
        /* Container Strict Containment */
        .dashboard-content {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            overflow-x: hidden !important;
        }
        .records-wrapper {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            overflow-x: hidden !important;
        }

        /* 0. Hero Header Card */
        .reports-page-hero {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.45) 0%, rgba(30, 41, 59, 0.65) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .reports-page-hero::before {
            content: '';
            position: absolute;
            top: -60%;
            left: -10%;
            width: 45%;
            height: 220%;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        .hero-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 0;
            flex: 1 1 300px;
        }
        .hero-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fbbf24, #d97706);
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3);
            flex-shrink: 0;
        }
        .hero-titles {
            min-width: 0;
        }
        .hero-titles h2 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.2rem 0;
            letter-spacing: -0.3px;
        }
        .hero-titles p {
            font-size: 0.84rem;
            color: #94a3b8;
            margin: 0;
            line-height: 1.4;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.28);
            color: #34d399;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.4rem 0.9rem;
            border-radius: 30px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .hero-badge .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* 1. Leading-Icon Metric KPI Cards */
        .reports-stats-cards-5 {
            display: grid !important;
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 1rem !important;
            margin-bottom: 1.5rem !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 1350px) {
            .reports-stats-cards-5 {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
        }
        @media (max-width: 900px) {
            .reports-stats-cards-5 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }
        @media (max-width: 576px) {
            .reports-stats-cards-5 {
                grid-template-columns: 1fr !important;
            }
        }
        .summary-stat-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.8) 100%) !important;
            backdrop-filter: blur(14px) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 14px !important;
            padding: 1.1rem 1.25rem !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            min-height: 118px !important;
            min-width: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
            position: relative !important;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
        }
        .summary-stat-card:hover {
            border-color: rgba(251, 191, 36, 0.35) !important;
            transform: translateY(-3px) !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1) !important;
        }
        .summary-card-header {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 0.5rem;
            min-width: 0 !important;
            width: 100% !important;
            overflow: hidden !important;
        }
        .summary-card-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .summary-stat-card:nth-child(1) .summary-card-icon {
            background: rgba(59, 130, 246, 0.16);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.32);
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.15);
        }
        .summary-stat-card:nth-child(2) .summary-card-icon {
            background: rgba(16, 185, 129, 0.16);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.32);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
        }
        .summary-stat-card:nth-child(3) .summary-card-icon {
            background: rgba(168, 85, 247, 0.16);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.32);
            box-shadow: 0 0 10px rgba(168, 85, 247, 0.15);
        }
        .summary-stat-card:nth-child(4) .summary-card-icon {
            background: rgba(245, 158, 11, 0.16);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.32);
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.15);
        }
        .summary-stat-card:nth-child(5) .summary-card-icon {
            background: rgba(6, 182, 212, 0.16);
            color: #22d3ee;
            border: 1px solid rgba(6, 182, 212, 0.32);
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.15);
        }
        .summary-card-label {
            font-size: 0.76rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            min-width: 0 !important;
            flex: 1;
        }
        .summary-card-value {
            font-size: 1.55rem !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            color: #ffffff !important;
            margin: 0.2rem 0 0.35rem 0 !important;
            white-space: normal !important;
            word-wrap: break-word !important;
            min-width: 0 !important;
            max-width: 100% !important;
            width: 100% !important;
            display: block !important;
            cursor: default;
            letter-spacing: -0.5px;
        }
        .summary-card-sub {
            font-size: 0.74rem;
            color: #64748b;
            display: block !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            min-width: 0 !important;
            max-width: 100% !important;
            width: 100% !important;
        }

        /* 2. Consolidated Responsive Horizontal Filter Bar (Strict Single-Row Layout) */
        .records-filter-bar-unified {
            display: flex;
            align-items: center;
            flex-wrap: nowrap !important;
            gap: 0.5rem;
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.55rem 0.75rem;
            margin-bottom: 1.5rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            overflow-x: auto;
            scrollbar-width: thin;
        }
        .rec-filter-item {
            position: relative;
            display: flex;
            align-items: center;
            flex: 1 1 0;
            min-width: 110px;
            box-sizing: border-box;
        }
        .rec-search-item {
            flex: 1.35 1 0;
            min-width: 140px;
        }
        .rec-filter-icon {
            position: absolute;
            left: 0.65rem;
            color: #94a3b8;
            font-size: 0.8rem;
            pointer-events: none;
            z-index: 2;
        }
        .rec-filter-select,
        .rec-filter-input {
            width: 100%;
            height: 38px;
            padding: 0 0.5rem 0 1.85rem;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 0.82rem;
            font-family: inherit;
            outline: none;
            box-sizing: border-box;
            transition: all 0.2s ease;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .rec-filter-select:focus,
        .rec-filter-input:focus {
            border-color: rgba(251, 191, 36, 0.5);
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
            background: rgba(15, 23, 42, 0.9);
        }
        .rec-filter-select option {
            background: #1e293b;
            color: #f8fafc;
        }
        .rec-filter-actions {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-shrink: 0;
            margin-left: 0.2rem;
            box-sizing: border-box;
        }
        .rec-filter-btn {
            height: 38px;
            padding: 0 0.8rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            border: none;
            box-sizing: border-box;
            flex-shrink: 0;
        }
        .rec-btn-reset {
            background: rgba(148, 163, 184, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.22);
            color: #cbd5e1;
        }
        .rec-btn-reset:hover {
            background: rgba(148, 163, 184, 0.22);
            color: #ffffff;
            border-color: rgba(148, 163, 184, 0.4);
        }
        .rec-btn-reset:hover i {
            transform: rotate(-90deg);
        }
        .rec-btn-reset i {
            transition: transform 0.3s ease;
        }
        .rec-btn-export {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
            color: #0f172a;
            font-weight: 700;
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.28);
        }
        .rec-btn-export:hover {
            background: linear-gradient(135deg, #fcd34d 0%, #fbbf24 50%, #f59e0b 100%);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.4);
            transform: translateY(-1px);
        }

        /* 3. Side-by-Side Equal-Width Charts */
        .records-charts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .records-charts-grid .chart-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6) 0%, rgba(15, 23, 42, 0.7) 100%);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            flex-direction: column;
            width: 100% !important;
            max-width: 100% !important;
            height: 350px !important;
            max-height: 350px !important;
            box-sizing: border-box;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            transition: border-color 0.25s ease;
        }
        .records-charts-grid .chart-card:hover {
            border-color: rgba(251, 191, 36, 0.25);
        }
        .records-charts-grid .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            flex-shrink: 0;
        }
        .records-charts-grid .chart-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .records-charts-grid .chart-body {
            position: relative !important;
            width: 100% !important;
            height: 260px !important;
            max-height: 260px !important;
            min-height: 0 !important;
            overflow: hidden !important;
            display: block !important;
            box-sizing: border-box !important;
        }
        .records-charts-grid .chart-body canvas {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            max-height: 260px !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 900px) {
            .records-charts-grid {
                grid-template-columns: 1fr;
            }
        }

        /* 4. Collapsible Panels: Student Records by School Year */
        .sy-records-section {
            margin-top: 1.75rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .sy-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.15rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .sy-section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin: 0;
        }
        .sy-total-badge {
            font-size: 0.82rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .sy-panels-loading {
            padding: 3rem;
            text-align: center;
            color: #fbbf24;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        .sy-panels-container {
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .sy-panel-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.55) 0%, rgba(15, 23, 42, 0.65) 100%);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            overflow: hidden;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }
        .sy-panel-card:hover {
            border-color: rgba(251, 191, 36, 0.25);
        }
        .sy-panel-card.open {
            border-color: rgba(251, 191, 36, 0.35);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.25);
        }
        .sy-panel-header {
            padding: 1.1rem 1.4rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            background: rgba(15, 23, 42, 0.4);
            transition: background 0.2s ease;
            box-sizing: border-box;
        }
        .sy-panel-header:hover {
            background: rgba(251, 191, 36, 0.05);
        }
        .sy-panel-card.open .sy-panel-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(15, 23, 42, 0.6);
        }
        .sy-header-left {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-wrap: wrap;
        }
        .sy-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .sy-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
            white-space: nowrap;
        }
        .sy-badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
        }
        .sy-badge-past {
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }
        .sy-badge-count {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .sy-header-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .sy-chevron {
            color: #94a3b8;
            font-size: 0.95rem;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease;
        }
        .sy-panel-card.open .sy-chevron {
            transform: rotate(180deg);
            color: #fbbf24;
        }
        .sy-panel-body {
            padding: 1.25rem 1.4rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow-x: auto;
        }
        .sy-empty-records {
            padding: 2.5rem 1rem;
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
        }
        .data-table.results-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .data-table.results-table th {
            background: rgba(15, 23, 42, 0.6);
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
        }
        .data-table.results-table td {
            padding: 0.95rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: #cbd5e1;
            font-size: 0.88rem;
            vertical-align: middle;
        }
        .data-table.results-table tr:hover td {
            background: rgba(251, 191, 36, 0.03);
        }
        .student-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .student-avatar-badge {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .student-cell-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .student-cell-name {
            font-weight: 600;
            color: #f8fafc;
        }
        .student-cell-id {
            font-size: 0.75rem;
            color: #64748b;
        }
        .strand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .strand-stem {
            background: rgba(6, 182, 212, 0.15);
            color: #22d3ee;
            border: 1px solid rgba(6, 182, 212, 0.3);
        }
        .strand-abm {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .strand-humss {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }
        .strand-gas {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .strand-tvl, .strand-tvlhe, .strand-tvlict {
            background: rgba(249, 115, 22, 0.15);
            color: #fb923c;
            border: 1px solid rgba(249, 115, 22, 0.3);
        }
        .strand-other {
            background: rgba(148, 163, 184, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.3);
        }
        .course-rec-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #f1f5f9;
            font-weight: 600;
        }
        .course-rec-pill i {
            color: #fbbf24;
            font-size: 0.85rem;
        }
        .score-display-box {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            min-width: 90px;
        }
        .score-val-text {
            font-size: 0.88rem;
            font-weight: 700;
            color: #f8fafc;
        }
        .score-progress-track {
            width: 100%;
            height: 5px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 3px;
            overflow: hidden;
        }
        .score-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #fbbf24, #10b981);
            border-radius: 3px;
            transition: width 0.4s ease;
        }
        .btn-action.view-details {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-action.view-details:hover {
            background: rgba(59, 130, 246, 0.25);
            color: #ffffff;
            border-color: rgba(59, 130, 246, 0.5);
            transform: scale(1.05);
        }
    </style>
</head>
<body data-user-logged-in="true">
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
                    <h1>Reports & Analytics</h1>
                </div>
                
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
                            <?php else: ?>
                                <i class="fa-solid <?php echo is_counselor() ? 'fa-user-tie' : 'fa-user-shield'; ?>"></i>
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
                
                <!-- ==================== REPORTS & ANALYTICS ==================== -->
                <div class="records-wrapper">

                    <!-- Hero Banner -->
                    <div class="reports-page-hero">
                        <div class="hero-left">
                            <div class="hero-icon">
                                <i class="fa-solid fa-chart-pie"></i>
                            </div>
                            <div class="hero-titles">
                                <h2>School-Wide Evaluation Analytics</h2>
                                <p>Longitudinal career assessment results, course preference distribution, and student records grouped by school year.</p>
                            </div>
                        </div>
                        <div class="hero-badge">
                            <span class="live-dot"></span> Real-Time Analytics
                        </div>
                    </div>

                    <!-- 5 Summary KPI Cards Grid (Leading-Icon Layout) -->
                    <div class="stats-cards reports-stats-cards-5">
                        <div class="stat-card summary-stat-card">
                            <div class="summary-card-header">
                                <span class="summary-card-icon"><i class="fa-solid fa-user-graduate"></i></span>
                                <span class="summary-card-label">Total Students Assessed</span>
                            </div>
                            <div class="summary-card-value" id="kpi-total-students">0</div>
                            <small class="summary-card-sub">Completed evaluation records</small>
                        </div>
                        <div class="stat-card summary-stat-card">
                            <div class="summary-card-header">
                                <span class="summary-card-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                                <span class="summary-card-label">Completed Assessments</span>
                            </div>
                            <div class="summary-card-value" id="kpi-total-completed">0</div>
                            <small class="summary-card-sub">Evaluations finalized</small>
                        </div>
                        <div class="stat-card summary-stat-card">
                            <div class="summary-card-header">
                                <span class="summary-card-icon"><i class="fa-solid fa-chart-line"></i></span>
                                <span class="summary-card-label">Average Match Score</span>
                            </div>
                            <div class="summary-card-value" id="kpi-avg-score-records">0%</div>
                            <small class="summary-card-sub">Overall match average</small>
                        </div>
                        <div class="stat-card summary-stat-card">
                            <div class="summary-card-header">
                                <span class="summary-card-icon"><i class="fa-solid fa-trophy"></i></span>
                                <span class="summary-card-label">Top Course</span>
                            </div>
                            <div class="summary-card-value" id="kpi-top-course-records" title="N/A">N/A</div>
                            <small class="summary-card-sub">Most recommended course</small>
                        </div>
                        <div class="stat-card summary-stat-card">
                            <div class="summary-card-header">
                                <span class="summary-card-icon"><i class="fa-solid fa-layer-group"></i></span>
                                <span class="summary-card-label">Most Chosen Cluster</span>
                            </div>
                            <div class="summary-card-value" id="kpi-top-cluster-unified" title="<?php echo htmlspecialchars($mostChosenCluster); ?>"><?php echo htmlspecialchars($mostChosenCluster); ?></div>
                            <small class="summary-card-sub">Top career preference</small>
                        </div>
                    </div>

                    <!-- Accuracy Analytics -->
                    <div class="stats-cards reports-stats-cards-5" style="margin-top: 1rem;">
                        <div class="stat-card summary-stat-card" style="border-color: rgba(59, 130, 246, 0.3);">
                            <div class="summary-card-header">
                                <span class="summary-card-icon" style="background: rgba(59,130,246,0.16); color:#60a5fa;"><i class="fa-solid fa-clipboard-user"></i></span>
                                <span class="summary-card-label">Evaluated Assessments</span>
                            </div>
                            <div class="summary-card-value" id="kpi-acc-assessments">0</div>
                            <small class="summary-card-sub">Total verified</small>
                        </div>
                        <div class="stat-card summary-stat-card" style="border-color: rgba(168, 85, 247, 0.3);">
                            <div class="summary-card-header">
                                <span class="summary-card-icon" style="background: rgba(168,85,247,0.16); color:#c084fc;"><i class="fa-solid fa-list-check"></i></span>
                                <span class="summary-card-label">Evaluated Recommendations</span>
                            </div>
                            <div class="summary-card-value" id="kpi-acc-evaluated">0</div>
                            <small class="summary-card-sub">Total evaluated</small>
                        </div>
                        <div class="stat-card summary-stat-card" style="border-color: rgba(16, 185, 129, 0.3);">
                            <div class="summary-card-header">
                                <span class="summary-card-icon" style="background: rgba(16,185,129,0.16); color:#34d399;"><i class="fa-solid fa-check-double"></i></span>
                                <span class="summary-card-label">Aligned Recommendations</span>
                            </div>
                            <div class="summary-card-value" id="kpi-acc-aligned">0</div>
                            <small class="summary-card-sub">Aligned with career goals</small>
                        </div>
                        <div class="stat-card summary-stat-card" style="border-color: rgba(239, 68, 68, 0.3);">
                            <div class="summary-card-header">
                                <span class="summary-card-icon" style="background: rgba(239,68,68,0.16); color:#f87171;"><i class="fa-solid fa-xmark"></i></span>
                                <span class="summary-card-label">Not Aligned</span>
                            </div>
                            <div class="summary-card-value" id="kpi-acc-not-aligned">0</div>
                            <small class="summary-card-sub">Recommendations altered</small>
                        </div>
                        <div class="stat-card summary-stat-card" style="border-color: rgba(245, 158, 11, 0.3);">
                            <div class="summary-card-header">
                                <span class="summary-card-icon" style="background: rgba(245,158,11,0.16); color:#fbbf24;"><i class="fa-solid fa-bullseye"></i></span>
                                <span class="summary-card-label">Overall Accuracy</span>
                            </div>
                            <div class="summary-card-value" id="kpi-acc-pct">0%</div>
                            <small class="summary-card-sub">System recommendation accuracy</small>
                        </div>
                    </div>

                    <!-- Consolidated Responsive Horizontal Filter Bar (Zero-Overflow Guarantee) -->
                    <div class="records-filter-bar-unified">
                        <!-- School Year -->
                        <div class="rec-filter-item">
                            <i class="fa-solid fa-calendar-days rec-filter-icon"></i>
                            <select id="rec-school-year" class="rec-filter-select">
                                <option value="0">All School Years</option>
                                <?php foreach ($schoolYears as $sy): ?>
                                <option value="<?php echo (int)$sy['id']; ?>"><?php echo htmlspecialchars($sy['year_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Search -->
                        <div class="rec-filter-item rec-search-item">
                            <i class="fa-solid fa-magnifying-glass rec-filter-icon"></i>
                            <input type="text" id="rec-search" class="rec-filter-input" placeholder="Search student name or ID...">
                        </div>

                        <!-- Strand -->
                        <div class="rec-filter-item">
                            <i class="fa-solid fa-layer-group rec-filter-icon"></i>
                            <select id="rec-strand" class="rec-filter-select">
                                <option value="0">All Strands</option>
                                <?php foreach ($strands as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Student Status -->
                        <div class="rec-filter-item">
                            <i class="fa-solid fa-user-check rec-filter-icon"></i>
                            <select id="rec-student-status" class="rec-filter-select">
                                <option value="active" selected>Active Enrolled</option>
                                <option value="graduated">Graduated Alumni</option>
                                <option value="all">All Students</option>
                            </select>
                        </div>

                        <!-- Actions Group (Reset & Export PDF) -->
                        <div class="rec-filter-actions">
                            <button id="rec-reset-btn" class="rec-filter-btn rec-btn-reset" title="Reset All Filters">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                            <button class="rec-filter-btn rec-btn-export" id="btnExportAllPdf" title="Export assessment records to PDF">
                                <i class="fa-solid fa-file-pdf"></i> Export PDF
                            </button>
                        </div>
                    </div>

                    <!-- 2 Interactive Charts Grid (Side-by-Side Equal Width) -->
                    <div class="records-charts-grid" id="recordsChartsGrid">
                        <!-- Chart 1: Most Recommended Courses -->
                        <div class="chart-card" id="chartTopCoursesCard">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-pie" style="color: #fbbf24;"></i> Most Recommended Courses</h3>
                            </div>
                            <div class="chart-body">
                                <canvas id="chartTopCourses"></canvas>
                            </div>
                        </div>

                        <!-- Chart 2: Strand Distribution -->
                        <div class="chart-card" id="chartStrandDistCard">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-pie" style="color: #38bdf8;"></i> Strand Distribution</h3>
                            </div>
                            <div class="chart-body">
                                <canvas id="chartStrandDist"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Student Records by School Year (Collapsible Accordion Panels) -->
                    <div class="sy-records-section" id="syRecordsSection">
                        <div class="sy-section-header">
                            <h3 class="sy-section-title">
                                <i class="fa-solid fa-folder-tree" style="color: #fbbf24;"></i>
                                Student Records by School Year
                            </h3>
                            <span class="sy-total-badge" id="rec-total-count-badge">
                                <i class="fa-solid fa-database"></i> 0 Total Records
                            </span>
                        </div>

                        <!-- Loading State -->
                        <div id="rec-panels-loading" class="sy-panels-loading" style="display: flex;">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <span>Loading school year records...</span>
                        </div>

                        <!-- Collapsible Panels Container -->
                        <div id="syPanelsContainer" class="sy-panels-container">
                            <!-- Populated dynamically via recRenderSchoolYearPanels() -->
                        </div>
                    </div>

                </div> <!-- End Unified Reports & Analytics -->

            </div>
        <?php include 'includes/assessment_details_modal.php'; ?>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <script src="admin.js"></script>
    <script>
    // ==================== REPORTS & ANALYTICS MODULE ====================

    document.addEventListener('DOMContentLoaded', function() {

        // ==================== RECORDS STATE & FETCHING ====================
        let recState = {
            strand_id:     0,
            school_year_id:0,
            status_filter: 'active',
            search_term:   '',
            sort_by:       'completed_at',
            sort_order:    'DESC',
            page:          1,
            hasLoaded:     false,
            isLoading:     false
        };

        // Chart instances
        let chartCoursesInst = null;
        let chartStrandInst  = null;

        async function recFetchRecords() {
            if (recState.isLoading) return;
            recState.isLoading = true;

            const loadingEl = document.getElementById('rec-panels-loading');
            if (loadingEl) loadingEl.style.display = 'flex';

            try {
                const fd = new FormData();
                fd.append('action',         'get_historical_records');
                fd.append('strand_id',      recState.strand_id);
                fd.append('school_year_id', recState.school_year_id);
                fd.append('status_filter',  recState.status_filter);
                fd.append('search_term',    recState.search_term);
                fd.append('sort_by',        recState.sort_by);
                fd.append('sort_order',     recState.sort_order);
                fd.append('page',           recState.page);

                const res  = await fetch('reports.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    recState.hasLoaded = true;
                    recRenderStats(data.summary_stats);
                    recRenderCharts(data.charts);
                    recRenderSchoolYearPanels(data.records || [], data.school_years || []);
                }
            } catch (e) {
                console.error('Historical records fetch error:', e);
            }

            recState.isLoading = false;
            if (loadingEl) loadingEl.style.display = 'none';
        }

        // Auto-load on page open
        recFetchRecords();

        // Render 5 Summary Cards
        function recRenderStats(stats) {
            if (!stats) return;
            const elStudents  = document.getElementById('kpi-total-students');
            const elCompleted = document.getElementById('kpi-total-completed');
            const elAvgScore  = document.getElementById('kpi-avg-score-records');
            const elTopCourse = document.getElementById('kpi-top-course-records');

            if (elStudents)  elStudents.textContent  = (stats.total_students || 0).toLocaleString();
            if (elCompleted) elCompleted.textContent = (stats.total_completed || 0).toLocaleString();
            if (elAvgScore)  elAvgScore.textContent  = stats.avg_score || '0%';
            if (elTopCourse) {
                const topCourse = stats.most_recommended_course || 'N/A';
                elTopCourse.textContent = topCourse;
                elTopCourse.title = topCourse;
            }
            
            const elAccAssessments = document.getElementById('kpi-acc-assessments');
            const elAccEvaluated   = document.getElementById('kpi-acc-evaluated');
            const elAccAligned     = document.getElementById('kpi-acc-aligned');
            const elAccNotAligned  = document.getElementById('kpi-acc-not-aligned');
            const elAccPct         = document.getElementById('kpi-acc-pct');

            if (elAccAssessments) elAccAssessments.textContent = (stats.accuracy_assessments || 0).toLocaleString();
            if (elAccEvaluated)   elAccEvaluated.textContent   = (stats.accuracy_evaluated || 0).toLocaleString();
            if (elAccAligned)     elAccAligned.textContent     = (stats.accuracy_aligned || 0).toLocaleString();
            if (elAccNotAligned)  elAccNotAligned.textContent  = (stats.accuracy_not_aligned || 0).toLocaleString();
            if (elAccPct)         elAccPct.textContent         = stats.accuracy_pct || '0%';
        }

        // Helper: HTML Escaping
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.toString().replace(/[&<>"']/g, m => map[m]);
        }

        // Render Collapsible School Year Panels
        function recRenderSchoolYearPanels(records, schoolYears) {
            const container = document.getElementById('syPanelsContainer');
            const totalCountBadge = document.getElementById('rec-total-count-badge');
            if (!container) return;

            if (totalCountBadge) {
                totalCountBadge.innerHTML = `<i class="fa-solid fa-database"></i> ${records.length} ${records.length === 1 ? 'Total Record' : 'Total Records'}`;
            }

            // Determine which school years to display
            let yearsToDisplay = [];
            if (recState.school_year_id > 0) {
                const found = schoolYears.find(sy => parseInt(sy.id, 10) === recState.school_year_id);
                if (found) {
                    yearsToDisplay = [found];
                } else {
                    const selectEl = document.getElementById('rec-school-year');
                    const text = selectEl ? selectEl.options[selectEl.selectedIndex].text : 'Selected S.Y.';
                    yearsToDisplay = [{ id: recState.school_year_id, year_label: text, is_current: 0 }];
                }
            } else {
                // "All School Years": only include school years that actually contain matching records
                yearsToDisplay = schoolYears.filter(sy => {
                    return records.some(r => {
                        if (!r.school_year || r.school_year === 'N/A') {
                            return sy.year_label === 'Unassigned / Legacy' || sy.year_label === 'N/A';
                        }
                        return r.school_year === sy.year_label;
                    });
                });

                // Also include any records with unassigned/legacy labels not in schoolYears
                const knownLabels = new Set(yearsToDisplay.map(sy => sy.year_label));
                records.forEach(r => {
                    const yLabel = r.school_year || 'Unassigned / Legacy';
                    if (!knownLabels.has(yLabel)) {
                        knownLabels.add(yLabel);
                        yearsToDisplay.push({ id: 0, year_label: yLabel, is_current: 0 });
                    }
                });
            }

            if (yearsToDisplay.length === 0) {
                container.innerHTML = `
                    <div class="sy-panel-card">
                        <div class="sy-empty-records">
                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; color: #475569; margin-bottom: 0.75rem; display: block;"></i>
                            <p style="margin: 0; color: #94a3b8;">No assessment records found matching the selected filters.</p>
                        </div>
                    </div>
                `;
                return;
            }

            let panelsHtml = '';

            yearsToDisplay.forEach((sy, idx) => {
                const isActive = (parseInt(sy.is_current, 10) === 1) || (idx === 0 && yearsToDisplay.every(y => parseInt(y.is_current, 10) !== 1));
                const isOpen = (recState.school_year_id > 0) ? true : isActive;

                const yearRecords = records.filter(r => {
                    if (!r.school_year || r.school_year === 'N/A') {
                        return sy.year_label === 'Unassigned / Legacy' || sy.year_label === 'N/A';
                    }
                    return r.school_year === sy.year_label;
                });

                let rowsHtml = '';
                if (yearRecords.length > 0) {
                    rowsHtml = yearRecords.map(r => {
                        const scoreVal = (r.total_score != null && r.total_score !== '') ? parseFloat(r.total_score) : null;
                        const scoreHtml = scoreVal !== null
                            ? `<div class="score-display-box"><span class="score-val-text">${scoreVal.toFixed(1)}%</span><div class="score-progress-track"><div class="score-progress-bar" style="width:${Math.min(scoreVal, 100)}%"></div></div></div>`
                            : `<span style="color:#64748b;">—</span>`;
                        
                        const strandCode = escapeHtml(r.strand_code || r.strand_name || 'N/A');
                        const strandClass = (r.strand_code || r.strand_name || 'other').toLowerCase().replace(/[^a-z0-9]/g, '');
                        const topCourse = escapeHtml(r.top_course || '—');
                        const studentName = escapeHtml(r.student_name || '—');
                        const schoolId = escapeHtml(r.school_id || '');
                        const dateStr = r.formatted_date || (r.completed_at ? new Date(r.completed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
                        
                        const isGrad = (r.student_status === 'graduated');
                        const gradBadge = isGrad ? '<span class="suggestion-status-badge graduated" style="font-size:0.75rem; padding:2px 8px; margin-left:6px;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>' : '';
                        const prevIdHtml = (r.grade11_id && r.grade11_id !== r.school_id)
                            ? ` <span class="prev-id-tag" style="display:inline-flex; align-items:center; gap:3px; background:rgba(251,191,36,0.12); color:#fbbf24; border:1px solid rgba(251,191,36,0.25); border-radius:4px; padding:1px 5px; font-size:0.72rem; font-weight:600;" title="Grade 11 ID: ${escapeHtml(r.grade11_id)}"><i class="fa-solid fa-clock-rotate-left" style="font-size:0.65rem;"></i> G11: ${escapeHtml(r.grade11_id)}</span>`
                            : '';

                        return `
                            <tr data-id="${r.assessment_id}">
                                <td>
                                    <div class="student-cell-info">
                                        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:0.35rem;">
                                            <span class="student-cell-name">${studentName}</span>
                                            ${gradBadge}
                                        </div>
                                        ${schoolId ? `<div style="display:flex; align-items:center; gap:0.4rem; margin-top:2px; flex-wrap:wrap;"><span class="student-cell-id">${schoolId}</span>${prevIdHtml}</div>` : ''}
                                    </div>
                                </td>
                                <td>
                                    <span class="strand-badge strand-${strandClass}">${strandCode}</span>
                                </td>
                                <td style="max-width: 260px; line-height: 1.35;">
                                    <div class="course-rec-pill" title="${topCourse}">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                        <span>${topCourse}</span>
                                    </div>
                                </td>
                                <td>${scoreHtml}</td>
                                <td style="white-space: nowrap; font-size: 0.82rem; color: #94a3b8;">
                                    <i class="fa-regular fa-calendar" style="margin-right: 4px; color: #64748b;"></i> ${dateStr}
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn-action view-details" data-id="${r.assessment_id}" title="View Assessment Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }

                panelsHtml += `
                    <div class="sy-panel-card ${isOpen ? 'open' : ''}" data-year-label="${escapeHtml(sy.year_label)}">
                        <div class="sy-panel-header" onclick="toggleSyAccordion(this)">
                            <div class="sy-header-left">
                                <span class="sy-title"><i class="fa-solid fa-calendar-days" style="color: #fbbf24;"></i> S.Y. ${escapeHtml(sy.year_label)}</span>
                                ${isActive 
                                    ? '<span class="sy-badge sy-badge-active"><i class="fa-solid fa-circle-check"></i> Current School Year</span>' 
                                    : '<span class="sy-badge sy-badge-past"><i class="fa-solid fa-clock-rotate-left"></i> Past School Year</span>'}
                                <span class="sy-badge sy-badge-count">${yearRecords.length} ${yearRecords.length === 1 ? 'Record' : 'Records'}</span>
                            </div>
                            <div class="sy-header-right">
                                <i class="fa-solid fa-chevron-down sy-chevron"></i>
                            </div>
                        </div>
                        <div class="sy-panel-body" style="${isOpen ? 'display: block;' : 'display: none;'}">
                            ${yearRecords.length > 0 ? `
                                <div class="table-responsive">
                                    <table class="data-table results-table">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <th>Strand</th>
                                                <th>Top Recommended Course</th>
                                                <th>Match Score</th>
                                                <th>Date Completed</th>
                                                <th style="text-align: center;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${rowsHtml}
                                        </tbody>
                                    </table>
                                </div>
                            ` : `
                                <div class="sy-empty-records">
                                    <i class="fa-solid fa-inbox" style="font-size: 1.6rem; color: #475569; margin-bottom: 0.5rem; display: block;"></i>
                                    <p style="margin: 0;">No assessment records found for S.Y. ${escapeHtml(sy.year_label)}.</p>
                                </div>
                            `}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = panelsHtml;

            // Attach view-details click listeners
            container.querySelectorAll('.view-details').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const assessmentId = this.getAttribute('data-id');
                    if (assessmentId) openAssessmentDetails(assessmentId);
                });
            });
        }

        // Accordion Toggle function
        window.toggleSyAccordion = function(headerEl) {
            const panelCard = headerEl.closest('.sy-panel-card');
            if (!panelCard) return;
            const panelBody = panelCard.querySelector('.sy-panel-body');
            const isOpen = panelCard.classList.contains('open');

            if (isOpen) {
                panelCard.classList.remove('open');
                if (panelBody) panelBody.style.display = 'none';
            } else {
                panelCard.classList.add('open');
                if (panelBody) panelBody.style.display = 'block';
            }
        };

        // Shared Assessment Details Modal Controller
        const viewDetailsModal = document.getElementById('viewDetailsModal');
        const closeDetailsModal = document.getElementById('closeDetailsModal');
        const closeDetailsBtn = document.getElementById('closeDetailsBtn');

        function closeAssessmentModal() {
            if (viewDetailsModal) viewDetailsModal.classList.remove('active');
        }

        if (closeDetailsModal) closeDetailsModal.addEventListener('click', closeAssessmentModal);
        if (closeDetailsBtn) closeDetailsBtn.addEventListener('click', closeAssessmentModal);
        if (viewDetailsModal) {
            const overlay = viewDetailsModal.querySelector('.modal-overlay');
            if (overlay) overlay.addEventListener('click', closeAssessmentModal);
        }

        async function openAssessmentDetails(assessmentId) {
            if (!viewDetailsModal) return;
            try {
                const fd = new FormData();
                fd.append('action', 'get_assessment_details');
                fd.append('assessment_id', assessmentId);

                const res = await fetch('reports.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success && data.assessment) {
                    const assessment = data.assessment;
                    const categoryScores = data.category_scores || [];
                    const recommendations = data.recommendations || [];

                    // Avatar Initials
                    const firstInit = (assessment.first_name || 'S').charAt(0).toUpperCase();
                    const lastInit = (assessment.last_name || '').charAt(0).toUpperCase();
                    const middleInit = assessment.middle_name ? assessment.middle_name.trim().charAt(0).toUpperCase() + '.' : '';
                    const fullName = [assessment.first_name, middleInit, assessment.last_name, assessment.suffix].filter(Boolean).join(' ') || 'Student';

                    const avatarEl = document.getElementById('detailAvatar');
                    if (avatarEl) avatarEl.textContent = firstInit + lastInit;

                    // Student Name & LRN
                    const nameEl = document.getElementById('detailStudentName');
                    if (nameEl) nameEl.textContent = fullName;
                    const lrnEl = document.getElementById('detailLrn');
                    if (lrnEl) {
                        let idText = assessment.student_id || 'Not Assigned';
                        if (assessment.grade11_id && assessment.grade11_id !== assessment.student_id) {
                            idText += ` (Grade 11 ID: ${assessment.grade11_id})`;
                        }
                        lrnEl.textContent = idText;
                    }

                    // Grade Level & Strand
                    const gradeText = assessment.grade_level ? (assessment.grade_level.includes('Grade') ? assessment.grade_level : 'Grade ' + assessment.grade_level) : 'Grade 11';
                    const gradeEl = document.getElementById('detailGradeLevel');
                    if (gradeEl) gradeEl.textContent = gradeText;

                    const strandText = assessment.strand_name ? (assessment.strand_name + (assessment.strand_code ? ' (' + assessment.strand_code.toUpperCase() + ')' : '')) : 'N/A';
                    const strandEl = document.getElementById('detailStrand');
                    if (strandEl) strandEl.textContent = strandText;

                    // Status Badge
                    const isCompleted = (assessment.status === 'completed');
                    const statusPill = document.getElementById('detailStatusPill');
                    if (statusPill) {
                        statusPill.className = 'shb-pill stat-badge ' + (isCompleted ? 'completed' : 'in-progress');
                        statusPill.innerHTML = isCompleted 
                            ? '<i class="fa-solid fa-circle-check"></i> <span id="detailStatusText">Completed</span>'
                            : '<i class="fa-solid fa-clock"></i> <span id="detailStatusText">' + (assessment.status || 'In Progress') + '</span>';
                    }

                    // Score
                    const scoreVal = (assessment.total_score != null ? parseFloat(assessment.total_score).toFixed(1) : '0.0') + '%';
                    const scoreEl = document.getElementById('detailScore');
                    if (scoreEl) scoreEl.textContent = scoreVal;

                    // Top Course & Cluster
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

                    // Date & Time Completed
                    const dateObj = assessment.completed_at ? new Date(assessment.completed_at) : (assessment.created_at ? new Date(assessment.created_at) : null);
                    const dateEl = document.getElementById('detailDate');
                    const timeEl = document.getElementById('detailTime');
                    if (dateObj && !isNaN(dateObj.getTime())) {
                        if (dateEl) dateEl.textContent = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                        if (timeEl) timeEl.textContent = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    } else {
                        if (dateEl) dateEl.textContent = '—';
                        if (timeEl) timeEl.textContent = '—';
                    }

                    // Category Score Breakdown
                    const breakdownList = document.getElementById('breakdownList');
                    if (breakdownList) {
                        const categoryConfigs = {
                            career:      { fill: 'linear-gradient(90deg, #f59e0b, #fb923c)', label: 'Career Interest', icon: 'fa-briefcase' },
                            personality: { fill: 'linear-gradient(90deg, #8b5cf6, #a78bfa)', label: 'Personality & Traits', icon: 'fa-brain' },
                            skills:      { fill: 'linear-gradient(90deg, #10b981, #34d399)', label: 'Skills & Strengths', icon: 'fa-bolt' },
                            strand:      { fill: 'linear-gradient(90deg, #3b82f6, #60a5fa)', label: 'Strand Alignment', icon: 'fa-compass' },
                        };

                        if (categoryScores.length > 0) {
                            breakdownList.innerHTML = categoryScores.map(score => {
                                const key = (score.category || '').toLowerCase();
                                const cfg = categoryConfigs[key] || { fill: 'linear-gradient(90deg, #64748b, #94a3b8)', label: score.category, icon: 'fa-chart-pie' };
                                const percentage = score.percentage != null
                                    ? Math.min(100, Math.round(parseFloat(score.percentage)))
                                    : Math.min(100, Math.round(parseFloat(score.score || 0)));
                                return `
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
                            }).join('');
                        } else {
                            breakdownList.innerHTML = '<p style="color:#64748b;font-size:0.875rem;">No category scores recorded for this assessment.</p>';
                        }
                    }

                    // Ranked Recommendations
                    const coursesWrap = document.getElementById('detailCoursesWrap');
                    const countBadge = document.getElementById('detailCourseCountBadge');
                    if (coursesWrap) {
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
                    }

                    viewDetailsModal.classList.add('active');
                } else {
                    alert(data.message || 'Failed to load assessment details');
                }
            } catch (err) {
                console.error('Error fetching assessment details:', err);
                alert('Error loading assessment details');
            }
        }

        // Render 2 Charts (Auto-hide cards when no data)
        function recRenderCharts(charts) {
            if (!charts) return;

            const cardCourses = document.getElementById('chartTopCoursesCard');
            const cardStrand  = document.getElementById('chartStrandDistCard');
            const grid        = document.getElementById('recordsChartsGrid');

            const hasCoursesData = charts.top_courses && charts.top_courses.data && charts.top_courses.data.length > 0 && charts.top_courses.data.some(val => val > 0);
            const hasStrandData  = charts.strands && charts.strands.data && charts.strands.data.length > 0 && charts.strands.data.some(val => val > 0);

            if (cardCourses) cardCourses.style.display = hasCoursesData ? 'flex' : 'none';
            if (cardStrand)  cardStrand.style.display  = hasStrandData ? 'flex' : 'none';

            if (grid) {
                if (hasCoursesData && hasStrandData) {
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
                } else if (hasCoursesData || hasStrandData) {
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = '1fr';
                } else {
                    grid.style.display = 'none';
                }
            }

            // 1. Most Recommended Courses (Pie Chart)
            if (hasCoursesData) {
                const ctxCourses = document.getElementById('chartTopCourses');
                if (ctxCourses) {
                    if (chartCoursesInst) {
                        chartCoursesInst.destroy();
                        chartCoursesInst = null;
                    }
                    ctxCourses.removeAttribute('style');
                    ctxCourses.removeAttribute('width');
                    ctxCourses.removeAttribute('height');

                    chartCoursesInst = new Chart(ctxCourses, {
                        type: 'pie',
                        data: {
                            labels: charts.top_courses.labels,
                            datasets: [{
                                data: charts.top_courses.data,
                                backgroundColor: ['#fbbf24', '#3b82f6', '#10b981', '#a855f7', '#ec4899'],
                                borderWidth: 2,
                                borderColor: '#1e293b'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        color: '#94a3b8',
                                        boxWidth: 12,
                                        font: { size: 11 }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            // 2. Strand Distribution (Doughnut Chart)
            if (hasStrandData) {
                const ctxStrand = document.getElementById('chartStrandDist');
                if (ctxStrand) {
                    if (chartStrandInst) {
                        chartStrandInst.destroy();
                        chartStrandInst = null;
                    }
                    ctxStrand.removeAttribute('style');
                    ctxStrand.removeAttribute('width');
                    ctxStrand.removeAttribute('height');

                    chartStrandInst = new Chart(ctxStrand, {
                        type: 'doughnut',
                        data: {
                            labels: charts.strands.labels,
                            datasets: [{
                                data: charts.strands.data,
                                backgroundColor: ['#06b6d4', '#10b981', '#fbbf24', '#64748b', '#ec4899', '#8b5cf6'],
                                borderWidth: 2,
                                borderColor: '#1e293b'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        color: '#94a3b8',
                                        boxWidth: 12,
                                        font: { size: 11 }
                                    }
                                }
                            }
                        }
                    });
                }
            }
        }

        // Filter Event Listeners
        ['rec-school-year', 'rec-strand', 'rec-student-status'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function() {
                    recState.school_year_id = parseInt(document.getElementById('rec-school-year')?.value || 0, 10);
                    recState.strand_id      = parseInt(document.getElementById('rec-strand')?.value || 0, 10);
                    recState.status_filter  = document.getElementById('rec-student-status')?.value || 'active';
                    recState.page           = 1;
                    recFetchRecords();
                });
            }
        });

        let recSearchTimeout = null;
        const searchInput = document.getElementById('rec-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(recSearchTimeout);
                recSearchTimeout = setTimeout(() => {
                    recState.search_term = this.value;
                    recState.page = 1;
                    recFetchRecords();
                }, 300);
            });
        }

        document.getElementById('rec-reset-btn').addEventListener('click', function() {
            document.getElementById('rec-school-year').value = '0';
            document.getElementById('rec-strand').value      = '0';
            if (document.getElementById('rec-student-status')) document.getElementById('rec-student-status').value = 'active';
            if (document.getElementById('rec-search')) document.getElementById('rec-search').value = '';

            recState.strand_id     = 0;
            recState.school_year_id= 0;
            recState.status_filter = 'active';
            recState.search_term   = '';
            recState.sort_by       = 'completed_at';
            recState.sort_order    = 'DESC';
            recState.page          = 1;

            recFetchRecords();
        });

        // Export PDF Handler (Includes Active Filters)
        document.getElementById('btnExportAllPdf').addEventListener('click', function() {
            let url = 'api/export_pdf.php?type=assessment_results';
            if (recState.school_year_id > 0) url += '&year_id=' + recState.school_year_id;
            if (recState.strand_id > 0) url += '&strand_id=' + recState.strand_id;
            if (recState.status_filter) url += '&status_filter=' + encodeURIComponent(recState.status_filter);
            if (recState.search_term) url += '&search=' + encodeURIComponent(recState.search_term);
            window.location.href = url;
        });
    });

    // Notification dropdown handlers
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function() {
        document.getElementById('notificationDropdown').style.display = 'none';
    });
    document.getElementById('notificationDropdown').addEventListener('click', function(e) {
        e.stopPropagation();
    });

    function markAllRead(e) {
        e.preventDefault(); e.stopPropagation();
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read&caller_type=admin'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => item.classList.remove('unread'));
                const badge = document.getElementById('notificationBadge'); if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
                document.querySelector('.mark-all-read')?.remove();
            }
        });
    }

    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            if (this.classList.contains('unread')) {
                const notifId = this.dataset.id;
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_read&id=' + notifId + '&caller_type=admin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.classList.remove('unread');
                        const badge = document.getElementById('notificationBadge');
                        if (badge) {
                            const current = parseInt(badge.textContent) || 0;
                            if (current > 1) {
                                badge.textContent = current - 1;
                            } else {
                                badge.style.display = 'none';
                                badge.textContent = '0';
                            }
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
