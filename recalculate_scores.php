<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/recommendation_scoring.php';

// Suppress output buffering if any
while (ob_get_level()) {
    ob_end_flush();
}

echo "Starting score recalculation for all completed assessments...\n";

// Get all completed assessments
$stmt = $mysqli->query("SELECT id, student_id, preferred_region FROM student_assessments WHERE status = 'completed'");

if (!$stmt) {
    die("Error fetching assessments: " . $mysqli->error . "\n");
}

$assessments = $stmt->fetch_all(MYSQLI_ASSOC);
$total = count($assessments);

echo "Found $total completed assessments.\n\n";

$count = 0;
foreach ($assessments as $assessment) {
    $count++;
    $assessmentId = $assessment['id'];
    $studentId = $assessment['student_id'];
    $region = $assessment['preferred_region'];
    
    echo "[$count/$total] Recalculating for Assessment ID: $assessmentId (Student: $studentId)... ";
    
    try {
        // We will call the function to regenerate the recommendations.
        // First recalculate the category scores if needed (they shouldn't have changed, but just to be sure)
        saveCategoryScores($mysqli, $assessmentId);
        
        // Then regenerate recommendations using the updated two-stage scoring logic.
        // Set forceRegenerate to true to force it to run and update everything.
        generateRecommendations($mysqli, $assessmentId, $region, true);
        echo "Done.\n";
    } catch (Exception $e) {
        echo "Failed! Error: " . $e->getMessage() . "\n";
    }
}

echo "\nFinished recalculating all scores!\n";
