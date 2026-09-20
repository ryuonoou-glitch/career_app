<?php
// Counselor Assessment Results Page - With Backend

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration and database
require_once 'system_config.php';
require_once 'config.php';

// Check if user is logged in (admin or counselor)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    header('Location: login.php');
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'get_assessment_details':
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            if ($assessmentId <= 0) {
                $response['message'] = 'Invalid assessment ID';
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

                // Check Low Confidence / Broad Interests
                $isLowConfidence = false;
                $confidenceMessage = null;
                if (!empty($recommendations)) {
                    $topScore = (float)($recommendations[0]['match_percentage'] ?? 0);
                    $secondScore = isset($recommendations[1]) ? (float)($recommendations[1]['match_percentage'] ?? 0) : 0;
                    if ($topScore < 50.0) {
                        $isLowConfidence = true;
                        $confidenceMessage = "Top recommended program match is below 50%. This student may benefit from guidance counseling to explore and align their strengths.";
                    } elseif (($topScore - $secondScore) < 5.0 && count($recommendations) > 1) {
                        $isLowConfidence = true;
                        $confidenceMessage = "Multiple close options detected — the student's top degree matches are within 5% of each other. This indicates broad, balanced interests across multiple pathways rather than one dominant career direction.";
                    }
                }
                
                $response['success'] = true;
                $response['assessment'] = $assessment;
                $response['category_scores'] = $categoryScores;
                $response['recommendations'] = $recommendations;
                $response['is_low_confidence'] = $isLowConfidence;
                $response['confidence_message'] = $confidenceMessage;
            } else {
                $response['message'] = 'Assessment not found';
            }
            echo json_encode($response);
            exit;
    }
}

// School-wide Guidance Counselor — no per-strand scoping

// Load all strands for the filter dropdown
$allStrands = [];
$strandsResult = $mysqli->query("SELECT id, name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY grade_level, name");
if ($strandsResult) {
    while ($row = $strandsResult->fetch_assoc()) {
        $allStrands[] = $row;
    }
}

// Get all assessments (completed & abandoned) with student details, strand, and attempt numbers
$assessmentResults = [];
$result = $mysqli->query("
    SELECT
        sa.id,
        sa.student_id  AS student_db_id,
        sa.status,
        sa.created_at  AS started_at,
        sa.completed_at,
        sa.total_score,
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
    ORDER BY sa.created_at DESC
    LIMIT 100
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $topRec = null;
        $isLowConfidence = false;
        
        if ($row['status'] === 'completed') {
            $recStmt = $mysqli->prepare("
                SELECT c.course_name, cl.name AS cluster_name, r.match_percentage
                FROM recommendations r
                LEFT JOIN courses  c  ON r.course_id   = c.id
                LEFT JOIN clusters cl ON c.cluster_id  = cl.id
                WHERE r.assessment_id = ?
                ORDER BY r.match_percentage DESC, r.rank ASC
                LIMIT 2
            ");
            $recStmt->bind_param('i', $row['id']);
            $recStmt->execute();
            $recs = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $recStmt->close();
            
            if (!empty($recs)) {
                $topRec = $recs[0];
                if ((float)$recs[0]['match_percentage'] < 50.0) {
                    $isLowConfidence = true;
                } elseif (count($recs) > 1 && ((float)$recs[0]['match_percentage'] - (float)$recs[1]['match_percentage']) < 5.0) {
                    $isLowConfidence = true;
                }
            }
        }

        $assessmentResults[] = [
            'id'                => $row['id'],
            'student_id'        => $row['student_db_id'],
            'student_name'      => $row['student_name'] ?: 'Student',
            'lrn'               => $row['lrn'],
            'strand'            => $row['strand_name'] ?? 'N/A',
            'strand_code'       => $row['strand_code']  ?? '',
            'status'            => $row['status'],
            'attempt_number'    => (int)$row['attempt_number'],
            'top_category'      => $topRec['cluster_name'] ?? '—',
            'top_course'        => $topRec['course_name']  ?? '—',
            'is_low_confidence' => $isLowConfidence,
            'score'             => $row['total_score'] !== null ? round((float)$row['total_score'], 1) : null,
            'date'              => $row['status'] === 'completed'
                                        ? $row['completed_at']
                                        : $row['started_at'],
            'date_label'        => $row['status'] === 'completed' ? 'Completed' : 'Started',
        ];
    }
}

$totalResults = count($assessmentResults);

// Calculate Summary Cards
$totalCompleted = 0;
$avgMatch = 0;
$highestMatch = 0;
$totalMatches = 0;

foreach ($assessmentResults as $result) {
    if ($result['status'] === 'completed') {
        $totalCompleted++;
        if ($result['score'] !== null && $result['score'] > 0) {
            $totalMatches += $result['score'];
            if ($result['score'] > $highestMatch) {
                $highestMatch = $result['score'];
            }
        }
    }
}

if ($totalCompleted > 0) {
    $avgMatch = round($totalMatches / $totalCompleted, 1);
}

// Get counselor/admin name
$userName = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_name'] : $_SESSION['admin_name'] ?? 'Guidance Counselor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results - Guidance Counselor - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
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

        /* Modal header custom */
        .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
            text-align: center;
        }
        .modal-header-custom .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        .modal-header-custom .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .modal-header-custom .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .modal-header-custom .header-titles h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            padding: 0;
            border: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .modal-header-custom .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        .modal-header-custom .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }

        /* Low Confidence / Broad Interests Alert Banner */
        .rc-confidence-banner {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 1rem 1.25rem;
        }
        .rc-confidence-banner i {
            color: #fbbf24;
            font-size: 1.3rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .rc-confidence-banner h4 {
            margin: 0 0 4px;
            color: #f8fafc;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .rc-confidence-banner p {
            margin: 0;
            color: #cbd5e1;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        /* ── Results Table Styling (Matching Assessment Results Design) ── */
        .table-section {
            margin-top: 1.5rem;
            margin-bottom: 2rem;
            width: 100%;
        }
        .table-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            overflow: hidden;
            padding: 0 !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            width: 100%;
            box-sizing: border-box;
        }
        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            margin-bottom: 0;
        }
        .table-header h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .table-header h3 i {
            color: #fbbf24;
            font-size: 1.1rem;
        }
        .table-responsive {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .results-table th {
            text-align: left;
            padding: 1rem 1.25rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(15, 23, 42, 0.4);
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            white-space: nowrap;
        }
        .results-table td {
            padding: 1rem 1.25rem;
            font-size: 0.88rem;
            color: #e2e8f0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.06);
            vertical-align: middle;
        }
        .results-table tbody tr:hover {
            background: rgba(251, 191, 36, 0.03);
        }
        .results-table tbody tr:last-child td {
            border-bottom: none;
        }

        .results-table .student-name {
            white-space: nowrap;
            font-weight: 600;
            color: #f8fafc;
            font-size: 0.9rem;
        }
        .results-table .top-category-cell {
            line-height: 1.4;
            font-size: 0.88rem;
            color: #e2e8f0;
            font-weight: 500;
        }
        .results-table .date-cell {
            white-space: nowrap;
            font-size: 0.85rem;
            color: #cbd5e1;
        }

        .results-table .score-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fbbf24;
            white-space: nowrap;
        }

        .results-table .strand-badge {
            display: inline-block;
            padding: 0.28rem 0.85rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
            background: rgba(245, 158, 11, 0.12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        .results-table .strand-badge.strand-acadpro {
            background: rgba(245, 158, 11, 0.12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        .results-table .strand-badge.strand-stem {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.35);
        }
        .results-table .strand-badge.strand-abm {
            background: rgba(6, 182, 212, 0.15);
            color: #22d3ee;
            border: 1px solid rgba(6, 182, 212, 0.35);
        }
        .results-table .strand-badge.strand-humss {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }
        .results-table .strand-badge.strand-tvl {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        .results-table .strand-badge.strand-gas {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.35);
        }

        .results-table .attempt-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            font-weight: 700;
            font-size: 0.8rem;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .results-table .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .results-table .status-pill.status-completed {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .results-table .status-pill.status-abandoned {
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.25);
        }
        .results-table .status-pill.status-in_progress {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .results-table .btn-action {
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 8px;
            border: 1px solid rgba(59, 130, 246, 0.4);
            background: rgba(30, 58, 138, 0.2);
            color: #60a5fa;
            font-size: 0.9rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .results-table .btn-action:hover {
            background: rgba(59, 130, 246, 0.3);
            border-color: #60a5fa;
            color: #93c5fd;
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <!-- Dashboard Container -->
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
                    <h1>Assessment Results</h1>
                </div>
                <div class="top-bar-actions">
                    <?php if (isset($_SESSION['counselor_id'])): ?>
                        <?php require_once __DIR__ . '/includes/counselor_notifications_bell.php'; ?>
                    <?php endif; ?>
                    <div class="user-profile">
                        <div class="user-avatar counselor-avatar">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Header Section -->
                <div class="dashboard-header">
                    <h2>Assessment Results</h2>
                    <p class="subtitle">Overview of student performance and outcomes</p>
                </div>

                <!-- Summary Cards -->
                <div class="overview-cards results-summary">
                    <div class="overview-card">
                        <div class="card-icon assessments completed">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Completed</h3>
                            <p class="card-number"><?php echo $totalResults; ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="card-icon average-score">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="card-info">
                            <h3>Average Score</h3>
                            <p class="card-number"><?php 
                                if ($totalCompleted > 0) {
                                    echo round($totalMatches / $totalCompleted) . '%';
                                } else {
                                    echo 'N/A';
                                }
                            ?></p>
                        </div>
                    </div>
                    

                    <div class="overview-card">
                        <div class="card-icon top-category">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <div class="card-info">
                            <h3>Highest Score</h3>
                            <p class="card-number"><?php 
                                if ($highestMatch > 0) {
                                    echo round($highestMatch) . '%';
                                } else {
                                    echo 'N/A';
                                }
                            ?></p>
                        </div>
                    </div>
                </div>

                <div class="student-search-section results-search-section">
                    <div class="search-header">
                        <h3><i class="fa-solid fa-filter"></i> Filter Results</h3>
                    </div>
                    <div class="search-controls">
                        <div class="search-input-group with-status">
                            <input type="text" id="searchInput" placeholder="Search by student name">
                            <select id="strandFilter" class="status-select">
                                <option value="">All Strands</option>
                                <?php foreach ($allStrands as $strand): ?>
                                <option value="<?php echo htmlspecialchars($strand['code']); ?>">
                                    <?php echo htmlspecialchars($strand['code'] . ' — ' . $strand['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn-student-search" id="searchBtn">
                                <i class="fa-solid fa-search"></i>
                                <span>Search</span>
                            </button>
                            <button class="btn-clear" id="clearBtn">
                                <i class="fa-solid fa-times"></i>
                                <span>Clear</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Results List Section -->
                <div class="table-section">
                    <div class="table-card">
                        <div class="table-header">
                            <h3><i class="fa-solid fa-list"></i> Assessment Results</h3>
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
                                    <?php if ($totalResults > 0): ?>
                                        <?php foreach ($assessmentResults as $result): ?>
                                        <?php
                                            $statusCfg = [
                                                'completed' => [
                                                    'label' => 'Completed',
                                                    'class' => 'status-completed',
                                                ],
                                                'abandoned' => [
                                                    'label' => 'Abandoned',
                                                    'class' => 'status-abandoned',
                                                ],
                                                'in_progress' => [
                                                    'label' => 'In Progress',
                                                    'class' => 'status-in_progress',
                                                ],
                                            ];
                                            $sc          = $statusCfg[$result['status']] ?? ['label' => ucfirst($result['status']), 'class' => 'status-abandoned'];
                                            $scoreVal    = $result['score'] !== null ? number_format($result['score'], 1) : null;
                                            $dateStr     = $result['date'] ? date('M d, Y', strtotime($result['date'])) : '—';
                                            $strandLabel = !empty($result['strand_code']) ? $result['strand_code'] : $result['strand'];
                                            $strandClass = strtolower(str_replace(' ', '', $strandLabel));
                                        ?>
                                        <tr data-id="<?php echo $result['id']; ?>" data-strand="<?php echo htmlspecialchars(strtolower($strandLabel)); ?>" data-name="<?php echo strtolower($result['student_name']); ?>">
                                            <td class="student-name" title="<?php echo htmlspecialchars($result['student_name']); ?>">
                                                <?php echo htmlspecialchars($result['student_name']); ?>
                                            </td>
                                            <td>
                                                <span class="strand-badge strand-<?php echo $strandClass; ?>">
                                                    <?php echo htmlspecialchars($strandLabel); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="attempt-badge">
                                                    <?php echo $result['attempt_number']; ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="status-pill <?php echo $sc['class']; ?>">
                                                    <?php echo $sc['label']; ?>
                                                </span>
                                            </td>
                                            <td class="top-category-cell" title="<?php echo htmlspecialchars($result['top_category']); ?>">
                                                <?php echo htmlspecialchars($result['top_category']); ?>
                                            </td>
                                            <td>
                                                <?php if ($scoreVal !== null): ?>
                                                <span class="score-value"><?php echo $scoreVal; ?>%</span>
                                                <?php else: ?>
                                                <span style="color:#475569;font-size:0.85rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="date-cell" title="<?php echo $result['date_label']; ?>"><?php echo $dateStr; ?></td>
                                            <td style="text-align:center;">
                                                <?php if ($result['status'] === 'completed'): ?>
                                                <button class="btn-action view-details" onclick="openAssessmentDetails(<?php echo $result['id']; ?>, <?php echo $result['student_id']; ?>)" title="View Details">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <?php else: ?>
                                                <span title="No results to view for abandoned attempts" style="color:#334155;font-size:0.82rem;padding:0 6px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" style="text-align:center; padding:24px; color:#64748b;">No assessment results found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php include 'includes/assessment_details_modal.php'; ?>


        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="counselor.js"></script>
    <script>
    const viewDetailsModal = document.getElementById('viewDetailsModal');
    const closeDetailsModal = document.getElementById('closeDetailsModal');
    const closeDetailsBtn = document.getElementById('closeDetailsBtn');
    
    function closeModal() {
        if (viewDetailsModal) viewDetailsModal.classList.remove('active');
    }
    if (closeDetailsModal) closeDetailsModal.addEventListener('click', closeModal);
    if (closeDetailsBtn) closeDetailsBtn.addEventListener('click', closeModal);
    const overlay = viewDetailsModal ? viewDetailsModal.querySelector('.modal-overlay') : null;
    if (overlay) overlay.addEventListener('click', closeModal);

    async function openAssessmentDetails(assessmentId, studentId) {
        if (!assessmentId) return;
        
        const fd = new FormData();
        fd.append('action', 'get_assessment_details');
        fd.append('assessment_id', assessmentId);
        
        try {
            const res = await fetch('counselor_results.php', { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success && data.assessment) {
                const assessment = data.assessment;
                const categoryScores = data.category_scores || [];
                const recommendations = data.recommendations || [];
                
                // Format Student Name
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
                if (statusText) statusText.textContent = isCompleted ? 'Completed' : (assessment.status === 'in_progress' ? 'In Progress' : (assessment.status === 'abandoned' ? 'Abandoned' : 'Not Taken'));
                if (statusPill) {
                    statusPill.className = 'shb-pill stat-badge ' + (isCompleted ? 'completed' : (assessment.status === 'in_progress' ? 'in-progress' : 'abandoned'));
                    statusPill.innerHTML = isCompleted 
                        ? '<i class="fa-solid fa-circle-check"></i> <span id="detailStatusText">Completed</span>'
                        : '<i class="fa-solid fa-clock"></i> <span id="detailStatusText">' + (assessment.status === 'in_progress' ? 'In Progress' : 'Abandoned') + '</span>';
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

                // Low Confidence Banner
                const confBanner = document.getElementById('detailConfidenceBanner');
                const confMsg = document.getElementById('detailConfidenceMessage');
                if (data.is_low_confidence) {
                    if (confBanner) confBanner.style.display = 'flex';
                    if (confMsg && data.confidence_message) confMsg.textContent = data.confidence_message;
                } else {
                    if (confBanner) confBanner.style.display = 'none';
                }

                // Update View Answers Button URL
                const answersBtn = document.getElementById('detailViewAnswersBtn');
                if (answersBtn) {
                    answersBtn.href = 'counselor_answers.php?student_id=' + (studentId || assessment.student_id);
                }

                viewDetailsModal.classList.add('active');
            } else {
                alert(data.message || 'Failed to load assessment details');
            }
        } catch (err) {
            console.error(err);
            alert('Error loading assessment details');
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const strandFilter = document.getElementById('strandFilter');
        const searchBtn = document.getElementById('searchBtn');
        const clearBtn = document.getElementById('clearBtn');

        function applyFilters() {
            const name = (searchInput ? searchInput.value.trim().toLowerCase() : '');
            const strand = (strandFilter ? strandFilter.value.toLowerCase() : '');

            document.querySelectorAll('.results-table tbody tr').forEach(row => {
                const rowName = (row.dataset.name || '').toLowerCase();
                const rowStrand = (row.dataset.strand || '').toLowerCase();
                const nameOk = !name || rowName.includes(name);
                const strandOk = !strand || rowStrand.includes(strand);
                row.style.display = (nameOk && strandOk) ? '' : 'none';
            });

            document.querySelectorAll('.result-card').forEach(card => {
                const cardName = (card.dataset.name || '').toLowerCase();
                const cardStrand = (card.dataset.strand || '').toLowerCase();
                const nameOk = !name || cardName.includes(name);
                const strandOk = !strand || cardStrand.includes(strand);
                card.style.display = (nameOk && strandOk) ? '' : 'none';
            });
        }

        if (searchBtn) searchBtn.addEventListener('click', applyFilters);
        if (searchInput) searchInput.addEventListener('keyup', function(e) { if(e.key==='Enter' || this.value==='') applyFilters(); });
        if (strandFilter) strandFilter.addEventListener('change', applyFilters);
        if (clearBtn) clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (strandFilter) strandFilter.value = '';
            applyFilters();
        });
    });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
