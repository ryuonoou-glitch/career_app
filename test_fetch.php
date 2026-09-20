<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

// Simulate the logic in school_year_transition.php
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

$stmt = $mysqli->query("SELECT id FROM student_assessments WHERE status = 'completed' LIMIT 1");
if ($stmt && $stmt->num_rows > 0) {
    $row = $stmt->fetch_assoc();
    $data = buildAssessmentSnapshot($mysqli, $row['id']);
    echo "KEYS:\n";
    print_r(array_keys($data));
    echo "\n\nJSON:\n";
    echo json_encode($data, JSON_PRETTY_PRINT);
} else {
    echo "NO_COMPLETED_ASSESSMENTS";
}
