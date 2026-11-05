<?php
require 'config.php';
$queries = [
    "ALTER TABLE `student_assessments` ADD COLUMN `verification_status` ENUM('pending', 'verified', 'returned') NOT NULL DEFAULT 'pending'",
    "ALTER TABLE `student_assessments` ADD COLUMN `verified_by` INT(11) NULL",
    "ALTER TABLE `student_assessments` ADD COLUMN `verified_at` TIMESTAMP NULL",
    "ALTER TABLE `student_assessments` ADD COLUMN `counselor_notes` TEXT NULL",
    "ALTER TABLE `student_assessments` ADD CONSTRAINT `fk_sa_counselor` FOREIGN KEY (`verified_by`) REFERENCES `counselors`(`id`) ON DELETE SET NULL"
];
foreach($queries as $q) {
    if(!$mysqli->query($q)) {
        echo "Error: " . $mysqli->error . "\n";
    } else {
        echo "Success: $q\n";
    }
}
?>
