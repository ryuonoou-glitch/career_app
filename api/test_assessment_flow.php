<?php
require_once '../config.php';
require_once '../includes/recommendation_scoring.php';

session_start();
$_SESSION['student_db_id'] = 1; // Fernando Estrada
$_SESSION['student_id'] = 'STU001';

// Setup POST array for assessment.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'create',
    'student_id' => 1,
    'type' => 'comprehensive',
    'region' => 'Region I',
    'preferred_region' => 'Region I'
];

ob_start();
require __DIR__ . '/assessment.php';
$createRes = json_decode(ob_get_clean(), true);

if (empty($createRes['assessment_id'])) {
    die("Failed to create: " . print_r($createRes, true));
}
$assessmentId = $createRes['assessment_id'];
echo "Assessment ID: $assessmentId\n";

// Insert dummy answers
$questions = [
    ['table' => 'questions_career', 'type' => 'career'],
    ['table' => 'questions_personality', 'type' => 'personality'],
    ['table' => 'questions_skills', 'type' => 'skills'],
    ['table' => 'questions_strand', 'type' => 'strand']
];

foreach ($questions as $q) {
    $stmt = $mysqli->query("SELECT id FROM {$q['table']} LIMIT 10");
    while ($row = $stmt->fetch_assoc()) {
        $qid = $row['id'];
        $insert = $mysqli->prepare("INSERT INTO student_answers (assessment_id, question_id, question_type, likert_value) VALUES (?, ?, ?, 3)");
        $insert->bind_param("iis", $assessmentId, $qid, $q['type']);
        $insert->execute();
        $insert->close();
    }
}
echo "Dummy answers inserted.\n";

// Complete assessment
$_POST = [
    'action' => 'complete',
    'assessment_id' => $assessmentId,
    'total_time' => 300
];

// Instead of require, which fails on redeclaration, I'll just call the function directly!
// Because the first require included all the functions.
$response = ['success' => false, 'message' => ''];
handleCompleteAssessment($mysqli, $response);

if ($response['success']) {
    echo "SUCCESS: Assessment completed! The API returned true.\n";
    echo "Message: " . $response['message'] . "\n";
} else {
    echo "FAILED: " . print_r($response, true) . "\n";
}
