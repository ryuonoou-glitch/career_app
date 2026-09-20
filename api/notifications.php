<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Detect caller type ────────────────────────────────────────────────────
$isAdmin     = !empty($_SESSION['admin_id']);
$isCounselor = !empty($_SESSION['counselor_id']);
$isStudent   = !empty($_SESSION['student_id']) || !empty($_SESSION['student_db_id']);

if (!$isAdmin && !$isCounselor && !$isStudent) {
    $response['message'] = 'Not authorized';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$action = $_POST['action'] ?? '';
$callerType = trim($_POST['caller_type'] ?? '');

// Auto-resolve caller role based on active authenticated session
if ($isCounselor && !$isAdmin && !$isStudent) {
    $callerType = 'counselor';
} elseif ($isAdmin && !$isCounselor && !$isStudent) {
    $callerType = 'admin';
} elseif ($isStudent && !$isAdmin && !$isCounselor) {
    $callerType = 'student';
} else {
    // If ambiguous or user holds multiple session keys, respect valid caller_type if provided
    if ($callerType === 'counselor' && $isCounselor) {
        $callerType = 'counselor';
    } elseif ($callerType === 'student' && $isStudent) {
        $callerType = 'student';
    } elseif ($callerType === 'admin' && $isAdmin) {
        $callerType = 'admin';
    } elseif ($isAdmin) {
        $callerType = 'admin';
    } elseif ($isCounselor) {
        $callerType = 'counselor';
    } elseif ($isStudent) {
        $callerType = 'student';
    }
}

// ── Route ─────────────────────────────────────────────────────────────────
if ($callerType === 'admin' && $isAdmin) {
    $adminId = (int) $_SESSION['admin_id'];
    switch ($action) {
        case 'mark_read':       handleAdminMarkRead($mysqli, $adminId, $response);    break;
        case 'mark_all_read':   handleAdminMarkAllRead($mysqli, $adminId, $response); break;
        case 'clear_all':       handleAdminClearAll($mysqli, $adminId, $response);    break;
        case 'get_notifications': handleAdminGetNotifications($mysqli, $adminId, $response); break;
        case 'add_notification':  handleAddNotification($mysqli, $response);          break;
        default: $response['message'] = 'Invalid action';
    }
} elseif ($callerType === 'counselor' && $isCounselor) {
    $counselorId = (int) $_SESSION['counselor_id'];
    switch ($action) {
        case 'mark_read':           handleCounselorMarkRead($mysqli, $counselorId, $response);    break;
        case 'mark_all_read':       handleCounselorMarkAllRead($mysqli, $counselorId, $response); break;
        case 'clear_all':           handleCounselorClearAll($mysqli, $counselorId, $response);    break;
        case 'get_notifications':   handleCounselorGetNotifications($mysqli, $counselorId, $response); break;
        default: $response['message'] = 'Invalid action';
    }
} elseif ($callerType === 'student' && $isStudent) {
    // Resolve the student's DB primary key
    $studentDbId = (int) ($_SESSION['student_db_id'] ?? 0);
    if ($studentDbId <= 0) {
        // Fallback: look up by school ID
        $tmp = $mysqli->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
        $tmp->bind_param('s', $_SESSION['student_id']);
        $tmp->execute();
        $row = $tmp->get_result()->fetch_assoc();
        $tmp->close();
        $studentDbId = (int) ($row['id'] ?? 0);
    }
    switch ($action) {
        case 'mark_read':       handleStudentMarkRead($mysqli, $studentDbId, $response);    break;
        case 'mark_all_read':   handleStudentMarkAllRead($mysqli, $studentDbId, $response); break;
        case 'clear_all':       handleStudentClearAll($mysqli, $studentDbId, $response);    break;
        case 'get_notifications': handleStudentGetNotifications($mysqli, $studentDbId, $response); break;
        default: $response['message'] = 'Invalid action';
    }
} else {
    $response['message'] = 'Unauthorized caller type or role mismatch';
}

echo json_encode($response);

// ══════════════════════════════════════════════════════════════════════════
// ADMIN HANDLERS (unchanged logic)
// ══════════════════════════════════════════════════════════════════════════

function handleAdminMarkRead($mysqli, $adminId, &$response) {
    $notifId = intval($_POST['id'] ?? 0);
    if ($notifId <= 0) { $response['message'] = 'Invalid notification ID'; return; }
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL) AND user_type = 'admin'");
    $stmt->bind_param('ii', $notifId, $adminId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'Notification marked as read'; }
    else { $response['message'] = 'Failed to update notification'; }
    $stmt->close();
}

function handleAdminMarkAllRead($mysqli, $adminId, &$response) {
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
    $stmt->bind_param('i', $adminId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications marked as read'; }
    else { $response['message'] = 'Failed to update notifications'; }
    $stmt->close();
}

function handleAdminGetNotifications($mysqli, $adminId, &$response) {
    $notifications = [];
    $stmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 20");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $notifications[] = $row; }
    $stmt->close();
    $response['success'] = true;
    $response['notifications'] = $notifications;
}

function handleAdminClearAll($mysqli, $adminId, &$response) {
    $stmt = $mysqli->prepare("DELETE FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin'");
    $stmt->bind_param('i', $adminId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications cleared'; }
    else { $response['message'] = 'Failed to clear notifications'; }
    $stmt->close();
}

function handleAddNotification($mysqli, &$response) {
    if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
        $response['message'] = 'Not authorized'; return;
    }
    $userId   = $_POST['user_id']   ?? null;
    $userType = $_POST['user_type'] ?? 'admin';
    $title    = trim($_POST['title']   ?? '');
    $message  = trim($_POST['message'] ?? '');
    $type     = $_POST['type'] ?? 'info';
    $link     = $_POST['link'] ?? null;
    if ($link !== null && $link !== '') {
        $scheme = strtolower(parse_url($link, PHP_URL_SCHEME) ?? '');
        if (in_array($scheme, ['javascript', 'data', 'vbscript'])) {
            $link = null;
        }
    }
    if (empty($title) || empty($message)) { $response['message'] = 'Title and message are required'; return; }
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, user_type, title, message, type, link, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('isssss', $userId, $userType, $title, $message, $type, $link);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'Notification added'; $response['id'] = $mysqli->insert_id; }
    else { $response['message'] = 'Failed to add notification'; }
    $stmt->close();
}

// ══════════════════════════════════════════════════════════════════════════
// COUNSELOR HANDLERS
// ══════════════════════════════════════════════════════════════════════════

function handleCounselorMarkRead($mysqli, $counselorId, &$response) {
    $notifId = intval($_POST['id'] ?? 0);
    if ($notifId <= 0) { $response['message'] = 'Invalid notification ID'; return; }
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL) AND user_type = 'counselor'");
    $stmt->bind_param('ii', $notifId, $counselorId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'Notification marked as read'; }
    else { $response['message'] = 'Failed to update notification'; }
    $stmt->close();
}

function handleCounselorMarkAllRead($mysqli, $counselorId, &$response) {
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'counselor' AND is_read = 0");
    $stmt->bind_param('i', $counselorId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications marked as read'; }
    else { $response['message'] = 'Failed to update notifications'; }
    $stmt->close();
}

function handleCounselorGetNotifications($mysqli, $counselorId, &$response) {
    $notifications = [];
    $stmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'counselor' ORDER BY created_at DESC LIMIT 20");
    $stmt->bind_param('i', $counselorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $notifications[] = $row; }
    $stmt->close();
    $response['success'] = true;
    $response['notifications'] = $notifications;
}

function handleCounselorClearAll($mysqli, $counselorId, &$response) {
    $stmt = $mysqli->prepare("DELETE FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'counselor'");
    $stmt->bind_param('i', $counselorId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications cleared'; }
    else { $response['message'] = 'Failed to clear notifications'; }
    $stmt->close();
}

// ══════════════════════════════════════════════════════════════════════════
// STUDENT HANDLERS
// ══════════════════════════════════════════════════════════════════════════

function handleStudentMarkRead($mysqli, $studentDbId, &$response) {
    $notifId = intval($_POST['id'] ?? 0);
    $response['debug'] = [
        'notifId' => $notifId,
        'studentDbId' => $studentDbId
    ];
    if ($notifId <= 0 || $studentDbId <= 0) { 
        $response['message'] = 'Invalid parameters'; 
        return; 
    }
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND user_type = 'student'");
    $stmt->bind_param('ii', $notifId, $studentDbId);
    if ($stmt->execute()) { 
        $response['success'] = true; 
        $response['message'] = 'Notification marked as read'; 
        $response['debug']['affected_rows'] = $stmt->affected_rows;
    } else { 
        $response['message'] = 'Failed to update notification'; 
        $response['debug']['error'] = $stmt->error;
    }
    $stmt->close();
}

function handleStudentMarkAllRead($mysqli, $studentDbId, &$response) {
    if ($studentDbId <= 0) { $response['message'] = 'Invalid student'; return; }
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND user_type = 'student' AND is_read = 0");
    $stmt->bind_param('i', $studentDbId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications marked as read'; }
    else { $response['message'] = 'Failed to update notifications'; }
    $stmt->close();
}

function handleStudentGetNotifications($mysqli, $studentDbId, &$response) {
    if ($studentDbId <= 0) { $response['notifications'] = []; $response['success'] = true; return; }
    $notifications = [];
    $stmt = $mysqli->prepare("SELECT * FROM notifications WHERE user_id = ? AND user_type = 'student' ORDER BY created_at DESC LIMIT 20");
    $stmt->bind_param('i', $studentDbId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $notifications[] = $row; }
    $stmt->close();
    $response['success'] = true;
    $response['notifications'] = $notifications;
}

function handleStudentClearAll($mysqli, $studentDbId, &$response) {
    if ($studentDbId <= 0) { $response['message'] = 'Invalid student'; return; }
    $stmt = $mysqli->prepare("DELETE FROM notifications WHERE user_id = ? AND user_type = 'student'");
    $stmt->bind_param('i', $studentDbId);
    if ($stmt->execute()) { $response['success'] = true; $response['message'] = 'All notifications cleared'; }
    else { $response['message'] = 'Failed to clear notifications'; }
    $stmt->close();
}


