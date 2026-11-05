<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['valid' => false, 'message' => 'Invalid request method.']);
    exit;
}

$student_id = trim($_POST['student_id'] ?? '');

if (empty($student_id) || !preg_match('/^\d{10,12}$/', $student_id)) {
    echo json_encode(['valid' => false, 'exists' => false, 'message' => 'Student ID must be 10-12 digits.']);
    exit;
}

$stmt = $mysqli->prepare("SELECT id, is_registered, grade_level, strand_code, first_name, last_name FROM valid_student_ids WHERE student_id = ?");
if (!$stmt) {
    echo json_encode(['valid' => false, 'exists' => false, 'message' => 'Database error.']);
    exit;
}

$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['valid' => false, 'exists' => false, 'message' => 'Invalid Student ID. Please contact administration.']);
    exit;
}

$validRow = $result->fetch_assoc();

if ($validRow['is_registered'] == 1) {
    echo json_encode(['valid' => false, 'exists' => true, 'message' => 'This Student ID is already registered. Please login.']);
    exit;
}

echo json_encode([
    'valid' => true,
    'exists' => true,
    'message' => 'Student ID verified successfully.',
    'strand_code' => $validRow['strand_code'],
    'grade_level' => $validRow['grade_level'],
    'first_name' => $validRow['first_name'],
    'last_name' => $validRow['last_name']
]);
$stmt->close();
