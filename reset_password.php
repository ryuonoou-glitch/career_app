<?php
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/password_reset.php';

$fromAdmin = isset($_GET['from']) && $_GET['from'] === 'admin';
$backLink = $fromAdmin ? 'admin_login.php' : 'login.php';
$forgotLink = $fromAdmin ? 'forgot_password.php?from=admin' : 'forgot_password.php';

$ctx = $_SESSION[password_reset_session_key()] ?? null;
if (!$ctx || empty($ctx['otp_verified'])) {
    header('Location: ' . $forgotLink);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
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
                <h2>Reset Password</h2>
                <p>Create a new secure password for your account.</p>
            </div>

            <div id="errorMessage" class="alert alert-error" style="display: none;"></div>
            <div id="successMessage" class="alert alert-success" style="display: none;"></div>

            <form id="resetPasswordForm" method="post" action="#" novalidate>
                <?php if ($fromAdmin): ?>
                <input type="hidden" name="from" value="admin">
                <?php endif; ?>
                <div class="form-group">
                    <label for="newPassword">New Password</label>
                    <div class="password-input-wrapper" style="position: relative;">
                        <input type="password" id="newPassword" name="newPassword" placeholder="Enter new password" required minlength="8" style="width: 100%;">
                        <button type="button" class="toggle-password" tabindex="-1" title="Show/Hide Password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-requirements">
                        <p>Password must contain:</p>
                        <ul>
                            <li id="req-length"><i class="fa-solid fa-circle"></i> At least 8 characters</li>
                            <li id="req-uppercase"><i class="fa-solid fa-circle"></i> One uppercase letter</li>
                            <li id="req-lowercase"><i class="fa-solid fa-circle"></i> One lowercase letter</li>
                            <li id="req-number"><i class="fa-solid fa-circle"></i> One number</li>
                            <li id="req-special"><i class="fa-solid fa-circle"></i> One special character</li>
                        </ul>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="password-input-wrapper" style="position: relative;">
                        <input type="password" id="confirmPassword" name="confirmPassword" placeholder="Confirm new password" required style="width: 100%;">
                        <button type="button" class="toggle-password" tabindex="-1" title="Show/Hide Password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <span class="error-message" id="passwordError" style="display: none;">Passwords do not match</span>
                </div>

                <button type="submit" class="btn-submit">Reset Password</button>
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
