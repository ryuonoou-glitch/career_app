<?php
// Activity Logs - admin-only
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// RBAC Auth check
require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('view_own_activity');

$student = getCurrentStudent(); // dummy if needed

// Fetch filters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$userFilter = $_GET['user_filter'] ?? '';
$actionFilter = $_GET['action_filter'] ?? '';

// Define allowed action types based on staff role (RBAC)
if (is_counselor()) {
    // Counselors strictly manage Students and Questions (CRUD)
    $studentActions = [
        'Added Student', 
        'Edited Student', 
        'Deleted Student', 
        'Activated Student', 
        'Deactivated Student'
    ];
    $questionActions = [
        'Added Question', 
        'Edited Question', 
        'Deleted Question'
    ];
    
    // Also fetch any dynamic actions logged specifically by this counselor
    $counselorId = get_staff_id();
    $dbActions = [];
    $actionsStmt = $mysqli->prepare("SELECT DISTINCT action FROM audit_logs WHERE user_type = 'counselor' AND user_id = ?");
    if ($actionsStmt) {
        $actionsStmt->bind_param('i', $counselorId);
        $actionsStmt->execute();
        $res = $actionsStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dbActions[] = $row['action'];
        }
        $actionsStmt->close();
    }
    
    // Disallow any administrative action types from appearing in counselor view
    $restrictedKeywords = ['school', 'course', 'job', 'counselor', 'backup', 'restore', 'admin', 'cluster', 'transition', 'setting'];
    $allowedExtra = array_filter($dbActions, function($act) use ($restrictedKeywords, $studentActions, $questionActions) {
        if (in_array($act, $studentActions, true) || in_array($act, $questionActions, true)) return false;
        $actLower = strtolower($act);
        foreach ($restrictedKeywords as $kw) {
            if (strpos($actLower, $kw) !== false) return false;
        }
        return true;
    });

    $allActions = array_merge($studentActions, $questionActions, $allowedExtra);
} else {
    // Admin predefined actions list
    $predefinedActions = [
        'Added School', 'Edited School', 'Deleted School',
        'Added Course', 'Edited Course', 'Deleted Course',
        'Added Job', 'Edited Job', 'Deleted Job',
        'Added Question', 'Edited Question', 'Deleted Question',
        'Added Student', 'Edited Student', 'Deleted Student', 'Activated Student', 'Deactivated Student',
        'Added Counselor', 'Edited Counselor', 'Deleted Counselor',
        'Link Job to Course', 'Link School to Course', 'Update School Specialization', 
        'Unlink School from Course', 'Unlink Job from Course',
        'Database Backup', 'Database Restore'
    ];

    $dbActions = [];
    $actionsRes = $mysqli->query("SELECT DISTINCT action FROM audit_logs");
    if ($actionsRes) {
        while ($row = $actionsRes->fetch_assoc()) {
            $dbActions[] = $row['action'];
        }
    }

    $allActions = array_unique(array_merge($predefinedActions, $dbActions));
    sort($allActions);

    // Fetch filter options list for Admins only
    $adminsRes = $mysqli->query("SELECT id, CONCAT(first_name, ' ', IFNULL(middle_name, ''), IF(middle_name IS NOT NULL AND middle_name != '', ' ', ''), last_name, IF(suffix IS NOT NULL AND suffix != '', CONCAT(' ', suffix), '')) AS full_name FROM admins ORDER BY first_name");
    $cnsRes = $mysqli->query("SELECT id, CONCAT(first_name, ' ', IFNULL(middle_name, ''), IF(middle_name IS NOT NULL AND middle_name != '', ' ', ''), last_name, IF(suffix IS NOT NULL AND suffix != '', CONCAT(' ', suffix), '')) AS full_name FROM counselors ORDER BY first_name");
}

// Build filters where clause
$where = [];
$params = [];
$types = '';

if (!empty($startDate)) {
    $where[] = "al.created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}
if (!empty($endDate)) {
    $where[] = "al.created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
    $types .= 's';
}
if (is_counselor()) {
    // Counselors strictly see their own activity logs
    $where[] = "al.user_type = 'counselor' AND al.user_id = ?";
    $params[] = get_staff_id();
    $types .= 'i';
} elseif (!empty($userFilter) && strpos($userFilter, '-') !== false) {
    list($utype, $uid) = explode('-', $userFilter);
    $where[] = "al.user_type = ? AND al.user_id = ?";
    $params[] = $utype;
    $params[] = (int)$uid;
    $types .= 'si';
}
if (!empty($actionFilter)) {
    if (is_counselor() && !in_array($actionFilter, $allActions, true)) {
        $actionFilter = '';
    } else {
        $where[] = "al.action = ?";
        $params[] = $actionFilter;
        $types .= 's';
    }
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$query = "
    SELECT 
        al.*,
        COALESCE(
            CONCAT(adm.first_name, ' ', IFNULL(adm.middle_name, ''), IF(adm.middle_name IS NOT NULL AND adm.middle_name != '', ' ', ''), adm.last_name, IF(adm.suffix IS NOT NULL AND adm.suffix != '', CONCAT(' ', adm.suffix), '')),
            CONCAT(cns.first_name, ' ', IFNULL(cns.middle_name, ''), IF(cns.middle_name IS NOT NULL AND cns.middle_name != '', ' ', ''), cns.last_name, IF(cns.suffix IS NOT NULL AND cns.suffix != '', CONCAT(' ', cns.suffix), '')),
            'System'
        ) AS user_name,
        COALESCE(adm.role, 'Counselor') AS user_role
    FROM audit_logs al
    LEFT JOIN admins adm ON al.user_id = adm.id AND al.user_type = 'admin'
    LEFT JOIN counselors cns ON al.user_id = cns.id AND al.user_type = 'counselor'
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT 200
";

$stmt = $mysqli->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $logs = [];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        body {
            background-color: #0b0f19;
            color: #f1f5f9;
        }
        .dashboard-content {
            padding: 1.25rem 1.5rem !important;
            zoom: 0.98;
        }
        .page-header {
            margin-top: 0 !important;
            padding-top: 0 !important;
            margin-bottom: 1.25rem !important;
        }
        .page-header h2 {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff 0%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.25rem;
            margin-top: 0;
        }
        .page-header p {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0;
        }

        /* Filter Section Glassmorphism */
        .filter-card {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.2);
        }
        .filter-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1 1 125px;
            min-width: 115px;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .filter-group label {
            font-size: 0.68rem;
            font-weight: 700;
            color: #fbbf24;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.1rem;
            display: inline-block;
        }
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.4rem 0.65rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 0.82rem;
            outline: none;
            transition: all 0.2s ease;
            font-family: inherit;
            height: 36px;
            box-sizing: border-box;
        }
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
            background: rgba(15, 23, 42, 0.8);
        }
        .filter-actions {
            display: flex;
            gap: 0.4rem;
            flex: 0 0 auto;
        }
        .filter-actions .btn {
            padding: 0.4rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
            text-decoration: none;
            box-sizing: border-box;
            height: 36px;
        }
        .btn-filter-submit {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            border: none;
            color: #0b0f19;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
        }
        .btn-filter-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(251, 191, 36, 0.3);
        }
        .btn-filter-reset {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }
        .btn-filter-reset:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Modern Table Card */
        .admin-card {
            background: rgba(30, 41, 59, 0.35);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        .admin-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            text-align: left;
        }
        .admin-table th {
            background: rgba(15, 23, 42, 0.4);
            padding: 0.75rem 0.85rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-sizing: border-box;
        }
        .admin-table td {
            padding: 0.75rem 0.85rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
            box-sizing: border-box;
        }
        .admin-table td.cell-desc {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.82rem;
            color: #cbd5e1;
            max-width: 200px;
        }
        .admin-table tbody tr {
            transition: all 0.2s ease;
        }
        .admin-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .admin-table tr:last-child td {
            border-bottom: none;
        }

        /* Avatar styling */
        .user-avatar-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }
        .avatar-circle {
            width: 28px;
            height: 28px;
            min-width: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.72rem;
            color: #fff;
            text-transform: uppercase;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
        }

        /* Modern action badges */
        .admin-table td.cell-action {
            white-space: nowrap;
        }
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.55rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            white-space: nowrap;
            max-width: 100%;
            box-sizing: border-box;
        }
        .action-add { 
            background: rgba(16, 185, 129, 0.15); 
            border-color: rgba(16, 185, 129, 0.25); 
            color: #34d399; 
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.05);
        }
        .action-edit { 
            background: rgba(59, 130, 246, 0.15); 
            border-color: rgba(59, 130, 246, 0.25); 
            color: #60a5fa; 
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.05);
        }
        .action-delete { 
            background: rgba(239, 68, 68, 0.15); 
            border-color: rgba(239, 68, 68, 0.25); 
            color: #f87171; 
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.05);
        }
        .action-default { 
            background: rgba(245, 158, 11, 0.15); 
            border-color: rgba(245, 158, 11, 0.25); 
            color: #fbbf24; 
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.05);
        }

        .btn-changes {
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.25);
            color: #fbbf24;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0.35rem 0.65rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-changes:hover {
            background: rgba(251, 191, 36, 0.2);
            border-color: #fbbf24;
            color: #fff;
            transform: translateY(-1px);
        }

        /* Detail Modal Changes style */
        .changes-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
            .changes-comparison {
                grid-template-columns: 1fr;
            }
        }
        .change-box {
            background: #0f172a;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 1.15rem;
        }
        .payload-key-value-list {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            max-height: 480px;
            overflow-y: auto;
            padding-right: 0.35rem;
        }
        .payload-key-value-list::-webkit-scrollbar {
            width: 6px;
        }
        .payload-key-value-list::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 10px;
        }
        .payload-key-value-list::-webkit-scrollbar-thumb {
            background: rgba(251, 191, 36, 0.35);
            border-radius: 10px;
        }
        .payload-key-value-list::-webkit-scrollbar-thumb:hover {
            background: rgba(251, 191, 36, 0.7);
        }
        .payload-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            border-left: 3px solid #fbbf24;
            font-size: 0.88rem;
            gap: 0.75rem;
            align-items: center;
        }
        .payload-row.empty {
            border-left-color: #475569;
            color: #64748b;
            font-style: italic;
            display: block;
            text-align: center;
            padding: 1.5rem;
        }
        .payload-key {
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 0.76rem;
            letter-spacing: 0.04em;
        }
        .payload-val {
            color: #f1f5f9;
            font-family: monospace;
            word-break: break-all;
            white-space: pre-wrap;
            font-size: 0.86rem;
            line-height: 1.45;
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- ==========================================================================
         Sidebar
         ========================================================================== -->
    <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>

    <!-- ==========================================================================
         Main Content
         ========================================================================== -->
    <main class="main-content">
        <header class="top-bar">
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="page-title">
                <h1><?php echo is_counselor() ? 'My Activity' : 'Activity Logs'; ?></h1>
            </div>
            <?php 
            // Staff name & notifications
            $userName = get_staff_name();
            $notifications = [];
            $unreadCount = 0;
            $staffId = get_staff_id();
            $staffRole = get_staff_role();
            $userType = ($staffRole === 'counselor') ? 'counselor' : 'admin';
            $adminProfilePic = null;
            
            if ($staffId) {
                if ($userType === 'admin') {
                    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                    if ($profileStmt) {
                        $profileStmt->bind_param('i', $staffId);
                        $profileStmt->execute();
                        $profileResult = $profileStmt->get_result();
                        $adminData = $profileResult->fetch_assoc();
                        $adminProfilePic = $adminData['profile_picture'] ?? null;
                        $profileStmt->close();
                    }
                }

                $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
                if ($countStmt) {
                    $countStmt->bind_param('is', $staffId, $userType);
                    $countStmt->execute();
                    $unreadCount = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
                    $countStmt->close();
                }
                
                $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? ORDER BY created_at DESC LIMIT 10");
                if ($notifStmt) {
                    $notifStmt->bind_param('is', $staffId, $userType);
                    $notifStmt->execute();
                    $result = $notifStmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $notifications[] = $row;
                    }
                    $notifStmt->close();
                }
            }
            ?>
            <div class="top-bar-actions">
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                        <div class="notification-header">
                            <h4>Notifications</h4>
                            <?php if ($unreadCount > 0): ?>
                            <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                            <?php endif; ?>
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
                        <?php else: ?>
                            <i class="fa-solid <?php echo is_counselor() ? 'fa-user-tie' : 'fa-user-shield'; ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-dropdown">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <!-- Page Header Header -->
            <div class="page-header">
                <h2><?php echo is_counselor() ? 'My Activity Logs' : 'System Audit Logs'; ?></h2>
                <p><?php echo is_counselor() ? 'View all actions and modifications performed by your account' : 'Track all administrative modifications and database events'; ?></p>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="get" action="activity_logs.php">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="startDate">Start Date</label>
                            <input type="date" id="startDate" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="endDate">End Date</label>
                            <input type="date" id="endDate" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        </div>
                        <?php if (!is_counselor()): ?>
                        <div class="filter-group">
                            <label for="userFilter">Performed By</label>
                            <select id="userFilter" name="user_filter">
                                <option value="">All Users</option>
                                <optgroup label="Administrators">
                                    <?php while ($adm = $adminsRes->fetch_assoc()): 
                                        $val = 'admin-' . $adm['id'];
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $userFilter === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adm['full_name']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </optgroup>
                                <optgroup label="Counselors">
                                    <?php while ($cns = $cnsRes->fetch_assoc()): 
                                        $val = 'counselor-' . $cns['id'];
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $userFilter === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cns['full_name']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </optgroup>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="filter-group">
                            <label for="actionFilter">Action Type</label>
                            <select id="actionFilter" name="action_filter">
                                <option value="">All Actions</option>
                                <?php if (is_counselor()): ?>
                                    <optgroup label="Student Management (CRUD)">
                                        <?php foreach ($studentActions as $act): ?>
                                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($act); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Question Management (CRUD)">
                                        <?php foreach ($questionActions as $act): ?>
                                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($act); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php if (!empty($allowedExtra)): ?>
                                    <optgroup label="Other Activities">
                                        <?php foreach ($allowedExtra as $act): ?>
                                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($act); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php foreach ($allActions as $act): ?>
                                    <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($act); ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-filter-submit">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                            <a href="activity_logs.php" class="btn btn-filter-reset">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Table Card -->
            <div class="admin-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 155px;">Date/Time</th>
                                <th style="width: 150px;">User</th>
                                <th style="width: 175px;">Action</th>
                                <th style="width: 200px;">Description</th>
                                <th style="width: 110px; text-align: center; padding-right: 1.25rem;">Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="no-data">
                                    <i class="fa-solid fa-history"></i>
                                    <p>No audit logs matching selection found.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($logs as $log): 
                                // Parse badge class and icon
                                $actionName = strtolower($log['action']);
                                $actionIcon = 'fa-info-circle';
                                if (strpos($actionName, 'add') !== false || strpos($actionName, 'create') !== false) {
                                    $badgeClass = 'action-add';
                                    $actionIcon = 'fa-plus-circle';
                                } elseif (strpos($actionName, 'edit') !== false || strpos($actionName, 'update') !== false) {
                                    $badgeClass = 'action-edit';
                                    $actionIcon = 'fa-pen-to-square';
                                } elseif (strpos($actionName, 'delete') !== false || strpos($actionName, 'remove') !== false) {
                                    $badgeClass = 'action-delete';
                                    $actionIcon = 'fa-trash-can';
                                } else {
                                    $badgeClass = 'action-default';
                                    if (strpos($actionName, 'login') !== false) {
                                        $actionIcon = 'fa-sign-in-alt';
                                    } elseif (strpos($actionName, 'logout') !== false) {
                                        $actionIcon = 'fa-sign-out-alt';
                                    }
                                }
                            ?>
                            <tr>
                                <td style="font-size: 0.78rem; color: #94a3b8; white-space: nowrap; padding-right: 0.75rem;">
                                    <i class="fa-regular fa-calendar" style="color: #64748b; margin-right: 0.35rem;"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #f1f5f9; font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($log['user_name']); ?>">
                                        <?php echo htmlspecialchars($log['user_name']); ?>
                                    </div>
                                    <span style="font-size: 0.7rem; color: #64748b; font-weight: 500; display: block;">
                                        <?php echo htmlspecialchars($log['user_role']); ?>
                                    </span>
                                </td>
                                <td class="cell-action">
                                    <span class="action-badge <?php echo $badgeClass; ?>">
                                        <i class="fa-solid <?php echo $actionIcon; ?>"></i>
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td class="cell-desc" title="<?php echo htmlspecialchars($log['description']); ?>">
                                    <?php 
                                        $desc = $log['description'];
                                        if (mb_strlen($desc) > 23) {
                                            echo htmlspecialchars(rtrim(mb_substr($desc, 0, 21))) . '...';
                                        } else {
                                            echo htmlspecialchars($desc);
                                        }
                                    ?>
                                </td>
                                <td style="text-align: center; padding-right: 1.25rem;">
                                    <button class="btn-changes" onclick="viewLogChanges(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                        <i class="fa-solid fa-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<!-- Changes Modal -->
<div class="modal" id="changesModal">
    <div class="modal-overlay" onclick="closeChangesModal()"></div>
    <div class="modal-content" style="max-width: 960px; width: 92%; max-height: 90vh; display: flex; flex-direction: column; background: #111827; color: #f1f5f9; border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 20px 50px rgba(0,0,0,0.6); border-radius: 16px;">
        <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 1.25rem 1.5rem;">
            <h2 id="modalTitle" style="color: #fbbf24; font-size: 1.2rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin: 0;">
                <i class="fa-solid fa-database"></i> Action Payload Details
            </h2>
            <button class="modal-close" onclick="closeChangesModal()" style="font-size: 1.5rem; background: none; border: none; color: #64748b; cursor: pointer; transition: color 0.2s ease;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.5rem; overflow-y: auto; flex: 1;">
            <div id="modalDescription" style="font-size: 0.95rem; color: #f1f5f9; font-weight: 600; margin-bottom: 1.25rem; line-height: 1.5; padding: 0.9rem 1.25rem; background: rgba(255,255,255,0.03); border-radius: 10px; border-left: 4px solid #fbbf24;"></div>
            
            <div class="changes-comparison">
                <div class="change-box">
                    <h4 style="color: #f87171; font-size: 0.85rem; font-weight: 700; margin-top: 0; margin-bottom: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-history"></i> Old Value (Before)
                    </h4>
                    <div id="oldValCode" class="payload-key-value-list"></div>
                </div>
                <div class="change-box">
                    <h4 style="color: #34d399; font-size: 0.85rem; font-weight: 700; margin-top: 0; margin-bottom: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-circle-check"></i> New Value (After)
                    </h4>
                    <div id="newValCode" class="payload-key-value-list"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; background: rgba(15, 23, 42, 0.2);">
            <button class="btn btn-secondary" onclick="closeChangesModal()" style="padding: 0.6rem 1.3rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #94a3b8; font-weight: 600; font-size: 0.88rem; cursor: pointer; transition: all 0.2s ease;">Close</button>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatPayload(payloadStr) {
        if (!payloadStr) {
            return `<div class="payload-row empty">No records / Empty</div>`;
        }
        try {
            const data = JSON.parse(payloadStr);
            if (typeof data !== 'object' || data === null) {
                return `<div class="payload-row"><div class="payload-key">Value</div><div class="payload-val">${escHtml(payloadStr)}</div></div>`;
            }
            
            const entries = Object.entries(data);
            if (entries.length === 0) {
                return `<div class="payload-row empty">No records / Empty</div>`;
            }
            
            let html = '';
            const ignoredKeys = ['region', 'password', 'logo'];
            for (const [key, val] of entries) {
                if (ignoredKeys.includes(key.toLowerCase())) continue;
                const humanKey = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                let displayVal = val;
                if (typeof val === 'object' && val !== null) {
                    displayVal = JSON.stringify(val, null, 2);
                } else if (val === null || val === undefined) {
                    displayVal = '-';
                }
                html += `<div class="payload-row"><div class="payload-key">${escHtml(humanKey)}</div><div class="payload-val">${escHtml(displayVal)}</div></div>`;
            }
            return html;
        } catch (e) {
            return `<div class="payload-row"><div class="payload-key">Value</div><div class="payload-val">${escHtml(payloadStr)}</div></div>`;
        }
    }

    function viewLogChanges(log) {
        document.getElementById('modalDescription').innerText = log.description || 'No description available.';
        
        const oldContainer = document.getElementById('oldValCode');
        const newContainer = document.getElementById('newValCode');
        
        oldContainer.innerHTML = formatPayload(log.old_value);
        newContainer.innerHTML = formatPayload(log.new_value);
        
        document.getElementById('changesModal').classList.add('active');
    }

    function closeChangesModal() {
        document.getElementById('changesModal').classList.remove('active');
    }
</script>
<script>
    // Notification dropdown toggle
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown) dropdown.style.display = 'none';
    });

    // Prevent dropdown close when clicking inside
    document.getElementById('notificationDropdown').addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Mark all notifications as read
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
<script src="admin.js"></script>
<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
