<?php
require_once '../config.php';
require_once '../includes/recommendation_scoring.php';

session_start();
$studentId = 1;
$schoolYearId = 1; 

$stmt = $mysqli->prepare("INSERT INTO student_assessments (student_id, school_year_id, preferred_region, status) VALUES (?, ?, 'Region I', 'in_progress')");
$stmt->bind_param("ii", $studentId, $schoolYearId);
if (!$stmt->execute()) {
    die("Failed to insert assessment: " . $stmt->error);
}
$assessmentId = $mysqli->insert_id;
$stmt->close();

echo "Assessment created. ID: $assessmentId\n";

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

require_once 'assessment.php'; 

$_POST = [
    'assessment_id' => $assessmentId,
    'total_time' => 300
];

$response = ['success' => false, 'message' => ''];
$_SESSION['student_db_id'] = $studentId;
handleCompleteAssessment($mysqli, $response);

echo "Final Response:\n";
print_r($response);

$mysqli->query("UPDATE student_assessments SET status = 'abandoned' WHERE id = $assessmentId");
