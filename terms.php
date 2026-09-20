<?php
require_once 'config.php';
require_once 'system_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms and Conditions - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .legal-container {
            max-width: 800px;
            margin: 120px auto 40px;
            padding: 2.5rem;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(251, 191, 36, 0.2);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            color: #e2e8f0;
            line-height: 1.7;
        }
        .legal-header {
            text-align: center;
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid rgba(251, 191, 36, 0.2);
        }
        .legal-header h1 {
            font-size: 2.5rem;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }
        .legal-header p {
            color: #94a3b8;
            font-size: 1.1rem;
        }
        .legal-section {
            margin-bottom: 2rem;
        }
        .legal-section h2 {
            color: #fbbf24;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        .legal-section p {
            margin-bottom: 1rem;
        }
        .legal-section ul {
            list-style-type: disc;
            margin-left: 2rem;
            margin-bottom: 1rem;
        }
        .legal-section li {
            margin-bottom: 0.5rem;
            color: #94a3b8;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #fbbf24;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .back-btn:hover {
            color: #f59e0b;
            transform: translateX(-4px);
        }
        @media (max-width: 768px) {
            .legal-container {
                margin: 100px 15px 40px;
                padding: 1.5rem;
            }
            .legal-header h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body class="login-page">
    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="javascript:history.back()" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <div class="nav-logo">
                <?php echo getSystemLogo('logo-icon'); ?>
                <h1><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h1>
            </div>
            <div class="placeholder" style="width: 70px;"></div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="legal-container">
        <div class="legal-header">
            <h1>Terms and Conditions</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="legal-section">
            <h2>1. Acceptance of Terms</h2>
            <p>By accessing and using the <?php echo htmlspecialchars(getSystemConfig('name')); ?> (hereinafter referred to as the "System"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by these terms, please do not use this System.</p>
        </div>

        <div class="legal-section">
            <h2>2. Purpose of the System</h2>
            <p>The System is designed to provide career guidance, assessment tools, and course recommendations for students. The recommendations provided are generated based on your assessment results and are intended for guidance purposes only. They do not guarantee admission into specific schools, courses, or career paths.</p>
        </div>

        <div class="legal-section">
            <h2>3. User Accounts</h2>
            <ul>
                <li>You must be a registered student, counselor, or administrator to access the full features of the System.</li>
                <li>You are responsible for maintaining the confidentiality of your login credentials.</li>
                <li>You agree to provide accurate and complete information when registering or updating your profile.</li>
                <li>The administration reserves the right to suspend or terminate accounts that violate these terms or institutional policies.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Privacy and Data Usage</h2>
            <p>Your privacy is important to us. Information collected through the System, including assessment results, demographic data, and profile details, will be used solely for the purpose of providing career guidance and producing generalized statistical reports for the institution. Please refer to our <a href="privacy.php" style="color: #fbbf24;">Privacy Policy</a> for more detailed information on how your data is handled.</p>
        </div>

        <div class="legal-section">
            <h2>5. Intellectual Property</h2>
            <p>All content, algorithms, assessments, and visual design included in this System are the property of the institution or its content suppliers and are protected by applicable copyright and intellectual property laws.</p>
        </div>

        <div class="legal-section">
            <h2>6. Limitation of Liability</h2>
            <p>The institution and the developers of this System shall not be held liable for any direct, indirect, incidental, or consequential damages resulting from the use or inability to use the System or the recommendations provided.</p>
        </div>

        <div class="legal-section">
            <h2>7. Modifications to Terms</h2>
            <p>We reserve the right to modify these terms at any time. Significant changes will be communicated to users through the System's notification features. Continued use of the System after any such changes constitutes your consent to such changes.</p>
        </div>

        <div style="margin-top: 3rem; text-align: center;">
            <a href="javascript:history.back()" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i> Return to Previous Page
            </a>
        </div>
    </div>

    <?php require_once 'includes/public_footer.php'; ?>
</body>
</html>
