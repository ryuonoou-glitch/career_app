<?php
// Counselor Students Page - With Backend

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

// School-wide Guidance Counselor — no per-strand scoping

// Handle AJAX requests for strands
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        if ($_POST['action'] === 'get_strands_by_grade') {
            $gradeLevel = (int)($_POST['gradeLevel'] ?? 0);
            if ($gradeLevel !== 11 && $gradeLevel !== 12) {
                throw new Exception('Invalid grade level.');
            }
            
            $stmt = $mysqli->prepare("SELECT id, name, code FROM strands WHERE grade_level = ? AND code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
            $stmt->bind_param('i', $gradeLevel);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $strands = [];
            while ($row = $result->fetch_assoc()) {
                $strands[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'code' => $row['code']
                ];
            }
            $stmt->close();
            
            $response['success'] = true;
            $response['strands'] = $strands;
            echo json_encode($response);
            exit;
        } elseif ($_POST['action'] === 'add_student') {
            // Counselor can add students to any grade/strand
            if (!isset($_SESSION['counselor_id'])) {
                throw new Exception('Unauthorized.');
            }

            $firstName = trim($_POST['firstName'] ?? '');
            $middleName = trim($_POST['middleName'] ?? '');
            $lastName = trim($_POST['lastName'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $studentId = trim($_POST['schoolId'] ?? '');
            $gender = $_POST['gender'] ?? '';
            $status = 'active';

            $address = trim($_POST['address'] ?? '');

            // Grade and strand now come from the form
            $gradeLevelDb = trim($_POST['gradeLevel'] ?? '');
            $strandCode   = trim($_POST['strandCode']   ?? '');

            $firstName  = $firstName  !== '' ? ucwords(strtolower($firstName))  : '';
            $middleName = $middleName !== '' ? ucwords(strtolower($middleName)) : '';
            $lastName   = $lastName   !== '' ? ucwords(strtolower($lastName))   : '';

            if ($firstName === '' || $lastName === '' || $email === '' || $studentId === '' || $gender === '') {
                throw new Exception('Please fill in all required fields.');
            }

            $stmt = $mysqli->prepare('SELECT id FROM students WHERE email = ? OR student_id = ? LIMIT 1');
            $stmt->bind_param('ss', $email, $studentId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) {
                throw new Exception('Student email or ID already exists.');
            }

            // Check valid_student_ids status before inserting
            $chkValid = $mysqli->prepare("SELECT id, is_registered, grade_level, strand_code FROM valid_student_ids WHERE student_id = ? LIMIT 1");
            $chkValid->bind_param("s", $studentId);
            $chkValid->execute();
            $validRow = $chkValid->get_result()->fetch_assoc();
            $chkValid->close();

            if ($validRow && (int)$validRow['is_registered'] === 1) {
                throw new Exception('This Student ID is already registered.');
            }

            // Verify strand exists
            $strandStmt = $mysqli->prepare('SELECT id, name FROM strands WHERE code = ? OR name = ? LIMIT 1');
            $strandStmt->bind_param('ss', $strandCode, $strandCode);
            $strandStmt->execute();
            $strandRow = $strandStmt->get_result()->fetch_assoc();
            $strandStmt->close();
            $strandId = $strandRow ? (int)$strandRow['id'] : null;
            if (!$strandId) throw new Exception('Invalid counselor strand configuration.');



            // Auto-generate a random temporary password
            $rawPassword = bin2hex(random_bytes(6)); // 12-char hex
            $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);

            // Get active school_year_id
            $activeSchoolYearId = getCurrentSchoolYearId($mysqli);
            
            $ins = $mysqli->prepare('INSERT INTO students (student_id, first_name, middle_name, last_name, suffix, gender, email, password, address, strand_id, school_year_id, grade_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->bind_param('sssssssssiiss', $studentId, $firstName, $middleName, $lastName, $suffix, $gender, $email, $hashedPassword, $address, $strandId, $activeSchoolYearId, $gradeLevelDb, $status);

            if (!$ins->execute()) {
                throw new Exception('Failed to add student: ' . $ins->error);
            }
            
            // Reliably capture real auto-increment ID
            $newStudentId = (int)$ins->insert_id;
            if ($newStudentId <= 0) {
                $newStudentId = (int)$mysqli->insert_id;
            }
            $ins->close();

            // Fallback verification
            if ($newStudentId <= 0) {
                $fetchRealId = $mysqli->prepare("SELECT id FROM students WHERE student_id = ? ORDER BY id DESC LIMIT 1");
                if ($fetchRealId) {
                    $fetchRealId->bind_param("s", $studentId);
                    $fetchRealId->execute();
                    $realRow = $fetchRealId->get_result()->fetch_assoc();
                    $fetchRealId->close();
                    if ($realRow && isset($realRow['id'])) {
                        $newStudentId = (int)$realRow['id'];
                    }
                }
            }

            if ($newStudentId <= 0) {
                throw new Exception('Failed to capture real auto-generated student ID.');
            }

            // Sync valid_student_ids
            if ($validRow) {
                $vsUpdate = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 1, registered_student_id = ?, school_year_id = ? WHERE student_id = ?");
                if ($vsUpdate) {
                    $vsUpdate->bind_param("iis", $newStudentId, $activeSchoolYearId, $studentId);
                    $vsUpdate->execute();
                    $vsUpdate->close();
                }
            } else {
                $vsInsert = $mysqli->prepare("INSERT INTO valid_student_ids (student_id, school_year_id, grade_level, strand_code, is_registered, registered_student_id, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())");
                if ($vsInsert) {
                    $vsInsert->bind_param("sissi", $studentId, $activeSchoolYearId, $gradeLevelDb, $strandCode, $newStudentId);
                    $vsInsert->execute();
                    $vsInsert->close();
                }
            }
            
            require_once __DIR__ . '/includes/mailer.php';
            require_once __DIR__ . '/includes/notify.php';
            require_once __DIR__ . '/includes/audit.php';
            
            $emailSent = send_admin_created_account_email([
                'first_name'  => $firstName,
                'middle_name' => $middleName,
                'last_name'   => $lastName,
                'email'       => $email,
                'student_id'  => $studentId,
                'grade_level' => $gradeLevelDb,
                'strand_name' => $strandRow['name'] ?? $strandCode,
                'password'    => $rawPassword,
            ]);

            // Bell notification
            notify_student(
                $newStudentId,
                'Account Activated',
                'Account Activated — You can now log in and take your assessment.',
                'success',
                'dashboard.php'
            );

            $studentFullName = trim(implode(' ', array_filter([$firstName, $lastName])));
            $strandName = $strandRow['name'] ?? $strandCode;
            $descriptionText = "Counselor added student {$studentFullName} (School ID: {$studentId}) to Grade {$gradeLevelDb} - {$strandName}";
            
            $newStudentSafe = [
                'student_id' => $studentId,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'suffix' => $suffix,
                'gender' => $gender,
                
                'email' => $email,
                'strand_id' => $strandId,
                'grade_level' => $gradeLevelDb,
                'status' => $status
            ];
            log_activity($_SESSION['counselor_id'], 'counselor', 'Added Student', 'students', $newStudentId, $descriptionText, null, json_encode($newStudentSafe));

            $response['success'] = true;
            $response['message'] = 'Student added successfully';
            $response['generated_password'] = $rawPassword;
            $response['email_sent'] = $emailSent;
            echo json_encode($response);
            exit;
        } elseif ($_POST['action'] === 'edit_student') {
            if (!isset($_SESSION['counselor_id'])) {
                throw new Exception('Unauthorized.');
            }
            
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid student ID.');
            
            $firstName  = trim($_POST['firstName']  ?? '');
            $middleName = trim($_POST['middleName'] ?? '');
            $lastName   = trim($_POST['lastName']   ?? '');
            $suffix     = trim($_POST['suffix']     ?? '');
            $email      = trim($_POST['email']      ?? '');
            $studentId  = trim($_POST['schoolId']   ?? '');
            $gender     = $_POST['gender']          ?? '';

            $address    = trim($_POST['address']    ?? '');
            $status     = trim($_POST['status']     ?? 'active');
            
            $firstName  = $firstName  !== '' ? ucwords(strtolower($firstName))  : '';
            $middleName = $middleName !== '' ? ucwords(strtolower($middleName)) : '';
            $lastName   = $lastName   !== '' ? ucwords(strtolower($lastName))   : '';

            if ($firstName === '' || $lastName === '' || $email === '' || $studentId === '' || $gender === '') {
                throw new Exception('Please fill in all required fields.');
            }

            $dupStmt = $mysqli->prepare('SELECT id FROM students WHERE (email = ? OR student_id = ?) AND id != ? LIMIT 1');
            $dupStmt->bind_param('ssi', $email, $studentId, $id);
            $dupStmt->execute();
            $dupExists = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();
            if ($dupExists) throw new Exception('Another student with this email or ID already exists.');


            $address = $address !== '' ? $address : null;

            $upd = $mysqli->prepare('UPDATE students SET student_id=?, first_name=?, middle_name=?, last_name=?, suffix=?, gender=?, email=?, address=?, status=? WHERE id=?');
            $upd->bind_param('sssssssssi', $studentId, $firstName, $middleName, $lastName, $suffix, $gender, $email, $address, $status, $id);
            
            if (!$upd->execute()) {
                throw new Exception('Failed to update student: ' . $upd->error);
            }
            $upd->close();

            require_once __DIR__ . '/includes/audit.php';
            $studentFullName = trim(implode(' ', array_filter([$firstName, $lastName])));
            log_activity($_SESSION['counselor_id'], 'counselor', 'Edited Student', 'students', $id, "Counselor updated student {$studentFullName} (School ID: {$studentId})", null, null);

            $response['success'] = true;
            $response['message'] = 'Student updated successfully';
            echo json_encode($response);
            exit;
        } elseif ($_POST['action'] === 'get_all_strands') {
            $stmt = $mysqli->prepare("SELECT id, name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $allStrands = [];
            while ($row = $result->fetch_assoc()) {
                $allStrands[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'code' => $row['code']
                ];
            }
            $stmt->close();
            
            $response['success'] = true;
            $response['strands'] = $allStrands;
            echo json_encode($response);
            exit;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// Get all strands for filter/form dropdowns
$allStrands = [];
$strandResult = $mysqli->query("SELECT * FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY grade_level, name");
if ($strandResult) {
    while ($row = $strandResult->fetch_assoc()) {
        $allStrands[] = $row;
    }
}

// Get all students from database
// Get all students from database
$students = [];
$studentQuery = "
    SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.suffix, s.email, s.grade_level, s.status, s.gender, s.address, s.created_at, s.profile_picture,
           st.name as strand_name, st.code as strand_code,
           (SELECT COUNT(*) FROM student_assessments sa WHERE sa.student_id = s.id) as assessment_count,
           (SELECT status FROM student_assessments sa WHERE sa.student_id = s.id ORDER BY COALESCE(sa.completed_at, sa.started_at, sa.created_at) DESC LIMIT 1) as latest_assessment_status
    FROM students s
    LEFT JOIN strands st ON s.strand_id = st.id
    WHERE s.status != 'deleted'
    ORDER BY s.first_name ASC, s.last_name ASC";

$stmt = $mysqli->prepare($studentQuery);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Get total count
$totalStudents = count($students);

// Get counselor/admin name
$userName = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_name'] : $_SESSION['admin_name'] ?? 'Guidance Counselor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
    <style>
        /* ==========================================================================
           VIEW STUDENT MODAL - Dark Premium Theme
           ========================================================================== */
        #viewStudentModal .modal-content {
            position: relative;
            width: 100%;
            max-width: 700px;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            background: #12192b;
            border: 1px solid rgba(251,191,36,0.18);
            border-radius: 16px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.7);
            overflow: hidden;
        }
        #viewStudentModal .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 28px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
        }
        #viewStudentModal .modal-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
        }
        #viewStudentModal .modal-header h2 i { color: #f59e0b; }
        #viewStudentModal .modal-close {
            width: 32px; height: 32px;
            border-radius: 8px; border: none;
            background: rgba(255,255,255,0.06);
            color: #94a3b8; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s, color 0.2s;
        }
        #viewStudentModal .modal-close:hover { background: rgba(239,68,68,0.18); color: #f87171; }
        #viewStudentModal .modal-body {
            padding: 0;
            overflow-y: auto;
            flex: 1;
        }

        /* Avatar banner */
        .view-profile-banner {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding: 22px 28px 18px;
            background: linear-gradient(135deg, rgba(245,158,11,0.08) 0%, rgba(18,25,43,0) 60%);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            text-align: center;
        }
        .view-avatar {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            font-size: 1.8rem;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            letter-spacing: -1px;
            overflow: hidden;
        }
        .view-avatar img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .view-banner-info { flex: 0 1 auto; min-width: 0; }
        .view-banner-info h3 {
            font-size: 1.25rem; font-weight: 700;
            color: #f1f5f9; margin: 0 0 8px;
            white-space: normal;
        }
        .view-banner-meta {
            display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap;
        }
        .view-banner-meta .view-id {
            font-size: 12px; color: #64748b;
        }
        .view-banner-meta .view-strand-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px; font-weight: 700;
            background: rgba(245,158,11,0.15);
            color: #f59e0b;
            border: 1px solid rgba(245,158,11,0.25);
        }
        .view-banner-status {
            display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;
        }

        /* View sections */
        .view-sections { padding: 24px 32px; display: flex; flex-direction: column; gap: 0; }
        .view-section { margin-bottom: 24px; }
        .view-section:last-child { margin-bottom: 0; }
        .view-section-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 0.9rem; font-weight: 800;
            color: #f59e0b; margin-bottom: 16px;
            letter-spacing: 0.04em; text-transform: uppercase;
        }
        .view-section-title i { font-size: 0.95rem; }
        .view-section-divider {
            border: none; border-top: 1px solid rgba(255,255,255,0.05);
            margin: 0 0 24px;
        }

        /* Info grid: 2 columns tight */
        .view-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 30px;
        }
        .view-info-grid .span-full { grid-column: 1 / -1; }
        .view-info-item {
            display: flex; flex-direction: column; gap: 6px;
            padding: 8px 0;
            align-items: flex-start;
        }
        .view-info-item .vi-label {
            font-size: 12px; font-weight: 700;
            color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em;
        }
        .view-info-item .vi-value {
            font-size: 15px; font-weight: 600;
            color: #e2e8f0; word-break: break-word;
            line-height: 1.4;
        }
        .view-info-item .vi-value.status-badge {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 16px; border-radius: 20px; width: fit-content;
            font-size: 13px; font-weight: 800;
        }
        .view-info-item .vi-value.active   { background: rgba(34,197,94,0.12); color: #4ade80; }
        .view-info-item .vi-value.inactive { background: rgba(148,163,184,0.1); color: #94a3b8; }
        .view-info-item .vi-value.suspended{ background: rgba(239,68,68,0.12);  color: #f87171; }
        .view-info-item .vi-value.completed   { background: rgba(34,197,94,0.12);  color: #4ade80; }
        .view-info-item .vi-value.in-progress { background: rgba(250,204,21,0.12); color: #facc15; }
        .view-info-item .vi-value.not-taken   { background: rgba(148,163,184,0.1); color: #94a3b8; }

        /* View footer */
        #viewStudentModal .modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 28px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            flex-shrink: 0;
            background: #12192b;
        }
        #viewStudentModal .modal-footer-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        #viewStudentModal .modal-footer a,
        #viewStudentModal .modal-footer button {
            text-decoration: none !important;
        }
        #viewStudentModal .modal-footer a.btn-primary,
        #viewStudentModal .modal-footer a.btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 18px;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 8px;
            white-space: nowrap;
            transition: all 0.2s ease;
            text-decoration: none !important;
        }
        #viewStudentModal .modal-footer a.btn-primary {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #0f172a;
            border: none;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        #viewStudentModal .modal-footer a.btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
            color: #0f172a;
            text-decoration: none !important;
        }
        #viewStudentModal .modal-footer a.btn-secondary {
            background: #1e2a3a;
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        #viewStudentModal .modal-footer a.btn-secondary:hover {
            background: #273549;
            color: #f1f5f9;
            border-color: rgba(148, 163, 184, 0.35);
            text-decoration: none !important;
        }
        #viewStudentModal .btn-close-view,
        #viewStudentModal .btn-cancel-modal {
            padding: 9px 22px;
            font-size: 13.5px;
            font-weight: 600;
            font-family: inherit;
            color: #cbd5e1;
            background: #1e2a3a;
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
            text-decoration: none !important;
        }
        #viewStudentModal .btn-close-view:hover,
        #viewStudentModal .btn-cancel-modal:hover {
            background: #273549;
            color: #f1f5f9;
        }

        @media (max-width: 600px) {
            .view-info-grid { grid-template-columns: 1fr; }
            .view-profile-banner { flex-wrap: wrap; }
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
                    <h1>Students</h1>
                </div>
                <div class="top-bar-actions">
                    <?php if (isset($_SESSION['counselor_id'])): ?>
                        <?php require_once __DIR__ . '/includes/counselor_notifications_bell.php'; ?>
                    <?php endif; ?>
                    <div class="user-profile">
                        <div class="user-avatar counselor-avatar">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Summary Cards -->
                <div class="overview-cards">
                    <div class="overview-card">
                        <div class="card-icon students">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="card-info">
                            <h3>Total Students</h3>
                            <p class="card-number"><?php echo $totalStudents; ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="card-icon assessments completed">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div class="card-info">
                            <h3>Completed Assessments</h3>
                            <p class="card-number"><?php 
                                $completedResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments sa WHERE sa.status = 'completed'");
                                echo $completedResult->fetch_assoc()['count'] ?? 0;
                            ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="card-icon assessments pending">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div class="card-info">
                            <h3>Pending Assessments</h3>
                            <p class="card-number"><?php 
                                $pendingResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments sa WHERE sa.status = 'in_progress'");
                                echo $pendingResult->fetch_assoc()['count'] ?? 0;
                            ?></p>
                        </div>
                    </div>
                </div>

                <!-- Student Search Section -->
                <div class="student-search-section">
                    <div class="search-header">
                        <h3><i class="fa-solid fa-filter"></i> Filter Students</h3>
                    </div>
                    <div class="search-controls">
                        <div class="search-input-group">
                            <input type="text" id="searchInput" placeholder="Search by name or school ID">
                            <select id="strandFilter" class="status-select">
                                <option value="">All Strands</option>
                                <?php foreach ($allStrands as $strand): ?>
                                <option value="<?php echo htmlspecialchars($strand['code']); ?>">
                                    <?php echo htmlspecialchars($strand['code']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="statusFilter" class="status-select">
                                <option value="active" selected>Active Enrolled</option>
                                <option value="graduated">Graduated Alumni</option>
                                <option value="all">All Students</option>
                            </select>
                            <button class="btn-student-search" id="searchBtn">
                                <i class="fa-solid fa-search"></i>
                                <span>Search</span>
                            </button>
                            <button class="btn-add-student" id="addStudentBtn">
                                <i class="fa-solid fa-plus"></i>
                                <span>Add Student</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Students List Section -->
                <div class="students-list-section">
                    <div class="table-header">
                        <h2>Student List</h2>
                        <span class="results-count">Showing <?php echo min(count($students), 6); ?> of <?php echo $totalStudents; ?> students</span>
                    </div>
                    
                    <!-- Desktop Table Layout -->
                    <div class="table-container desktop-table">
                        <table class="data-table students-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>School ID</th>
                                    <th>Grade Level</th>
                                    <th>Strand</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($totalStudents > 0): ?>
                                    <?php foreach ($students as $student): 
                                        $fullName = htmlspecialchars(getStudentDisplayName($student));
                                        $strandCode = strtolower($student['strand_code'] ?? 'none');
                                        $strandName = $student['strand_code'] ?? 'N/A';
                                        
                                        $latestStatus = $student['latest_assessment_status'] ?? '';
                                        
                                        if ($latestStatus === 'in_progress') {
                                            $statusClass = 'in-progress';
                                            $statusText = 'Ongoing';
                                        } elseif ($latestStatus === 'completed') {
                                            $statusClass = 'completed';
                                            $statusText = 'Completed';
                                        } elseif ($latestStatus === 'abandoned') {
                                            $statusClass = 'abandoned';
                                            $statusText = 'Abandoned';
                                        } else {
                                            $statusClass = 'not-taken';
                                            $statusText = 'Not Taken';
                                        }
                                    ?>
                                    <tr data-student-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>">
                                        <td>
                                            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                                <span><?php echo $fullName; ?></span>
                                                <?php if (($student['status'] ?? '') === 'graduated'): ?>
                                                    <span class="suggestion-status-badge graduated" style="font-size:0.75rem; padding:2px 8px;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?></td>
                                        <td><span class="strand-badge <?php echo $strandCode; ?>"><?php echo htmlspecialchars($strandName); ?></span></td>
                                        <td><span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn-table-action view-student" 
                                                    data-id="<?php echo $student['id']; ?>"
                                                    data-first-name="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>"
                                                    data-middle-name="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>"
                                                    data-last-name="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>"
                                                    data-suffix="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>"
                                                    data-school-id="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                                    data-email="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                                                    
                                                    data-gender="<?php echo htmlspecialchars($student['gender'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($student['address'] ?? ''); ?>"
                                                    data-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>"

                                                    data-full-name="<?php echo htmlspecialchars(getStudentDisplayName($student)); ?>"
                                                    data-initials="<?php echo htmlspecialchars(strtoupper(substr($student['first_name'] ?? 'S', 0, 1) . substr($student['last_name'] ?? 'N', 0, 1))); ?>"
                                                    data-strand-name="<?php echo htmlspecialchars(strtoupper(strtolower($student['strand_code'] ?? 'none'))); ?>"
                                                    data-grade-display="<?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?>"
                                                    data-assessment-status="<?php echo htmlspecialchars($statusText); ?>"
                                                    data-assessment-class="<?php echo htmlspecialchars($statusClass); ?>"
                                                    data-account-status="<?php echo htmlspecialchars(ucfirst(strtolower($student['status'] ?? 'pending'))); ?>"
                                                    data-created="<?php echo htmlspecialchars(!empty($student['created_at']) ? date('F d, Y', strtotime($student['created_at'])) : ''); ?>"
                                                    data-profile-picture="<?php echo htmlspecialchars(!empty($student['profile_picture']) && file_exists(__DIR__ . '/' . $student['profile_picture']) ? $student['profile_picture'] : ''); ?>"
                                                    title="View Student">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-table-action edit-student" 
                                                    data-id="<?php echo $student['id']; ?>"
                                                    data-first-name="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>"
                                                    data-middle-name="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>"
                                                    data-last-name="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>"
                                                    data-suffix="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>"
                                                    data-school-id="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                                    data-email="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                                                    
                                                    data-gender="<?php echo htmlspecialchars($student['gender'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($student['address'] ?? ''); ?>"
                                                    data-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>"
                                                    title="Edit Student">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem;">No students found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card Layout -->
                    <div class="student-cards mobile-cards">
                        <?php if ($totalStudents > 0): ?>
                            <?php foreach ($students as $student): 
                                $fullName = htmlspecialchars(getStudentDisplayName($student));
                                $strandName = $student['strand_code'] ?? 'N/A';
                                
                                $latestStatus = $student['latest_assessment_status'] ?? '';
                                
                                if ($latestStatus === 'in_progress') {
                                    $statusClass = 'in-progress';
                                    $statusText = 'Ongoing';
                                } elseif ($latestStatus === 'completed') {
                                    $statusClass = 'completed';
                                    $statusText = 'Completed';
                                } elseif ($latestStatus === 'abandoned') {
                                    $statusClass = 'abandoned';
                                    $statusText = 'Abandoned';
                                } else {
                                    $statusClass = 'not-taken';
                                    $statusText = 'Not Taken';
                                }
                            ?>
                            <div class="student-card" data-student-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>">
                                <div class="student-card-header">
                                    <div class="student-info">
                                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                            <h3 style="margin:0;"><?php echo $fullName; ?></h3>
                                            <?php if (($student['status'] ?? '') === 'graduated'): ?>
                                                <span class="suggestion-status-badge graduated" style="font-size:0.7rem; padding:1px 6px;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="school-id">ID: <?php echo htmlspecialchars($student['student_id']); ?></span>
                                    </div>
                                    <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </div>
                                <div class="student-card-details">
                                    <span class="grade-strand"><?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?> • <?php echo htmlspecialchars($strandName); ?></span>
                                </div>
                                <div class="student-card-actions">
                                    <button class="card-action-btn view-student" 
                                        data-id="<?php echo $student['id']; ?>"
                                        data-first-name="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>"
                                        data-middle-name="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>"
                                        data-last-name="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>"
                                        data-suffix="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>"
                                        data-school-id="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                                        
                                        data-gender="<?php echo htmlspecialchars($student['gender'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($student['address'] ?? ''); ?>"
                                        data-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>"

                                        data-full-name="<?php echo htmlspecialchars(getStudentDisplayName($student)); ?>"
                                        data-initials="<?php echo htmlspecialchars(strtoupper(substr($student['first_name'] ?? 'S', 0, 1) . substr($student['last_name'] ?? 'N', 0, 1))); ?>"
                                        data-strand-name="<?php echo htmlspecialchars(strtoupper(strtolower($student['strand_code'] ?? 'none'))); ?>"
                                        data-grade-display="<?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?>"
                                        data-assessment-status="<?php echo htmlspecialchars($statusText); ?>"
                                        data-assessment-class="<?php echo htmlspecialchars($statusClass); ?>"
                                        data-account-status="<?php echo htmlspecialchars(ucfirst(strtolower($student['status'] ?? 'pending'))); ?>"
                                        data-created="<?php echo htmlspecialchars(!empty($student['created_at']) ? date('F d, Y', strtotime($student['created_at'])) : ''); ?>"
                                        data-profile-picture="<?php echo htmlspecialchars(!empty($student['profile_picture']) && file_exists(__DIR__ . '/' . $student['profile_picture']) ? $student['profile_picture'] : ''); ?>"
                                        title="View Student">
                                        <i class="fa-solid fa-eye"></i>
                                        <span>View</span>
                                    </button>
                                    <button class="card-action-btn edit-student" 
                                        data-id="<?php echo $student['id']; ?>"
                                        data-first-name="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>"
                                        data-middle-name="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>"
                                        data-last-name="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>"
                                        data-suffix="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>"
                                        data-school-id="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                                        
                                        data-gender="<?php echo htmlspecialchars($student['gender'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($student['address'] ?? ''); ?>"
                                        data-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>"
                                        title="Edit Student">
                                        <i class="fa-solid fa-pen"></i>
                                        <span>Edit</span>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem;">No students found</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Add Student Modal (Counselor Scoped) -->
    <div class="modal" id="addStudentModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-plus"></i> Add New Student</h2>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="addStudentForm" class="student-form">
                    


                    <!-- Personal Information -->
                    <div class="form-section-title">
                        <i class="fa-solid fa-circle-user"></i>
                        Personal Information
                    </div>

                    <div class="form-row names-row">
                        <div class="form-group">
                            <label for="firstName">First Name <span class="required">*</span></label>
                            <input type="text" id="firstName" name="firstName" placeholder="Enter first name" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="middleName">Middle Name</label>
                            <input type="text" id="middleName" name="middleName" placeholder="Enter middle name">
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="lastName" name="lastName" placeholder="Enter last name" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="suffix">Suffix</label>
                            <select id="suffix" name="suffix">
                                <option value="">None</option>
                                <option value="Jr">Jr.</option>
                                <option value="Sr">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row three-col">
                        <div class="form-group">
                            <label for="email">Email <span class="required">*</span></label>
                            <input type="email" id="email" name="email" placeholder="Enter email address" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender <span class="required">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="address">Address <span style="font-size: 0.85em; font-weight: normal; color: #94a3b8;">(Optional)</span></label>
                            <input type="text" id="address" name="address" placeholder="Enter complete address (Optional)">
                            <span class="error-message"></span>
                        </div>
                    </div>

                    <!-- Academic Assignment -->
                    <div class="form-section-title" style="margin-top: 18px;">
                        <i class="fa-solid fa-graduation-cap"></i>
                        Academic Assignment
                    </div>

                    <div class="form-row three-col">
                        <div class="form-group">
                            <label for="schoolId">Student ID <span class="required">*</span></label>
                            <input type="tel" id="schoolId" name="schoolId" maxlength="12" placeholder="Enter student ID" pattern="[0-9]*" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,this.maxLength)" required>
                            <span class="error-message"></span>
                        </div>

                        <div class="form-group">
                            <label for="gradeLevel">Grade Level <span class="required">*</span></label>
                            <select id="gradeLevel" name="gradeLevel" required>
                                <option value="">Select grade level</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="strandCode">Strand <span class="required">*</span></label>
                            <select id="strandCode" name="strandCode" required>
                                <option value="">Select grade first</option>
                                <?php foreach ($allStrands as $strand): ?>
                                <option value="<?php echo htmlspecialchars($strand['code']); ?>" data-grade="<?php echo htmlspecialchars($strand['grade_level']); ?>">
                                    <?php echo htmlspecialchars($strand['code'] . ' — ' . $strand['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="error-message"></span>
                        </div>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" id="cancelAdd">Cancel</button>
                <button type="submit" class="btn-submit-modal" form="addStudentForm">
                    <i class="fa-solid fa-user-plus"></i> Add Student
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal (Counselor Scoped) -->
    <div class="modal" id="editStudentModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Student</h2>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editStudentForm" class="student-form">
                    <input type="hidden" id="editStudentId" name="id">


                    <div class="form-section-title">
                        <i class="fa-solid fa-circle-user"></i> Personal Information
                    </div>

                    <div class="form-row names-row">
                        <div class="form-group">
                            <label for="editFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editFirstName" name="firstName" required>
                        </div>
                        <div class="form-group">
                            <label for="editMiddleName">Middle Name</label>
                            <input type="text" id="editMiddleName" name="middleName">
                        </div>
                        <div class="form-group">
                            <label for="editLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editLastName" name="lastName" required>
                        </div>
                        <div class="form-group">
                            <label for="editSuffix">Suffix</label>
                            <select id="editSuffix" name="suffix">
                                <option value="">None</option>
                                <option value="Jr">Jr.</option>
                                <option value="Sr">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row three-col">
                        <div class="form-group">
                            <label for="editEmail">Email <span class="required">*</span></label>
                            <input type="email" id="editEmail" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="editGender">Gender <span class="required">*</span></label>
                            <select id="editGender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="editAddress">Address <span style="font-size: 0.85em; font-weight: normal; color: #94a3b8;">(Optional)</span></label>
                            <input type="text" id="editAddress" name="address" placeholder="Enter complete address (Optional)">
                        </div>
                    </div>

                    <div class="form-row two-col">
                        <div class="form-group">
                            <label for="editSchoolId">Student ID <span class="required">*</span></label>
                            <input type="tel" id="editSchoolId" name="schoolId" maxlength="12" pattern="[0-9]*" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,this.maxLength)" required>
                        </div>
                        <div class="form-group">
                            <label for="editStatus">Account Status <span class="required">*</span></label>
                            <select id="editStatus" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="graduated">Graduated</option>
                            </select>
                        </div>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" id="cancelEdit">Cancel</button>
                <button type="submit" class="btn-submit-modal" form="editStudentForm">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div class="modal" id="viewStudentModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-id-card"></i> Student Profile</h2>
                <button class="modal-close" id="closeViewModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="view-profile-banner">
                    <div class="view-avatar" id="viewAvatar">--</div>
                    <div class="view-banner-info">
                        <h3 id="viewFullName">—</h3>
                        <div class="view-banner-meta">
                            <span class="view-id">ID: <span id="viewStudentId">—</span></span>
                            <span class="view-strand-badge" id="viewStrandBadge">—</span>
                        </div>
                    </div>
                </div>

                <div class="view-sections">
                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-circle-user"></i> Personal Information
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">Full Name</span>
                                <span class="vi-value" id="viewName">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Gender</span>
                                <span class="vi-value" id="viewGender">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Birthdate</span>
                                <span class="vi-value" id="viewBirthdate">—</span>
                            </div>
                        </div>
                    </div>

                    <hr class="view-section-divider">

                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-graduation-cap"></i> Academic Information
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">Student ID</span>
                                <span class="vi-value" id="viewSchoolId">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Grade Level</span>
                                <span class="vi-value" id="viewGrade">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Strand</span>
                                <span class="vi-value" id="viewStrand">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Assessment Status</span>
                                <span class="vi-value" id="viewAssessmentStatus">—</span>
                            </div>
                        </div>
                    </div>

                    <hr class="view-section-divider">

                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-address-card"></i> Contact & Account
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">Email</span>
                                <span class="vi-value" id="viewEmail">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Account Status</span>
                                <span class="vi-value status-badge" id="viewAccountStatus">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Address</span>
                                <span class="vi-value" id="viewAddress">—</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Created Date</span>
                                <span class="vi-value" id="viewCreatedDate">—</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="modal-footer-left">
                    <a id="viewAnswersLink" href="#" class="btn-primary">
                        <i class="fa-solid fa-file-lines"></i> View Answers
                    </a>
                    <a id="viewResultsLink" href="#" class="btn-secondary">
                        <i class="fa-solid fa-chart-pie"></i> View Results
                    </a>
                </div>
                <button type="button" class="btn-cancel-modal" id="closeViewBtn">Close</button>
            </div>
        </div>
    </div>

    <script src="counselor.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const editModal = document.getElementById('editStudentModal');
        if (!editModal) return;
        
        document.querySelectorAll('.edit-student').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const data = this.dataset;
                document.getElementById('editStudentId').value = data.id || '';
                document.getElementById('editFirstName').value = data.firstName || '';
                document.getElementById('editMiddleName').value = data.middleName || '';
                document.getElementById('editLastName').value = data.lastName || '';
                document.getElementById('editSuffix').value = data.suffix || '';
                document.getElementById('editEmail').value = data.email || '';
                document.getElementById('editSchoolId').value = data.schoolId || '';
                document.getElementById('editStatus').value = (data.status || 'active').toLowerCase();
                document.getElementById('editGender').value = (data.gender || '').toLowerCase();
                document.getElementById('editAddress').value = data.address || '';
                
                if (data.birthdate) {
                    try {
                        const d = new Date(data.birthdate);
                        if (!isNaN(d)) {
                            const yyyy = d.getFullYear();
                            const mm = String(d.getMonth() + 1).padStart(2, '0');
                            const dd = String(d.getDate()).padStart(2, '0');
                            document.getElementById('editBirthdate').value = yyyy + '-' + mm + '-' + dd;
                        }
                    } catch(e) {}
                } else {
                    document.getElementById('editBirthdate').value = '';
                }
                
                editModal.classList.add('active');
            });
        });

        function closeEdit() { editModal.classList.remove('active'); }
        document.getElementById('closeEditModal').addEventListener('click', closeEdit);
        document.getElementById('cancelEdit').addEventListener('click', closeEdit);
        editModal.querySelector('.modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeEdit();
        });

        const editForm = document.getElementById('editStudentForm');
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = editForm.closest('.modal-content').querySelector('.btn-submit-modal');
            if(btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
            
            const fd = new FormData(editForm);
            fd.append('action', 'edit_student');
            
            fetch('counselor_students.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error updating student');
                        if(btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes'; }
                    }
                })
                .catch(e => {
                    alert('Network error');
                    if(btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes'; }
                });
        });
        const viewModal = document.getElementById('viewStudentModal');
        if (viewModal) {
            document.querySelectorAll('.view-student').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const data = this.dataset;
                    
                    document.getElementById('viewFullName').textContent = data.fullName || '—';
                    document.getElementById('viewStudentId').textContent = data.schoolId || '—';
                    
                    const avatar = document.getElementById('viewAvatar');
                    if (data.profilePicture) {
                        avatar.innerHTML = `<img src="${data.profilePicture}" alt="Avatar">`;
                    } else {
                        avatar.textContent = data.initials || '—';
                    }

                    const badge = document.getElementById('viewStrandBadge');
                    badge.textContent = data.strandName || '—';
                    badge.className = 'view-strand-badge';
                    
                    document.getElementById('viewName').textContent = data.fullName || '—';
                    document.getElementById('viewGender').textContent = data.gender || '—';
                    document.getElementById('viewBirthdate').textContent = data.birthdate || '—';
                    
                    document.getElementById('viewSchoolId').textContent = data.schoolId || '—';
                    document.getElementById('viewGrade').textContent = data.gradeDisplay || '—';
                    document.getElementById('viewStrand').textContent = data.strandName || '—';
                    
                    const assessStatus = document.getElementById('viewAssessmentStatus');
                    assessStatus.textContent = data.assessmentStatus || '—';
                    assessStatus.className = 'vi-value ' + (data.assessmentClass || '');
                    
                    document.getElementById('viewEmail').textContent = data.email || '—';
                    document.getElementById('viewAddress').textContent = data.address || '—';
                    
                    const accStatus = document.getElementById('viewAccountStatus');
                    accStatus.textContent = data.accountStatus || '—';
                    accStatus.className = 'vi-value status-badge ' + (data.status || 'active');
                    
                    document.getElementById('viewCreatedDate').textContent = data.created || '—';

                    // Update footer links
                    document.getElementById('viewAnswersLink').href = 'counselor_answers.php?student_id=' + data.id;
                    document.getElementById('viewResultsLink').href = 'counselor_results.php?student_id=' + data.id;
                    
                    viewModal.classList.add('active');
                });
            });

            function closeView() { viewModal.classList.remove('active'); }
            document.getElementById('closeViewModal').addEventListener('click', closeView);
            document.getElementById('closeViewBtn').addEventListener('click', closeView);
            viewModal.querySelector('.modal-overlay').addEventListener('click', function(e) {
                if (e.target === this) closeView();
            });
        }
    });

    // Grade Level → Strand dropdown filter in Add Student modal
    const gradeLevelSel = document.getElementById('gradeLevel');
    const strandCodeSel = document.getElementById('strandCode');
    if (gradeLevelSel && strandCodeSel) {
        gradeLevelSel.addEventListener('change', function() {
            const grade = this.value; // e.g. "Grade 11"
            const gradeNum = grade.replace('Grade ', '').trim(); // "11" or "12"
            // Show/hide options by data-grade attribute
            Array.from(strandCodeSel.options).forEach(opt => {
                if (!opt.value) return; // keep placeholder
                const optGrade = opt.dataset.grade || '';
                opt.hidden = grade !== '' && optGrade !== gradeNum;
            });
            strandCodeSel.value = '';
            strandCodeSel.querySelector('option[value=""]').textContent = grade ? 'Select strand' : 'Select grade first';
        });
    }

    // Unified Student Filtering (Search + Strand + Student Status)
    function applyStudentFilters() {
        const query = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
        const strandVal = (document.getElementById('strandFilter')?.value || '').toLowerCase().trim();
        const statusVal = (document.getElementById('statusFilter')?.value || 'active').toLowerCase().trim();

        let visibleCount = 0;

        // Desktop table rows
        const rows = document.querySelectorAll('.students-table tbody tr');
        rows.forEach(row => {
            if (row.cells.length < 5) return; // skip empty state row
            const studentStatus = (row.dataset.studentStatus || 'active').toLowerCase();
            const name = (row.cells[0]?.textContent || '').toLowerCase();
            const id = (row.cells[1]?.textContent || '').toLowerCase();
            const strand = (row.cells[3]?.textContent || '').toLowerCase();

            let matchQuery = !query || name.includes(query) || id.includes(query);
            let matchStrand = !strandVal || strand.includes(strandVal);
            let matchStatus = (statusVal === 'all') || (studentStatus === statusVal);

            if (matchQuery && matchStrand && matchStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Mobile cards
        document.querySelectorAll('.student-cards .student-card').forEach(card => {
            const studentStatus = (card.dataset.studentStatus || 'active').toLowerCase();
            const name = (card.querySelector('.student-info')?.textContent || '').toLowerCase();
            const id = (card.querySelector('.school-id')?.textContent || '').toLowerCase();
            const strand = (card.querySelector('.strand-badge')?.textContent || '').toLowerCase();

            let matchQuery = !query || name.includes(query) || id.includes(query);
            let matchStrand = !strandVal || strand.includes(strandVal);
            let matchStatus = (statusVal === 'all') || (studentStatus === statusVal);

            if (matchQuery && matchStrand && matchStatus) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });

        const resultsCountEl = document.querySelector('.results-count');
        if (resultsCountEl) {
            resultsCountEl.textContent = `Showing ${visibleCount} student${visibleCount === 1 ? '' : 's'}`;
        }
    }

    document.getElementById('searchInput')?.addEventListener('input', applyStudentFilters);
    document.getElementById('strandFilter')?.addEventListener('change', applyStudentFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyStudentFilters);
    document.getElementById('searchBtn')?.addEventListener('click', applyStudentFilters);

    // Initial run: strictly scope to Active Enrolled by default
    applyStudentFilters();
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
