<?php
require_once 'config.php';
$output = "";
$tables = ['assessments', 'student_assessments', 'recommendations', 'counselor_verifications', 'assessment_recommendations', 'courses'];
foreach ($tables as $t) {
    $output .= "--- Table: $t ---\n";
    $res = $mysqli->query("SHOW CREATE TABLE $t");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_row();
        $output .= $row[1] . "\n\n";
    } else {
        $output .= "Table not found.\n\n";
    }
}
file_put_contents('scratch_db_output.txt', $output);
