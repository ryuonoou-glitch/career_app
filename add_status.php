<?php
require_once 'config.php';
$result = $mysqli->query("SHOW COLUMNS FROM admins LIKE 'status'");
if ($result->num_rows == 0) {
    echo "Adding status column...\n";
    $mysqli->query("ALTER TABLE admins ADD COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active'");
    echo "Done.\n";
} else {
    echo "Status column already exists.\n";
}
