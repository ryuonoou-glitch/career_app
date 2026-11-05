<?php
// Admin & Staff Login Page - Redesigned Executive Portal

require_once 'system_config.php';
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
        $success = 'Your password has been reset. You can log in with your new password.';
    } elseif (isset($_GET['session']) && $_GET['session'] === 'expired') {
        $error = 'Your session has expired due to inactivity. Please log in again.';
    } elseif (isset($_GET['logout']) && $_GET['logout'] === 'success') {
        $success = 'You have been successfully logged out of the admin portal.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['adminEmail'] ?? $_POST['email'] ?? '');
    $password = $_POST['adminPassword'] ?? $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        // Use $mysqli from config.php
        $stmt = $mysqli->prepare("SELECT id, email, password, first_name, middle_name, last_name, suffix, role, status, must_change_password FROM admins WHERE email = ? AND role IN ('super_admin', 'Admin')");
        if (!$stmt) {
            $error = 'Database error: ' . $mysqli->error;
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $admin = $result->fetch_assoc();

                // Check if account is deactivated
                if (isset($admin['status']) && ($admin['status'] === 'inactive' || $admin['status'] === 'suspended')) {
                    $error = 'Your administrator account has been deactivated. Please contact the system administrator.';
                } else {
                    // Check password - support both hashed and plain text during transition
                    $passwordValid = false;
                    // Try password_verify first (for hashed passwords)
                    if (password_verify($password, $admin['password'])) {
                        $passwordValid = true;
                    }
                    // Fallback to plain text comparison (for old passwords) and rehash immediately
                    elseif ($password === $admin['password']) {
                        $passwordValid = true;
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $rehashStmt = $mysqli->prepare("UPDATE admins SET password = ? WHERE id = ?");
                        if ($rehashStmt) {
                            $rehashStmt->bind_param('si', $newHash, $admin['id']);
                            $rehashStmt->execute();
                            $rehashStmt->close();
                        }
                    }

                    if ($passwordValid) {
                        session_unset();
                        session_regenerate_id(true);
                        $nameParts = array_filter([$admin['first_name'], $admin['middle_name'], $admin['last_name']]);
                        $fullName = implode(' ', $nameParts);
                        if (!empty($admin['suffix'])) {
                            $fullName .= ' ' . $admin['suffix'];
                        }

                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_email'] = $admin['email'];
                        $_SESSION['email'] = $admin['email']; // For dashboard compatibility
                        $_SESSION['admin_name'] = $fullName;
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['is_admin'] = true;
                        $_SESSION['staff_id'] = $admin['id'];
                        $_SESSION['staff_role'] = $admin['role'];
                        $_SESSION['staff_name'] = $fullName;
                        $_SESSION['last_activity'] = time();

                        // Update last login
                        $updateStmt = $mysqli->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                        $updateStmt->bind_param('i', $admin['id']);
                        $updateStmt->execute();
                        $updateStmt->close();

                        // Redirect — force password change for admin-created accounts
                        if (!empty($admin['must_change_password'])) {
                            header('Location: set_password.php');
                        } else {
                            header('Location: admin_dashboard.php');
                        }
                        exit();
                    } else {
                        $error = 'Invalid email or password.';
                    }
                }
            } else {
                // Check if counselor is logging in via admin login
                $stmtCounselor = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, email, password, status, must_change_password FROM counselors WHERE email = ?");
                if ($stmtCounselor) {
                    $stmtCounselor->bind_param('s', $email);
                    $stmtCounselor->execute();
                    $cResult = $stmtCounselor->get_result();

                    if ($cResult->num_rows === 1) {
                        $counselor = $cResult->fetch_assoc();

                        if ($counselor['status'] !== 'active') {
                            $error = 'Your counselor account is not active. Please contact the administrator.';
                        } else {
                            $passwordValid = false;
                            // Try password_verify first (for hashed passwords)
                            if (password_verify($password, $counselor['password'])) {
                                $passwordValid = true;
                            }
                            // Fallback to plain text comparison (for old passwords) and rehash immediately
                            elseif ($password === $counselor['password']) {
                                $passwordValid = true;
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $rehashStmt = $mysqli->prepare("UPDATE counselors SET password = ? WHERE id = ?");
                                if ($rehashStmt) {
                                    $rehashStmt->bind_param('si', $newHash, $counselor['id']);
                                    $rehashStmt->execute();
                                    $rehashStmt->close();
                                }
                            }

                            if ($passwordValid) {
                                session_regenerate_id(true);
                                unset(
                                    $_SESSION['counselor_id'],
                                    $_SESSION['counselor_name'],
                                    $_SESSION['counselor_email'],
                                    $_SESSION['staff_id'],
                                    $_SESSION['staff_role'],
                                    $_SESSION['staff_name']
                                );
                                $_SESSION['counselor_id'] = $counselor['id'];
                                $counselorFullNameParts = array_filter([$counselor['first_name'], $counselor['middle_name'] ?? '', $counselor['last_name']], 'strlen');
                                $_SESSION['counselor_name'] = implode(' ', $counselorFullNameParts);
                                $_SESSION['counselor_email'] = $counselor['email'];
                                $_SESSION['staff_id'] = $counselor['id'];
                                $_SESSION['staff_role'] = 'counselor';
                                $_SESSION['staff_name'] = $_SESSION['counselor_name'];
                                $_SESSION['last_activity'] = time();

                                if (!empty($counselor['must_change_password'])) {
                                    header('Location: set_password.php');
                                } else {
                                    header('Location: admin_dashboard.php');
                                }
                                exit();
                            } else {
                                $error = 'Invalid email or password.';
                            }
                        }
                    } else {
                        $error = 'Invalid email or password.';
                    }
                    $stmtCounselor->close();
                } else {
                    $error = 'Invalid email or password.';
                }
            }
            $stmt->close();
        }
    }    
}
// Check if already logged in
if (isset($_SESSION['counselor_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit();
}

if (isset($_SESSION['admin_id']) && isset($_SESSION['is_admin'])) {
    header('Location: admin_dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin &amp; Staff Portal &middot; <?php echo htmlspecialchars(getSystemConfig('name') ?: 'Career Guidance System'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* Scoped High-Security Executive Admin Login Styles */
        :root {
            --adm-bg: #060a15;
            --adm-card-bg: rgba(15, 23, 42, 0.78);
            --adm-border: rgba(255, 255, 255, 0.08);
            --adm-gold: #fbbf24;
            --adm-gold-hover: #fcd34d;
            --adm-gold-dark: #d97706;
            --adm-blue: #38bdf8;
            --adm-sapphire: #2563eb;
            --adm-emerald: #10b981;
            --adm-text-main: #f8fafc;
            --adm-text-muted: #94a3b8;
        }

        body.admin-login-page {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: var(--adm-bg);
            color: var(--adm-text-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: clip;
            scrollbar-width: thin;
            scrollbar-color: rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6);
        }

        /* Ambient Glowing Cyber Orbs */
        .admin-ambient-glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(130px);
            pointer-events: none;
            z-index: 0;
            opacity: 0.45;
            animation: pulseAdminGlow 14s ease-in-out infinite alternate;
        }

        .admin-ambient-glow.glow-1 {
            top: -12%;
            left: 8%;
            width: 520px;
            height: 520px;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.24) 0%, rgba(245, 158, 11, 0) 70%);
        }

        .admin-ambient-glow.glow-2 {
            bottom: -8%;
            right: 12%;
            width: 560px;
            height: 560px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.22) 0%, rgba(14, 165, 233, 0) 70%);
            animation-duration: 16s;
        }

        .admin-ambient-glow.glow-3 {
            top: 35%;
            left: 48%;
            width: 440px;
            height: 440px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.16) 0%, rgba(59, 130, 246, 0) 70%);
            animation-duration: 19s;
        }

        @keyframes pulseAdminGlow {
            0% { transform: scale(1) translate(0, 0); opacity: 0.35; }
            50% { transform: scale(1.12) translate(25px, -20px); opacity: 0.55; }
            100% { transform: scale(0.94) translate(-15px, 15px); opacity: 0.4; }
        }

        /* Modern Navigation Header */
        .admin-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
            padding: 1rem 2rem;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            background: rgba(6, 10, 21, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            gap: 1rem;
        }

        .admin-nav .back-btn {
            justify-self: start;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            color: var(--adm-text-muted);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-nav .back-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(251, 191, 36, 0.35);
            transform: translateX(-3px);
        }

        .admin-nav .back-btn .btn-text-short {
            display: none;
        }

        .admin-nav .brand-lockup {
            justify-self: center;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            max-width: 100%;
        }

        .admin-nav .brand-lockup .logo-icon,
        .admin-nav .brand-lockup .logo-img,
        .admin-nav .brand-lockup img {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            min-height: 42px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            flex-shrink: 0 !important;
            border: 1.5px solid rgba(251, 191, 36, 0.4);
            box-shadow: 0 0 16px rgba(251, 191, 36, 0.25);
            transition: transform 0.25s ease, border-color 0.25s ease;
        }

        .admin-nav .brand-lockup:hover .logo-icon,
        .admin-nav .brand-lockup:hover .logo-img,
        .admin-nav .brand-lockup img {
            transform: scale(1.06);
            border-color: rgba(251, 191, 36, 0.8);
            box-shadow: 0 0 20px rgba(251, 191, 36, 0.4);
        }

        .admin-nav .brand-lockup h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #ffffff 40%, var(--adm-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }

        .admin-nav .student-shortcut-btn {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            color: #38bdf8;
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 600;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(56, 189, 248, 0.08);
            border: 1px solid rgba(56, 189, 248, 0.25);
            transition: all 0.25s ease;
            white-space: nowrap;
        }

        .admin-nav .student-shortcut-btn:hover {
            color: #ffffff;
            background: rgba(56, 189, 248, 0.18);
            border-color: rgba(56, 189, 248, 0.5);
            transform: translateY(-1px);
        }

        /* Main Section Container */
        .admin-main-container {
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
            position: relative;
            z-index: 1;
            box-sizing: border-box;
        }

        /* Split Card Wrapper */
        .admin-card-wrapper {
            width: 100%;
            max-width: 1040px;
            display: grid;
            grid-template-columns: 1fr 1.05fr;
            background: var(--adm-card-bg);
            border: 1px solid var(--adm-border);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        /* Left Hero Side */
        .admin-hero-side {
            padding: 3.5rem 3rem;
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.65) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .admin-hero-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at top left, rgba(251, 191, 36, 0.06), transparent 70%);
            pointer-events: none;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: var(--adm-gold);
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.42rem 0.9rem;
            border-radius: 9999px;
            margin-bottom: 1.5rem;
            align-self: flex-start;
        }

        .hero-headline {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1.22;
            color: #ffffff;
            margin: 0 0 1rem 0;
            letter-spacing: -0.02em;
        }

        .hero-headline span {
            background: linear-gradient(135deg, #fde68a, #fbbf24);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtext {
            color: var(--adm-text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 2.2rem;
        }

        .hero-features-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 1.15rem;
        }

        .hero-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 0.95rem;
        }

        .hero-feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.28);
            color: var(--adm-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.92rem;
            flex-shrink: 0;
        }

        .hero-feature-icon.icon-blue {
            background: rgba(56, 189, 248, 0.12);
            border-color: rgba(56, 189, 248, 0.28);
            color: #38bdf8;
        }

        .hero-feature-icon.icon-emerald {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.28);
            color: #10b981;
        }

        .hero-feature-text h4 {
            margin: 0 0 0.2rem 0;
            font-size: 0.92rem;
            font-weight: 600;
            color: #f1f5f9;
        }

        .hero-feature-text p {
            margin: 0;
            font-size: 0.81rem;
            color: #94a3b8;
            line-height: 1.45;
        }

        .hero-footer-note {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.82rem;
            color: #94a3b8;
        }

        .hero-footer-note i {
            color: #38bdf8;
            font-size: 1rem;
        }

        /* Right Form Side */
        .admin-form-side {
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(15, 23, 42, 0.88);
            position: relative;
        }

        .form-header {
            margin-bottom: 1.8rem;
        }

        .form-header h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.4rem 0;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .form-header h2 i {
            color: var(--adm-gold);
            font-size: 1.4rem;
        }

        .form-header p {
            margin: 0 0 1rem 0;
            color: var(--adm-text-muted);
            font-size: 0.9rem;
        }

        /* Modern Alert Banners */
        .admin-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            padding: 0.9rem 1.1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.45;
            margin-bottom: 1.5rem;
            animation: fadeInAdminAlert 0.3s ease-out;
        }

        @keyframes fadeInAdminAlert {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .admin-alert.alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        .admin-alert.alert-error i {
            color: #ef4444;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        .admin-alert.alert-success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
        }

        .admin-alert.alert-success i {
            color: #22c55e;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        /* Modern Form Groups */
        .admin-form-group {
            margin-bottom: 1.35rem;
        }

        .admin-form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.86rem;
            font-weight: 600;
            color: #e2e8f0;
        }

        .admin-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .admin-input-icon {
            position: absolute;
            left: 1.1rem;
            color: #64748b;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.25s ease;
        }

        .admin-input {
            width: 100%;
            box-sizing: border-box;
            background: rgba(15, 23, 42, 0.65);
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.9rem 1.1rem 0.9rem 2.85rem;
            color: #ffffff;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-input::placeholder {
            color: #64748b;
        }

        .admin-input:focus {
            outline: none;
            border-color: var(--adm-gold);
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        }

        .admin-input-wrapper:focus-within .admin-input-icon {
            color: var(--adm-gold);
        }

        /* Password Input Specifics */
        .admin-input.has-toggle {
            padding-right: 2.85rem;
        }

        .toggle-password-btn {
            position: absolute;
            right: 0.75rem;
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .toggle-password-btn:hover {
            color: #f1f5f9;
            background: rgba(255, 255, 255, 0.08);
        }

        /* Caps Lock Warning */
        .caps-warning {
            display: none;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.78rem;
            color: #fbbf24;
            margin-top: 0.35rem;
        }

        .caps-warning.visible {
            display: flex;
        }

        /* Error Message Display */
        .error-message {
            color: #ef4444;
            font-size: 0.8rem;
            font-weight: 500;
            display: none;
            margin-top: 0.35rem;
            padding: 0.35rem 0.65rem;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 6px;
            border-left: 3px solid #ef4444;
        }

        .error-message.show {
            display: block;
        }

        /* Autofill Overrides */
        .admin-input:-webkit-autofill,
        .admin-input:-webkit-autofill:hover,
        .admin-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0px 1000px #0b1324 inset !important;
            -webkit-text-fill-color: #ffffff !important;
            caret-color: #ffffff !important;
            color-scheme: dark !important;
            border-color: var(--adm-gold) !important;
        }

        /* Submit Button */
        .admin-login-btn {
            width: 100%;
            padding: 0.95rem 1.5rem;
            margin-top: 0.85rem;
            background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);
            border: none;
            border-radius: 12px;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.45);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-login-btn:hover {
            background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(245, 158, 11, 0.55);
        }

        .admin-login-btn:active {
            transform: translateY(0);
            box-shadow: 0 5px 15px -3px rgba(245, 158, 11, 0.4);
        }

        .admin-login-btn i {
            transition: transform 0.2s ease;
        }

        .admin-login-btn:hover i {
            transform: translateX(3px);
        }

        /* Forgot Password Row (BELOW LOGIN BUTTON) */
        .admin-forgot-row {
            margin-top: 1.15rem;
            text-align: center;
        }

        .admin-forgot-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.88rem;
            color: var(--adm-text-muted);
            text-decoration: none;
            font-weight: 500;
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-forgot-link:hover {
            color: var(--adm-gold);
            background: rgba(251, 191, 36, 0.08);
            text-decoration: none;
            transform: translateY(-1px);
        }

        .admin-forgot-link i {
            font-size: 0.82rem;
            color: var(--adm-gold);
            opacity: 0.8;
            transition: transform 0.25s ease;
        }

        .admin-forgot-link:hover i {
            opacity: 1;
            transform: rotate(-12deg);
        }

        /* Card Footer */
        .form-card-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
            font-size: 0.88rem;
            color: var(--adm-text-muted);
        }

        .form-card-footer a {
            color: var(--adm-gold);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .form-card-footer a:hover {
            color: #fde68a;
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 880px) {
            .admin-card-wrapper {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            .admin-hero-side {
                display: none;
            }
            .admin-form-side {
                padding: 2.8rem 2rem;
            }
            .admin-nav {
                padding: 0.85rem 1.25rem;
            }
        }

        @media (max-width: 560px) {
            .admin-main-container {
                padding: 1.5rem 1rem;
            }
            .admin-form-side {
                padding: 2.2rem 1.35rem;
            }
            .admin-nav {
                grid-template-columns: auto 1fr auto;
                padding: 0.75rem 0.85rem;
                gap: 0.5rem;
            }
            .admin-nav .back-btn .btn-text-full {
                display: none;
            }
            .admin-nav .back-btn .btn-text-short {
                display: inline;
            }
            .admin-nav .brand-lockup h1 {
                font-size: 1rem;
            }
            .admin-nav .brand-lockup .logo-icon,
            .admin-nav .brand-lockup .logo-img,
            .admin-nav .brand-lockup img {
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
            }
            .admin-nav .student-shortcut-btn {
                padding: 0.45rem 0.75rem;
                font-size: 0.8rem;
            }
            .admin-nav .student-shortcut-btn .shortcut-text {
                display: none;
            }
        }
    </style>
</head>
<body class="admin-login-page">

    <!-- Ambient Glowing Cyber Orbs -->
    <div class="admin-ambient-glow glow-1"></div>
    <div class="admin-ambient-glow glow-2"></div>
    <div class="admin-ambient-glow glow-3"></div>

    <!-- Navigation Header -->
    <header class="admin-nav">
        <a href="index.php" class="back-btn" id="backToWebsiteBtn" aria-label="Back to Website" title="Back to Website">
            <i class="fa-solid fa-arrow-left"></i>
            <span class="btn-text-full">Back to Website</span>
            <span class="btn-text-short">Back</span>
        </a>

        <a href="index.php" class="brand-lockup" aria-label="<?php echo htmlspecialchars(getSystemConfig('short_name') ?: 'System'); ?>">
            <?php echo getSystemLogo('logo-icon'); ?>
            <h1><?php echo htmlspecialchars(getSystemConfig('short_name') ?: 'Career Guidance'); ?></h1>
        </a>

      
    </header>

    <!-- Main Container -->
    <main class="admin-main-container">
        <div class="admin-card-wrapper">

            <!-- Left Hero Authority Column -->
            <div class="admin-hero-side">
                <div>
                    <div class="hero-badge">
                        <i class="fa-solid fa-shield-halved"></i> Staff &amp; Admin Console
                    </div>
                    <h2 class="hero-headline">
                        Empowering Institutional <span>Guidance &amp; Leadership</span>
                    </h2>
                    <p class="hero-subtext">
                        Welcome to the dedicated administrative terminal. Oversee student assessment pathways, manage career clusters, review analytical data, and administer counselors.
                    </p>

                    <ul class="hero-features-list">
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-user-shield"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Role-Based Access Control</h4>
                                <p>Strict governance for Super Admins, Administrators, and Counselors.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon icon-blue">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Real-Time Assessment Analytics</h4>
                                <p>Monitor student progress across Holland RIASEC and Big Five assessments.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon icon-emerald">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Curriculum &amp; Strand Alignment</h4>
                                <p>Configure SHS tracks, partner schools, and career cluster pathways.</p>
                            </div>
                        </li>
                    </ul>
                </div>       
            </div>

            <!-- Right Login Form Column -->
            <div class="admin-form-side">
                <div class="form-header">
                    <h2F>
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Admin Sign In</span>
                    </h2>
                    <p>Enter your administrative credentials to access the console</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                <div class="admin-alert alert-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="admin-alert alert-success" role="alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <?php endif; ?>

                <!-- Admin Login Form -->
                <form id="adminLoginForm" class="admin-login-form" action="admin_login.php" method="POST" novalidate>
                    <!-- Email Input -->
                    <div class="admin-form-group">
                        <label for="adminEmail">Staff Email Address</label>
                        <div class="admin-input-wrapper">
                            <i class="fa-solid fa-envelope admin-input-icon"></i>
                            <input 
                                type="email" 
                                id="adminEmail" 
                                name="adminEmail" 
                                class="admin-input" 
                                placeholder="name@domain.edu" 
                                required 
                                autocomplete="username" 
                                spellcheck="false"
                                value="<?php echo htmlspecialchars($_POST['adminEmail'] ?? $_POST['email'] ?? ''); ?>"
                            >
                        </div>
                        <div class="error-message" id="usernameError"></div>
                    </div>

                    <!-- Password Input -->
                    <div class="admin-form-group">
                        <label for="adminPassword">Password</label>
                        <div class="admin-input-wrapper">
                            <i class="fa-solid fa-lock admin-input-icon"></i>
                            <input 
                                type="password" 
                                id="adminPassword" 
                                name="adminPassword" 
                                class="admin-input has-toggle" 
                                placeholder="Enter your password" 
                                required 
                                autocomplete="current-password"
                            >
                            <button type="button" class="toggle-password-btn" id="togglePasswordBtn" onclick="toggleAdminPassword()" title="Show or hide password" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye" id="adminPasswordIcon"></i>
                            </button>
                        </div>
                        <div class="caps-warning" id="capsWarning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Caps Lock is ON</span>
                        </div>
                        <div class="error-message" id="passwordError"></div>
                    </div>

                    <!-- Login Button -->
                    <button type="submit" class="admin-login-btn" id="adminLoginSubmitBtn">
                        <span>Login to Admin Console</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>

                    <!-- Forgot Password Link (BELOW THE LOGIN BUTTON) -->
                    <div class="admin-forgot-row">
                        <a href="forgot_password.php?from=admin" class="admin-forgot-link" id="adminForgotPasswordLink">
                            <i class="fa-solid fa-key"></i>
                            <span>Forgot your password?</span>
                        </a>
                    </div>
                </form>

           
            </div>

        </div>
    </main>

    <script>
        // Password Visibility Toggle
        function toggleAdminPassword() {
            const passwordInput = document.getElementById('adminPassword');
            const icon = document.getElementById('adminPasswordIcon');
            if (!passwordInput || !icon) return;

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Caps Lock Detection
        const passwordInput = document.getElementById('adminPassword');
        const capsWarning = document.getElementById('capsWarning');
        if (passwordInput && capsWarning) {
            passwordInput.addEventListener('keyup', function(e) {
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    capsWarning.classList.add('visible');
                } else {
                    capsWarning.classList.remove('visible');
                }
            });
        }

        // Clear errors on input
        const emailInput = document.getElementById('adminEmail');
        const usernameError = document.getElementById('usernameError');
        const passwordError = document.getElementById('passwordError');

        if (emailInput && usernameError) {
            emailInput.addEventListener('input', function() {
                usernameError.textContent = '';
                usernameError.classList.remove('show');
            });
        }
        if (passwordInput && passwordError) {
            passwordInput.addEventListener('input', function() {
                passwordError.textContent = '';
                passwordError.classList.remove('show');
            });
        }

        // Submit Button Loading State & Client Validation
        const adminLoginForm = document.getElementById('adminLoginForm');
        const submitBtn = document.getElementById('adminLoginSubmitBtn');

        if (adminLoginForm && submitBtn) {
            adminLoginForm.addEventListener('submit', function(e) {
                let hasError = false;
                const emailVal = emailInput ? emailInput.value.trim() : '';
                const passVal = passwordInput ? passwordInput.value : '';

                if (!emailVal) {
                    if (usernameError) {
                        usernameError.textContent = 'Please enter your email address.';
                        usernameError.classList.add('show');
                    }
                    hasError = true;
                }

                if (!passVal) {
                    if (passwordError) {
                        passwordError.textContent = 'Please enter your password.';
                        passwordError.classList.add('show');
                    }
                    hasError = true;
                }

                if (hasError) {
                    e.preventDefault();
                    return false;
                }

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Authenticating Console...</span>';
                submitBtn.style.opacity = '0.85';
                submitBtn.style.cursor = 'wait';
            });
        }
    </script>
    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
