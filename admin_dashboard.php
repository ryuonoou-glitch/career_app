<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';

require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('view_dashboard');

// Bypass prevention: if staff logged in with temp password and must_change_password = 1,
// redirect to set_password.php even if they navigate directly to this URL.
$staffId = get_staff_id();
if (is_counselor()) {
    $mcpBypassCheck = $mysqli->prepare("SELECT must_change_password FROM counselors WHERE id = ? LIMIT 1");
    if ($mcpBypassCheck) {
        $mcpBypassCheck->bind_param('i', $staffId);
        $mcpBypassCheck->execute();
        $mcpBypassRow = $mcpBypassCheck->get_result()->fetch_assoc();
        $mcpBypassCheck->close();
        if (!empty($mcpBypassRow['must_change_password'])) {
            header('Location: set_password.php');
            exit();
        }
    }
} else {
    $mcpBypassCheck = $mysqli->prepare("SELECT must_change_password FROM admins WHERE id = ? LIMIT 1");
    if ($mcpBypassCheck) {
        $mcpBypassCheck->bind_param('i', $staffId);
        $mcpBypassCheck->execute();
        $mcpBypassRow = $mcpBypassCheck->get_result()->fetch_assoc();
        $mcpBypassCheck->close();
        if (!empty($mcpBypassRow['must_change_password'])) {
            header('Location: set_password.php');
            exit();
        }
    }
}

// Set admin_email if needed for compatibility
if (isset($_SESSION['admin_email']) && !isset($_SESSION['email'])) {
    $_SESSION['email'] = $_SESSION['admin_email'];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {
        case 'get_ongoing_assessments':
            // Count total ongoing assessments (latest per student)
            $count_stmt = $mysqli->prepare("
                SELECT COUNT(*) as total
                FROM student_assessments sa
                WHERE sa.status = 'in_progress'
                AND sa.id IN (
                    SELECT MAX(id)
                    FROM student_assessments
                    WHERE status = 'in_progress'
                    GROUP BY student_id
                )
            ");
            $count_stmt->execute();
            $total_ongoing = (int)$count_stmt->get_result()->fetch_assoc()['total'];
            $count_stmt->close();

            // Get latest 5 ongoing assessments for the dashboard widget
            $stmt = $mysqli->prepare("
                SELECT sa.id as assessment_id, sa.created_at, sa.student_id,
                       CONCAT(COALESCE(s.first_name, ''), IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''), ' ', COALESCE(s.last_name, ''), IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')) AS student_name,
                       st.name AS strand, st.code AS strand_code,
                       COUNT(sa2.id) as answered_count
                FROM student_assessments sa
                LEFT JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN student_answers sa2 ON sa.id = sa2.assessment_id
                WHERE sa.status = 'in_progress'
                AND sa.id IN (
                    SELECT MAX(id)
                    FROM student_assessments
                    WHERE status = 'in_progress'
                    GROUP BY student_id
                )
                GROUP BY sa.id
                ORDER BY sa.created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            $assessments = [];
            while ($row = $result->fetch_assoc()) {
                $assessments[] = $row;
            }
            $stmt->close();
            $response['success'] = true;
            $response['assessments'] = $assessments;
            $response['total'] = $total_ongoing;
            break;
    }
    echo json_encode($response);
    exit;
}

// Get real statistics from database
$conn = getDBConnection();

// Total Students
$students_query = "SELECT COUNT(*) as total FROM students";
$students_result = $conn->query($students_query);
$total_students = $students_result->fetch_assoc()['total'] ?? 0;

// Total Assessments
$assessments_query = "SELECT COUNT(*) as total FROM student_assessments";
$assessments_result = $conn->query($assessments_query);
$total_assessments = $assessments_result->fetch_assoc()['total'] ?? 0;

// Total Courses
$courses_query = "SELECT COUNT(*) as total FROM courses";
$courses_result = $conn->query($courses_query);
$total_courses = $courses_result->fetch_assoc()['total'] ?? 0;

// Total Schools
$schools_query = "SELECT COUNT(*) as total FROM schools";
$schools_result = $conn->query($schools_query);
$total_schools = $schools_result->fetch_assoc()['total'] ?? 0;

// Get recent assessments (only latest per student)
$recent_query = "SELECT 
                    sa.*,
                    CONCAT(COALESCE(s.first_name, ''), IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''), ' ', COALESCE(s.last_name, ''), IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')) AS student_name,
                    st.name AS strand,
                    st.code AS strand_code
                 FROM student_assessments sa 
                 LEFT JOIN students s ON sa.student_id = s.id 
                 LEFT JOIN strands st ON s.strand_id = st.id
                 WHERE sa.id IN (
                    SELECT MAX(id)
                    FROM student_assessments
                    GROUP BY student_id
                 )
                 ORDER BY sa.created_at DESC 
                 LIMIT 5";
$recent_assessments = $conn->query($recent_query);

// Get staff user info
$userName = get_staff_name();
$staffId = get_staff_id();
$userType = is_counselor() ? 'counselor' : 'admin';

// Get notifications for current staff member
$notifications = [];
$unreadCount = 0;
$adminProfilePic = null;

if (!is_counselor() && $staffId) {
    // Get admin profile picture safely (admins table only)
    $profileStmt = $conn->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
    if ($profileStmt) {
        $profileStmt->bind_param('i', $staffId);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $adminData = $profileResult->fetch_assoc();
        $adminProfilePic = $adminData['profile_picture'] ?? null;
        $profileStmt->close();
    }
}

if ($staffId) {
    // Get unread count
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
    if ($countStmt) {
        $countStmt->bind_param('is', $staffId, $userType);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $unreadCount = $countResult->fetch_assoc()['count'] ?? 0;
        $countStmt->close();
    }
    
    // Get recent notifications (last 10)
    $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? ORDER BY created_at DESC LIMIT 10");
    if ($notifStmt) {
        $notifStmt->bind_param('is', $staffId, $userType);
        $notifStmt->execute();
        $notifResult = $notifStmt->get_result();
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notifStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo is_counselor() ? 'Counselor Dashboard' : 'Admin Dashboard'; ?> - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ==========================================================================
           ADMIN DASHBOARD - MODERN SLEEK REDESIGN
           ========================================================================== */

        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
        }

        /* -- 4 Overview Cards Redesign -- */
        .overview-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 0;
        }

        .overview-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.75) 0%, rgba(15, 23, 42, 0.9) 100%);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 1.2rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            cursor: pointer;
            min-width: 0;
        }

        .overview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.1);
        }

        .overview-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.18);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.4);
        }

        .overview-card:hover .card-icon {
            transform: scale(1.08);
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            transition: transform 0.25s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Card Colors */
        .overview-card:nth-child(1)::before { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
        .overview-card:nth-child(1) .card-icon.students {
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.3);
            color: #38bdf8;
        }

        .overview-card:nth-child(2)::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .overview-card:nth-child(2) .card-icon.assessments {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .overview-card:nth-child(3)::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .overview-card:nth-child(3) .card-icon.courses-stat {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        .overview-card:nth-child(4)::before { background: linear-gradient(90deg, #a855f7, #c084fc); }
        .overview-card:nth-child(4) .card-icon.schools {
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.3);
            color: #c084fc;
        }

        .card-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }

        .card-info h3 {
            font-size: 0.72rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            margin: 0 0 0.25rem 0;
            line-height: 1.25;
            white-space: normal;
            word-break: normal;
        }

        .card-number {
            font-size: 1.65rem;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.15;
            margin: 0;
            font-feature-settings: "tnum";
        }

        /* -- Table Sections Redesign -- */
        .table-section {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.85) 100%);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 1.35rem 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.15rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .table-header h2 {
            font-size: 1.15rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .table-header .badge {
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.3);
            color: #38bdf8;
            padding: 0.15rem 0.55rem;
            border-radius: 12px;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .table-header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .update-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #34d399;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .update-indicator i {
            font-size: 0.5rem;
            color: #10b981;
            box-shadow: 0 0 6px #10b981;
            border-radius: 50%;
        }

        .view-all-link {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.84rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
        }

        .view-all-link:hover {
            color: #7dd3fc;
            text-decoration: underline;
        }

        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead th {
            padding: 0.75rem 0.9rem;
            text-align: left;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            background: rgba(15, 23, 42, 0.6);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            white-space: nowrap;
        }

        .data-table tbody td {
            padding: 0.9rem 0.9rem;
            font-size: 0.86rem;
            color: #e2e8f0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }

        .data-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Pill Elements */
        .strand-pill-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.25);
            color: #38bdf8;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .answered-count-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            font-size: 0.78rem;
            font-weight: 700;
            font-feature-settings: "tnum";
        }

        .action-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.25);
            color: #38bdf8;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .action-link:hover {
            background: #0ea5e9;
            color: #ffffff;
            border-color: #0ea5e9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.35);
        }

        /* Status Badges */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .status.completed {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.28);
            color: #34d399;
        }

        .status.in-progress {
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.28);
            color: #38bdf8;
        }

        .status.abandoned {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.28);
            color: #f87171;
        }

        .status.pending {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.28);
            color: #fbbf24;
        }

        /* Ongoing Summary Bar */
        .ongoing-summary-bar {
            margin-top: 1rem;
            padding: 0.65rem 1rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            color: #94a3b8;
        }

        .ongoing-view-all-link {
            color: #38bdf8;
            font-weight: 700;
            text-decoration: none;
        }

        .ongoing-view-all-link:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .overview-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .overview-cards {
                grid-template-columns: 1fr;
            }
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .table-header-right {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body data-user-logged-in="true">
    <!-- Dashboard Container -->
    <div class="dashboard-container">
        
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1><?php echo is_counselor() ? 'Guidance Counselor Dashboard' : 'Admin Dashboard'; ?></h1>
                </div>
                <div class="top-bar-actions">
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <i class="fa-solid fa-bell"></i>
                            <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <a href="#" class="clear-all-notifs" onclick="clearAllNotifications(event)" style="color: #ef4444; font-size: 0.8rem; text-decoration: none;">Clear All</a>
                                    <?php if ($unreadCount > 0): ?>
                                    <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notification-list">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                                        <div class="notification-icon <?php echo $notif['type']; ?>">
                                            <i class="fa-solid <?php echo $notif['type'] === 'success' ? 'fa-check-circle' : ($notif['type'] === 'warning' ? 'fa-exclamation-triangle' : ($notif['type'] === 'error' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                            <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <span class="notification-time"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-notifications">
                                        <i class="fa-solid fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="user-profile" onclick="window.location.href='<?php echo is_counselor() ? 'counselor_profile.php' : 'settings.php'; ?>'" title="<?php echo is_counselor() ? 'My Profile' : 'Settings'; ?>">
                        <div class="user-avatar <?php echo is_counselor() ? 'counselor-avatar' : ''; ?>">
                            <?php if ($adminProfilePic && file_exists(__DIR__ . '/' . $adminProfilePic)): ?>
                                <img src="<?php echo htmlspecialchars($adminProfilePic); ?>" alt="Admin" class="avatar-img">
                            <?php elseif (is_counselor()): ?>
                                <i class="fa-solid fa-user-tie"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-user-shield"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Overview Cards -->
                <div class="overview-cards">
                    <div class="overview-card" onclick="window.location.href='manage_students.php'">
                        <div class="card-icon students">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Students</h3>
                            <p class="card-number"><?php echo number_format($total_students); ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card" onclick="window.location.href='admin_assessment_results.php'">
                        <div class="card-icon assessments">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Assessments</h3>
                            <p class="card-number"><?php echo number_format($total_assessments); ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card" onclick="window.location.href='manage_courses.php'">
                        <div class="card-icon courses-stat">
                            <i class="fa-solid fa-book"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Courses</h3>
                            <p class="card-number"><?php echo number_format($total_courses); ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card" onclick="window.location.href='manage_schools.php'">
                        <div class="card-icon schools">
                            <i class="fa-solid fa-school"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Schools</h3>
                            <p class="card-number"><?php echo number_format($total_schools); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Ongoing Assessments Section -->
                <div class="table-section">
                    <div class="table-header">
                        <h2>
                            Ongoing Assessments
                            <span class="badge" id="ongoing-count">0</span>
                        </h2>
                        <div class="table-header-right">
                            <span class="update-indicator" id="ongoing-live-indicator">
                                <i class="fa-solid fa-circle"></i> Live
                            </span>
                            <a href="ongoing_assessments.php" class="view-all-link">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> View All
                            </a>
                        </div>
                    </div>

                    <div class="table-container" style="max-height: none; overflow-y: visible;">
                        <table class="data-table" id="ongoing-assessments-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Strand</th>
                                    <th>Answered Questions</th>
                                    <th>Started</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ongoing-assessments-tbody">
                                <tr>
                                    <td colspan="5" class="empty-cell" style="text-align: center; color: #64748b; padding: 2rem 0;">
                                        <i class="fa-solid fa-circle-notch fa-spin"></i> Loading ongoing assessments...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary bar: shown only when total > 5 -->
                    <div class="ongoing-summary-bar" id="ongoing-summary-bar" style="display:none;">
                        <span><i class="fa-solid fa-circle-info" style="color: #38bdf8; margin-right: 0.4rem;"></i> <span id="ongoing-summary-text"></span></span>
                        <a href="ongoing_assessments.php" class="ongoing-view-all-link">View all &rarr;</a>
                    </div>
                </div>

                <!-- Recent Assessments Table -->
                <div class="table-section">
                    <div class="table-header">
                        <h2>Recent Assessments</h2>
                        <a href="admin-assessments.php" class="view-all-link">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> View All
                        </a>
                    </div>
                    
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Strand</th>
                                    <th>Status</th>
                                    <th>Started</th>
                                    <th>Completed</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="recent-assessments-tbody">
                                <?php if ($recent_assessments && $recent_assessments->num_rows > 0): ?>
                                    <?php while($assessment = $recent_assessments->fetch_assoc()): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: #f1f5f9;"><?php echo htmlspecialchars($assessment['student_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="strand-pill-tag"><?php echo htmlspecialchars($assessment['strand_code'] ?? $assessment['strand'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $assessment['status'] ?? 'pending';
                                            $status_class = '';
                                            switch($status) {
                                                case 'completed':
                                                    $status_class = 'completed';
                                                    $status_text = 'Completed';
                                                    break;
                                                case 'in_progress':
                                                    $status_class = 'in-progress';
                                                    $status_text = 'In Progress';
                                                    break;
                                                case 'abandoned':
                                                    $status_class = 'abandoned';
                                                    $status_text = 'Abandoned';
                                                    break;
                                                default:
                                                    $status_class = 'pending';
                                                    $status_text = 'Pending';
                                            }
                                            ?>
                                            <span class="status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        </td>
                                        <td style="color: #94a3b8; font-size: 0.82rem;"><?php echo date('Y-m-d h:i A', strtotime($assessment['created_at'])); ?></td>
                                        <td style="color: #94a3b8; font-size: 0.82rem;"><?php echo $assessment['completed_at'] ? date('Y-m-d h:i A', strtotime($assessment['completed_at'])) : '&mdash;'; ?></td>
                                        <td>
                                            <a href="admin-assessments.php?id=<?php echo $assessment['id']; ?>" class="action-link">
                                                <i class="fa-solid fa-eye"></i> View Answers
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #64748b; padding: 2rem 0;">No assessments found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="admin.js"></script>
    <script>
        // ==================== ONGOING ASSESSMENTS WIDGET ====================

        async function fetchOngoingAssessments() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_ongoing_assessments');

                const response = await fetch('admin_dashboard.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    renderOngoingAssessments(data.assessments, data.total);
                }
            } catch (error) {
                console.error('Error fetching ongoing assessments:', error);
            }
        }

        function renderOngoingAssessments(assessments, total) {
            const tbody      = document.getElementById('ongoing-assessments-tbody');
            const countBadge = document.getElementById('ongoing-count');
            const summaryBar = document.getElementById('ongoing-summary-bar');
            const summaryTxt = document.getElementById('ongoing-summary-text');

            // Update total badge
            countBadge.textContent = total;

            if (assessments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-cell" style="text-align: center; color: #64748b; padding: 2rem 0;">No ongoing assessments at this time.</td></tr>';
                if (summaryBar) summaryBar.style.display = 'none';
                return;
            }

            let html = '';
            assessments.forEach(a => {
                const strand = a.strand_code || a.strand || 'N/A';
                html += `
                    <tr>
                        <td style="font-weight: 700; color: #f1f5f9;">${esc(a.student_name)}</td>
                        <td><span class="strand-pill-tag">${esc(strand)}</span></td>
                        <td><span class="answered-count-badge"><i class="fa-solid fa-list-check" style="color: #38bdf8;"></i> ${a.answered_count} answered</span></td>
                        <td style="color: #94a3b8; font-size: 0.82rem;">${formatDate(a.created_at)}</td>
                        <td><a href="admin-assessments.php?id=${a.assessment_id}" class="action-link"><i class="fa-solid fa-eye"></i> View Answers</a></td>
                    </tr>`;
            });
            tbody.innerHTML = html;

            // Show "Showing 5 of XX" bar only when there are more than 5
            if (summaryBar) {
                if (total > 5) {
                    summaryTxt.textContent = `Showing 5 of ${total} ongoing assessments`;
                    summaryBar.style.display = 'flex';
                } else {
                    summaryBar.style.display = 'none';
                }
            }
        }

        function esc(text) {
            if (!text) return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        function formatDate(dateStr) {
            return new Date(dateStr).toLocaleString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: 'numeric', minute: '2-digit'
            });
        }

        // Initial fetch, then auto-refresh every 10 seconds
        fetchOngoingAssessments();
        setInterval(fetchOngoingAssessments, 10000);
        
        // Notification dropdown toggle
        document.getElementById('notificationBtn')?.addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) dropdown.style.display = 'none';
        });

        // Prevent dropdown close when clicking inside
        document.getElementById('notificationDropdown')?.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Mark all notifications as read
        function clearAllNotifications(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.showConfirm === 'function') {
                window.showConfirm("Are you sure you want to clear all notifications?", "Clear Notifications", executeClear);
            } else if (confirm("Are you sure you want to clear all notifications?")) {
                executeClear();
            }
            
            function executeClear() {
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=clear_all'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const list = document.querySelector('.notification-list');
                        if (list) {
                            list.innerHTML = '<div class="no-notifications"><p>No notifications.</p></div>';
                        }
                        const badge = document.getElementById('notificationBadge');
                        if (badge) {
                            badge.textContent = '0';
                            badge.style.display = 'none';
                        }
                        const markAllBtn = document.querySelector('.mark-all-read');
                        if (markAllBtn) markAllBtn.style.display = 'none';
                    }
                });
            }
        }

        function markAllRead(e) {
            e.preventDefault();
            e.stopPropagation();
            
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read&caller_type=<?php echo is_counselor() ? 'counselor' : 'admin'; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    const badge = document.getElementById('notificationBadge');
                    if (badge) {
                        badge.textContent = '0';
                        badge.style.display = 'none';
                    }
                    document.querySelector('.mark-all-read')?.remove();
                }
            });
        }

        // Mark single notification as read on click
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                if (this.classList.contains('unread')) {
                    const notifId = this.dataset.id;
                    
                    fetch('api/notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&id=' + notifId + '&caller_type=<?php echo is_counselor() ? 'counselor' : 'admin'; ?>'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.classList.remove('unread');
                            const badge = document.getElementById('notificationBadge');
                            if (badge) {
                                const currentCount = parseInt(badge.textContent) || 0;
                                if (currentCount <= 1) {
                                    badge.textContent = '0';
                                    badge.style.display = 'none';
                                    document.querySelector('.mark-all-read')?.remove();
                                } else {
                                    badge.textContent = currentCount - 1;
                                }
                            }
                        }
                    });
                }
            });
        });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>