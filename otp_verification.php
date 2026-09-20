<?php
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/password_reset.php';

$fromAdmin = isset($_GET['from']) && $_GET['from'] === 'admin';
$backLink = $fromAdmin ? 'forgot_password.php?from=admin' : 'forgot_password.php';

$ctx = $_SESSION[password_reset_session_key()] ?? null;
if (!$ctx || empty($ctx['email'])) {
    header('Location: ' . $backLink);
    exit;
}

$maskedEmail = password_reset_mask_email($ctx['email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
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
                <i class="fa-solid fa-arrow-left"></i> Back
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
                <h2>Verify OTP</h2>
                <p>Enter the 6-digit code we sent to your email.</p>
                <p class="email-hint" id="recoveryEmailHint"><?php echo htmlspecialchars($maskedEmail); ?></p>
            </div>
            
            <div id="errorMessage" class="alert alert-error" style="display: none;"></div>
            <div id="successMessage" class="alert alert-success" style="display: none;"></div>
            
            <form id="otpForm" method="post" action="#" novalidate>
                <?php if ($fromAdmin): ?>
                <input type="hidden" name="from" value="admin">
                <?php endif; ?>
                <div class="form-group">
                    <label>Verification Code</label>
                    <div class="otp-inputs">
                        <input type="text" name="otp1" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="one-time-code">
                        <input type="text" name="otp2" class="otp-input" maxlength="1" pattern="[0-9]" required>
                        <input type="text" name="otp3" class="otp-input" maxlength="1" pattern="[0-9]" required>
                        <input type="text" name="otp4" class="otp-input" maxlength="1" pattern="[0-9]" required>
                        <input type="text" name="otp5" class="otp-input" maxlength="1" pattern="[0-9]" required>
                        <input type="text" name="otp6" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    </div>
                </div>

                <div class="timer-section">
                    <p>Code expires in <span class="timer" id="otpTimer">05:00</span></p>
                    <button type="button" class="btn-resend" id="resendBtn" disabled>Resend OTP</button>
                </div>

                <button type="submit" class="btn-submit">Verify & Continue</button>
            </form>

            <div class="auth-footer">
                <p>Didn't receive the code? <a href="<?php echo $backLink; ?>">Try another email</a></p>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
