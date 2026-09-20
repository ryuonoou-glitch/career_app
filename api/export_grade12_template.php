<?php
session_start();
require_once '../config.php';
require_once '../system_config.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$activeSchoolYearId = getCurrentSchoolYearId($mysqli);

// Export active/pending Grade 12 students for the current school year
$stmt = $mysqli->prepare("
    SELECT first_name, last_name, student_id AS old_id 
    FROM students 
    WHERE grade_level = 'Grade 12' 
      AND school_year_id = ? 
      AND status IN ('active','pending')
    ORDER BY last_name ASC, first_name ASC
");
$stmt->bind_param('i', $activeSchoolYearId);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Grade_12_ID_Mapping_Template.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['First Name', 'Last Name', 'Old Grade 11 ID', 'New Grade 12 ID']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['first_name'],
        $row['last_name'],
        $row['old_id'],
        '' // New ID column is intentionally left blank for the admin to fill
    ]);
}
fclose($output);
$stmt->close();
