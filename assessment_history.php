<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

$student = getCurrentStudent();

$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);


$assessmentHistory = [];
if ($student) {
    $stmt = $mysqli->prepare("SELECT id, status, completed_at, created_at FROM student_assessments WHERE student_id = ? ORDER BY COALESCE(completed_at, created_at) DESC");
    $stmt->bind_param("i", $student['id']);
    $stmt->execute();
    $assessmentHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment History - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css?v=<?php echo filemtime('user.css'); ?>">
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
                <a href="assessment_results.php" class="nav-item">
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
                    <h1>Assessment History</h1>
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

            <!-- History Content -->
            <div class="dashboard-content">
                <section class="history-section">
                    <div class="section-header">
                        <h2><i class="fa-solid fa-clock-rotate-left"></i> Past Assessments</h2>
                        <p>View all your previous assessments and track your progress</p>
                    </div>

                    <div class="history-list">
                        <?php if (empty($assessmentHistory)): ?>
                            <div class="history-item">
                                <div class="history-header">
                                    <div class="assessment-info">
                                        <h3>No assessments yet</h3>
                                        <p class="completion-date">Take your first assessment to see your history here.</p>
                                    </div>
                                </div>
                                <div class="history-actions">
                                    <a href="take_assessment.php" class="btn btn-sm btn-primary">
                                        <i class="fa-solid fa-clipboard-list"></i>
                                        Take Assessment
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($assessmentHistory as $a): ?>
                                <?php
                                    $completedAt = $a['completed_at'] ?: $a['created_at'];
                                    $completedLabel = $completedAt ? date('F j, Y', strtotime($completedAt)) : '';
                                    $status = $a['status'] ?: 'completed';
                                    $statusClass = strtolower($status);
                                ?>
                                <div class="history-item">
                                    <div class="history-header">
                                        <div class="assessment-info">
                                            <h3>Assessment #<?php echo (int)$a['id']; ?></h3>
                                            <?php if ($completedLabel !== ''): ?>
                                                <p class="completion-date">Completed: <?php echo htmlspecialchars($completedLabel); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="assessment-status">
                                            <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                        </div>
                                    </div>

                                    <div class="history-actions">
                                        <a href="assessment_results.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fa-solid fa-eye"></i>
                                            View Details
                                        </a>
                                        <a href="take_assessment.php" class="btn btn-sm btn-primary">
                                            <i class="fa-solid fa-redo"></i>
                                            Retake
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                </section>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
