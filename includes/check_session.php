<?php
/**
 * Check session status and return timeout information
 * Called via AJAX to monitor session activity
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$response = [
    'session_expired' => false,
    'show_warning' => false,
    'time_remaining' => 0,
    'is_logged_in' => false,
    'expired_login_url' => base_url('login.php?session=expired'),
];

// Check if user is logged in
if (isset($_SESSION['student_id']) || isset($_SESSION['counselor_id']) || isset($_SESSION['admin_id'])) {
    $response['is_logged_in'] = true;
    $response['expired_login_url'] = base_url(getSessionExpiredLoginPage());
    
    $now = time();
    $lastActivity = $_SESSION['last_activity'] ?? $now;
    $timeSinceLastActivity = $now - $lastActivity;
    
    $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    $timeout = $isAdmin ? ADMIN_SESSION_TIMEOUT : SESSION_TIMEOUT;
    $warningTime = $isAdmin ? ADMIN_SESSION_WARNING_TIME : SESSION_WARNING_TIME;
    
    // Calculate remaining time
    $timeRemaining = $timeout - $timeSinceLastActivity;
    
    if ($timeRemaining <= 0) {
        $response['session_expired'] = true;
        clearSession();
    } elseif ($timeRemaining <= ($timeout - $warningTime)) {
        // Show warning
        $response['show_warning'] = true;
        $response['time_remaining'] = $timeRemaining;
    }
}

echo json_encode($response);
exit;
?>
