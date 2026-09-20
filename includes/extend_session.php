<?php
/**
 * Extend session by updating last activity timestamp
 * Called via AJAX when user clicks "Stay Logged In"
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Check if user is logged in
if (isset($_SESSION['student_id']) || isset($_SESSION['counselor_id']) || isset($_SESSION['admin_id'])) {
    // Update last activity timestamp to current time
    $_SESSION['last_activity'] = time();
    
    $response['success'] = true;
    $response['message'] = 'Session extended successfully';
} else {
    $response['success'] = false;
    $response['message'] = 'Not logged in';
}

echo json_encode($response);
exit;
?>
