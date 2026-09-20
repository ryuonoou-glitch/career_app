<?php
// Counselor Profile Page - Redesigned & Modernized

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration and database
require_once 'system_config.php';
require_once 'config.php';

// Check if user is logged in (admin or counselor)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    header('Location: login.php');
    exit();
}

// Counselor self-deactivation (same as admin delete counselor in Settings — sets status inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account']) && isset($_SESSION['counselor_id'])) {
    $counselorId = (int) $_SESSION['counselor_id'];

    // Fetch counselor details before deactivation for logging/notifying
    $counselorName = 'A counselor';
    $counselorEmail = '';
    $nameStmt = $mysqli->prepare("SELECT first_name, last_name, email FROM counselors WHERE id = ?");
    if ($nameStmt) {
        $nameStmt->bind_param('i', $counselorId);
        $nameStmt->execute();
        $cnsData = $nameStmt->get_result()->fetch_assoc();
        if ($cnsData) {
            $counselorName = trim($cnsData['first_name'] . ' ' . $cnsData['last_name']);
            $counselorEmail = $cnsData['email'];
        }
        $nameStmt->close();
    }

    $stmt = $mysqli->prepare("UPDATE counselors SET status = 'inactive' WHERE id = ? AND status = 'active'");
    if ($stmt) {
        $stmt->bind_param('i', $counselorId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Load audit and notify helpers
            require_once __DIR__ . '/includes/audit.php';
            require_once __DIR__ . '/includes/notify.php';
            
            // Write activity/audit log
            log_activity(
                $counselorId,
                'counselor',
                'Deleted Counselor',
                'counselors',
                $counselorId,
                "Counselor {$counselorName} ({$counselorEmail}) deactivated their own account.",
                json_encode(['status' => 'active']),
                json_encode(['status' => 'inactive'])
            );

            // Notify admin
            notify_admin(
                'Counselor Account Deactivated',
                "Counselor {$counselorName} has deactivated their account.",
                'warning',
                'settings.php'
            );
        }
        $stmt->close();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php?account=inactive');
    exit();
}

// Handle counselor profile AJAX (counselors only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_SESSION['counselor_id'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $counselorId = (int) $_SESSION['counselor_id'];

    switch ($_POST['action']) {
        case 'update_counselor_profile':
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $suffix = trim((string) ($_POST['suffix'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $contact = trim((string) ($_POST['contactNumber'] ?? ''));

            if ($firstName === '' || $lastName === '' || $email === '' || $contact === '') {
                $response['message'] = 'First name, last name, email, and contact number are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = 'Please enter a valid email address.';
            } else {
                $stmt = $mysqli->prepare('UPDATE counselors SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, phone = ? WHERE id = ? AND status = ?');
                $status = 'active';
                $stmt->bind_param('ssssssis', $firstName, $middleName, $lastName, $suffix, $email, $contact, $counselorId, $status);
                if ($stmt->execute()) {
                    $nameParts = array_filter([$firstName, $middleName, $lastName], 'strlen');
                    $fullName = implode(' ', $nameParts);
                    if (!empty($suffix)) $fullName .= ' ' . $suffix;
                    $_SESSION['counselor_name'] = $fullName;
                    $_SESSION['counselor_email'] = $email;
                    $response['success'] = true;
                    $response['message'] = 'Profile updated successfully!';
                    $response['fullName'] = $fullName;
                    $response['email'] = $email;
                    $response['contact'] = $contact;
                } else {
                    $response['message'] = 'Failed to update profile. Email might already be taken.';
                }
                $stmt->close();
            }
            echo json_encode($response);
            exit;

        case 'update_counselor_password':
            $currentPassword = (string) ($_POST['currentPassword'] ?? '');
            $newPassword = (string) ($_POST['newPassword'] ?? '');

            if ($currentPassword === '' || $newPassword === '') {
                $response['message'] = 'All password fields are required.';
            } elseif (strlen($newPassword) < 8) {
                $response['message'] = 'New password must be at least 8 characters long.';
            } else {
                $stmt = $mysqli->prepare('SELECT password FROM counselors WHERE id = ? AND status = ? LIMIT 1');
                $status = 'active';
                $stmt->bind_param('is', $counselorId, $status);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row || !password_verify($currentPassword, $row['password'])) {
                    $response['message'] = 'Current password is incorrect.';
                } else {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $mysqli->prepare('UPDATE counselors SET password = ? WHERE id = ?');
                    $updateStmt->bind_param('si', $hashed, $counselorId);
                    if ($updateStmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Password updated successfully!';
                    } else {
                        $response['message'] = 'Failed to update password.';
                    }
                    $updateStmt->close();
                }
            }
            echo json_encode($response);
            exit;
    }
}

// Get user info — load from DB so profile matches Settings counselor data
$userName = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_name'] : $_SESSION['admin_name'] ?? 'Counselor';
$userEmail = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_email'] : $_SESSION['admin_email'] ?? '';
$userRole = isset($_SESSION['counselor_id']) ? 'Guidance Counselor' : 'Administrator';
$userContact = '';
$userFirstName = '';
$userMiddleName = '';
$userLastName = '';
$userSuffix = '';
$counselorCreatedAt = date('Y-m-d H:i:s');
$counselorStatus = 'active';

if (isset($_SESSION['counselor_id'])) {
    $counselorId = (int) $_SESSION['counselor_id'];
    $stmt = $mysqli->prepare('SELECT first_name, middle_name, last_name, suffix, email, phone, status, created_at FROM counselors WHERE id = ? AND status = ? LIMIT 1');
    $status = 'active';
    $stmt->bind_param('is', $counselorId, $status);
    $stmt->execute();
    $counselor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($counselor) {
        $userFirstName = $counselor['first_name'];
        $userMiddleName = $counselor['middle_name'] ?? '';
        $userLastName = $counselor['last_name'];
        $userSuffix = $counselor['suffix'] ?? '';
        $counselorCreatedAt = $counselor['created_at'] ?? date('Y-m-d H:i:s');
        $counselorStatus = $counselor['status'] ?? 'active';
        
        $cNameParts = array_filter([$userFirstName, $userMiddleName, $userLastName], 'strlen');
        $userName = implode(' ', $cNameParts);
        if (!empty($userSuffix)) $userName .= ' ' . $userSuffix;
        $userEmail = $counselor['email'];
        $userContact = $counselor['phone'] ?? '';
        $_SESSION['counselor_name'] = $userName;
        $_SESSION['counselor_email'] = $userEmail;
    }
}

// Compute initials
$initials = 'GC';
if (!empty($userFirstName) && !empty($userLastName)) {
    $initials = strtoupper(substr($userFirstName, 0, 1) . substr($userLastName, 0, 1));
}

// Quick stats for profile overview
$totalStudents = 0;
$resS = $mysqli->query("SELECT COUNT(*) AS cnt FROM students WHERE status = 'active'");
if ($resS && $row = $resS->fetch_assoc()) {
    $totalStudents = (int)$row['cnt'];
}

$totalCompletedAssessments = 0;
$resA = $mysqli->query("SELECT COUNT(*) AS cnt FROM student_assessments WHERE status = 'completed'");
if ($resA && $row = $resA->fetch_assoc()) {
    $totalCompletedAssessments = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Guidance Counselor - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
    <style>
        /* ==========================================================================
           REDESIGNED COUNSELOR PROFILE STYLES
           ========================================================================== */
        .profile-page-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ── Hero Banner Card ── */
        .profile-hero-card {
            position: relative;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85) 0%, rgba(15, 23, 42, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            overflow: hidden;
        }
        .profile-hero-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .profile-hero-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        .profile-hero-main {
            display: flex;
            align-items: center;
            gap: 1.75rem;
            flex-wrap: wrap;
        }
        .hero-avatar-wrapper {
            position: relative;
            width: 96px;
            height: 96px;
            border-radius: 24px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: 1px;
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35);
            border: 3px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
        }
        .hero-avatar-status {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #10b981;
            border: 3px solid #0f172a;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.6);
        }
        .hero-details {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .hero-name-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-wrap: wrap;
        }
        .hero-name {
            font-size: 1.65rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }
        .hero-badges {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.85rem;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .badge-role {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .badge-scope {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .hero-meta-chips {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex-wrap: wrap;
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 0.25rem;
        }
        .meta-chip {
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .meta-chip i {
            color: #fbbf24;
            font-size: 0.85rem;
        }

        /* ── Hero Stats Pills ── */
        .hero-stats-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .hero-stat-pill {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 14px;
            padding: 0.85rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            min-width: 140px;
        }
        .stat-icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .stat-icon-wrap.students {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }
        .stat-icon-wrap.assessments {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        .stat-meta {
            display: flex;
            flex-direction: column;
        }
        .stat-val {
            font-size: 1.25rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }
        .stat-lbl {
            font-size: 0.72rem;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        /* ── Navigation Tabs ── */
        .profile-nav-tabs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 0.4rem;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .profile-nav-tabs::-webkit-scrollbar {
            display: none;
        }
        .profile-tab-btn {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            white-space: nowrap;
        }
        .profile-tab-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.04);
        }
        .profile-tab-btn.active {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        .profile-tab-btn i {
            font-size: 0.95rem;
        }

        /* ── Tab Panes ── */
        .profile-tab-pane {
            display: none;
            animation: fadeInTab 0.3s ease-out forwards;
        }
        .profile-tab-pane.active {
            display: block;
        }
        @keyframes fadeInTab {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Card Container Styles ── */
        .profile-section-card {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .section-header-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            gap: 1rem;
            flex-wrap: wrap;
        }
        .section-title-group h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .section-title-group h3 i {
            color: #fbbf24;
        }
        .section-title-group p {
            margin: 0;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        /* ── Modern Form Controls ── */
        .grid-form {
            display: flex;
            flex-direction: column;
            gap: 1.35rem;
        }
        .form-grid-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
        }
        .form-grid-row.two-cols {
            grid-template-columns: 1fr 1fr;
        }
        .form-grid-row.three-cols {
            grid-template-columns: 1.2fr 1fr 1fr;
        }
        .form-field-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .form-field-group label {
            font-size: 0.84rem;
            font-weight: 600;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .form-field-group label i {
            color: #fbbf24;
            font-size: 0.8rem;
        }
        .form-field-group label .required-star {
            color: #ef4444;
        }
        .input-icon-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon-wrapper i.lead-icon {
            position: absolute;
            left: 1rem;
            color: #64748b;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        .input-icon-wrapper input,
        .input-icon-wrapper select,
        .form-field-group input,
        .form-field-group select {
            width: 100%;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            color: #ffffff;
            font-size: 0.92rem;
            font-family: inherit;
            transition: all 0.25s ease;
            box-sizing: border-box;
        }
        .input-icon-wrapper input {
            padding-left: 2.75rem;
        }
        .input-icon-wrapper input:focus,
        .input-icon-wrapper select:focus,
        .form-field-group input:focus,
        .form-field-group select:focus {
            outline: none;
            border-color: #f59e0b;
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .input-icon-wrapper input:focus + .lead-icon,
        .input-icon-wrapper:focus-within i.lead-icon {
            color: #fbbf24;
        }
        .input-hint {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.2rem;
        }

        /* ── Password Toggles ── */
        .password-toggle-btn {
            position: absolute;
            right: 0.85rem;
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 0.95rem;
            cursor: pointer;
            padding: 0.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease;
        }
        .password-toggle-btn:hover {
            color: #fbbf24;
        }

        /* ── Password Strength Bar ── */
        .strength-meter-container {
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 0.25rem;
        }
        .strength-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .strength-label {
            font-weight: 700;
            color: #94a3b8;
        }
        .strength-bars {
            display: flex;
            gap: 6px;
            height: 6px;
        }
        .strength-bar-seg {
            flex: 1;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 3px;
            transition: background 0.3s ease;
        }
        .strength-rules-list {
            display: flex;
            gap: 1.25rem;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: #64748b;
        }
        .strength-rule-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            transition: color 0.2s ease;
        }
        .strength-rule-item.valid {
            color: #34d399;
        }
        .strength-rule-item.valid i {
            color: #10b981;
        }

        /* ── Form Actions ── */
        .form-actions-bar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        .btn-primary-action {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.25);
        }
        .btn-primary-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
            filter: brightness(1.05);
        }
        .btn-primary-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ── Alert Banners ── */
        .profile-alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.25rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            animation: slideDownAlert 0.3s ease;
        }
        .profile-alert.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #6ee7b7;
        }
        .profile-alert.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }
        @keyframes slideDownAlert {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Overview Tab Cards Grid ── */
        .overview-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .overview-feature-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            transition: all 0.3s ease;
        }
        .overview-feature-card:hover {
            border-color: rgba(245, 158, 11, 0.3);
            background: rgba(15, 23, 42, 0.8);
            transform: translateY(-2px);
        }
        .ofc-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .ofc-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
        }
        .ofc-desc {
            font-size: 0.84rem;
            color: #94a3b8;
            line-height: 1.5;
            margin: 0;
        }
        .quick-nav-links {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        .quick-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.1rem;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .quick-nav-btn:hover {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        /* ── Danger Zone Card ── */
        .danger-zone-card {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 18px;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        .danger-zone-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: #ef4444;
        }
        .danger-title {
            color: #f87171 !important;
        }
        .danger-title i {
            color: #ef4444 !important;
        }
        .danger-desc-box {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.15);
            border-radius: 12px;
            padding: 1.25rem;
            margin: 1.25rem 0;
        }
        .danger-desc-box p {
            margin: 0 0 0.5rem 0;
            color: #fca5a5;
            font-size: 0.88rem;
            line-height: 1.5;
        }
        .danger-desc-box ul {
            margin: 0;
            padding-left: 1.25rem;
            color: #f87171;
            font-size: 0.82rem;
            line-height: 1.6;
        }
        .btn-danger-action {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.35);
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.8rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-danger-action:hover {
            background: #ef4444;
            color: #ffffff;
            border-color: #ef4444;
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* ── Deactivation Custom Modal ── */
        .counselor-modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            animation: modalFadeIn 0.25s ease;
        }
        .counselor-modal-backdrop.active {
            display: flex;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .counselor-modal-box {
            background: #0f172a;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 20px;
            max-width: 480px;
            width: 100%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            text-align: center;
            position: relative;
        }
        .modal-warning-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
            border: 2px solid rgba(239, 68, 68, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin: 0 auto;
        }
        .counselor-modal-box h4 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
        }
        .counselor-modal-box p {
            font-size: 0.88rem;
            color: #94a3b8;
            line-height: 1.5;
            margin: 0;
        }
        .modal-btn-row {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        .modal-btn-cancel {
            flex: 1;
            padding: 0.85rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .modal-btn-cancel:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }
        .modal-btn-confirm {
            flex: 1;
            padding: 0.85rem;
            border-radius: 12px;
            background: #ef4444;
            border: none;
            color: #ffffff;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.35);
        }
        .modal-btn-confirm:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        /* ── Responsive Mobile ── */
        @media (max-width: 900px) {
            .profile-hero-content {
                flex-direction: column;
                align-items: flex-start;
            }
            .hero-stats-group {
                width: 100%;
            }
            .hero-stat-pill {
                flex: 1;
            }
            .form-grid-row.two-cols,
            .form-grid-row.three-cols {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 600px) {
            .profile-hero-card {
                padding: 1.5rem;
            }
            .hero-avatar-wrapper {
                width: 76px;
                height: 76px;
                font-size: 1.8rem;
            }
            .hero-name {
                font-size: 1.35rem;
            }
            .hero-stat-pill {
                min-width: 100%;
            }
            .profile-section-card {
                padding: 1.25rem;
            }
            .form-actions-bar {
                justify-content: stretch;
            }
            .btn-primary-action {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
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
                    <h1>My Profile</h1>
                </div>
                <div class="top-bar-actions">
                    <?php if (isset($_SESSION['counselor_id'])): ?>
                        <?php require_once __DIR__ . '/includes/counselor_notifications_bell.php'; ?>
                    <?php endif; ?>
                    <div class="user-profile">
                        <div class="user-avatar counselor-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#0f172a;font-weight:800;">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="profile-page-wrapper">
                    
                    <!-- 1. Hero Header Card -->
                    <div class="profile-hero-card">
                        <div class="profile-hero-content">
                            <div class="profile-hero-main">
                                <div class="hero-avatar-wrapper" id="profileHeroAvatar">
                                    <?php echo htmlspecialchars($initials); ?>
                                    <div class="hero-avatar-status" title="Account Status: Active"></div>
                                </div>
                                <div class="hero-details">
                                    <div class="hero-name-row">
                                        <h2 class="hero-name" id="heroDisplayName"><?php echo htmlspecialchars($userName); ?></h2>
                                        <div class="hero-badges">
                                            <span class="hero-badge badge-role">
                                                <i class="fa-solid fa-user-shield"></i>
                                                <?php echo htmlspecialchars($userRole); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="hero-meta-chips">
                                        <div class="meta-chip">
                                            <i class="fa-solid fa-envelope"></i>
                                            <span id="heroEmailDisplay"><?php echo htmlspecialchars($userEmail); ?></span>
                                        </div>
                                        <?php if (!empty($userContact)): ?>
                                        <div class="meta-chip">
                                            <i class="fa-solid fa-phone"></i>
                                            <span id="heroPhoneDisplay"><?php echo htmlspecialchars($userContact); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="meta-chip">
                                            <i class="fa-solid fa-calendar-check"></i>
                                            <span>Joined <?php echo date('M Y', strtotime($counselorCreatedAt)); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div>

                    <!-- 2. Interactive Navigation Tabs -->
                    <div class="profile-nav-tabs">
                        <button class="profile-tab-btn active" data-tab="tab-personal">
                            <i class="fa-solid fa-user-pen"></i>
                            <span>Personal Information</span>
                        </button>
                        <button class="profile-tab-btn" data-tab="tab-security">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Security & Password</span>
                        </button>
                        <?php if (isset($_SESSION['counselor_id'])): ?>
                        <button class="profile-tab-btn" data-tab="tab-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Account Status</span>
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- 3. Tab Panes -->

                    <!-- Tab 1: Personal Information -->
                    <div class="profile-tab-pane active" id="tab-personal">
                        <div class="profile-section-card">
                            <div class="section-header-wrap">
                                <div class="section-title-group">
                                    <h3><i class="fa-solid fa-user-edit"></i> Personal Information</h3>
                                    <p>Update your public counselor display details and verified contact info.</p>
                                </div>
                            </div>

                            <div id="profileMsgContainer"></div>

                            <form class="grid-form" id="profileForm">
                                <div class="form-grid-row three-cols">
                                    <div class="form-field-group">
                                        <label for="firstName">First Name <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="text" id="firstName" name="first_name" value="<?php echo htmlspecialchars($userFirstName ?? ''); ?>" placeholder="e.g. Maria" required>
                                            <i class="fa-solid fa-user lead-icon"></i>
                                        </div>
                                    </div>
                                    <div class="form-field-group">
                                        <label for="middleName">Middle Name</label>
                                        <div class="input-icon-wrapper">
                                            <input type="text" id="middleName" name="middle_name" value="<?php echo htmlspecialchars($userMiddleName ?? ''); ?>" placeholder="Optional">
                                            <i class="fa-solid fa-user-tag lead-icon"></i>
                                        </div>
                                    </div>
                                    <div class="form-field-group">
                                        <label for="lastName">Last Name <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="text" id="lastName" name="last_name" value="<?php echo htmlspecialchars($userLastName ?? ''); ?>" placeholder="e.g. Santos" required>
                                            <i class="fa-solid fa-user lead-icon"></i>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-grid-row three-cols">
                                    <div class="form-field-group">
                                        <label for="suffix">Name Suffix</label>
                                        <select id="suffix" name="suffix">
                                            <option value="">None (N/A)</option>
                                            <option value="Jr." <?php echo ($userSuffix ?? '') === 'Jr.' ? 'selected' : ''; ?>>Jr.</option>
                                            <option value="Sr." <?php echo ($userSuffix ?? '') === 'Sr.' ? 'selected' : ''; ?>>Sr.</option>
                                            <option value="II" <?php echo ($userSuffix ?? '') === 'II' ? 'selected' : ''; ?>>II</option>
                                            <option value="III" <?php echo ($userSuffix ?? '') === 'III' ? 'selected' : ''; ?>>III</option>
                                            <option value="IV" <?php echo ($userSuffix ?? '') === 'IV' ? 'selected' : ''; ?>>IV</option>
                                            <option value="V" <?php echo ($userSuffix ?? '') === 'V' ? 'selected' : ''; ?>>V</option>
                                        </select>
                                    </div>
                                    <div class="form-field-group">
                                        <label for="email">Email Address <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($userEmail); ?>" placeholder="counselor@school.edu.ph" required>
                                            <i class="fa-solid fa-envelope lead-icon"></i>
                                        </div>
                                    </div>
                                    <div class="form-field-group">
                                        <label for="contactNumber">Contact Number <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="tel" id="contactNumber" name="contactNumber" value="<?php echo htmlspecialchars($userContact); ?>" placeholder="e.g. 09171234567" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required>
                                            <i class="fa-solid fa-phone lead-icon"></i>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions-bar">
                                    <button type="submit" class="btn-primary-action btn-save">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Save Changes</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Tab 2: Security & Password -->
                    <div class="profile-tab-pane" id="tab-security">
                        <div class="profile-section-card">
                            <div class="section-header-wrap">
                                <div class="section-title-group">
                                    <h3><i class="fa-solid fa-lock"></i> Security & Password</h3>
                                    <p>Ensure your account remains safe with a strong, distinct password.</p>
                                </div>
                            </div>

                            <div id="passwordMsgContainer"></div>

                            <form class="grid-form" id="passwordForm">
                                <div class="form-field-group">
                                    <label for="currentPassword">Current Password <span class="required-star">*</span></label>
                                    <div class="input-icon-wrapper">
                                        <input type="password" id="currentPassword" name="currentPassword" placeholder="Enter your current password" required>
                                        <i class="fa-solid fa-key lead-icon"></i>
                                        <button type="button" class="password-toggle-btn" data-target="currentPassword" title="Toggle password visibility">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-grid-row two-cols">
                                    <div class="form-field-group">
                                        <label for="newPassword">New Password <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="password" id="newPassword" name="newPassword" placeholder="Minimum 8 characters" required>
                                            <i class="fa-solid fa-shield-halved lead-icon"></i>
                                            <button type="button" class="password-toggle-btn" data-target="newPassword" title="Toggle password visibility">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-field-group">
                                        <label for="confirmPassword">Confirm New Password <span class="required-star">*</span></label>
                                        <div class="input-icon-wrapper">
                                            <input type="password" id="confirmPassword" name="confirmPassword" placeholder="Re-enter new password" required>
                                            <i class="fa-solid fa-check-double lead-icon"></i>
                                            <button type="button" class="password-toggle-btn" data-target="confirmPassword" title="Toggle password visibility">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Real-time Strength Meter -->
                                <div class="strength-meter-container">
                                    <div class="strength-header">
                                        <span>Password Security Strength</span>
                                        <span class="strength-label" id="strengthLabel">Enter a password</span>
                                    </div>
                                    <div class="strength-bars">
                                        <div class="strength-bar-seg" id="seg1"></div>
                                        <div class="strength-bar-seg" id="seg2"></div>
                                        <div class="strength-bar-seg" id="seg3"></div>
                                        <div class="strength-bar-seg" id="seg4"></div>
                                    </div>
                                    <div class="strength-rules-list">
                                        <div class="strength-rule-item" id="ruleLength">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                            <span>At least 8 characters</span>
                                        </div>
                                        <div class="strength-rule-item" id="ruleMix">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                            <span>Contains letters & numbers</span>
                                        </div>
                                        <div class="strength-rule-item" id="ruleMatch">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                            <span>Passwords match</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions-bar">
                                    <button type="submit" class="btn-primary-action btn-update">
                                        <i class="fa-solid fa-key"></i>
                                        <span>Update Password</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['counselor_id'])): ?>
                    <!-- Tab 4: Danger Zone -->
                    <div class="profile-tab-pane" id="tab-danger">
                        <div class="danger-zone-card">
                            <div class="section-header-wrap" style="border-bottom-color: rgba(239, 68, 68, 0.15);">
                                <div class="section-title-group">
                                    <h3 class="danger-title"><i class="fa-solid fa-user-slash"></i> Account Status & Deactivation</h3>
                                    <p>Temporarily deactivate your counselor account when you are away or transitioning.</p>
                                </div>
                            </div>

                            <div class="danger-desc-box">
                                <p><strong>Important Warning:</strong> Deactivating your counselor account will:</p>
                                <ul>
                                    <li>Immediately log you out of this session.</li>
                                    <li>Set your status to <em>Inactive</em> and disable future logins.</li>
                                    <li>Require a School Administrator to reactivate your account in <strong>Settings</strong>.</li>
                                </ul>
                            </div>

                            <form id="deactivateAccountForm" method="post" action="counselor_profile.php" style="display:none;">
                                <input type="hidden" name="deactivate_account" value="1">
                            </form>

                            <button type="button" class="btn-danger-action" id="openDeactivateModalBtn">
                                <i class="fa-solid fa-user-slash"></i>
                                <span>Deactivate My Account</span>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Deactivation Confirmation Modal -->
    <div class="counselor-modal-backdrop" id="deactivateModal">
        <div class="counselor-modal-box">
            <div class="modal-warning-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h4>Deactivate Counselor Account?</h4>
            <p>You will be immediately logged out and won't be able to sign in until an administrator reactivates your account in the admin settings.</p>
            <div class="modal-btn-row">
                <button type="button" class="modal-btn-cancel" id="cancelDeactivateBtn">Cancel</button>
                <button type="button" class="modal-btn-confirm" id="confirmDeactivateBtn">Yes, Deactivate</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // ── Mobile Sidebar Toggle ──
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        let sidebarOverlay = document.getElementById('sidebarOverlay');
        if (!sidebarOverlay) {
            sidebarOverlay = document.createElement('div');
            sidebarOverlay.id = 'sidebarOverlay';
            sidebarOverlay.className = 'sidebar-overlay';
            document.body.appendChild(sidebarOverlay);
        }

        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            });
        }
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
        }
        if (sidebarOverlay && sidebar) {
            sidebarOverlay.addEventListener('click', () => {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
        }

        // ── Tab Switching ──
        const tabBtns = document.querySelectorAll('.profile-tab-btn');
        const tabPanes = document.querySelectorAll('.profile-tab-pane');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-tab');
                
                tabBtns.forEach(b => b.classList.remove('active'));
                tabPanes.forEach(p => p.classList.remove('active'));

                btn.classList.add('active');
                const targetPane = document.getElementById(targetId);
                if (targetPane) targetPane.classList.add('active');
            });
        });

        // ── Password Toggles ──
        const passwordToggles = document.querySelectorAll('.password-toggle-btn');
        passwordToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = this.querySelector('i');
                if (input && icon) {
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });

        // ── Live Password Strength & Match Meter ──
        const newPassInput = document.getElementById('newPassword');
        const confirmPassInput = document.getElementById('confirmPassword');
        const strengthLabel = document.getElementById('strengthLabel');
        const seg1 = document.getElementById('seg1');
        const seg2 = document.getElementById('seg2');
        const seg3 = document.getElementById('seg3');
        const seg4 = document.getElementById('seg4');

        const ruleLength = document.getElementById('ruleLength');
        const ruleMix = document.getElementById('ruleMix');
        const ruleMatch = document.getElementById('ruleMatch');

        function updatePasswordRules() {
            if (!newPassInput) return;
            const val = newPassInput.value;
            const confVal = confirmPassInput ? confirmPassInput.value : '';

            // Rule 1: Length
            const hasLen = val.length >= 8;
            setRuleState(ruleLength, hasLen);

            // Rule 2: Mix
            const hasLetters = /[a-zA-Z]/.test(val);
            const hasNumbers = /[0-9]/.test(val);
            const hasMix = hasLetters && hasNumbers;
            setRuleState(ruleMix, hasMix);

            // Rule 3: Match
            const isMatch = val.length > 0 && val === confVal;
            setRuleState(ruleMatch, isMatch);

            // Score calculation (0 to 4)
            let score = 0;
            if (val.length >= 8) score++;
            if (hasMix) score++;
            if (/[^a-zA-Z0-9]/.test(val)) score++;
            if (val.length >= 12) score++;

            // Clear bars
            [seg1, seg2, seg3, seg4].forEach(seg => {
                if (seg) seg.style.background = 'rgba(255, 255, 255, 0.08)';
            });

            if (val.length === 0) {
                if (strengthLabel) {
                    strengthLabel.textContent = 'Enter a password';
                    strengthLabel.style.color = '#94a3b8';
                }
                return;
            }

            if (score === 1) {
                if (seg1) seg1.style.background = '#ef4444';
                if (strengthLabel) { strengthLabel.textContent = 'Weak'; strengthLabel.style.color = '#ef4444'; }
            } else if (score === 2) {
                if (seg1) seg1.style.background = '#f97316';
                if (seg2) seg2.style.background = '#f97316';
                if (strengthLabel) { strengthLabel.textContent = 'Fair'; strengthLabel.style.color = '#f97316'; }
            } else if (score === 3) {
                if (seg1) seg1.style.background = '#f59e0b';
                if (seg2) seg2.style.background = '#f59e0b';
                if (seg3) seg3.style.background = '#f59e0b';
                if (strengthLabel) { strengthLabel.textContent = 'Good'; strengthLabel.style.color = '#f59e0b'; }
            } else if (score >= 4) {
                [seg1, seg2, seg3, seg4].forEach(seg => { if (seg) seg.style.background = '#10b981'; });
                if (strengthLabel) { strengthLabel.textContent = 'Strong'; strengthLabel.style.color = '#10b981'; }
            }
        }

        function setRuleState(el, isValid) {
            if (!el) return;
            const icon = el.querySelector('i');
            if (isValid) {
                el.classList.add('valid');
                if (icon) {
                    icon.className = 'fa-solid fa-circle-check';
                }
            } else {
                el.classList.remove('valid');
                if (icon) {
                    icon.className = 'fa-solid fa-circle-xmark';
                }
            }
        }

        if (newPassInput) newPassInput.addEventListener('input', updatePasswordRules);
        if (confirmPassInput) confirmPassInput.addEventListener('input', updatePasswordRules);

        // ── Helper to Show Alert Banners ──
        function showAlert(containerId, type, message) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = `
                <div class="profile-alert ${type}">
                    <i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'}"></i>
                    <span>${message}</span>
                </div>
            `;
        }

        // ── Profile Form Submission ──
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const firstName = document.getElementById('firstName').value.trim();
                const middleName = document.getElementById('middleName').value.trim();
                const lastName = document.getElementById('lastName').value.trim();
                const suffix = document.getElementById('suffix').value;
                const email = document.getElementById('email').value.trim();
                const contactNumber = document.getElementById('contactNumber').value.trim();

                if (!firstName || !lastName || !email || !contactNumber) {
                    showAlert('profileMsgContainer', 'error', 'Please fill in all required fields.');
                    return;
                }

                const saveBtn = profileForm.querySelector('.btn-save');
                const origHtml = saveBtn.innerHTML;
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Saving...</span>';

                const fd = new FormData();
                fd.append('action', 'update_counselor_profile');
                fd.append('first_name', firstName);
                fd.append('middle_name', middleName);
                fd.append('last_name', lastName);
                fd.append('suffix', suffix);
                fd.append('email', email);
                fd.append('contactNumber', contactNumber);

                try {
                    const res = await fetch('counselor_profile.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        showAlert('profileMsgContainer', 'success', data.message || 'Profile updated successfully!');
                        
                        // Update Header
                        const heroName = document.getElementById('heroDisplayName');
                        const topBarName = document.querySelector('.top-bar .user-name');
                        const heroEmail = document.getElementById('heroEmailDisplay');
                        const heroPhone = document.getElementById('heroPhoneDisplay');
                        const heroAvatar = document.getElementById('profileHeroAvatar');
                        const topAvatar = document.querySelector('.top-bar .user-avatar');

                        if (heroName && data.fullName) heroName.textContent = data.fullName;
                        if (topBarName && data.fullName) topBarName.textContent = data.fullName;
                        if (heroEmail) heroEmail.textContent = email;
                        if (heroPhone) heroPhone.textContent = contactNumber;

                        // Initials update
                        let newInitials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
                        if (heroAvatar) {
                            heroAvatar.childNodes[0].nodeValue = newInitials + ' ';
                        }
                        if (topAvatar) topAvatar.textContent = newInitials;

                        saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Saved!</span>';
                    } else {
                        showAlert('profileMsgContainer', 'error', data.message || 'Failed to update profile.');
                        saveBtn.innerHTML = origHtml;
                    }
                } catch (err) {
                    showAlert('profileMsgContainer', 'error', 'An error occurred while saving your profile.');
                    saveBtn.innerHTML = origHtml;
                }

                setTimeout(() => {
                    saveBtn.innerHTML = origHtml;
                    saveBtn.disabled = false;
                }, 2000);
            });
        }

        // ── Password Form Submission ──
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const currentPassword = document.getElementById('currentPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;

                if (!currentPassword || !newPassword || !confirmPassword) {
                    showAlert('passwordMsgContainer', 'error', 'Please fill in all password fields.');
                    return;
                }

                if (newPassword.length < 8) {
                    showAlert('passwordMsgContainer', 'error', 'New password must be at least 8 characters long.');
                    return;
                }

                if (newPassword !== confirmPassword) {
                    showAlert('passwordMsgContainer', 'error', 'New password and confirmation do not match.');
                    return;
                }

                const updateBtn = passwordForm.querySelector('.btn-update');
                const origHtml = updateBtn.innerHTML;
                updateBtn.disabled = true;
                updateBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Updating...</span>';

                const fd = new FormData();
                fd.append('action', 'update_counselor_password');
                fd.append('currentPassword', currentPassword);
                fd.append('newPassword', newPassword);

                try {
                    const res = await fetch('counselor_profile.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        showAlert('passwordMsgContainer', 'success', data.message || 'Password changed successfully!');
                        passwordForm.reset();
                        updatePasswordRules();
                        updateBtn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Password Changed!</span>';
                    } else {
                        showAlert('passwordMsgContainer', 'error', data.message || 'Failed to update password.');
                        updateBtn.innerHTML = origHtml;
                    }
                } catch (err) {
                    showAlert('passwordMsgContainer', 'error', 'An error occurred while updating your password.');
                    updateBtn.innerHTML = origHtml;
                }

                setTimeout(() => {
                    updateBtn.innerHTML = origHtml;
                    updateBtn.disabled = false;
                }, 2000);
            });
        }

        // ── Custom Deactivation Modal ──
        const openDeactBtn = document.getElementById('openDeactivateModalBtn');
        const deactModal = document.getElementById('deactivateModal');
        const cancelDeactBtn = document.getElementById('cancelDeactivateBtn');
        const confirmDeactBtn = document.getElementById('confirmDeactivateBtn');
        const deactForm = document.getElementById('deactivateAccountForm');

        if (openDeactBtn && deactModal) {
            openDeactBtn.addEventListener('click', () => {
                deactModal.classList.add('active');
            });
        }

        if (cancelDeactBtn && deactModal) {
            cancelDeactBtn.addEventListener('click', () => {
                deactModal.classList.remove('active');
            });
        }

        if (confirmDeactBtn && deactForm) {
            confirmDeactBtn.addEventListener('click', () => {
                confirmDeactBtn.disabled = true;
                confirmDeactBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Deactivating...';
                deactForm.submit();
            });
        }

        // Close modal on backdrop click
        if (deactModal) {
            deactModal.addEventListener('click', (e) => {
                if (e.target === deactModal) {
                    deactModal.classList.remove('active');
                }
            });
        }
    });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
