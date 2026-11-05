<?php
/**
 * Application email helper (PHPMailer).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Full site URL for links in emails (e.g. http://localhost/career_app/).
 */
function mailer_app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = rtrim(BASE_URL, '/');

    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . ($path !== '' ? '/' . $path : '');
    }

    return 'http://localhost' . $base . ($path !== '' ? '/' . $path : '');
}

/**
 * Send an HTML email. Returns true on success.
 */
function send_app_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
{
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return false;
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Mailer: run composer install in career_app to enable email.');
        return false;
    }

    require_once $autoload;

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody))));
    }

    $fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME;
    if (function_exists('getSystemConfig')) {
        $cfgName = getSystemConfig('short_name');
        if ($cfgName !== '') {
            $fromName = $cfgName;
        }
    }

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        if (defined('MAIL_USE_SMTP') && MAIL_USE_SMTP) {
            $mail->isSMTP();
            $mail->Host = MAIL_SMTP_HOST;
            $mail->Port = (int) MAIL_SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_SMTP_USER;
            $mail->Password = MAIL_SMTP_PASS;
            $secure = defined('MAIL_SMTP_SECURE') ? strtolower(MAIL_SMTP_SECURE) : 'tls';
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
        } else {
            $mail->isMail();
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $replyTo = function_exists('getSystemConfig') ? getSystemConfig('email') : '';
        if ($replyTo !== '' && isValidEmailAddress($replyTo)) {
            $mail->addReplyTo($replyTo, $fromName);
        }

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send "account created" email after student signup.
 */
function send_signup_account_created_email(array $student): bool
{
    $firstName = $student['first_name'] ?? '';
    $lastName = $student['last_name'] ?? '';
    $fullName = trim($firstName . ' ' . ($student['middle_name'] ?? '') . ' ' . $lastName);
    $fullName = preg_replace('/\s+/', ' ', $fullName);

    $email = $student['email'] ?? '';
    if ($email === '' || !isValidEmailAddress($email)) {
        return false;
    }

    $systemName = function_exists('getSystemConfig') ? getSystemConfig('short_name') : APP_NAME;
    if ($systemName === '') {
        $systemName = APP_NAME;
    }

    $studentId = $student['student_id'] ?? '';
    $gradeLevel = $student['grade_level'] ?? '';
    $strandName = $student['strand_name'] ?? '';
    $loginUrl = mailer_app_url('login.php');

    $subject = 'Your ' . $systemName . ' account has been created';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;max-width:560px;margin:0 auto;padding:24px;">'
        . '<h2 style="color:#0f172a;margin:0 0 16px;">Account created successfully</h2>'
        . '<p>Hello <strong>' . htmlspecialchars($fullName !== '' ? $fullName : 'Student', ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p>Welcome to <strong>' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '</strong>. Your student account is ready.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
        . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc;width:40%;">School ID</td>'
        . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc;">Grade level</td>'
        . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars($gradeLevel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc;">Strand</td>'
        . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars($strandName !== '' ? $strandName : 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p>You can sign in using your email and the password you chose during registration.</p>'
        . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:#f59e0b;color:#0f172a;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Log in to your account</a></p>'
        . '<p style="font-size:12px;color:#64748b;">If you did not create this account, please contact your school administrator.</p>'
        . '</body></html>';

    $text = "Hello {$fullName},\n\n"
        . "Your {$systemName} account has been created.\n\n"
        . "School ID: {$studentId}\n"
        . "Grade level: {$gradeLevel}\n"
        . "Strand: " . ($strandName !== '' ? $strandName : 'N/A') . "\n\n"
        . "Log in here: {$loginUrl}\n\n"
        . "If you did not create this account, contact your school administrator.";

    return send_app_email($email, $fullName !== '' ? $fullName : 'Student', $subject, $html, $text);
}

/**
 * Send password reset OTP email.
 */
function send_password_reset_otp_email(array $data): bool
{
    $email = $data['email'] ?? '';
    $name = trim($data['name'] ?? 'User');
    $otp = $data['otp'] ?? '';
    $expiresMinutes = (int) ($data['expires_minutes'] ?? 5);

    if ($email === '' || !isValidEmailAddress($email) || strlen($otp) !== 6) {
        return false;
    }

    $systemName = function_exists('getSystemConfig') ? getSystemConfig('short_name') : APP_NAME;
    if ($systemName === '') {
        $systemName = APP_NAME;
    }

    $subject = $systemName . ' — Password reset code';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;max-width:560px;margin:0 auto;padding:24px;">'
        . '<h2 style="color:#0f172a;margin:0 0 16px;">Password reset verification</h2>'
        . '<p>Hello <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p>Use this code to reset your password. It expires in <strong>' . $expiresMinutes . ' minutes</strong>.</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:8px;text-align:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:24px 0;">'
        . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:12px;color:#64748b;">If you did not request a password reset, ignore this email or contact your administrator.</p>'
        . '</body></html>';

    $text = "Hello {$name},\n\nYour {$systemName} password reset code is: {$otp}\n\n"
        . "This code expires in {$expiresMinutes} minutes.\n\n"
        . "If you did not request this, ignore this email.";

    return send_app_email($email, $name, $subject, $html, $text);
}

/**
 * Send "account created by admin" email with temporary password to student.
 */
function send_admin_created_account_email(array $student): bool
{
    $firstName  = $student['first_name']  ?? '';
    $middleName = $student['middle_name'] ?? '';
    $lastName   = $student['last_name']   ?? '';
    $fullName   = preg_replace('/\s+/', ' ', trim($firstName . ' ' . $middleName . ' ' . $lastName));

    $email = $student['email'] ?? '';
    if ($email === '' || !isValidEmailAddress($email)) {
        return false;
    }

    $password   = $student['password']    ?? '';
    $studentId  = $student['student_id']  ?? '';
    $gradeLevel = $student['grade_level'] ?? '';
    $strandName = $student['strand_name'] ?? '';
    $loginUrl   = mailer_app_url('login.php');

    $systemName = function_exists('getSystemConfig') ? getSystemConfig('short_name') : (defined('APP_NAME') ? APP_NAME : 'CareerApp');
    if ($systemName === '') $systemName = 'CareerApp';

    $subject = 'Welcome to ' . $systemName . ' — Your account is ready';

    $displayName = htmlspecialchars($fullName !== '' ? $fullName : 'Student', ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;max-width:580px;margin:0 auto;padding:0;">'
        . '<div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:32px 28px;border-radius:12px 12px 0 0;">'
        . '<h1 style="margin:0;color:#0f172a;font-size:22px;">Welcome to ' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '!</h1>'
        . '<p style="margin:6px 0 0;color:#1c1917;font-size:14px;">Your student account has been created by an administrator.</p>'
        . '</div>'
        . '<div style="background:#ffffff;padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">'
        . '<p>Hello <strong>' . $displayName . '</strong>,</p>'
        . '<p>An administrator has created your account on <strong>' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '</strong>. '
        . 'Below are your account details and a temporary password to log in.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;">'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;width:38%;font-weight:600;">School ID</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;">Grade Level</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($gradeLevel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;">Strand</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($strandName !== '' ? $strandName : 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;">Email</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0 8px;font-weight:600;">Your temporary password:</p>'
        . '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;text-align:center;'
        . 'background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;padding:14px;margin:0 0 20px;color:#92400e;">'
        . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:13px;color:#64748b;">&#9888;&#65039; Please log in and change your password as soon as possible.</p>'
        . '<p style="text-align:center;margin:28px 0 8px;">'
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:#f59e0b;color:#0f172a;text-decoration:none;'
        . 'padding:13px 32px;border-radius:8px;font-weight:bold;font-size:15px;">Log in to your account</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#94a3b8;margin-top:24px;border-top:1px solid #f1f5f9;padding-top:16px;">'
        . 'If you did not expect this email, please contact your school administrator.</p>'
        . '</div></body></html>';

    $text = "Hello {$fullName},\n\n"
        . "An administrator has created your {$systemName} account.\n\n"
        . "School ID:    {$studentId}\n"
        . "Grade Level:  {$gradeLevel}\n"
        . "Strand:       " . ($strandName !== '' ? $strandName : 'N/A') . "\n"
        . "Email:        {$email}\n\n"
        . "Temporary password: {$password}\n\n"
        . "Please log in and change your password immediately:\n{$loginUrl}\n\n"
        . "If you did not expect this, contact your school administrator.";

    return send_app_email($email, $fullName !== '' ? $fullName : 'Student', $subject, $html, $text);
}

/**
 * Send welcome email to counselor created by administrator
 */
function send_admin_created_counselor_email(array $counselor): bool
{
    $firstName  = $counselor['first_name']  ?? '';
    $middleName = $counselor['middle_name'] ?? '';
    $lastName   = $counselor['last_name']   ?? '';
    $fullName   = preg_replace('/\s+/', ' ', trim($firstName . ' ' . $middleName . ' ' . $lastName));

    $email = $counselor['email'] ?? '';
    if ($email === '' || !isValidEmailAddress($email)) {
        return false;
    }

    $password   = $counselor['password']    ?? '';
    $loginUrl   = mailer_app_url('login.php');

    $systemName = function_exists('getSystemConfig') ? getSystemConfig('short_name') : (defined('APP_NAME') ? APP_NAME : 'CareerApp');
    if ($systemName === '') $systemName = 'CareerApp';

    $subject = 'Welcome to ' . $systemName . ' — Counselor Account Ready';

    $displayName = htmlspecialchars($fullName !== '' ? $fullName : 'Counselor', ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;max-width:580px;margin:0 auto;padding:0;">'
        . '<div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:32px 28px;border-radius:12px 12px 0 0;">'
        . '<h1 style="margin:0;color:#0f172a;font-size:22px;">Welcome to ' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '!</h1>'
        . '<p style="margin:6px 0 0;color:#1c1917;font-size:14px;">Your counselor account has been created by an administrator.</p>'
        . '</div>'
        . '<div style="background:#ffffff;padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">'
        . '<p>Hello <strong>' . $displayName . '</strong>,</p>'
        . '<p>An administrator has created your counselor account on <strong>' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '</strong>. '
        . 'Below are your account details and a temporary password to log in.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;">'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;width:38%;font-weight:600;">Email</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0 8px;font-weight:600;">Your temporary password:</p>'
        . '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;text-align:center;'
        . 'background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;padding:14px;margin:0 0 20px;color:#92400e;">'
        . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:13px;color:#64748b;">&#9888;&#65039; Please log in and change your password as soon as possible.</p>'
        . '<p style="text-align:center;margin:28px 0 8px;">'
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:#f59e0b;color:#0f172a;text-decoration:none;'
        . 'padding:13px 32px;border-radius:8px;font-weight:bold;font-size:15px;">Log in to your account</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#94a3b8;margin-top:24px;border-top:1px solid #f1f5f9;padding-top:16px;">'
        . 'If you did not expect this email, please contact your school administrator.</p>'
        . '</div></body></html>';

    $text = "Hello {$fullName},\n\n"
        . "An administrator has created your counselor account on {$systemName}.\n\n"
        . "Email:           {$email}\n\n"
        . "Temporary password: {$password}\n\n"
        . "Please log in and change your password immediately:\n{$loginUrl}\n\n"
        . "If you did not expect this, contact your school administrator.";

    return send_app_email($email, $fullName !== '' ? $fullName : 'Counselor', $subject, $html, $text);
}

/**
 * Send welcome email to administrator created by another administrator
 */
function send_admin_created_admin_email(array $admin): bool
{
    $firstName  = $admin['first_name']  ?? '';
    $middleName = $admin['middle_name'] ?? '';
    $lastName   = $admin['last_name']   ?? '';
    $fullName   = preg_replace('/\s+/', ' ', trim($firstName . ' ' . $middleName . ' ' . $lastName));

    $email = $admin['email'] ?? '';
    if ($email === '' || !isValidEmailAddress($email)) {
        return false;
    }

    $username   = $admin['username']    ?? '';
    $password   = $admin['password']    ?? '';
    $loginUrl   = mailer_app_url('admin_login.php');

    $systemName = function_exists('getSystemConfig') ? getSystemConfig('short_name') : (defined('APP_NAME') ? APP_NAME : 'CareerApp');
    if ($systemName === '') $systemName = 'CareerApp';

    $subject = 'Welcome to ' . $systemName . ' — Administrator Account Ready';

    $displayName = htmlspecialchars($fullName !== '' ? $fullName : 'Administrator', ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;max-width:580px;margin:0 auto;padding:0;">'
        . '<div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:32px 28px;border-radius:12px 12px 0 0;">'
        . '<h1 style="margin:0;color:#0f172a;font-size:22px;">Welcome to ' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '!</h1>'
        . '<p style="margin:6px 0 0;color:#1c1917;font-size:14px;">Your Administrator account has been created.</p>'
        . '</div>'
        . '<div style="background:#ffffff;padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">'
        . '<p>Hello <strong>' . $displayName . '</strong>,</p>'
        . '<p>You have been added as an Administrator on <strong>' . htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') . '</strong>. '
        . 'Below are your login credentials.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;">'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;width:38%;font-weight:600;">Username</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;">Email</td>'
        . '<td style="padding:10px 12px;border:1px solid #e2e8f0;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0 8px;font-weight:600;">Your temporary password:</p>'
        . '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;text-align:center;'
        . 'background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;padding:14px;margin:0 0 20px;color:#92400e;">'
        . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:13px;color:#64748b;">&#9888;&#65039; Please log in and change your password as soon as possible via the Settings page.</p>'
        . '<p style="text-align:center;margin:28px 0 8px;">'
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:#f59e0b;color:#0f172a;text-decoration:none;'
        . 'padding:13px 32px;border-radius:8px;font-weight:bold;font-size:15px;">Log in to Admin Dashboard</a>'
        . '</p>'
        . '</div></body></html>';

    $text = "Hello {$fullName},\n\n"
        . "An administrator has created your {$systemName} account.\n\n"
        . "Username: {$username}\n"
        . "Email: {$email}\n\n"
        . "Temporary password: {$password}\n\n"
        . "Please log in and change your password immediately:\n{$loginUrl}\n\n";

    return send_app_email($email, $fullName !== '' ? $fullName : 'Administrator', $subject, $html, $text);
}


