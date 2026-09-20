<?php
/**
 * Password reset API: send OTP, verify OTP, reset password, resend OTP.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/password_reset.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = $_POST['action'] ?? '';
$forAdmin = isset($_POST['from']) && $_POST['from'] === 'admin';

switch ($action) {
    case 'send_otp':
        $email = trim($_POST['email'] ?? '');
        $result = password_reset_send_otp($mysqli, $email, $forAdmin);
        echo json_encode($result);
        break;

    case 'verify_otp':
        $otp = implode('', [
            $_POST['otp1'] ?? '',
            $_POST['otp2'] ?? '',
            $_POST['otp3'] ?? '',
            $_POST['otp4'] ?? '',
            $_POST['otp5'] ?? '',
            $_POST['otp6'] ?? '',
        ]);
        if ($otp === '' && !empty($_POST['otp'])) {
            $otp = preg_replace('/\D/', '', $_POST['otp']);
        }
        $result = password_reset_verify_otp($mysqli, $otp);
        echo json_encode($result);
        break;

    case 'resend_otp':
        $result = password_reset_resend_otp($mysqli);
        echo json_encode($result);
        break;

    case 'reset_password':
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';
        $result = password_reset_apply_new_password($mysqli, $newPassword, $confirmPassword);
        echo json_encode($result);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
