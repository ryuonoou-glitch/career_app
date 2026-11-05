<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/recommendation_scoring.php';

// Get one completed assessment to test
$stmt = $mysqli->query("SELECT id FROM student_assessments WHERE status = 'completed' LIMIT 1");
if (!$stmt) {
    die("Database error or no assessments found.");
}
$row = $stmt->fetch_assoc();
if (!$row) {
    die("No completed assessments found to test.");
}

$assessmentId = $row['id'];
echo "Testing scoring for Assessment ID: $assessmentId\n";

try {
    // Run the newly modified calculateCourseScores function
    $scoredCourses = calculateCourseScores($mysqli, $assessmentId);
    
    if (is_array($scoredCourses) && count($scoredCourses) > 0) {
        echo "SUCCESS: calculateCourseScores returned " . count($scoredCourses) . " courses.\n";
        echo "Top Course Match Percentage: " . $scoredCourses[0]['match_percentage'] . "%\n";
        
        // Also test generateScoreExplanation
        $top = $scoredCourses[0];
        $explanationUI = generateScoreExplanation(
            "Test text",
            $top['breakdown']['skills_part'],
            $top['breakdown']['career_part'],
            $top['breakdown']['personality_part'],
            $top['breakdown']['strand_part'],
            $top['match_percentage']
        );
        echo "SUCCESS: generateScoreExplanation generated HTML without errors.\n";
        
        // Test getJobRecommendationsForCourses (since we're checking for cascade errors)
        $jobsAttached = getJobRecommendationsForCourses($mysqli, [$top]);
        echo "SUCCESS: getJobRecommendationsForCourses worked.\n";
        
    } else {
        echo "FAILED: calculateCourseScores returned an empty array or null.\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR CAUGHT: " . $e->getMessage() . " on line " . $e->getLine();
}
