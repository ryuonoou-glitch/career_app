<?php
/**
 * Password reset OTP helpers (session + database for students).
 */

define('OTP_EXPIRY_SECONDS', 300); // 5 minutes
define('OTP_LENGTH', 6);

function password_reset_session_key(): string
{
    return 'password_reset';
}

function password_reset_generate_otp(): string
{
    return str_pad((string) random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
}

function password_reset_mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$name, $domain] = explode('@', $email, 2);
    $len = strlen($name);
    if ($len <= 2) {
        $masked = str_repeat('*', $len);
    } else {
        $masked = $name[0] . str_repeat('*', max(1, $len - 2)) . $name[$len - 1];
    }
    return $masked . '@' . $domain;
}

/**
 * @return array{success:bool,message:string,masked_email?:string,redirect?:string}
 */
function password_reset_send_otp(mysqli $mysqli, string $email, bool $forAdmin): array
{
    $email = trim(strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $account = null;
    $accountType = 'student';

    if ($forAdmin) {
        $stmt = $mysqli->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name, status FROM admins WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($account) {
            $accountType = 'admin';
            if (isset($account['status']) && ($account['status'] === 'inactive' || $account['status'] === 'suspended')) {
                return ['success' => false, 'message' => 'Your account is deactivated. Please contact the administrator.'];
            }
        } else {
            // Also check counselors table for staff portal
            $stmtC = $mysqli->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name, status FROM counselors WHERE LOWER(email) = ? LIMIT 1");
            if ($stmtC) {
                $stmtC->bind_param('s', $email);
                $stmtC->execute();
                $cAccount = $stmtC->get_result()->fetch_assoc();
                $stmtC->close();

                if ($cAccount) {
                    if (isset($cAccount['status']) && $cAccount['status'] !== 'active') {
                        return ['success' => false, 'message' => 'Your counselor account is not active. Please contact the administrator.'];
                    }
                    $account = $cAccount;
                    $accountType = 'counselor';
                }
            }
        }
    } else {
        $stmt = $mysqli->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM students WHERE LOWER(email) = ? AND (status = 'active' OR status = 'graduated') LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Fallback: If not found in students, check counselors as well
        if (!$account) {
            $stmtC = $mysqli->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name, status FROM counselors WHERE LOWER(email) = ? LIMIT 1");
            if ($stmtC) {
                $stmtC->bind_param('s', $email);
                $stmtC->execute();
                $cAccount = $stmtC->get_result()->fetch_assoc();
                $stmtC->close();

                if ($cAccount) {
                    if (isset($cAccount['status']) && $cAccount['status'] !== 'active') {
                        return ['success' => false, 'message' => 'Your counselor account is not active. Please contact the administrator.'];
                    }
                    $account = $cAccount;
                    $accountType = 'counselor';
                    $forAdmin = true;
                }
            }
        }
    }

    // Generic success message (do not reveal whether email exists)
    $genericSuccess = [
        'success' => true,
        'message' => 'If this email is registered, a verification code has been sent.',
        'masked_email' => password_reset_mask_email($email),
    ];

    if (!$account) {
        return $genericSuccess;
    }

    $otp = password_reset_generate_otp();
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);

    if ($accountType === 'student') {
        $upd = $mysqli->prepare('UPDATE students SET reset_token = ?, reset_expires = ? WHERE id = ?');
        $upd->bind_param('ssi', $otpHash, $expiresAt, $account['id']);
        $upd->execute();
        $upd->close();
    }

    $_SESSION[password_reset_session_key()] = [
        'email' => $email,
        'account_type' => $accountType,
        'user_id' => (int) $account['id'],
        'otp_verified' => false,
        'for_admin' => $forAdmin,
        'otp_hash' => $otpHash,
        'expires_at' => time() + OTP_EXPIRY_SECONDS,
    ];

    require_once __DIR__ . '/mailer.php';
    $sent = send_password_reset_otp_email([
        'email' => $account['email'],
        'name' => $account['name'] ?? 'User',
        'otp' => $otp,
        'expires_minutes' => (int) (OTP_EXPIRY_SECONDS / 60),
    ]);

    if (!$sent) {
        error_log('Password reset OTP email failed for: ' . $email);
    }

    $redirect = 'otp_verification.php' . ($forAdmin ? '?from=admin' : '');
    return array_merge($genericSuccess, ['redirect' => $redirect]);
}

/**
 * @return array{success:bool,message:string,redirect?:string}
 */
function password_reset_verify_otp(mysqli $mysqli, string $otp): array
{
    $otp = preg_replace('/\D/', '', $otp);
    if (strlen($otp) !== OTP_LENGTH) {
        return ['success' => false, 'message' => 'Please enter the complete 6-digit code.'];
    }

    $ctx = $_SESSION[password_reset_session_key()] ?? null;
    if (!$ctx || empty($ctx['email']) || empty($ctx['user_id'])) {
        return ['success' => false, 'message' => 'Session expired. Please request a new code.'];
    }

    if (!isset($_SESSION[password_reset_session_key()]['failed_attempts'])) {
        $_SESSION[password_reset_session_key()]['failed_attempts'] = 0;
    }
    if ($_SESSION[password_reset_session_key()]['failed_attempts'] >= 5) {
        unset($_SESSION[password_reset_session_key()]);
        return ['success' => false, 'message' => 'Too many failed attempts. Please request a new verification code.'];
    }

    $storedHash = null;
    $expiresAt = null;

    if ($ctx['account_type'] === 'student') {
        $stmt = $mysqli->prepare('SELECT reset_token, reset_expires FROM students WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $ctx['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $storedHash = $row['reset_token'];
            $expiresAt = $row['reset_expires'];
        }
    } else {
        // Admin OTP stored in session only
        $storedHash = $ctx['otp_hash'] ?? null;
        $expiresAt = isset($ctx['expires_at']) ? date('Y-m-d H:i:s', $ctx['expires_at']) : null;
    }

    if (!$storedHash || !$expiresAt) {
        return ['success' => false, 'message' => 'No active verification code. Please request a new one.'];
    }

    if (strtotime($expiresAt) < time()) {
        return ['success' => false, 'message' => 'This code has expired. Please request a new one.'];
    }

    if (!password_verify($otp, $storedHash)) {
        $_SESSION[password_reset_session_key()]['failed_attempts']++;
        return ['success' => false, 'message' => 'Invalid verification code. Please try again.'];
    }

    $_SESSION[password_reset_session_key()]['failed_attempts'] = 0;
    $_SESSION[password_reset_session_key()]['otp_verified'] = true;

    $forAdmin = !empty($ctx['for_admin']);
    $redirect = 'reset_password.php' . ($forAdmin ? '?from=admin' : '');

    return ['success' => true, 'message' => 'Code verified.', 'redirect' => $redirect];
}

/**
 * @return array{success:bool,message:string,redirect?:string}
 */
function password_reset_apply_new_password(mysqli $mysqli, string $newPassword, string $confirmPassword): array
{
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }

    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword)
        || !preg_match('/\d/', $newPassword) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $newPassword)) {
        return ['success' => false, 'message' => 'Password must include upper, lower, number, and special character.'];
    }

    $ctx = $_SESSION[password_reset_session_key()] ?? null;
    if (!$ctx || empty($ctx['otp_verified']) || empty($ctx['user_id'])) {
        return ['success' => false, 'message' => 'Please verify your code first.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($ctx['account_type'] === 'student') {
        $stmt = $mysqli->prepare("UPDATE students SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->bind_param('si', $hash, $ctx['user_id']);
        $ok = $stmt->execute();
        $stmt->close();
    } elseif ($ctx['account_type'] === 'counselor') {
        $stmt = $mysqli->prepare("UPDATE counselors SET password = ?, must_change_password = 0 WHERE id = ?");
        $stmt->bind_param('si', $hash, $ctx['user_id']);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare('UPDATE admins SET password = ?, must_change_password = 0 WHERE id = ?');
        $stmt->bind_param('si', $hash, $ctx['user_id']);
        $ok = $stmt->execute();
        $stmt->close();
    }

    if (!$ok) {
        return ['success' => false, 'message' => 'Could not update password. Please try again.'];
    }

    unset($_SESSION[password_reset_session_key()]);

    $forAdmin = !empty($ctx['for_admin']);
    $redirect = ($forAdmin ? 'admin_login.php' : 'login.php') . '?reset=success';

    return ['success' => true, 'message' => 'Password reset successfully.', 'redirect' => $redirect];
}

/**
 * Resend OTP for current session email.
 * @return array{success:bool,message:string}
 */
function password_reset_resend_otp(mysqli $mysqli): array
{
    $ctx = $_SESSION[password_reset_session_key()] ?? null;
    if (!$ctx || empty($ctx['email'])) {
        return ['success' => false, 'message' => 'Session expired. Please start again.'];
    }

    return password_reset_send_otp($mysqli, $ctx['email'], !empty($ctx['for_admin']));
}
