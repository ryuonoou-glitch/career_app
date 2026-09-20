<?php
/**
 * Update user activity timestamp
 * Called via AJAX on user interactions (mouse, keyboard, scroll, etc.)
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Only update if user is logged in
if (isset($_SESSION['student_id']) || isset($_SESSION['counselor_id']) || isset($_SESSION['admin_id'])) {
    $_SESSION['last_activity'] = time();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}

exit;
?>
