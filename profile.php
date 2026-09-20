<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

// Read-only guard: archived Grade 12 students cannot edit their profile
$_currentStudentForGuard = getCurrentStudent();
if (!empty($_currentStudentForGuard['is_readonly']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your account is in read-only mode. Profile changes are not allowed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    
    if ($dbId > 0 && $sid !== '' && isset($_FILES['avatar'])) {
        $file = $_FILES['avatar'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload error: ' . $file['error']]);
            exit;
        }
        
        // Validate file type via MIME and getimagesize
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];
        $fileType = mime_content_type($file['tmp_name']);
        if (!array_key_exists($fileType, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
            exit;
        }
        
        // Verify image signature
        if (@getimagesize($file['tmp_name']) === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid image file signature.']);
            exit;
        }
        
        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size is 5MB.']);
            exit;
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads/avatars/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Ensure .htaccess protection
        if (!file_exists($uploadDir . '.htaccess')) {
            @file_put_contents($uploadDir . '.htaccess', "<FilesMatch \"\.(php|phtml|php3|php4|php5|php7|phps|pl|py|jsp|asp|htm|html|shtml|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        
        // Generate unique filename using strict extension mapping
        $fileExt = $allowedTypes[$fileType];
        $fileName = uniqid('avatar_', true) . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        $relativePath = 'uploads/avatars/' . $fileName;
        
        // Move uploaded file to target directory
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Update database
            $stmt = $mysqli->prepare("UPDATE students SET profile_picture = ?, updated_at = NOW() WHERE id = ? AND student_id = ?");
            
            if ($stmt) {
                $stmt->bind_param('sis', $relativePath, $dbId, $sid);
                $stmt->execute();
                $stmt->close();
                
                // Refresh session data
                $refetchStmt = $mysqli->prepare("SELECT * FROM students WHERE id = ? AND student_id = ?");
                if ($refetchStmt) {
                    $refetchStmt->bind_param('is', $dbId, $sid);
                    $refetchStmt->execute();
                    $refetched = $refetchStmt->get_result()->fetch_assoc();
                    $refetchStmt->close();
                    
                    if ($refetched) {
                        $_SESSION['student_data'] = $refetched;
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Avatar uploaded successfully!', 'avatar_url' => $relativePath . '?v=' . time()]);
                exit;
            }
        }
        
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    
    if ($dbId > 0 && $sid !== '') {
        $firstName = trim($_POST['firstName'] ?? '');
        $middleName = trim($_POST['middleName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');

        $gender = trim($_POST['gender'] ?? '');

        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        $stmt = $mysqli->prepare("
            UPDATE students 
            SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, gender = ?, email = ?, address = ?, updated_at = NOW()
            WHERE id = ? AND student_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param(
                'sssssssis', 
                $firstName, $middleName, $lastName, $suffix, $gender,
                $email, $address,
                $dbId, $sid
            );
            
            $stmt->execute();
            $stmt->close();
            
            // Refresh session data by refetching the student
            $refetchStmt = $mysqli->prepare("SELECT * FROM students WHERE id = ? AND student_id = ?");
            if ($refetchStmt) {
                $refetchStmt->bind_param('is', $dbId, $sid);
                $refetchStmt->execute();
                $refetched = $refetchStmt->get_result()->fetch_assoc();
                $refetchStmt->close();
                
                if ($refetched) {
                    $_SESSION['student_data'] = $refetched;
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_student_password') {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    if ($dbId > 0 && $sid !== '') {
        $currentPassword = (string) ($_POST['currentPassword'] ?? '');
        $newPassword = (string) ($_POST['newPassword'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            echo json_encode(['success' => false, 'message' => 'All password fields are required.']);
            exit;
        } elseif (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
            exit;
        } else {
            $stmt = $mysqli->prepare('SELECT password FROM students WHERE id = ? AND student_id = ? LIMIT 1');
            $stmt->bind_param('is', $dbId, $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($currentPassword, $row['password'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $mysqli->prepare('UPDATE students SET password = ?, updated_at = NOW() WHERE id = ? AND student_id = ?');
                $updateStmt->bind_param('sis', $hashed, $dbId, $sid);
                if ($updateStmt->execute()) {
                    require_once __DIR__ . '/includes/audit.php';
                    log_activity(
                        $dbId, 'student', 'Changed Password', 'students', $dbId,
                        "Student changed their password securely.", null, null
                    );
                    echo json_encode(['success' => true, 'message' => 'Password updated successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
                }
                $updateStmt->close();
                exit;
            }
        }
    }
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account'])) {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    if ($dbId > 0 && $sid !== '') {
        // Fetch details before deactivation for logging/notifying
        $studentName = 'A student';
        $studentEmail = '';
        $nameStmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email FROM students WHERE id = ?");
        if ($nameStmt) {
            $nameStmt->bind_param('i', $dbId);
            $nameStmt->execute();
            $studentData = $nameStmt->get_result()->fetch_assoc();
            if ($studentData) {
                $studentName = trim(implode(' ', array_filter([$studentData['first_name'], $studentData['middle_name'], $studentData['last_name']])));
                $studentEmail = $studentData['email'];
            }
            $nameStmt->close();
        }

        $stmt = $mysqli->prepare("UPDATE students SET status = 'inactive' WHERE id = ? AND student_id = ? AND status = 'active'");
        if ($stmt) {
            $stmt->bind_param('is', $dbId, $sid);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Load audit and notify helpers
                require_once __DIR__ . '/includes/audit.php';
                require_once __DIR__ . '/includes/notify.php';
                
                // Write activity/audit log
                log_activity(
                    $dbId,
                    'student',
                    'Deactivated Student',
                    'students',
                    $dbId,
                    "Student {$studentName} ({$studentEmail}) deactivated their own account.",
                    json_encode(['status' => 'active']),
                    json_encode(['status' => 'inactive'])
                );

                // Notify admin
                notify_admin(
                    'Student Account Deactivated',
                    "Student {$studentName} has deactivated their account.",
                    'warning',
                    'manage_students.php'
                );
            }
            $stmt->close();
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php?account=inactive');
    exit;
}

$student = getCurrentStudent();
if (!$student) {
    header('Location: login.php');
    exit;
}

$studentName   = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);
$isReadonly    = !empty($student['is_readonly']);

// ── Profile Overview Stats ──
$assessmentsCount = 0;
$coursesCount = 0;

$stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND status = 'completed'");
$stmt->bind_param("i", $student['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$assessmentsCount = $row['count'];
$stmt->close();

$stmt = $mysqli->prepare("SELECT COUNT(DISTINCT r.course_id) as count FROM recommendations r JOIN student_assessments sa ON r.assessment_id = sa.id WHERE sa.student_id = ?");
$stmt->bind_param("i", $student['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$coursesCount = $row['count'];
$stmt->close();

$fields = ['first_name', 'last_name', 'email', 'address', 'gender'];
$filled = 0;
foreach ($fields as $field) {
    if (!empty($student[$field])) $filled++;
}
$profileCompletion = round(($filled / count($fields)) * 100);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css?v=<?php echo filemtime('user.css'); ?>">
    <style>
        /* ==========================================================================
           REDESIGNED STUDENT PROFILE STYLES
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
            padding: 2.5rem;
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
            gap: 2rem;
            flex-wrap: wrap;
        }
        .hero-avatar-wrapper {
            position: relative;
            width: 110px;
            height: 110px;
            border-radius: 24px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
            font-size: 2.5rem;
            font-weight: 800;
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35);
            border: 3px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
            overflow: hidden;
        }
        .hero-avatar-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .hero-avatar-status {
            position: absolute;
            bottom: -5px;
            right: -5px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #10b981;
            border: 4px solid #0f172a;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.6);
            z-index: 2;
        }
        .hero-avatar-upload-btn {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.5rem;
            opacity: 0;
            transition: all 0.3s ease;
            cursor: pointer;
            backdrop-filter: blur(2px);
        }
        .hero-avatar-wrapper:hover .hero-avatar-upload-btn {
            opacity: 1;
        }
        .hero-details {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }
        .hero-name-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .hero-name {
            font-size: 1.8rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            letter-spacing: -0.5px;
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
        .badge-grade {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .badge-strand {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
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
            gap: 0.5rem;
        }
        .meta-chip i {
            color: #fbbf24;
            font-size: 0.9rem;
        }
        .copy-id-btn {
            background: transparent;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
        }
        .copy-id-btn:hover {
            color: #fbbf24;
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
        .stat-icon-wrap.assessments {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        .stat-icon-wrap.recommendations-stat {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }
        .stat-icon-wrap.progress {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
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
            margin-bottom: 1.5rem;
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

        /* ── Action Buttons ── */
        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            background: rgba(245, 158, 11, 0.1);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-outline:hover {
            background: rgba(245, 158, 11, 0.2);
            transform: translateY(-2px);
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
            grid-template-columns: 1fr 1fr 1fr;
        }
        .form-field-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .form-field-group.full-width {
            grid-column: 1 / -1;
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
        .input-icon-wrapper textarea,
        .form-field-group input,
        .form-field-group select,
        .form-field-group textarea {
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
        .form-field-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .input-icon-wrapper input,
        .input-icon-wrapper select {
            padding-left: 2.75rem;
        }
        .input-icon-wrapper input:focus,
        .input-icon-wrapper select:focus,
        .form-field-group input:focus,
        .form-field-group select:focus,
        .form-field-group textarea:focus {
            outline: none;
            border-color: #f59e0b;
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .input-icon-wrapper input:focus + .lead-icon,
        .input-icon-wrapper select:focus + .lead-icon {
            color: #fbbf24;
        }

        /* ── View Info Grid ── */
        .info-tiles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
        }
        .info-tile {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        .info-tile:hover {
            border-color: rgba(245, 158, 11, 0.2);
            background: rgba(15, 23, 42, 0.7);
            transform: translateY(-2px);
        }
        .info-tile.full-width {
            grid-column: 1 / -1;
        }
        .info-tile-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.1);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .info-tile-content {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            width: 100%;
        }
        .info-tile-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .info-tile-value {
            font-size: 1rem;
            font-weight: 600;
            color: #e2e8f0;
            word-break: break-word;
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
        .btn-secondary-action {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(148, 163, 184, 0.1);
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.92rem;
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-secondary-action:hover {
            background: rgba(148, 163, 184, 0.2);
            color: #ffffff;
        }
        .btn-primary-action:disabled, .btn-secondary-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ── Password Security Styles ── */
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

        /* ── Danger Zone Card ── */
        .danger-zone-card {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 18px;
            padding: 2rem;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
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
        .student-modal-backdrop {
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
        .student-modal-backdrop.active {
            display: flex;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .student-modal-box {
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
        .student-modal-box h4 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
        }
        .student-modal-box p {
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
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            color: #ffffff;
            border: none;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .modal-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            filter: brightness(1.1);
        }

        /* ── Alert Banners ── */
        .profile-alert {
            display: none;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.25rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            animation: slideDownAlert 0.3s ease;
        }
        .profile-alert.active {
            display: flex;
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

        /* Utility */
        .d-none { display: none !important; }

        @media (max-width: 768px) {
            .profile-hero-content {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .profile-hero-main {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            .hero-name-row, .hero-badges, .hero-meta-chips {
                justify-content: center;
            }
            .hero-stats-group {
                width: 100%;
                justify-content: center;
            }
            .hero-stat-pill {
                flex: 1;
                min-width: 130px;
                justify-content: center;
            }
            .form-grid-row.two-cols, .form-grid-row.three-cols {
                grid-template-columns: 1fr;
            }
            .form-actions-bar {
                flex-direction: column;
            }
            .form-actions-bar button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
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
                <a href="profile.php" class="nav-item active">
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="logout.php?role=student" class="nav-item logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                    <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                    <div class="user-profile">
                        <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar" id="topBarAvatar">
                        <div class="user-dropdown">
                            <span class="user-name" id="topBarName"><?php echo $studentName; ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Profile Content Wrapper -->
            <div class="dashboard-content profile-page-wrapper">
                
                <?php if ($isReadonly): ?>
                <div style="
                    display:flex;align-items:flex-start;gap:1rem;
                    background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.06));
                    border:1px solid rgba(245,158,11,.35);
                    border-radius:16px;
                    padding:1.25rem 1.5rem;
                ">
                    <i class="fa-solid fa-lock" style="color:#f59e0b;font-size:1.5rem;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:800;color:#f1f5f9;font-size:1.05rem;margin-bottom:.3rem;">
                            Read-Only Account
                        </div>
                        <div style="font-size:.9rem;color:#94a3b8;line-height:1.5;">
                            Your Grade 12 account has been archived following the school year transition.
                            Profile information and settings are displayed in view-only mode.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 1. Hero Banner Card -->
                <section class="profile-hero-card">
                    <div class="profile-hero-content">
                        <div class="profile-hero-main">
                            <div class="hero-avatar-wrapper">
                                <img src="<?php echo $avatarDataUri; ?>" alt="Profile Avatar" id="profileHeroAvatar">
                                <div class="hero-avatar-status"></div>
                                <?php if (!$isReadonly): ?>
                                <label for="avatarInput" class="hero-avatar-upload-btn" title="Change Avatar">
                                    <i class="fa-solid fa-camera"></i>
                                </label>
                                <input type="file" id="avatarInput" accept="image/*" class="d-none">
                                <?php endif; ?>
                            </div>
                            <div class="hero-details">
                                <div class="hero-name-row">
                                    <h2 class="hero-name" id="heroDisplayName"><?php echo $studentName; ?></h2>
                                    <div class="hero-badges">
                                        <span class="hero-badge badge-role"><i class="fa-solid fa-user-graduate"></i> Student</span>
                                        <span class="hero-badge badge-grade"><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($student['grade_level'] ?? 'Not Assigned'); ?></span>
                                    </div>
                                </div>
                                <div class="hero-meta-chips">
                                    <span class="meta-chip" title="Student ID / LRN">
                                        <i class="fa-solid fa-id-card"></i> 
                                        <?php echo htmlspecialchars($student['student_id']); ?>
                                        <button class="copy-id-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($student['student_id']); ?>')" title="Copy ID">
                                            <i class="fa-solid fa-copy"></i>
                                        </button>
                                    </span>
                                    <span class="meta-chip" title="Email Address">
                                        <i class="fa-solid fa-envelope"></i> 
                                        <span id="heroEmailDisplay"><?php echo htmlspecialchars($student['email']); ?></span>
                                    </span>
                                    <span class="meta-chip" title="Enrolled School">
                                        <i class="fa-solid fa-school"></i> 
                                        <?php echo htmlspecialchars(getSystemConfig('school_name')); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="hero-stats-group">
                            <div class="hero-stat-pill">
                                <div class="stat-icon-wrap assessments">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                </div>
                                <div class="stat-meta">
                                    <span class="stat-val"><?php echo $assessmentsCount; ?></span>
                                    <span class="stat-lbl">Assessments</span>
                                </div>
                            </div>
                            <div class="hero-stat-pill">
                                <div class="stat-icon-wrap recommendations-stat">
                                    <i class="fa-solid fa-briefcase"></i>
                                </div>
                                <div class="stat-meta">
                                    <span class="stat-val"><?php echo $coursesCount; ?></span>
                                    <span class="stat-lbl">Recommendations</span>
                                </div>
                            </div>
                            <div class="hero-stat-pill">
                                <div class="stat-icon-wrap progress">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                                <div class="stat-meta">
                                    <span class="stat-val"><?php echo $profileCompletion; ?>%</span>
                                    <span class="stat-lbl">Profile Complete</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 2. Navigation Tabs -->
                <nav class="profile-nav-tabs">
                    <button class="profile-tab-btn active" data-tab="tab-overview">
                        <i class="fa-solid fa-id-badge"></i> Profile Overview
                    </button>
                    <button class="profile-tab-btn" data-tab="tab-academic">
                        <i class="fa-solid fa-graduation-cap"></i> Academic Journey
                    </button>
                    <button class="profile-tab-btn" data-tab="tab-security">
                        <i class="fa-solid fa-shield-halved"></i> Security & Account
                    </button>
                </nav>

                <!-- 3. Tab Panes -->
                
                <!-- TAB 1: OVERVIEW -->
                <div class="profile-tab-pane active" id="tab-overview">
                    <div class="profile-section-card">
                        <div class="section-header-wrap">
                            <div class="section-title-group">
                                <h3><i class="fa-solid fa-user"></i> Personal Information</h3>
                                <p>Manage your personal details and contact information.</p>
                            </div>
                            <?php if (!$isReadonly): ?>
                            <button class="btn-outline" id="toggleEditBtn" onclick="toggleProfileEdit()">
                                <i class="fa-solid fa-pen"></i> Edit Profile
                            </button>
                            <?php endif; ?>
                        </div>

                        <div id="profileAlert" class="profile-alert">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="alert-msg"></span>
                        </div>

                        <!-- View Mode -->
                        <div class="info-tiles-grid" id="personalInfoView">
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-user"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">First Name</span>
                                    <span class="info-tile-value" id="viewFirstName"><?php echo htmlspecialchars($student['first_name']); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-regular fa-user"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Middle Name</span>
                                    <span class="info-tile-value" id="viewMiddleName"><?php echo htmlspecialchars($student['middle_name'] ?: '-'); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-user-tag"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Last Name</span>
                                    <span class="info-tile-value" id="viewLastName"><?php echo htmlspecialchars($student['last_name']); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-quote-right"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Suffix</span>
                                    <span class="info-tile-value" id="viewSuffix"><?php echo htmlspecialchars($student['suffix'] ?: '-'); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-venus-mars"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Gender</span>
                                    <span class="info-tile-value" id="viewGender"><?php echo htmlspecialchars(ucfirst($student['gender'])); ?></span>
                                </div>
                            </div>


                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-envelope"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Email Address</span>
                                    <span class="info-tile-value" id="viewEmail"><?php echo htmlspecialchars($student['email']); ?></span>
                                </div>
                            </div>
                            <div class="info-tile full-width">
                                <div class="info-tile-icon"><i class="fa-solid fa-location-dot"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Residential Address</span>
                                    <span class="info-tile-value" id="viewAddress"><?php echo htmlspecialchars($student['address'] ?: 'Not provided'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Edit Mode Form -->
                        <form id="personalInfoEdit" class="grid-form d-none">
                            <div class="form-grid-row two-cols">
                                <div class="form-field-group">
                                    <label for="firstName">First Name <span class="required-star">*</span></label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-solid fa-user lead-icon"></i>
                                        <input type="text" id="firstName" name="firstName" value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="form-field-group">
                                    <label for="middleName">Middle Name</label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-regular fa-user lead-icon"></i>
                                        <input type="text" id="middleName" name="middleName" value="<?php echo htmlspecialchars($student['middle_name']); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="form-grid-row three-cols">
                                <div class="form-field-group">
                                    <label for="lastName">Last Name <span class="required-star">*</span></label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-solid fa-user-tag lead-icon"></i>
                                        <input type="text" id="lastName" name="lastName" value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="form-field-group">
                                    <label for="suffix">Suffix</label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-solid fa-quote-right lead-icon"></i>
                                        <input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars($student['suffix']); ?>" placeholder="e.g. Jr., III">
                                    </div>
                                </div>
                                <div class="form-field-group">
                                    <label for="gender">Gender <span class="required-star">*</span></label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-solid fa-venus-mars lead-icon"></i>
                                        <select id="gender" name="gender" required>
                                            <option value="male" <?php echo $student['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo $student['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-grid-row two-cols">


                            </div>
                            <div class="form-grid-row two-cols">
                                <div class="form-field-group full-width">
                                    <label for="email">Email Address <span class="required-star">*</span></label>
                                    <div class="input-icon-wrapper">
                                        <i class="fa-solid fa-envelope lead-icon"></i>
                                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                                    </div>
                                </div>
                                <div class="form-field-group full-width">
                                    <label for="address">Residential Address <span style="font-size: 0.82em; font-weight: 500; color: #94a3b8;">(Optional)</span></label>
                                    <div class="input-icon-wrapper" style="align-items: flex-start;">
                                        <i class="fa-solid fa-location-dot lead-icon" style="top: 1rem;"></i>
                                        <textarea id="address" name="address" style="padding-left: 2.75rem;" placeholder="Enter residential address (Optional)"><?php echo htmlspecialchars($student['address']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="form-actions-bar">
                                <button type="button" class="btn-secondary-action" onclick="toggleProfileEdit()">
                                    <i class="fa-solid fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn-primary-action" id="saveProfileBtn">
                                    <i class="fa-solid fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TAB 2: ACADEMIC -->
                <div class="profile-tab-pane" id="tab-academic">
                    <div class="profile-section-card">
                        <div class="section-header-wrap">
                            <div class="section-title-group">
                                <h3><i class="fa-solid fa-graduation-cap"></i> Academic Information</h3>
                                <p>Your official academic classification and school records.</p>
                            </div>
                        </div>
                        <div class="info-tiles-grid">
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-id-card"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Learner Reference Number (LRN)</span>
                                    <span class="info-tile-value"><?php echo htmlspecialchars($student['student_id']); ?> <i class="fa-solid fa-circle-check" style="color:#10b981; margin-left: 5px; font-size: 0.85rem;" title="Verified"></i></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-calendar-check"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">School Year</span>
                                    <span class="info-tile-value"><?php echo htmlspecialchars($student['school_year'] ?? 'Not Assigned'); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-layer-group"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Grade Level</span>
                                    <span class="info-tile-value"><?php echo htmlspecialchars($student['grade_level'] ?? 'Not Assigned'); ?></span>
                                </div>
                            </div>
                            <div class="info-tile">
                                <div class="info-tile-icon"><i class="fa-solid fa-book-open"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label">Senior High Strand</span>
                                    <span class="info-tile-value">
                                        <?php echo htmlspecialchars(($student['strand_code'] ? strtoupper($student['strand_code']) . ' - ' : '') . ($student['strand_name'] ?? 'Not Assigned')); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-tile full-width" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                                <div class="info-tile-icon" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;"><i class="fa-solid fa-info"></i></div>
                                <div class="info-tile-content">
                                    <span class="info-tile-label" style="color:#93c5fd;">Registrar Sync Active</span>
                                    <span class="info-tile-value" style="font-size: 0.9rem; font-weight: 400; color: #bfdbfe; margin-top: 0.2rem;">
                                        Academic information such as your Strand, Grade Level, and School Year is managed by your school administrators and synchronized automatically. If you notice any discrepancies, please contact the guidance office.
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: SECURITY & ACCOUNT -->
                <div class="profile-tab-pane" id="tab-security">
                    <?php if (!$isReadonly): ?>
                    <div class="profile-section-card">
                        <div class="section-header-wrap">
                            <div class="section-title-group">
                                <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                                <p>Ensure your account is using a long, random password to stay secure.</p>
                            </div>
                        </div>
                        
                        <div id="passwordAlert" class="profile-alert">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="alert-msg"></span>
                        </div>

                        <form id="passwordForm" class="grid-form" style="max-width: 600px;">
                            <div class="form-field-group">
                                <label for="currentPassword">Current Password <span class="required-star">*</span></label>
                                <div class="input-icon-wrapper">
                                    <i class="fa-solid fa-lock lead-icon"></i>
                                    <input type="password" id="currentPassword" name="currentPassword" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('currentPassword', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-field-group">
                                <label for="newPassword">New Password <span class="required-star">*</span></label>
                                <div class="input-icon-wrapper">
                                    <i class="fa-solid fa-key lead-icon"></i>
                                    <input type="password" id="newPassword" name="newPassword" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('newPassword', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div class="strength-meter-container">
                                    <div class="strength-header">
                                        <span>Password Strength</span>
                                        <span class="strength-label" id="strengthLabel">Weak</span>
                                    </div>
                                    <div class="strength-bars">
                                        <div class="strength-bar-seg" id="sBar1"></div>
                                        <div class="strength-bar-seg" id="sBar2"></div>
                                        <div class="strength-bar-seg" id="sBar3"></div>
                                        <div class="strength-bar-seg" id="sBar4"></div>
                                    </div>
                                    <div class="strength-rules-list">
                                        <div class="strength-rule-item" id="ruleLength">
                                            <i class="fa-solid fa-circle-xmark"></i> 8+ characters
                                        </div>
                                        <div class="strength-rule-item" id="ruleMix">
                                            <i class="fa-solid fa-circle-xmark"></i> Letters & numbers
                                        </div>
                                        <div class="strength-rule-item" id="ruleSpecial">
                                            <i class="fa-solid fa-circle-xmark"></i> Special char (!@#$)
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-field-group">
                                <label for="confirmPassword">Confirm New Password <span class="required-star">*</span></label>
                                <div class="input-icon-wrapper">
                                    <i class="fa-solid fa-check-double lead-icon"></i>
                                    <input type="password" id="confirmPassword" name="confirmPassword" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirmPassword', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="form-actions-bar" style="border: none; padding-top: 0; margin-top: 0.5rem; justify-content: flex-start;">
                                <button type="submit" class="btn-primary-action" id="updatePasswordBtn">
                                    <i class="fa-solid fa-shield-check"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Danger Zone -->
                    <div class="danger-zone-card">
                        <div class="section-title-group">
                            <h3 class="danger-title"><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</h3>
                            <p>Irreversible and destructive account actions.</p>
                        </div>
                        
                        <div class="danger-desc-box">
                            <p><strong>Deactivate your account</strong></p>
                            <p>Once you deactivate your account, you will immediately lose access to the system.</p>
                            <ul>
                                <li>Your profile will be hidden from the active students list.</li>
                                <li>You will not be able to log in, take assessments, or view results.</li>
                                <li>An administrator must manually reactivate your account if you wish to return.</li>
                            </ul>
                        </div>
                        
                        <button class="btn-danger-action" id="openDeactivateModalBtn">
                            <i class="fa-solid fa-user-slash"></i> Deactivate My Account
                        </button>
                    </div>
                    
                    <div style="margin-top: 2rem;">
                        <a href="logout.php?role=student" class="btn-secondary-action" style="width: auto;">
                            <i class="fa-solid fa-sign-out-alt"></i> Sign out of current session
                        </a>
                    </div>
                </div>

            </div>
            
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Deactivation Modal -->
    <div class="student-modal-backdrop" id="deactivateModal">
        <div class="student-modal-box">
            <div class="modal-warning-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h4>Deactivate Account?</h4>
            <p>
                Are you absolutely sure you want to deactivate your student account? 
                You will be immediately logged out and will not be able to sign in again until an administrator reactivates your access.
            </p>
            <div class="modal-btn-row">
                <button class="modal-btn-cancel" id="cancelDeactivateBtn">Cancel</button>
                <button class="modal-btn-confirm" id="confirmDeactivateBtn">
                    <i class="fa-solid fa-user-slash"></i> Yes, Deactivate
                </button>
            </div>
            <form id="deactivateAccountForm" method="post" action="profile.php" class="d-none">
                <input type="hidden" name="deactivate_account" value="1">
            </form>
        </div>
    </div>

    <script src="script.js?v=<?php echo filemtime('script.js'); ?>"></script>
    <script>
    // ==========================================================================
    // REDESIGNED STUDENT PROFILE JAVASCRIPT
    // ==========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        // ── Navigation Tabs ──
        const tabBtns = document.querySelectorAll('.profile-tab-btn');
        const tabPanes = document.querySelectorAll('.profile-tab-pane');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-tab');
                
                // Update buttons
                tabBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                // Update panes
                tabPanes.forEach(pane => {
                    if(pane.id === targetId) {
                        pane.classList.add('active');
                    } else {
                        pane.classList.remove('active');
                    }
                });
            });
        });

        // ── Avatar Upload ──
        const avatarInput = document.getElementById('avatarInput');
        if (avatarInput) {
            avatarInput.addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (!file) return;

                // Preview immediately
                const reader = new FileReader();
                reader.onload = (e) => {
                    const heroAvatar = document.getElementById('profileHeroAvatar');
                    if (heroAvatar) heroAvatar.src = e.target.result;
                };
                reader.readAsDataURL(file);

                // Upload
                const fd = new FormData();
                fd.append('action', 'upload_avatar');
                fd.append('avatar', file);

                try {
                    const res = await fetch('profile.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        // Update other avatars
                        const topBarAvatar = document.getElementById('topBarAvatar');
                        if (topBarAvatar) topBarAvatar.src = data.avatar_url;
                        showToast('Profile picture updated!', 'success');
                    } else {
                        alert(data.message || 'Failed to upload avatar.');
                    }
                } catch (err) {
                    alert('Error uploading avatar.');
                }
            });
        }

        // ── Profile Edit Form ──
        const editForm = document.getElementById('personalInfoEdit');
        if (editForm) {
            editForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const saveBtn = document.getElementById('saveProfileBtn');
                const origHtml = saveBtn.innerHTML;
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Saving...';

                const fd = new FormData(editForm);
                fd.append('action', 'update_profile');

                try {
                    const res = await fetch('profile.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if (data.success) {
                        showAlert('profileAlert', 'success', data.message || 'Profile updated successfully!');
                        
                        // Update View Text
                        document.getElementById('viewFirstName').textContent = fd.get('firstName');
                        document.getElementById('viewMiddleName').textContent = fd.get('middleName') || '-';
                        document.getElementById('viewLastName').textContent = fd.get('lastName');
                        document.getElementById('viewSuffix').textContent = fd.get('suffix') || '-';
                        document.getElementById('viewGender').textContent = fd.get('gender').charAt(0).toUpperCase() + fd.get('gender').slice(1);

                        document.getElementById('viewEmail').textContent = fd.get('email');
                        document.getElementById('viewAddress').textContent = fd.get('address');
                        
                        // Update hero/top details
                        const fullName = `${fd.get('firstName')} ${fd.get('middleName')} ${fd.get('lastName')} ${fd.get('suffix')}`.replace(/\s+/g, ' ').trim();
                        document.getElementById('heroDisplayName').textContent = fullName;
                        document.getElementById('topBarName').textContent = fullName;
                        document.getElementById('heroEmailDisplay').textContent = fd.get('email');


                        
                        saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Saved!';
                        setTimeout(() => toggleProfileEdit(), 1000); // Close form
                    } else {
                        showAlert('profileAlert', 'error', data.message || 'Database error occurred.');
                        saveBtn.innerHTML = origHtml;
                    }
                } catch (err) {
                    showAlert('profileAlert', 'error', 'An error occurred while saving.');
                    saveBtn.innerHTML = origHtml;
                }
                
                setTimeout(() => { saveBtn.disabled = false; saveBtn.innerHTML = origHtml; }, 2000);
            });
        }

        // ── Password Form ──
        const passwordForm = document.getElementById('passwordForm');
        const newPasswordInput = document.getElementById('newPassword');
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', updatePasswordStrength);
        }
        
        if (passwordForm) {
            passwordForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const cur = document.getElementById('currentPassword').value;
                const newp = document.getElementById('newPassword').value;
                const conf = document.getElementById('confirmPassword').value;

                if (newp !== conf) {
                    showAlert('passwordAlert', 'error', 'New password and confirmation do not match.');
                    return;
                }

                const btn = document.getElementById('updatePasswordBtn');
                const origHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Updating...';

                const fd = new FormData();
                fd.append('action', 'update_student_password');
                fd.append('currentPassword', cur);
                fd.append('newPassword', newp);

                try {
                    const res = await fetch('profile.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        showAlert('passwordAlert', 'success', data.message);
                        passwordForm.reset();
                        updatePasswordStrength();
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Updated!';
                    } else {
                        showAlert('passwordAlert', 'error', data.message);
                        btn.innerHTML = origHtml;
                    }
                } catch (err) {
                    showAlert('passwordAlert', 'error', 'An error occurred.');
                    btn.innerHTML = origHtml;
                }
                setTimeout(() => { btn.disabled = false; btn.innerHTML = origHtml; }, 2000);
            });
        }

        // ── Deactivation Modal ──
        const openDeactBtn = document.getElementById('openDeactivateModalBtn');
        const deactModal = document.getElementById('deactivateModal');
        const cancelDeactBtn = document.getElementById('cancelDeactivateBtn');
        const confirmDeactBtn = document.getElementById('confirmDeactivateBtn');
        const deactForm = document.getElementById('deactivateAccountForm');

        if (openDeactBtn && deactModal) {
            openDeactBtn.addEventListener('click', () => deactModal.classList.add('active'));
        }
        if (cancelDeactBtn && deactModal) {
            cancelDeactBtn.addEventListener('click', () => deactModal.classList.remove('active'));
        }
        if (confirmDeactBtn && deactForm) {
            confirmDeactBtn.addEventListener('click', () => {
                confirmDeactBtn.disabled = true;
                confirmDeactBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Deactivating...';
                deactForm.submit();
            });
        }
        if (deactModal) {
            deactModal.addEventListener('click', (e) => {
                if (e.target === deactModal) deactModal.classList.remove('active');
            });
        }
    });

    // ── Helper Functions ──
    function toggleProfileEdit() {
        const view = document.getElementById('personalInfoView');
        const edit = document.getElementById('personalInfoEdit');
        const btn = document.getElementById('toggleEditBtn');
        
        if (view.classList.contains('d-none')) {
            // Show View
            view.classList.remove('d-none');
            edit.classList.add('d-none');
            if (btn) btn.style.display = 'inline-flex';
        } else {
            // Show Edit
            view.classList.add('d-none');
            edit.classList.remove('d-none');
            if (btn) btn.style.display = 'none';
        }
    }

    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
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

    function showAlert(containerId, type, message) {
        const alert = document.getElementById(containerId);
        if (!alert) return;
        const icon = alert.querySelector('i');
        const msg = alert.querySelector('.alert-msg');
        
        alert.className = `profile-alert ${type} active`;
        msg.textContent = message;
        icon.className = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation';
        
        setTimeout(() => {
            alert.classList.remove('active');
        }, 5000);
    }

    function showToast(message, type = 'success') {
        const alertBox = document.createElement('div');
        alertBox.style.position = 'fixed';
        alertBox.style.bottom = '20px';
        alertBox.style.right = '20px';
        alertBox.style.padding = '1rem 1.5rem';
        alertBox.style.borderRadius = '12px';
        alertBox.style.background = type === 'success' ? '#10b981' : '#ef4444';
        alertBox.style.color = '#fff';
        alertBox.style.fontWeight = '600';
        alertBox.style.boxShadow = '0 10px 25px rgba(0,0,0,0.2)';
        alertBox.style.zIndex = '99999';
        alertBox.style.animation = 'fadeInTab 0.3s ease';
        alertBox.innerHTML = `<i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> ${message}`;
        document.body.appendChild(alertBox);
        setTimeout(() => {
            alertBox.style.opacity = '0';
            alertBox.style.transition = 'opacity 0.3s ease';
            setTimeout(() => alertBox.remove(), 300);
        }, 3000);
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('Student ID copied to clipboard!');
        });
    }

    function updatePasswordStrength() {
        const val = document.getElementById('newPassword').value;
        const len = val.length >= 8;
        const mix = /[A-Za-z]/.test(val) && /[0-9]/.test(val);
        const spc = /[^A-Za-z0-9]/.test(val);
        
        document.getElementById('ruleLength').className = `strength-rule-item ${len ? 'valid' : ''}`;
        document.getElementById('ruleLength').querySelector('i').className = len ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        
        document.getElementById('ruleMix').className = `strength-rule-item ${mix ? 'valid' : ''}`;
        document.getElementById('ruleMix').querySelector('i').className = mix ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        
        document.getElementById('ruleSpecial').className = `strength-rule-item ${spc ? 'valid' : ''}`;
        document.getElementById('ruleSpecial').querySelector('i').className = spc ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';

        let score = 0;
        if (len) score++;
        if (mix) score++;
        if (spc) score++;
        if (val.length >= 12) score++;
        
        const bars = [document.getElementById('sBar1'), document.getElementById('sBar2'), document.getElementById('sBar3'), document.getElementById('sBar4')];
        const lbl = document.getElementById('strengthLabel');
        
        bars.forEach(b => b.style.background = 'rgba(255, 255, 255, 0.08)');
        
        if (val.length === 0) {
            lbl.textContent = 'None';
            lbl.style.color = '#94a3b8';
        } else if (score <= 1) {
            bars[0].style.background = '#ef4444';
            lbl.textContent = 'Weak';
            lbl.style.color = '#ef4444';
        } else if (score === 2) {
            bars[0].style.background = '#f59e0b';
            bars[1].style.background = '#f59e0b';
            lbl.textContent = 'Fair';
            lbl.style.color = '#f59e0b';
        } else if (score === 3) {
            bars[0].style.background = '#10b981';
            bars[1].style.background = '#10b981';
            bars[2].style.background = '#10b981';
            lbl.textContent = 'Good';
            lbl.style.color = '#10b981';
        } else {
            bars.forEach(b => b.style.background = '#10b981');
            lbl.textContent = 'Strong';
            lbl.style.color = '#10b981';
        }
    }
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
