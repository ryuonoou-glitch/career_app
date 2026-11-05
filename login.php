<?php
require_once 'config.php';
require_once 'system_config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['account']) && $_GET['account'] === 'inactive') {
    $error = 'Your account is not active. Please contact the administrator.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = 'Your password has been reset. You can log in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['session']) && $_GET['session'] === 'expired') {
    $error = 'Your session has expired due to inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have successfully logged out.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['signup'])) {
    if ($_GET['signup'] === 'success') {
        if (isset($_GET['email_sent']) && $_GET['email_sent'] === '1') {
            $success = 'Account created successfully! A confirmation email has been sent to you. Please log in.';
        } else {
            $success = 'Account created successfully! You can now log in below.';
        }
    } elseif ($_GET['signup'] === 'pending') {
        $success = 'Account created successfully! However, your identity must be manually verified. Please wait for an administrator to approve your account before logging in.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        // First check if user is a student
        $stmt = $mysqli->prepare("SELECT id, student_id, first_name, last_name, email, password, status, must_change_password FROM students WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $student = $result->fetch_assoc();

            if ($student['status'] !== 'active' && $student['status'] !== 'graduated' && $student['status'] !== 'inactive') {
                $error = 'Your account is not active. Please contact the administrator.';
            } elseif (password_verify($password, $student['password'])) {
                session_unset();
                session_regenerate_id(true);
                $_SESSION['student_id'] = $student['student_id'];
                $_SESSION['student_db_id'] = $student['id'];
                $_SESSION['first_name'] = $student['first_name'];
                $_SESSION['last_name'] = $student['last_name'];
                $_SESSION['last_activity'] = time();

                $updateStmt = $mysqli->prepare("UPDATE students SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param("i", $student['id']);
                $updateStmt->execute();
                $updateStmt->close();

                // Force password change for admin-created accounts
                if (!empty($student['must_change_password'])) {
                    header('Location: set_password.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            // If not a student, check if user is a counselor
            $stmt = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, email, password, status, must_change_password FROM counselors WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $counselorResult = $stmt->get_result();

            if ($counselorResult->num_rows === 1) {
                $counselor = $counselorResult->fetch_assoc();

                if ($counselor['status'] !== 'active') {
                    $error = 'Your account is not active. Please contact the administrator.';
                } elseif (password_verify($password, $counselor['password'])) {
                    session_unset();
                    session_regenerate_id(true);
                    $_SESSION['counselor_id'] = $counselor['id'];
                    $counselorFullNameParts = array_filter([$counselor['first_name'], $counselor['middle_name'] ?? '', $counselor['last_name']], 'strlen');
                    $_SESSION['counselor_name'] = implode(' ', $counselorFullNameParts);
                    $_SESSION['counselor_email'] = $counselor['email'];
                    $_SESSION['staff_id'] = $counselor['id'];
                    $_SESSION['staff_role'] = 'counselor';
                    $_SESSION['staff_name'] = $_SESSION['counselor_name'];
                    $_SESSION['last_activity'] = time();

                    // Force password change for admin-created accounts
                    if (!empty($counselor['must_change_password'])) {
                        header('Location: set_password.php');
                    } else {
                        header('Location: admin_dashboard.php');
                    }
                    exit();
                } else {
                    $error = 'Invalid email or password.';
                }
            } else {
                $error = 'Invalid email or password.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &middot; <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Scoped Premium Login Aesthetics */
        :root {
            --auth-bg: #070c18;
            --auth-card-bg: rgba(15, 23, 42, 0.75);
            --auth-border: rgba(255, 255, 255, 0.08);
            --auth-border-focus: rgba(251, 191, 36, 0.6);
            --auth-gold: #fbbf24;
            --auth-gold-dark: #d97706;
            --auth-cyan: #38bdf8;
            --auth-text-main: #f8fafc;
            --auth-text-muted: #94a3b8;
        }

        body.login-page {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: var(--auth-bg);
            color: var(--auth-text-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: clip;
        }

        /* Ambient Animated Glows */
        .ambient-glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            pointer-events: none;
            z-index: 0;
            opacity: 0.45;
            animation: pulseGlow 12s ease-in-out infinite alternate;
        }
        .ambient-glow.glow-1 {
            top: -10%;
            left: 10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.25) 0%, rgba(245, 158, 11, 0) 70%);
        }
        .ambient-glow.glow-2 {
            bottom: -5%;
            right: 15%;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.2) 0%, rgba(14, 165, 233, 0) 70%);
            animation-duration: 15s;
        }
        .ambient-glow.glow-3 {
            top: 40%;
            left: 45%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, rgba(99, 102, 241, 0) 70%);
            animation-duration: 18s;
        }

        @keyframes pulseGlow {
            0% { transform: scale(1) translate(0, 0); opacity: 0.35; }
            50% { transform: scale(1.15) translate(20px, -20px); opacity: 0.55; }
            100% { transform: scale(0.95) translate(-15px, 15px); opacity: 0.4; }
        }

        /* Modern Navigation Header */
        .login-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
            padding: 1rem 2rem;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(7, 12, 24, 0.82);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            gap: 1rem;
        }

        .login-nav .back-btn {
            justify-self: start;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            color: var(--auth-text-muted);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .login-nav .back-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(251, 191, 36, 0.35);
            transform: translateX(-3px);
        }

        .login-nav .back-btn:active {
            transform: scale(0.96);
        }

        .login-nav .back-btn .btn-text-short {
            display: none;
        }

        .login-nav .brand-lockup {
            justify-self: center;
            grid-column: 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            min-width: 0;
            max-width: 100%;
        }

        .login-nav .brand-lockup .logo-icon,
        .login-nav .brand-lockup .logo-img,
        .login-nav .brand-lockup img {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            min-height: 42px !important;
            max-width: 42px !important;
            max-height: 42px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            flex-shrink: 0 !important;
            border: 1.5px solid rgba(251, 191, 36, 0.35);
            box-shadow: 0 0 14px rgba(251, 191, 36, 0.2);
            transition: transform 0.25s ease, border-color 0.25s ease;
        }

        .login-nav .brand-lockup:hover .logo-icon,
        .login-nav .brand-lockup:hover .logo-img,
        .login-nav .brand-lockup:hover img {
            transform: scale(1.06);
            border-color: rgba(251, 191, 36, 0.7);
            box-shadow: 0 0 18px rgba(251, 191, 36, 0.35);
        }

        .login-nav .brand-lockup h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #ffffff 40%, var(--auth-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .login-nav .signup-shortcut-btn,
        .login-nav .auth-shortcut-btn {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--auth-gold);
            text-decoration: none;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(251, 191, 36, 0.08);
            border: 1px solid rgba(251, 191, 36, 0.25);
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .login-nav .signup-shortcut-btn:hover,
        .login-nav .auth-shortcut-btn:hover {
            background: rgba(251, 191, 36, 0.16);
            border-color: var(--auth-gold);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(251, 191, 36, 0.2);
        }

        .login-nav .signup-shortcut-btn:active,
        .login-nav .auth-shortcut-btn:active {
            transform: scale(0.96);
        }

        .login-nav .nav-spacer {
            justify-self: end;
            width: 36px;
            height: 1px;
            visibility: hidden;
        }

        /* Main Container & Split Layout */
        .login-main-container {
            position: relative;
            z-index: 5;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
            box-sizing: border-box;
        }

        .login-card-wrapper {
            width: 100%;
            max-width: 960px;
            background: var(--auth-card-bg);
            border: 1px solid var(--auth-border);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            overflow: hidden;
            position: relative;
        }

        /* Left Hero Panel */
        .login-hero-side {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.9) 100%);
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid rgba(255, 255, 255, 0.06);
            position: relative;
        }

        .login-hero-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--auth-gold), var(--auth-cyan));
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: var(--auth-gold);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.4rem 0.85rem;
            border-radius: 9999px;
            margin-bottom: 1.5rem;
            align-self: flex-start;
        }

        .hero-headline {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.15rem;
            font-weight: 800;
            line-height: 1.2;
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
            color: var(--auth-text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .hero-features-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .hero-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .hero-feature-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.25);
            color: var(--auth-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .hero-feature-text h4 {
            margin: 0 0 0.2rem 0;
            font-size: 0.92rem;
            font-weight: 600;
            color: #f1f5f9;
        }

        .hero-feature-text p {
            margin: 0;
            font-size: 0.8rem;
            color: #94a3b8;
            line-height: 1.4;
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
        .login-form-side {
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(15, 23, 42, 0.85);
        }

        .form-header {
            margin-bottom: 2rem;
        }

        .form-header h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.4rem 0;
            letter-spacing: -0.01em;
        }

        .form-header p {
            margin: 0;
            color: var(--auth-text-muted);
            font-size: 0.9rem;
        }



        /* Alerts */
        .login-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            padding: 0.9rem 1.1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.45;
            margin-bottom: 1.5rem;
            animation: fadeInAlert 0.3s ease-out;
        }

        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-alert.alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }
        .login-alert.alert-error i {
            color: #ef4444;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        .login-alert.alert-success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
        }
        .login-alert.alert-success i {
            color: #22c55e;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        /* Modern Form Groups */
        .login-form-group {
            margin-bottom: 1.35rem;
        }

        .login-form-group label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.86rem;
            font-weight: 600;
            color: #e2e8f0;
        }

        .login-form-group .label-link {
            font-size: 0.82rem;
            color: var(--auth-gold);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .login-form-group .label-link:hover {
            color: #fde68a;
            text-decoration: underline;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-prefix {
            position: absolute;
            left: 1.1rem;
            color: #64748b;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .login-input {
            width: 100%;
            box-sizing: border-box;
            background: rgba(15, 23, 42, 0.6);
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.9rem 1.1rem 0.9rem 2.85rem;
            color: #ffffff;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .login-input::placeholder {
            color: #64748b;
        }

        .login-input:focus {
            outline: none;
            border-color: var(--auth-gold);
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        }

        .login-input:focus + .input-icon-prefix,
        .input-wrapper:focus-within .input-icon-prefix {
            color: var(--auth-gold);
        }

        .input-wrapper .toggle-password-btn {
            position: absolute;
            right: 0.75rem;
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 8px;
            font-size: 0.95rem;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .input-wrapper .toggle-password-btn.visible {
            display: flex;
        }

        .input-wrapper .toggle-password-btn:hover {
            color: #f1f5f9;
            background: rgba(255, 255, 255, 0.06);
        }

        /* Autofill Overrides */
        .login-input:-webkit-autofill,
        .login-input:-webkit-autofill:hover,
        .login-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0px 1000px #0b1324 inset !important;
            -webkit-text-fill-color: #ffffff !important;
            caret-color: #ffffff !important;
            color-scheme: dark !important;
            border-color: var(--auth-gold) !important;
        }

        .input-feedback {
            font-size: 0.8rem;
            margin-top: 0.35rem;
            display: none;
        }
        .input-feedback.visible {
            display: block;
        }

        /* Submit Button */
        .btn-auth-submit {
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
            gap: 0.6rem;
            box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.45);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-auth-submit:hover {
            background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(245, 158, 11, 0.55);
        }

        .btn-auth-submit:active {
            transform: translateY(0);
            box-shadow: 0 5px 15px -3px rgba(245, 158, 11, 0.4);
        }

        .btn-auth-submit i {
            transition: transform 0.2s ease;
        }
        .btn-auth-submit:hover i {
            transform: translateX(3px);
        }

        /* Forgot Password Row (Below Login) */
        .forgot-password-row {
            margin-top: 1.15rem;
            text-align: center;
        }

        .forgot-password-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.88rem;
            color: var(--auth-text-muted);
            text-decoration: none;
            font-weight: 500;
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .forgot-password-link-btn:hover {
            color: var(--auth-gold);
            background: rgba(251, 191, 36, 0.08);
            text-decoration: none;
            transform: translateY(-1px);
        }

        .forgot-password-link-btn i {
            font-size: 0.82rem;
            color: var(--auth-gold);
            opacity: 0.8;
            transition: transform 0.25s ease;
        }

        .forgot-password-link-btn:hover i {
            opacity: 1;
            transform: rotate(-12deg);
        }

        /* Form Card Footer */
        .form-card-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
            font-size: 0.88rem;
            color: var(--auth-text-muted);
        }

        .form-card-footer a {
            color: var(--auth-gold);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .form-card-footer a:hover {
            color: #fde68a;
            text-decoration: underline;
        }

        /* Responsive Breakpoints */
        @media (max-width: 860px) {
            .login-card-wrapper {
                grid-template-columns: 1fr;
                max-width: 480px;
            }
            .login-hero-side {
                display: none; /* Streamlined single-column on mobile */
            }
            .login-form-side {
                padding: 2.5rem 2rem;
            }
            .login-nav {
                padding: 0.85rem 1.25rem;
                gap: 0.75rem;
            }
            .login-nav .brand-lockup .logo-icon,
            .login-nav .brand-lockup .logo-img,
            .login-nav .brand-lockup img {
                width: 36px !important;
                height: 36px !important;
                min-width: 36px !important;
                min-height: 36px !important;
                max-width: 36px !important;
                max-height: 36px !important;
            }
            .login-nav .brand-lockup h1 {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 640px) {
            .login-nav {
                grid-template-columns: auto 1fr auto;
                padding: 0.75rem 1rem;
                gap: 0.6rem;
            }
            .login-nav .back-btn {
                padding: 0.45rem 0.85rem;
                font-size: 0.82rem;
                gap: 0.45rem;
            }
            .login-nav .back-btn .btn-text-full {
                display: none !important;
            }
            .login-nav .back-btn .btn-text-short {
                display: inline !important;
            }
            .login-nav .signup-shortcut-btn,
            .login-nav .auth-shortcut-btn {
                padding: 0.45rem 0.85rem;
                font-size: 0.82rem;
                gap: 0.45rem;
            }
            .login-nav .brand-lockup {
                gap: 0.5rem;
            }
            .login-nav .brand-lockup .logo-icon,
            .login-nav .brand-lockup .logo-img,
            .login-nav .brand-lockup img {
                width: 32px !important;
                height: 32px !important;
                min-width: 32px !important;
                min-height: 32px !important;
                max-width: 32px !important;
                max-height: 32px !important;
            }
            .login-nav .brand-lockup h1 {
                font-size: 0.96rem;
            }
        }

        @media (max-width: 480px) {
            .login-main-container {
                padding: 1.5rem 1rem;
            }
            .login-form-side {
                padding: 2rem 1.25rem;
            }
            .form-header h2 {
                font-size: 1.5rem;
            }
            .login-nav {
                padding: 0.55rem 0.65rem;
                gap: 0.4rem;
            }
            .login-nav .back-btn {
                padding: 0.38rem 0.6rem;
                font-size: 0.76rem;
                gap: 0.35rem;
            }
            .login-nav .signup-shortcut-btn,
            .login-nav .auth-shortcut-btn {
                padding: 0.38rem 0.6rem;
                font-size: 0.76rem;
                gap: 0.35rem;
            }
            .login-nav .brand-lockup {
                gap: 0.35rem;
            }
            .login-nav .brand-lockup .logo-icon,
            .login-nav .brand-lockup .logo-img,
            .login-nav .brand-lockup img {
                width: 28px !important;
                height: 28px !important;
                min-width: 28px !important;
                min-height: 28px !important;
                max-width: 28px !important;
                max-height: 28px !important;
            }
            .login-nav .brand-lockup h1 {
                font-size: 0.86rem;
            }
            .login-nav .nav-spacer {
                display: none;
            }
        }

        @media (max-width: 350px) {
            .login-nav {
                padding: 0.5rem 0.55rem;
                gap: 0.35rem;
            }
            .login-nav .back-btn {
                width: 34px;
                height: 34px;
                min-width: 34px;
                padding: 0 !important;
                border-radius: 50%;
                gap: 0;
            }
            .login-nav .back-btn .btn-text-full,
            .login-nav .back-btn .btn-text-short,
            .login-nav .back-btn span {
                display: none !important;
            }
            .login-nav .signup-shortcut-btn,
            .login-nav .auth-shortcut-btn {
                width: 34px;
                height: 34px;
                min-width: 34px;
                padding: 0 !important;
                border-radius: 50%;
                gap: 0;
            }
            .login-nav .signup-shortcut-btn .shortcut-text,
            .login-nav .auth-shortcut-btn .shortcut-text {
                display: none !important;
            }
            .login-nav .brand-lockup .logo-icon,
            .login-nav .brand-lockup .logo-img,
            .login-nav .brand-lockup img {
                width: 24px !important;
                height: 24px !important;
                min-width: 24px !important;
                min-height: 24px !important;
                max-width: 24px !important;
                max-height: 24px !important;
            }
            .login-nav .brand-lockup h1 {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body class="login-page">

    <!-- Ambient Glowing Backdrops -->
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>
    <div class="ambient-glow glow-3"></div>

    <!-- Header Navigation -->
    <header class="login-nav">
        <a href="index.php" class="back-btn" id="backToHomeBtn" aria-label="Back to Home" title="Back to Home">
            <i class="fa-solid fa-arrow-left"></i>
            <span class="btn-text-full">Back to Home</span>
            <span class="btn-text-short">Back</span>
        </a>

        <a href="index.php" class="brand-lockup" aria-label="<?php echo htmlspecialchars(getSystemConfig('short_name')); ?>">
            <?php echo getSystemLogo('logo-icon'); ?>
            <h1><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h1>
        </a>


    </header>

    <!-- Main Content Container -->
    <main class="login-main-container">
        <div class="login-card-wrapper">

            <!-- Left Hero Brand Column -->
            <div class="login-hero-side">
                <div>
                    <div class="hero-badge">
                        <i class="fa-solid fa-compass"></i> Career Guidance Platform
                    </div>
                    <h2 class="hero-headline">
                        Discover Your <span>True Potential</span>
                    </h2>
                    <p class="hero-subtext">
                        Welcome to the intelligent career assessment system designed to guide senior high school students toward the perfect college degree and profession.
                    </p>

                    <ul class="hero-features-list">
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-brain"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Holland RIASEC Assessment</h4>
                                <p>Evaluates career interests across 6 psychological domains.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-chart-pie"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Big Five Personality Traits</h4>
                                <p>Aligns behavioral strengths with industry environments.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Academic Strand Alignment</h4>
                                <p>Personalized course recommendations for SHS tracks.</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="hero-footer-note">
                    <i class="fa-solid fa-shield-check"></i>
                    <span>Secure student career guidance portal</span>
                </div>
            </div>

            <!-- Right Login Form Column -->
            <div class="login-form-side">
                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Enter your credentials to access your account</p>
                </div>

                <?php if ($error): ?>
                <div class="login-alert alert-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="login-alert alert-success" role="alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="" novalidate>
                    <!-- Email Field -->
                    <div class="login-form-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-envelope input-icon-prefix"></i>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                class="login-input" 
                                placeholder="name@example.com" 
                                required 
                                autocomplete="username" 
                                spellcheck="false"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            >
                        </div>
                        <div class="input-feedback" id="emailValidation"></div>
                    </div>

                    <!-- Password Field -->
                    <div class="login-form-group">
                        <label for="password">
                            <span>Password</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock input-icon-prefix"></i>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="login-input" 
                                placeholder="Enter your password" 
                                required 
                                autocomplete="current-password"
                            >
                            <button type="button" class="toggle-password-btn" id="togglePasswordBtn" title="Show or hide password" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye" id="passwordEyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-auth-submit" id="loginSubmitBtn">
                        <span>Sign In to Account</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>

                    <!-- Forgot Password Link (Below Login) -->
                    <div class="forgot-password-row">
                        <a href="forgot_password.php" class="forgot-password-link-btn" id="forgotPasswordLink">
                            <i class="fa-solid fa-key"></i>
                            <span>Forgot password?</span>
                        </a>
                    </div>
                </form>

                <div class="form-card-footer">
                    <p>Don't have an account yet? <a href="signup.php" id="signupLink">Create Account</a></p>
                </div>
            </div>

        </div>
    </main>

    <script>
        // Password Visibility Toggle - only show when typing
        const togglePasswordBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('password');
        const passwordEyeIcon = document.getElementById('passwordEyeIcon');

        if (togglePasswordBtn && passwordInput && passwordEyeIcon) {
            const checkPasswordVisibility = () => {
                if (passwordInput.value.length > 0) {
                    togglePasswordBtn.classList.add('visible');
                } else {
                    togglePasswordBtn.classList.remove('visible');
                }
            };

            passwordInput.addEventListener('input', checkPasswordVisibility);
            checkPasswordVisibility();

            togglePasswordBtn.addEventListener('click', function() {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                passwordEyeIcon.classList.toggle('fa-eye', !isPassword);
                passwordEyeIcon.classList.toggle('fa-eye-slash', isPassword);
            });
        }

        // Debounced Client-Side Email Format Check
        const emailInput = document.getElementById('email');
        const emailValidation = document.getElementById('emailValidation');

        if (emailInput && emailValidation) {
            let debounceTimer = null;
            const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

            emailInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const val = this.value.trim();

                if (!val) {
                    emailValidation.classList.remove('visible');
                    emailValidation.textContent = '';
                    emailInput.style.borderColor = '';
                    return;
                }

                debounceTimer = setTimeout(() => {
                    if (!emailRegex.test(val)) {
                        emailValidation.textContent = 'Please enter a valid email address.';
                        emailValidation.style.color = '#f87171';
                        emailValidation.classList.add('visible');
                        emailInput.style.borderColor = 'rgba(239, 68, 68, 0.6)';
                    } else {
                        emailValidation.classList.remove('visible');
                        emailValidation.textContent = '';
                        emailInput.style.borderColor = '';
                    }
                }, 400);
            });
        }

        // Form Submit Loading State
        const loginForm = document.getElementById('loginForm');
        const loginSubmitBtn = document.getElementById('loginSubmitBtn');

        if (loginForm && loginSubmitBtn) {
            loginForm.addEventListener('submit', function() {
                const emailVal = emailInput ? emailInput.value.trim() : '';
                const passwordVal = passwordInput ? passwordInput.value : '';

                if (emailVal && passwordVal) {
                    loginSubmitBtn.disabled = true;
                    loginSubmitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Authenticating...</span>';
                    loginSubmitBtn.style.opacity = '0.85';
                    loginSubmitBtn.style.cursor = 'wait';
                }
            });
        }
    </script>

    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
