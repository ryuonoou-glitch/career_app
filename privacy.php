<?php
require_once 'config.php';
require_once 'system_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
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
            <h1>Privacy Policy</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="legal-section">
            <h2>1. Information We Collect</h2>
            <p>When you use the <?php echo htmlspecialchars(getSystemConfig('name')); ?>, we collect various types of information to provide you with tailored career guidance and recommendations. This includes:</p>
            <ul>
                <li><strong>Personal Data:</strong> Name, Student ID, email address, date of birth, gender, and contact details.</li>
                <li><strong>Academic Information:</strong> Grade level, academic strand, and school year.</li>
                <li><strong>Assessment Data:</strong> Your responses to career assessments, including skills, interests, and personality tests.</li>
                <li><strong>System Usage Data:</strong> Timestamps of assessments taken, login activity, and profile updates.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>2. How We Use Your Information</h2>
            <p>We use the collected information for the following purposes:</p>
            <ul>
                <li>To generate personalized career path, course, and school recommendations based on your assessment results.</li>
                <li>To allow school counselors and administrators to monitor your progress and provide direct guidance.</li>
                <li>To generate anonymized, aggregated statistical reports for school administration to analyze trends in student career interests.</li>
                <li>To maintain the security and integrity of the System.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Data Sharing and Disclosure</h2>
            <p>Your data is strictly confidential and is treated with the utmost care. We do not sell, trade, or rent your personal information to third parties.</p>
            <p>Your data will only be accessible to:</p>
            <ul>
                <li><strong>You:</strong> Through your personal student dashboard.</li>
                <li><strong>School Counselors:</strong> To provide you with personalized academic and career counseling.</li>
                <li><strong>System Administrators:</strong> For the purpose of system maintenance and generating school-wide reports.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Data Security</h2>
            <p>We implement a variety of security measures to maintain the safety of your personal information. These include secure password hashing, prepared database statements to prevent injection attacks, and restricted access controls for administrative areas.</p>
        </div>

        <div class="legal-section">
            <h2>5. Your Rights</h2>
            <p>You have the right to view the personal information and assessment results stored in the System. If you believe any of your demographic information is incorrect, you may update it through your profile page. To request deletion of your account or data, please contact your school counselor or system administrator.</p>
        </div>

        <div class="legal-section">
            <h2>6. Changes to This Privacy Policy</h2>
            <p>We may update our Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last updated" date.</p>
        </div>

        <div class="legal-section">
            <h2>7. Contact Us</h2>
            <p>If you have any questions about this Privacy Policy or how your data is handled, please contact your school's guidance office or the system administrator.</p>
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
