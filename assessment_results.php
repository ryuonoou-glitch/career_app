<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

$student = getCurrentStudent();

$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);
$studentId = $student['id'] ?? 0;

// Get current school year safely
$yearResult = $mysqli->query("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
$yearRow = $yearResult ? $yearResult->fetch_assoc() : null;
$schoolYearId = $yearRow ? (int)$yearRow['id'] : 1;

// Fetch limits and attempt counts
$MAX_ASSESSMENTS = 2;
$completedCount = 0;
$abandonedCount = 0;

if ($studentId > 0) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status = 'completed'");
    $stmt->bind_param("ii", $studentId, $schoolYearId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $completedCount = $result['count'] ?? 0;
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status = 'abandoned'");
    $stmt->bind_param("ii", $studentId, $schoolYearId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $abandonedCount = $result['count'] ?? 0;
    $stmt->close();
}


// Fetch latest assessment results
$assessmentResults = null;
$careerScore = 0;
$personalityScore = 0;
$skillsScore = 0;
$strandScore = 0;
$completionDate = null;

if ($studentId > 0) {
    $assessmentIdParam = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($assessmentIdParam > 0) {
        // Get the specific completed assessment, verifying it belongs to this student
        $stmt = $mysqli->prepare("SELECT id, completed_at, total_score, expires_at FROM student_assessments WHERE id = ? AND student_id = ? AND status = 'completed' LIMIT 1");
        $stmt->bind_param("ii", $assessmentIdParam, $studentId);
    } else {
        // Get the latest completed assessment
        $stmt = $mysqli->prepare("SELECT id, completed_at, total_score, expires_at FROM student_assessments WHERE student_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
        $stmt->bind_param("i", $studentId);
    }
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $assessment = $result->fetch_assoc();
        $stmt->close();
    
        if ($assessment) {
            $assessmentId = $assessment['id'];
            $completionDate = date('F d, Y', strtotime($assessment['completed_at']));
            $expiresAtStr = $assessment['expires_at'];
            if (empty($expiresAtStr)) {
                $expiresAtStr = date('Y-m-d H:i:s', strtotime($assessment['completed_at'] . ' + 24 hours'));
            }
            $expiresAtDate = date('F d, Y', strtotime($expiresAtStr));
            
            $isExpired = false;
            if (strtotime($expiresAtStr) < time()) {
                $isExpired = true;
            }
    
            // Get category scores from pre-computed category_scores table
            $stmt = $mysqli->prepare("
                SELECT category, score, percentage
                FROM category_scores
                WHERE assessment_id = ?
            ");
            $stmt->bind_param("i", $assessmentId);
            $stmt->execute();
            $result = $stmt->get_result();
    
            $assessmentResults = [
                'career' => ['score' => 0.0, 'percentage' => 0.0],
                'personality' => ['score' => 0.0, 'percentage' => 0.0],
                'skills' => ['score' => 0.0, 'percentage' => 0.0],
                'strand' => ['score' => 0.0, 'percentage' => 0.0],
                'completed_at' => $completionDate,
                'total_score' => (float)$assessment['total_score']
            ];
    
            while ($row = $result->fetch_assoc()) {
                $cat = $row['category'];
                if (isset($assessmentResults[$cat])) {
                    $assessmentResults[$cat]['score'] = (float)$row['score'];
                    $assessmentResults[$cat]['percentage'] = (float)$row['percentage'];
                }
            }
            $stmt->close();
            
            // Recompute total_score as average of categories if needed or just use DB value
            // We will use the DB total_score value as per instructions
        }
    }
}

// Generate initials for avatar
$initials = '';
if ($student) {
    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
}

function getQualitativeLabel($category, $percentage) {
    if ($percentage >= 70) {
        switch ($category) {
            case 'career': return 'Strong interest alignment';
            case 'personality': return 'Strong trait alignment';
            case 'skills': return 'Strong technical skills';
            case 'strand': return 'Strong academic fit';
        }
    } elseif ($percentage >= 40) {
        switch ($category) {
            case 'career': return 'Moderate interest alignment';
            case 'personality': return 'Well-rounded traits';
            case 'skills': return 'Developing technical skills';
            case 'strand': return 'Good academic fit';
        }
    } else {
        switch ($category) {
            case 'career': return 'Room to explore interests';
            case 'personality': return 'Room to grow';
            case 'skills': return 'Room to grow technically';
            case 'strand': return 'Room to improve fit';
        }
    }
    return '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css">
</head>
<body>
    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <?php echo getSystemLogo('logo-icon'); ?>
                    <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
                </div>
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Close sidebar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <i class="fa-solid fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="take_assessment.php" class="nav-item">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>Take Assessment</span>
                </a>
                <a href="assessment_results.php" class="nav-item active">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Assessment Results</span>
                </a>
                <a href="recommended_courses.php" class="nav-item">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Recommendations</span>
                </a>
                <a href="profile.php" class="nav-item">
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="logout.php?role=student" class="nav-item logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Mobile sidebar overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <!-- Title removed here to match requirement where title is in the content -->
                </div>
                <div class="top-bar-actions">
                    <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                    <div class="user-profile">
                        <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar">
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo $studentName; ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Results Content -->
            <div class="dashboard-content">
                <!-- Assessment Results -->
                    <section class="assessment-results" style="background: transparent; border: none; padding: 0;">
                        <style>
                            .summary-cards-row {
                                display: grid;
                                grid-template-columns: repeat(3, 1fr);
                                gap: 20px;
                                margin-top: 24px;
                            }
                            @media (max-width: 900px) {
                                .summary-cards-row {
                                    grid-template-columns: 1fr;
                                }
                            }
                            .summary-card {
                                background-color: #111827;
                                border: 1px solid rgba(255, 255, 255, 0.08);
                                border-radius: 12px;
                                padding: 20px;
                                display: flex;
                                align-items: center;
                                gap: 16px;
                            }
                            .summary-icon-container {
                                width: 48px;
                                height: 48px;
                                border-radius: 50%;
                                background: rgba(255, 255, 255, 0.05);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: #94a3b8;
                                font-size: 1.25rem;
                            }
                            .summary-content {
                                display: flex;
                                flex-direction: column;
                            }
                            .summary-content .summary-label {
                                font-size: 0.85rem;
                                color: #94a3b8;
                                margin-bottom: 4px;
                            }
                            .summary-content .summary-value {
                                font-size: 1.25rem;
                                color: #f8fafc;
                                font-weight: 700;
                            }
                            .summary-value.highlight-orange { color: #f59e0b; }
                            .summary-value.status-active { color: #10b981; }
                            .summary-value.status-expired { color: #94a3b8; }
                            .status-icon-active { color: #10b981; }
                            .status-icon-expired { color: #94a3b8; }
                            
                            .score-cards-grid {
                                display: grid;
                                grid-template-columns: repeat(4, 1fr);
                                gap: 20px;
                                margin-top: 32px;
                                margin-bottom: 24px;
                            }
                            @media (max-width: 1200px) {
                                .score-cards-grid {
                                    grid-template-columns: 1fr 1fr;
                                }
                            }
                            @media (max-width: 600px) {
                                .score-cards-grid {
                                    grid-template-columns: 1fr;
                                }
                            }
                            
                            .score-card-redesign {
                                background-color: #111827;
                                border: 1px solid rgba(255, 255, 255, 0.08);
                                border-radius: 12px;
                                padding: 24px;
                                display: flex;
                                flex-direction: row;
                                align-items: center;
                                gap: 20px;
                            }
                            .progress-ring-container {
                                position: relative;
                                width: 64px;
                                height: 64px;
                                border-radius: 50%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-shrink: 0;
                            }
                            .progress-ring-container::after {
                                content: '';
                                position: absolute;
                                inset: 6px; /* inner hole size */
                                background: #111827; /* Match card background */
                                border-radius: 50%;
                            }
                            .progress-value {
                                position: relative;
                                z-index: 2;
                                font-size: 1.1rem;
                                font-weight: 700;
                                color: #f8fafc;
                            }
                            .score-card-content {
                                display: flex;
                                flex-direction: column;
                            }
                            .score-card-content h3 {
                                margin: 0 0 4px 0;
                                font-size: 1rem;
                                color: #f8fafc;
                                font-weight: 600;
                            }
                            .score-card-content p {
                                margin: 0;
                                font-size: 0.8rem;
                                color: #94a3b8;
                                line-height: 1.3;
                            }
                            
                            .btn-solid-orange-full {
                                display: block;
                                width: 100%;
                                background: #f59e0b;
                                border: 1px solid #f59e0b;
                                color: #0f172a;
                                padding: 14px 24px;
                                border-radius: 8px;
                                text-decoration: none;
                                font-weight: 600;
                                font-size: 1.05rem;
                                transition: all 0.2s;
                                text-align: center;
                                margin-top: 16px;
                            }
                            .btn-solid-orange-full:hover {
                                background: #d97706;
                            }
                            
                            .retake-section {
                                background-color: #111827;
                                border: 1px solid rgba(255, 255, 255, 0.08);
                                border-radius: 12px;
                                padding: 24px;
                                margin-top: 32px;
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                flex-wrap: wrap;
                                gap: 20px;
                            }
                            .retake-content {
                                flex: 1;
                                min-width: 280px;
                                display: flex;
                                gap: 16px;
                                align-items: center;
                            }
                            .retake-icon {
                                width: 48px;
                                height: 48px;
                                border-radius: 8px;
                                background: rgba(59, 130, 246, 0.1);
                                color: #3b82f6;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-size: 1.25rem;
                                flex-shrink: 0;
                            }
                            .retake-text h3 {
                                margin: 0 0 4px 0;
                                color: #f8fafc;
                                font-size: 1.05rem;
                            }
                            .retake-text p {
                                margin: 0;
                                color: #94a3b8;
                                font-size: 0.9rem;
                            }
                            .abandoned-note {
                                display: block;
                                margin-top: 8px;
                                font-size: 0.85rem;
                                color: #f87171; /* Subtle red/orange for note */
                            }
                            
                            .btn-outline-blue {
                                background: transparent;
                                border: 1px solid rgba(59, 130, 246, 0.5);
                                color: #60a5fa;
                                padding: 10px 20px;
                                border-radius: 6px;
                                text-decoration: none;
                                font-weight: 500;
                                transition: all 0.2s;
                            }
                            .btn-outline-blue:hover {
                                background: rgba(59, 130, 246, 0.1);
                            }
                            .btn-disabled {
                                background: rgba(255, 255, 255, 0.05);
                                border: 1px solid rgba(255, 255, 255, 0.1);
                                color: #64748b;
                                padding: 10px 20px;
                                border-radius: 6px;
                                cursor: not-allowed;
                                font-weight: 500;
                                display: inline-block;
                            }
                            
                            .page-header-container {
                                display: flex;
                                align-items: flex-start;
                                justify-content: space-between;
                                flex-wrap: wrap;
                                gap: 16px;
                                margin-bottom: 24px;
                            }
                        </style>

                        <div class="page-header-container">
                            <div>
                                <h1 style="margin:0 0 8px 0;font-size:1.75rem;font-weight:700;color:#f8fafc;">Your Assessment Results</h1>
                                <?php if ($assessmentResults): ?>
                                <p style="margin:0;color:#94a3b8;font-size:0.95rem;">Completed on <?php echo $assessmentResults['completed_at']; ?></p>
                                <?php else: ?>
                                <p style="margin:0;color:#94a3b8;font-size:0.95rem;">No completed assessments yet</p>
                                <?php endif; ?>
                            </div>
                            <?php if ($assessmentResults): ?>
                            <a href="download_results_pdf.php<?php echo isset($_GET['id']) ? '?id=' . intval($_GET['id']) : ''; ?>" style="cursor:pointer; display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);padding:10px 20px;border-radius:6px;color:#cbd5e1;font-weight:500;font-size:0.9rem;transition:all 0.2s;text-decoration:none;" onmouseover="this.style.background='rgba(255,255,255,0.1)';" onmouseout="this.style.background='rgba(255,255,255,0.05)';">
                                <i class="fa-solid fa-download"></i> Download PDF
                            </a>
                            <?php endif; ?>
                        </div>

                        <?php if ($assessmentResults): ?>
                        <!-- Disclaimer Banner -->
                        <div style="display:flex;align-items:flex-start;gap:14px;background:rgba(59,130,246,0.07);border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
                            <i class="fa-solid fa-circle-info" style="color:#60a5fa;font-size:1.1rem;margin-top:2px;flex-shrink:0;"></i>
                            <p style="margin:0;color:#94a3b8;font-size:0.875rem;line-height:1.6;">
                                <strong style="color:#cbd5e1;">For guidance purposes only.</strong> The career recommendations generated by the system serve as guidance references only and are not intended as definitive career directives. You are <strong style="color:#cbd5e1;">not required to follow the recommended courses</strong>. This assessment is designed to help you reflect on your interests and explore possible career paths, not to make final decisions on your behalf.
                            </p>
                        </div>

                        <!-- Summary Cards -->
                        <div class="summary-cards-row">
                            <div class="summary-card">
                                <div class="summary-icon-container">
                                    <i class="fa-solid fa-chart-pie"></i>
                                </div>
                                <div class="summary-content">
                                    <span class="summary-label">Overall Match Score</span>
                                    <span class="summary-value highlight-orange"><?php echo number_format($assessmentResults['total_score'], 2); ?>%</span>
                                </div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-icon-container" style="background: rgba(16, 185, 129, 0.1);">
                                    <i class="fa-solid fa-check-circle status-icon-active"></i>
                                </div>
                                <div class="summary-content">
                                    <span class="summary-label">Status</span>
                                    <span class="summary-value status-active">
                                        Completed
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Category Scores -->
                        <div class="score-cards-grid">
                            <?php
                                $categories = [
                                    ['key' => 'career', 'title' => 'Overall Career Interest'],
                                    ['key' => 'personality', 'title' => 'Overall Personality Fit'],
                                    ['key' => 'skills', 'title' => 'Overall Skills Assessment'],
                                    ['key' => 'strand', 'title' => 'Overall Strand Score']
                                ];
                                
                                foreach ($categories as $cat):
                                    $pct = number_format($assessmentResults[$cat['key']]['percentage'], 0);
                                    $label = getQualitativeLabel($cat['key'], $pct);
                            ?>
                            <div class="score-card-redesign">
                                <div class="progress-ring-container" style="background: conic-gradient(#f59e0b <?php echo $pct; ?>%, rgba(255, 255, 255, 0.05) 0);">
                                    <span class="progress-value"><?php echo $pct; ?>%</span>
                                </div>
                                <div class="score-card-content">
                                    <h3><?php echo $cat['title']; ?></h3>
                                    <p><?php echo $label; ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Recommendations Button -->
                        <a href="recommended_courses.php" class="btn-solid-orange-full">
                            View Recommendations <i class="fa-solid fa-arrow-right" style="margin-left: 8px;"></i>
                        </a>
                        <?php endif; ?>

                        <!-- Assessment History Section -->
                        <?php 
                        // Fetch history — completed AND abandoned
                        $historyStmt = $mysqli->prepare("SELECT id, completed_at, started_at, total_score, expires_at, status FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status IN ('completed','abandoned') ORDER BY COALESCE(completed_at, started_at) DESC");
                        if ($historyStmt) {
                            $historyStmt->bind_param("ii", $studentId, $schoolYearId);
                            $historyStmt->execute();
                            $historyResult = $historyStmt->get_result();
                            $assessmentsHistory = [];
                            while ($row = $historyResult->fetch_assoc()) {
                                $assessmentsHistory[] = $row;
                            }
                            $historyStmt->close();
                        }
                        
                        if (!empty($assessmentsHistory)):
                        ?>
                        <style>
                            .history-section {
                                background-color: #111827;
                                border: 1px solid rgba(255, 255, 255, 0.08);
                                border-radius: 12px;
                                padding: 24px;
                                margin-top: 32px;
                            }
                            .history-header-title {
                                display: flex;
                                align-items: center;
                                gap: 12px;
                                font-size: 1.15rem;
                                font-weight: 600;
                                color: #f8fafc;
                                margin-bottom: 24px;
                            }
                            .timeline {
                                position: relative;
                                padding-left: 28px;
                            }
                            .timeline::before {
                                content: '';
                                position: absolute;
                                left: 6px;
                                top: 8px;
                                bottom: 8px;
                                width: 2px;
                                background-color: rgba(255, 255, 255, 0.1);
                            }
                            .timeline-item {
                                position: relative;
                                display: flex;
                                align-items: center;
                                justify-content: space-between;
                                padding-bottom: 24px;
                                flex-wrap: wrap;
                                gap: 16px;
                            }
                            .timeline-item:last-child {
                                padding-bottom: 0;
                            }
                            .timeline-marker {
                                position: absolute;
                                left: -28px;
                                top: 4px;
                                width: 14px;
                                height: 14px;
                                border-radius: 50%;
                                background-color: #111827;
                                border: 2px solid rgba(255, 255, 255, 0.2);
                                z-index: 2;
                            }
                            .timeline-item.latest .timeline-marker {
                                border-color: #10b981;
                            }
                            .timeline-item.latest .timeline-marker::after {
                                content: '';
                                position: absolute;
                                inset: 2px;
                                border-radius: 50%;
                                background-color: #10b981;
                            }
                            .timeline-info {
                                display: flex;
                                flex-direction: column;
                                gap: 4px;
                            }
                            .timeline-title-row {
                                display: flex;
                                align-items: center;
                                gap: 12px;
                            }
                            .timeline-title {
                                font-weight: 600;
                                color: #10b981;
                            }
                            .timeline-title.past {
                                color: #f8fafc;
                            }
                            .badge-completed {
                                background: rgba(16, 185, 129, 0.1);
                                color: #10b981;
                                padding: 2px 8px;
                                border-radius: 12px;
                                font-size: 0.75rem;
                                font-weight: 600;
                            }
                            .timeline-date {
                                color: #94a3b8;
                                font-size: 0.9rem;
                            }
                            .timeline-score {
                                color: #94a3b8;
                                font-size: 0.95rem;
                            }
                            .timeline-score span {
                                color: #f59e0b;
                                font-weight: 600;
                            }
                            .timeline-meta {
                                display: flex;
                                align-items: center;
                                gap: 24px;
                                flex-wrap: wrap;
                            }
                            .timeline-expire {
                                display: flex;
                                align-items: center;
                                gap: 8px;
                                color: #94a3b8;
                                font-size: 0.9rem;
                            }
                            .timeline-expire .expire-val {
                                color: #f59e0b;
                                font-weight: 500;
                            }
                            .timeline-expire .expired-val {
                                color: #94a3b8;
                                font-weight: 500;
                            }
                            .btn-view-details {
                                background: transparent;
                                border: 1px solid rgba(59, 130, 246, 0.5);
                                color: #60a5fa;
                                padding: 8px 16px;
                                border-radius: 6px;
                                text-decoration: none;
                                font-size: 0.9rem;
                                font-weight: 500;
                                transition: all 0.2s;
                            }
                            .btn-view-details:hover {
                                background: rgba(59, 130, 246, 0.1);
                            }
                            .history-footer-info {
                                margin-top: 24px;
                                padding-top: 16px;
                                border-top: 1px solid rgba(255, 255, 255, 0.08);
                                color: #94a3b8;
                                font-size: 0.9rem;
                                display: flex;
                                align-items: center;
                                gap: 8px;
                            }
                            .timeline-item.abandoned-item {
                                opacity: 0.6;
                            }
                            .timeline-item.abandoned-item .timeline-marker {
                                border-color: #64748b;
                            }
                            .badge-abandoned {
                                background: rgba(100, 116, 139, 0.15);
                                color: #94a3b8;
                                padding: 2px 8px;
                                border-radius: 12px;
                                font-size: 0.75rem;
                                font-weight: 600;
                                border: 1px solid rgba(100,116,139,0.25);
                            }
                            .abandoned-reason {
                                font-size: 0.82rem;
                                color: #64748b;
                                margin-top: 2px;
                            }
                        </style>
                        <div class="history-section">
                            <div class="history-header-title">
                                <i class="fa-solid fa-clock-rotate-left"></i> Assessment History
                            </div>
                            
                            <div class="timeline">
                                <?php foreach ($assessmentsHistory as $index => $historyItem):
                                    $itemStatus  = $historyItem['status'];
                                    $isAbandoned = ($itemStatus === 'abandoned');
                                    $isLatest    = (!$isAbandoned && $index === 0);
                                    // Count only completed for attempt numbers
                                    $completedItems = array_filter($assessmentsHistory, fn($r) => $r['status'] === 'completed');
                                    $completedItems = array_values($completedItems);
                                    $completedIdx   = array_search($historyItem['id'], array_column($completedItems, 'id'));
                                    $attemptNum = $isAbandoned ? null : (count($completedItems) - $completedIdx);

                                    $dateRaw     = $historyItem['completed_at'] ?: $historyItem['started_at'];
                                    $historyDate = $dateRaw ? date('F d, Y \a\t g:i A', strtotime($dateRaw)) : 'Unknown';

                                    // Expiry only relevant for completed
                                    $expDateStr = '';
                                    $isItemExpired = false;
                                    if (!$isAbandoned) {
                                        $expDateStr = $historyItem['expires_at'];
                                        if (empty($expDateStr)) {
                                            $expDateStr = date('Y-m-d H:i:s', strtotime($historyItem['completed_at'] . ' + 24 hours'));
                                        }
                                        $expDate = date('F d, Y', strtotime($expDateStr));
                                        $isItemExpired = strtotime($expDateStr) < time();
                                    }
                                ?>
                                <div class="timeline-item <?php echo $isLatest ? 'latest' : ''; ?> <?php echo $isAbandoned ? 'abandoned-item' : ''; ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-info">
                                        <div class="timeline-title-row">
                                            <span class="timeline-title <?php echo ($isLatest) ? '' : 'past'; ?>">
                                                <?php if ($isAbandoned): ?>
                                                    Abandoned Attempt
                                                <?php else: ?>
                                                    Attempt <?php echo $attemptNum; ?> <?php echo $isLatest ? '(Latest)' : ''; ?>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($isAbandoned): ?>
                                                <span class="badge-abandoned">Abandoned</span>
                                            <?php else: ?>
                                                <span class="badge-completed">Completed</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="timeline-date"><?php echo $historyDate; ?></span>
                                        <?php if ($isAbandoned): ?>
                                            <span class="abandoned-reason">This attempt was not completed and was not counted.</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!$isAbandoned): ?>
                                    <div class="timeline-score">
                                        Overall Match Score: <span><?php echo number_format($historyItem['total_score'], 2); ?>%</span>
                                    </div>

                                    <div class="timeline-meta">
                                        <div class="timeline-expire">
                                            <i class="fa-regular fa-calendar"></i>
                                            <div>
                                                <?php if ($isItemExpired): ?>
                                                    <div>Expired on</div>
                                                    <div class="expired-val"><?php echo $expDate; ?></div>
                                                <?php else: ?>
                                                    <div>Expires on</div>
                                                    <div class="expire-val"><?php echo $expDate; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a href="assessment_results.php?id=<?php echo $historyItem['id']; ?>" class="btn-view-details">View Details</a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="history-footer-info">
                                <i class="fa-solid fa-circle-info"></i> You can take the assessment up to <?php echo $MAX_ASSESSMENTS; ?> times. You have used <?php echo $completedCount == $MAX_ASSESSMENTS ? 'all your' : $completedCount; ?> attempts.
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    

                        <!-- Retake Section -->

                        <div class="retake-section">
                            <div class="retake-content">
                                <div class="retake-icon">
                                    <i class="fa-regular fa-calendar-plus"></i>
                                </div>
                                <div class="retake-text">
                                    <?php if ($completedCount >= $MAX_ASSESSMENTS): ?>
                                        <h3>Maximum attempts reached</h3>
                                        <p>You have used all <?php echo $MAX_ASSESSMENTS; ?> allowed assessment attempts for this school year.</p>
                                    <?php else: ?>
                                        <h3>Keep your results up to date!</h3>
                                        <p>Retake the assessment if your interests or goals change.</p>
                                    <?php endif; ?>
                                    
                                    <?php if ($abandonedCount > 0): ?>
                                        <span class="abandoned-note"><i class="fa-solid fa-circle-exclamation"></i> Your previous attempt expired after 24 hours of inactivity and was not counted.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="retake-action">
                                <?php if ($completedCount >= $MAX_ASSESSMENTS): ?>
                                    <span class="btn-disabled">Retake Assessment</span>
                                <?php else: ?>
                                    <a href="take_assessment.php" class="btn-outline-blue">Retake Assessment</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$assessmentResults && $completedCount == 0): ?>
                        <!-- Fallback message if completely empty -->
                        <div class="no-data-message" style="text-align:center; padding: 60px 20px; background: #111827; border-radius: 12px; margin-top: 24px;">
                            <i class="fa-solid fa-clipboard-list" style="font-size: 3rem; color: #475569; margin-bottom: 16px;"></i>
                            <h3 style="color:#f8fafc; font-size:1.25rem; margin-bottom: 8px;">No Assessment Results Yet</h3>
                            <p style="color:#94a3b8; margin-bottom: 24px;">Take an assessment to see your results and get personalized recommendations.</p>
                            <a href="take_assessment.php" class="btn-solid-orange" style="display:inline-block;">
                                Take Assessment
                            </a>
                        </div>
                        <?php endif; ?>
                        
                    </section>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
