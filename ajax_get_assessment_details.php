<?php
// ajax_get_assessment_details.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'system_config.php';
require_once 'config.php';
require_once 'includes/recommendation_scoring.php';

// Check if user is logged in (admin or counselor)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    http_response_code(403);
    echo "Unauthorized access";
    exit();
}

$assessmentId = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($assessmentId <= 0) {
    http_response_code(400);
    echo "Invalid assessment ID";
    exit();
}

// 1. Run the scoring algorithm to get the breakdowns
$scoredCourses = calculateCourseScores($mysqli, $assessmentId);

// 2. Fetch the top 3 recommended courses from the recommendations table
$stmt = $mysqli->prepare("
    SELECT c.*, r.match_percentage, r.explanation, r.rank
    FROM recommendations r
    JOIN courses c ON r.course_id = c.id
    WHERE r.assessment_id = ?
    ORDER BY r.rank ASC
    LIMIT 3
");
$stmt->bind_param("i", $assessmentId);
$stmt->execute();
$result = $stmt->get_result();
$recommendations = [];
while ($row = $result->fetch_assoc()) {
    $recommendations[] = $row;
}
$stmt->close();

if (empty($recommendations)) {
    http_response_code(404);
    echo "<div style='padding: 20px; color: #f8fafc; text-align: center;'>Recommendation data not found.</div>";
    exit();
}

// Attach breakdown
foreach ($recommendations as &$rec) {
    $rec['breakdown'] = null;
    foreach ($scoredCourses as $sc) {
        if ($sc['course_id'] == $rec['id']) {
            $rec['breakdown'] = $sc['breakdown'];
            $rec['match_percentage'] = $sc['match_percentage'];
            break;
        }
    }
}
unset($rec);

// Get preferred region from assessment
$regionStmt = $mysqli->prepare("SELECT preferred_region FROM student_assessments WHERE id = ? LIMIT 1");
$preferredRegion = '1';
if ($regionStmt) {
    $regionStmt->bind_param("i", $assessmentId);
    $regionStmt->execute();
    $regionRow = $regionStmt->get_result()->fetch_assoc();
    if (!empty($regionRow['preferred_region'])) {
        $preferredRegion = $regionRow['preferred_region'];
    }
    $regionStmt->close();
}

// Fetch Jobs & Schools for these courses
$recommendations = getJobRecommendationsForCourses($mysqli, $recommendations);
$recommendations = getSchoolRecommendationsForCourses($mysqli, $recommendations, $preferredRegion, $assessmentId);

// Render template for each course
$isCounselorView = true;
$isAjax = true;
$first = true;

echo '<div class="counselor-modal-scroll-container">';
foreach ($recommendations as $course) {
    $cRank = (int)$course['rank'];
    if (!$first) {
        echo '<div style="height: 12px; background: #0f172a; border-top: 1px solid rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.05); margin: 0;"></div>';
    }
    require 'includes/course_modal_template.php';
    $first = false;
}
echo '</div>';
