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
            <p>In accordance with School policies and standard educational data privacy laws (e.g., the Data Privacy Act of 2012), the <?php echo htmlspecialchars(getSystemConfig('name')); ?> collects specific data to provide tailored career guidance:</p>
            <ul>
                <li><strong>Personal Data:</strong> Name, valid Student ID, email address, date of birth, gender, and contact details as verified against School records.</li>
                <li><strong>Academic Information:</strong> Grade level, academic strand, and current school year enrollment.</li>
                <li><strong>Assessment Data:</strong> Your responses to career assessments, which form part of your internal academic profile.</li>
                <li><strong>System Usage Data:</strong> Timestamps of assessments taken, login activity, and profile updates for auditing purposes.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>2. How We Use Your Information</h2>
            <p>Data collected is used strictly for academic and career counseling purposes within the School:</p>
            <ul>
                <li>To generate personalized career path, course, and school recommendations based on your assessment results.</li>
                <li>To equip School Counselors and Administrators with accurate data to monitor student progress and provide effective guidance interventions.</li>
                <li>To generate anonymized, aggregated statistical reports for the School Administration to analyze curriculum alignment and student career interests.</li>
                <li>To maintain the security and academic integrity of the System.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Data Sharing and Disclosure</h2>
            <p>Your data is strictly confidential and treated as sensitive educational records. We do not sell, trade, or share your personal information with external third parties without explicit consent from you or your legal guardian.</p>
            <p>Internally, your data is accessible only on a strict need-to-know basis to:</p>
            <ul>
                <li><strong>You:</strong> Through your personal, secured student dashboard.</li>
                <li><strong>School Counselors:</strong> To provide you with personalized academic, behavioral, and career counseling.</li>
                <li><strong>System Administrators:</strong> Exclusively for system maintenance, auditing, and generating high-level School reports.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Data Security & Retention</h2>
            <p>The School employs strict security protocols to safeguard your educational records. These include encrypted passwords, secure database architecture, and restricted access controls. Student data is retained in accordance with the School's official records retention policy, after which it is securely archived or disposed of.</p>
        </div>

        <div class="legal-section">
            <h2>5. Student Rights</h2>
            <p>As a student, you (and your parents/guardians) have the right to review the personal information and assessment results stored in the System. If there are discrepancies in your demographic or academic data, you must coordinate with the School Registrar or Guidance Office for official correction. For inquiries regarding data deletion or privacy concerns, please contact your School Administrator.</p>
        </div>

        <div class="legal-section">
            <h2>6. Changes to This Privacy Policy</h2>
            <p>The School reserves the right to update this Privacy Policy to comply with new Department of Education mandates or legal privacy requirements. Updates will be broadcasted via official School announcements and updated on this page.</p>
        </div>

        <div class="legal-section">
            <h2>7. Contact the School</h2>
            <p>If you have any questions or concerns regarding this Privacy Policy or the handling of your educational records, please immediately contact the School's Guidance Office, the Data Protection Officer (DPO), or the Principal's Office.</p>
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
