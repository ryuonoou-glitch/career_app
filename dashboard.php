<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

$student = getCurrentStudent();

// Bypass prevention: re-check DB flag so navigating directly to this URL
// does not skip the forced password change for admin-created accounts.
if ($student && !empty($student['must_change_password'])) {
    header('Location: set_password.php');
    exit();
}

$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);


$assessmentsCount = 0;
$coursesCount = 0;
$schoolsCount = 0;

if ($student) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND status = 'completed'");
    $stmt->bind_param("i", $student['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $assessmentsCount = $row['count'];
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT COUNT(DISTINCT vr.course_id) as count FROM verified_recommendations vr JOIN student_assessments sa ON vr.assessment_id = sa.id WHERE sa.student_id = ? AND sa.verification_status = 'verified'");
    $stmt->bind_param("i", $student['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $coursesCount = $row['count'];
    $stmt->close();

    $stmt = $mysqli->prepare("
        SELECT COUNT(DISTINCT cs.school_id) as count 
        FROM course_schools cs 
        JOIN schools s ON cs.school_id = s.id 
        JOIN verified_recommendations vr ON cs.course_id = vr.course_id 
        JOIN student_assessments sa ON vr.assessment_id = sa.id 
        WHERE sa.student_id = ? AND s.status = 'active' AND sa.verification_status = 'verified'
    ");
    $stmt->bind_param("i", $student['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $schoolsCount = $row['count'];
    $stmt->close();
}

$profileCompletion = 0;
if ($student) {
    $fields = ['first_name', 'last_name', 'email', 'address', 'gender'];
    $filled = 0;
    foreach ($fields as $field) {
        if (!empty($student[$field])) $filled++;
    }
    $profileCompletion = round(($filled / count($fields)) * 100);
}

$isReadonly = !empty($student['is_readonly']);
$activeSchoolYear = getSystemConfig('school_year');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css?v=<?php echo filemtime('user.css'); ?>">
</head>
<body data-user-logged-in="true">
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
                <a href="dashboard.php" class="nav-item active">
                    <i class="fa-solid fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="take_assessment.php" class="nav-item<?php echo $isReadonly ? ' nav-item-disabled' : ''; ?>"<?php if ($isReadonly): ?> title="Account is in read-only mode"<?php endif; ?>>
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
                    <h1>Dashboard</h1>
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

            <!-- Dashboard Content -->
            <div class="dashboard-content">

                <?php if ($isReadonly): ?>
                <!-- Read-only notice banner -->
                <div style="
                    display:flex;align-items:flex-start;gap:1rem;
                    background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.06));
                    border:1px solid rgba(245,158,11,.35);
                    border-radius:14px;
                    padding:1.1rem 1.4rem;
                    margin-bottom:1.5rem;
                ">
                    <i class="fa-solid fa-lock" style="color:#f59e0b;font-size:1.3rem;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;color:#f1f5f9;font-size:.95rem;margin-bottom:.2rem;">
                            Your account is in Read-Only mode
                        </div>
                        <div style="font-size:.85rem;color:#94a3b8;line-height:1.5;">
                            The school year transition to <strong style="color:#f59e0b;"><?php echo htmlspecialchars($activeSchoolYear); ?></strong>
                            has been completed. Your Grade 12 account is now archived.
                            You can still view your past assessment results, but cannot take new assessments.
                        </div>
                    </div>
                </div>
                <?php elseif (isset($_GET['readonly'])): ?>
                <!-- Shown when redirected from take_assessment.php -->
                <div style="
                    display:flex;align-items:flex-start;gap:1rem;
                    background:linear-gradient(135deg,rgba(239,68,68,.10),rgba(239,68,68,.04));
                    border:1px solid rgba(239,68,68,.3);
                    border-radius:14px;
                    padding:1.1rem 1.4rem;
                    margin-bottom:1.5rem;
                ">
                    <i class="fa-solid fa-ban" style="color:#ef4444;font-size:1.3rem;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;color:#f1f5f9;font-size:.95rem;margin-bottom:.2rem;">
                            Assessment not available
                        </div>
                        <div style="font-size:.85rem;color:#94a3b8;">
                            Your account is in read-only mode. New assessments cannot be taken. Please contact your counselor for more information.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Welcome Section -->
                <section class="welcome-section">
                    <div class="welcome-card">
                        <h2>Welcome, <?php echo $studentName; ?>!</h2>
                        <p>Start your career journey by taking an assessment to get personalized recommendations.</p>
                    </div>
                </section>

                <!-- Summary Cards -->
                <section class="summary-cards">
                    <a href="assessment_results.php" class="summary-card" style="text-decoration: none; color: inherit;">
                        <div class="card-icon">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div class="card-content">
                            <h3>Assessments Completed</h3>
                            <div class="card-value"><?php echo $assessmentsCount; ?></div>
                        </div>
                    </a>
                    <a href="profile.php" class="summary-card" style="text-decoration: none; color: inherit;">
                        <div class="card-icon">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                        <div class="card-content">
                            <h3>Profile Completion</h3>
                            <div class="card-value"><?php echo $profileCompletion; ?>%</div>
                        </div>
                    </a>
                    <a href="recommended_courses.php" class="summary-card" style="text-decoration: none; color: inherit;">
                        <div class="card-icon">
                            <i class="fa-solid fa-book"></i>
                        </div>
                        <div class="card-content">
                            <h3>Recommended Courses</h3>
                            <div class="card-value"><?php echo $coursesCount; ?></div>
                        </div>
                    </a>
                    <a href="recommended_courses.php#schools" class="summary-card" style="text-decoration: none; color: inherit;">
                        <div class="card-icon">
                            <i class="fa-solid fa-school"></i>
                        </div>
                        <div class="card-content">
                            <h3>Recommended Schools</h3>
                            <div class="card-value"><?php echo $schoolsCount; ?></div>
                        </div>
                    </a>
                </section>

                <!-- Quick Actions -->
                <section class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="action-buttons">
                        <a href="take_assessment.php" class="action-btn primary">
                            <i class="fa-solid fa-clipboard-list"></i>
                            Take Assessment
                        </a>
                        <a href="assessment_results.php" class="action-btn secondary">
                            <i class="fa-solid fa-chart-line"></i>
                            View Results
                        </a>
                        <a href="profile.php" class="action-btn tertiary">
                            <i class="fa-solid fa-user-edit"></i>
                            Edit Profile
                        </a>
                    </div>
                </section>

                <!-- Recent Activity -->
                <section class="recent-activity">
                    <h2>Recent Activity</h2>
                    <div class="activity-list">
                        <?php if ($assessmentsCount > 0): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                </div>
                                <div class="activity-content">
                                    <p class="activity-text">You have completed <?php echo $assessmentsCount; ?> assessment(s)</p>
                                    <span class="activity-time">View your results</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fa-solid fa-info-circle"></i>
                                </div>
                                <div class="activity-content">
                                    <p class="activity-text">No activities yet. Take your first assessment!</p>
                                    <span class="activity-time">Just now</span>
                                </div>
                            </div>
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
