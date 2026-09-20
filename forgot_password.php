<?php
require_once 'config.php';
require_once 'system_config.php';

$fromAdmin = isset($_GET['from']) && $_GET['from'] === 'admin';
$backLink = $fromAdmin ? 'admin_login.php' : 'login.php';
$otpLink = 'otp_verification.php' . ($fromAdmin ? '?from=admin' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="login-page" data-password-reset-from="<?php echo $fromAdmin ? 'admin' : 'student'; ?>">
    <header class="navbar">
        <div class="nav-container">
            <a href="<?php echo $backLink; ?>" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Login
            </a>
            <div class="nav-logo">
                <?php echo getSystemLogo('logo-icon'); ?>
                <h1><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h1>
            </div>
            <div class="placeholder"></div>
        </div>
    </header>

    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-header">
                <h2>Password Recovery</h2>
                <p>Enter your email address and we'll send you a 6-digit code to reset your password.</p>
            </div>
            
            <div id="errorMessage" class="alert alert-error" style="display: none;"></div>
            <div id="successMessage" class="alert alert-success" style="display: none;"></div>
            
            <form id="forgotPasswordForm" method="post" action="#" novalidate>
                <?php if ($fromAdmin): ?>
                <input type="hidden" name="from" value="admin">
                <?php endif; ?>
                <div class="form-group">
                    <label for="recoveryEmail">Email Address</label>
                    <input type="email" id="recoveryEmail" name="email" placeholder="Enter your registered email" required autocomplete="email">
                </div>
                <button type="submit" class="btn-submit" id="sendOtpBtn">Send OTP</button>
            </form>
            <div class="auth-footer">
                <p>Remember your password? <a href="<?php echo $backLink; ?>">Back to Login</a></p>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
