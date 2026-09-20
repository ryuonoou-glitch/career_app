<?php
require_once __DIR__ . '/config.php';

// Add columns to clusters
$sql = "ALTER TABLE clusters 
        ADD COLUMN holland_traits VARCHAR(255) DEFAULT '',
        ADD COLUMN bigfive_traits VARCHAR(255) DEFAULT '',
        ADD COLUMN shs_strands VARCHAR(255) DEFAULT ''";

if ($mysqli->query($sql)) {
    echo "Columns added to clusters.\n";
} else {
    echo "Error adding columns to clusters: " . $mysqli->error . "\n";
}

// Remove columns from courses
$sql2 = "ALTER TABLE courses 
         DROP COLUMN holland_traits,
         DROP COLUMN bigfive_traits,
         DROP COLUMN shs_strands";

if ($mysqli->query($sql2)) {
    echo "Columns dropped from courses.\n";
} else {
    echo "Error dropping columns from courses: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
