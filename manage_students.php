<?php
// Manage Students - With Database Backend
ob_start(); // Buffer all output - AJAX responses will ob_end_clean() before echoing JSON

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration and database
require_once 'config.php';
require_once 'system_config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/audit.php';

require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('manage_students');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// Handle AJAX CRUD requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];

    $action = $_POST['action'] ?? '';
    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
    try {
        switch ($action) {
            case 'add_student':
                $firstName = trim($_POST['firstName'] ?? '');
                $middleName = trim($_POST['middleName'] ?? '');
                $lastName = trim($_POST['lastName'] ?? '');
                $suffix = trim($_POST['suffix'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $studentId = trim($_POST['schoolId'] ?? '');
                $gender = $_POST['gender'] ?? '';
                // Force newly added students to be active and auto-generate password
                $status = 'active';
                $strandCode = trim($_POST['strand'] ?? '');
                $gradeLevel = trim($_POST['gradeLevel'] ?? '');


                $address = trim($_POST['address'] ?? '');

                // Auto-format names to Proper Case
                $firstName  = $firstName  !== '' ? ucwords(strtolower($firstName))  : '';
                $middleName = $middleName !== '' ? ucwords(strtolower($middleName)) : '';
                $lastName   = $lastName   !== '' ? ucwords(strtolower($lastName))   : '';

                if ($firstName === '' || $lastName === '' || $email === '' || $studentId === '' || $gender === '' || $strandCode === '' || $gradeLevel === '') {
                    throw new Exception('Please fill in all required fields.');
                }
                
                if (!isValidEmailAddress($email)) {
                    throw new Exception('Please enter a valid email address.');
                }

                $gradeLevelDb = ($gradeLevel === '11' || $gradeLevel === 'Grade 11') ? 'Grade 11' : 'Grade 12';

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

                // If the valid ID has a grade_level set, enforce it - admin cannot override it
                if ($validRow && !empty($validRow['grade_level']) && $validRow['grade_level'] !== $gradeLevelDb) {
                    throw new Exception("Grade level mismatch: Student ID {$studentId} is assigned to {$validRow['grade_level']}, but you selected {$gradeLevelDb}.");
                }

                // If the valid ID has a strand_code set, enforce it - admin cannot override it
                if ($validRow && !empty($validRow['strand_code']) && strtoupper($validRow['strand_code']) !== strtoupper($strandCode)) {
                    throw new Exception("Strand mismatch: Student ID {$studentId} is assigned to strand '{$validRow['strand_code']}', but you selected '{$strandCode}'.");
                }

                $strandStmt = $mysqli->prepare('SELECT id, name FROM strands WHERE code = ? OR name = ? LIMIT 1');
                $strandStmt->bind_param('ss', $strandCode, $strandCode);
                $strandStmt->execute();
                $strandRow = $strandStmt->get_result()->fetch_assoc();
                $strandStmt->close();
                $strandId = $strandRow ? (int)$strandRow['id'] : null;

                $address  = $address   !== '' ? $address   : null;

                // Auto-generate a random temporary password
                $rawPassword = bin2hex(random_bytes(6)); // 12-char hex
                $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);

                // Get active school_year_id
                $activeSchoolYearId = getCurrentSchoolYearId($mysqli);

                // Fix Circular Foreign Key Constraint:
                // We MUST insert into valid_student_ids FIRST to satisfy the FK constraint on the students table.
                if (!$validRow) {
                    $vsInsertTemp = $mysqli->prepare("INSERT INTO valid_student_ids (student_id, school_year_id, grade_level, strand_code, is_registered, registered_student_id, created_at) VALUES (?, ?, ?, ?, 1, 0, NOW())");
                    if ($vsInsertTemp) {
                        $vsInsertTemp->bind_param("siss", $studentId, $activeSchoolYearId, $gradeLevelDb, $strandCode);
                        $vsInsertTemp->execute();
                        $vsInsertTemp->close();
                    }
                }

                $ins = $mysqli->prepare('INSERT INTO students (student_id, first_name, middle_name, last_name, suffix, gender, email, password, address, strand_id, school_year_id, grade_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->bind_param('sssssssssiiss', $studentId, $firstName, $middleName, $lastName, $suffix, $gender, $email, $hashedPassword, $address, $strandId, $activeSchoolYearId, $gradeLevelDb, $status);

                if (!$ins->execute()) {
                    $err = $ins->error;
                    $ins->close();
                    // Cleanup the temporary valid_student_ids row if the student insert fails
                    if (!$validRow) {
                        $mysqli->query("DELETE FROM valid_student_ids WHERE student_id = '$studentId'");
                    }
                    throw new Exception('Failed to add student: ' . $err);
                }
                
                // Reliably capture real auto-increment ID from statement first, then mysqli connection
                $newStudentId = (int)$ins->insert_id;
                if ($newStudentId <= 0) {
                    $newStudentId = (int)$mysqli->insert_id;
                }
                $ins->close();

                // Fallback verification query to guarantee real student ID is captured
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

                // Sync valid_student_ids: Now update the registered_student_id with the actual inserted ID
                $vsUpdate = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 1, registered_student_id = ?, school_year_id = ? WHERE student_id = ?");
                if ($vsUpdate) {
                    $vsUpdate->bind_param("iis", $newStudentId, $activeSchoolYearId, $studentId);
                    $vsUpdate->execute();
                    $vsUpdate->close();
                }
                // Mark account as requiring a forced password change on first login
                $mcpStmt = $mysqli->prepare("UPDATE students SET must_change_password = 1 WHERE id = ?");
                if ($mcpStmt) {
                    $mcpStmt->bind_param('i', $newStudentId);
                    $mcpStmt->execute();
                    $mcpStmt->close();
                }

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

                // Bell notification: account created / activated
                notify_student(
                    $newStudentId,
                    'Account Activated',
                    'Account Activated - You can now log in and take your assessment.',
                    'success',
                    'dashboard.php'
                );

                $studentFullName = trim(implode(' ', array_filter([$firstName, $lastName])));
                notify_all_active_counselors(
                    'New Student Registered',
                    $studentFullName . ' has registered.',
                    'info',
                    'manage_students.php'
                );

                // Audit logging
                $strandName = $strandRow['name'] ?? $strandCode;
                $descriptionText = "Admin added student {$studentFullName} (School ID: {$studentId}) to Grade {$gradeLevelDb} - {$strandName}";
                
                // Construct a safe JSON payload without the raw or hashed password
                $newStudentSafe = [
                    'student_id' => $studentId,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'suffix' => $suffix,
                    'gender' => $gender,

                    'email' => $email,

                    'address' => $address,
                    'strand_id' => $strandId,
                    'grade_level' => $gradeLevelDb,
                    'status' => $status
                ];
                log_activity($userId, $userType, 'Added Student', 'students', $newStudentId, $descriptionText, null, json_encode($newStudentSafe));

                $response['success'] = true;
                $response['message'] = 'Student added successfully';
                $response['generated_password'] = $rawPassword;
                $response['email_sent'] = $emailSent;
                ob_end_clean();
                echo json_encode($response);
                exit;

            case 'edit_student':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid student ID.');
                $firstName  = trim($_POST['firstName']  ?? '');
                $middleName = trim($_POST['middleName'] ?? '');
                $lastName   = trim($_POST['lastName']   ?? '');
                $suffix     = trim($_POST['suffix']     ?? '');
                $email      = trim($_POST['email']      ?? '');
                $studentId  = trim($_POST['schoolId']   ?? '');
                $gender     = $_POST['gender']          ?? '';
                $status     = trim($_POST['status']     ?? 'active');
                $strandCode = trim($_POST['strand']     ?? '');
                $gradeLevel = trim($_POST['gradeLevel'] ?? '');


                $address    = trim($_POST['address']    ?? '');
                $newPassword = trim($_POST['newPassword'] ?? '');

                // Auto-format names to Proper Case
                $firstName  = $firstName  !== '' ? ucwords(strtolower($firstName))  : '';
                $middleName = $middleName !== '' ? ucwords(strtolower($middleName)) : '';
                $lastName   = $lastName   !== '' ? ucwords(strtolower($lastName))   : '';

                if ($firstName === '' || $lastName === '' || $email === '' || $studentId === '' || $gender === '' || $strandCode === '' || $gradeLevel === '') {
                    throw new Exception('Please fill in all required fields.');
                }
                
                if (!isValidEmailAddress($email)) {
                    throw new Exception('Please enter a valid email address.');
                }

                $gradeLevelDb = ($gradeLevel === '11' || $gradeLevel === 'Grade 11') ? 'Grade 11' : 'Grade 12';

                $dupStmt = $mysqli->prepare('SELECT id FROM students WHERE (email = ? OR student_id = ?) AND id != ? LIMIT 1');
                $dupStmt->bind_param('ssi', $email, $studentId, $id);
                $dupStmt->execute();
                $dupExists = $dupStmt->get_result()->fetch_assoc();
                $dupStmt->close();
                if ($dupExists) throw new Exception('Another student with this email or ID already exists.');

                // Fetch complete old student row before editing
                $oldStudent = null;
                $oldFullStmt = $mysqli->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
                if ($oldFullStmt) {
                    $oldFullStmt->bind_param('i', $id);
                    $oldFullStmt->execute();
                    $oldStudent = $oldFullStmt->get_result()->fetch_assoc();
                    $oldFullStmt->close();
                }
                
                $oldStatus = $oldStudent['status'] ?? 'active';
                $oldGrade = $oldStudent['grade_level'] ?? '';
                $oldStrandId = (int) ($oldStudent['strand_id'] ?? 0);

                $strandStmt2 = $mysqli->prepare('SELECT id FROM strands WHERE code = ? OR name = ? LIMIT 1');
                $strandStmt2->bind_param('ss', $strandCode, $strandCode);
                $strandStmt2->execute();
                $strandRow2 = $strandStmt2->get_result()->fetch_assoc();
                $strandStmt2->close();
                $strandId = $strandRow2 ? (int)$strandRow2['id'] : null;

                $address    = $address    !== '' ? $address    : null;

                if ($newPassword !== '') {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upd = $mysqli->prepare('UPDATE students SET student_id=?, first_name=?, middle_name=?, last_name=?, suffix=?, gender=?, email=?, password=?, address=?, strand_id=?, grade_level=?, status=? WHERE id=?');
                    $upd->bind_param('sssssssssissi', $studentId, $firstName, $middleName, $lastName, $suffix, $gender, $email, $hashedPassword, $address, $strandId, $gradeLevelDb, $status, $id);
                } else {
                    $upd = $mysqli->prepare('UPDATE students SET student_id=?, first_name=?, middle_name=?, last_name=?, suffix=?, gender=?, email=?, address=?, strand_id=?, grade_level=?, status=? WHERE id=?');
                    $upd->bind_param('ssssssssissi', $studentId, $firstName, $middleName, $lastName, $suffix, $gender, $email, $address, $strandId, $gradeLevelDb, $status, $id);
                }

                if (!$upd->execute()) {
                    $err = $upd->error;
                    $upd->close();
                    throw new Exception('Failed to update student: ' . $err);
                }
                $upd->close();

                // Sync valid_student_ids if the School ID was changed during edit
                $oldSchoolId = trim($oldStudent['student_id'] ?? '');
                if ($oldSchoolId !== '' && $oldSchoolId !== $studentId) {
                    // 1. Free up the OLD School ID
                    $freeOldStmt = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 0, registered_student_id = NULL WHERE student_id = ?");
                    if ($freeOldStmt) {
                        $freeOldStmt->bind_param("s", $oldSchoolId);
                        $freeOldStmt->execute();
                        $freeOldStmt->close();
                    }

                    // 2. Check and claim/insert the NEW School ID
                    $chkNewStmt = $mysqli->prepare("SELECT id FROM valid_student_ids WHERE student_id = ? LIMIT 1");
                    $chkNewStmt->bind_param("s", $studentId);
                    $chkNewStmt->execute();
                    $newRow = $chkNewStmt->get_result()->fetch_assoc();
                    $chkNewStmt->close();

                    $editSchoolYearId = (int)($oldStudent['school_year_id'] ?? getCurrentSchoolYearId($mysqli));
                    if ($newRow) {
                        $claimNewStmt = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 1, registered_student_id = ?, school_year_id = ? WHERE student_id = ?");
                        if ($claimNewStmt) {
                            $claimNewStmt->bind_param("iis", $id, $editSchoolYearId, $studentId);
                            $claimNewStmt->execute();
                            $claimNewStmt->close();
                        }
                    } else {
                        $insNewStmt = $mysqli->prepare("INSERT INTO valid_student_ids (student_id, school_year_id, grade_level, strand_code, is_registered, registered_student_id, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())");
                        if ($insNewStmt) {
                            $insNewStmt->bind_param("sissi", $studentId, $editSchoolYearId, $gradeLevelDb, $strandCode, $id);
                            $insNewStmt->execute();
                            $insNewStmt->close();
                        }
                    }
                }

                // -- Bell notifications based on what changed -----------------------------
                if ($status === 'active' && $oldStatus !== 'active') {
                    notify_student($id, 'Account Activated',
                        'Account Activated - You can now log in and take your assessment.',
                        'success', 'dashboard.php');
                } elseif (in_array($status, ['suspended', 'inactive']) && $oldStatus === 'active') {
                    notify_student($id, 'Account Status Changed',
                        'Account Status Changed - Your account has been suspended. Contact your counselor for details.',
                        'warning', null);
                }
                if ($newPassword !== '') {
                    notify_student($id, 'Password Updated',
                        'Password Updated - Your password was successfully changed.',
                        'info', 'profile.php');
                }

                $studentFullName = trim(implode(' ', array_filter([$firstName, $lastName])));
                if ($status === 'active' && $oldStatus !== 'active') {
                    notify_all_active_counselors(
                        'Student Account Activated',
                        $studentFullName . '\'s account has been reactivated.',
                        'info',
                        'manage_students.php'
                    );
                }

                // Fetch complete new student row
                $newStudent = null;
                $newFullStmt = $mysqli->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
                if ($newFullStmt) {
                    $newFullStmt->bind_param('i', $id);
                    $newFullStmt->execute();
                    $newStudent = $newFullStmt->get_result()->fetch_assoc();
                    $newFullStmt->close();
                }

                // Identify changed fields
                $oldChanges = [];
                $newChanges = [];
                if ($oldStudent && $newStudent) {
                    foreach ($newStudent as $key => $val) {
                        if ($key === 'password') {
                            continue;
                        }
                        if (array_key_exists($key, $oldStudent) && $oldStudent[$key] !== $val) {
                            $oldChanges[$key] = $oldStudent[$key];
                            $newChanges[$key] = $val;
                        }
                    }
                }

                if (isset($newChanges['status']) && ($newChanges['status'] === 'suspended' || $newChanges['status'] === 'inactive')) {
                    $actionName = 'Suspended Student';
                    $descriptionText = "Admin suspended student {$studentFullName}";
                    if ($newChanges['status'] === 'inactive') {
                        $actionName = 'Deactivated Student';
                        $descriptionText = "Admin deactivated student {$studentFullName}";
                    }
                } else {
                    $actionName = 'Edited Student';
                    $descriptionText = "Admin edited student #{$id} ({$studentFullName})";
                }

                log_activity(
                    $userId,
                    $userType,
                    $actionName,
                    'students',
                    $id,
                    $descriptionText,
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );

                $response['success'] = true;
                $response['message'] = 'Student updated successfully';
                ob_end_clean();
                echo json_encode($response);
                exit;

            case 'delete_student':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid student.');
                
                // Fetch student details before deleting
                $oldStudent = null;
                $stQuery = $mysqli->prepare("SELECT * FROM students WHERE id = ?");
                if ($stQuery) {
                    $stQuery->bind_param('i', $id);
                    $stQuery->execute();
                    $oldStudent = $stQuery->get_result()->fetch_assoc();
                    $stQuery->close();
                }

                $stmt = $mysqli->prepare("UPDATE students SET status = 'deleted' WHERE id = ?");
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception('Failed to delete student: ' . $err);
                }
                $stmt->close();

                // Sync valid_student_ids when a student is soft-deleted
                $deletedSchoolId = trim($oldStudent['student_id'] ?? '');
                if ($deletedSchoolId !== '') {
                    $vsClear = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 0, registered_student_id = NULL WHERE student_id = ? OR registered_student_id = ?");
                    if ($vsClear) {
                        $vsClear->bind_param("si", $deletedSchoolId, $id);
                        $vsClear->execute();
                        $vsClear->close();
                    }
                } elseif ($id > 0) {
                    $vsClear = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 0, registered_student_id = NULL WHERE registered_student_id = ?");
                    if ($vsClear) {
                        $vsClear->bind_param("i", $id);
                        $vsClear->execute();
                        $vsClear->close();
                    }
                }

                $studentFullName = trim(implode(' ', array_filter([$oldStudent['first_name'] ?? '', $oldStudent['last_name'] ?? ''])));
                $descriptionText = "Admin removed student {$studentFullName} (School ID: " . ($oldStudent['student_id'] ?? $id) . ")";
                log_activity($userId, $userType, 'Deleted Student', 'students', $id, $descriptionText, json_encode($oldStudent), null);

                $response['success'] = true;
                $response['message'] = 'Student deleted successfully';
                ob_end_clean();
                echo json_encode($response);
                exit;

            case 'get_strands_by_grade':
                $gradeLevel = (int)($_POST['gradeLevel'] ?? 0);
                if ($gradeLevel !== 11 && $gradeLevel !== 12) {
                    throw new Exception('Invalid grade level.');
                }
                
                if ($gradeLevel === 11) {
                    $stmt = $mysqli->prepare("SELECT id, name, code FROM strands WHERE grade_level = 11 AND code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
                } else {
                    // For Grade 12 during transition year, they can be either old (12) or new (11) strands
                    $stmt = $mysqli->prepare("SELECT id, name, code FROM strands WHERE grade_level IN (11, 12) AND code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
                }
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
                ob_end_clean();
                echo json_encode($response);
                exit;

            case 'get_all_strands':
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
                ob_end_clean();
                echo json_encode($response);
                exit;

            case 'get_student':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid student id.');
                }

                $stmt = $mysqli->prepare("SELECT s.*, st.name AS strand_name, st.code AS strand_code, sy.year_label AS school_year FROM students s LEFT JOIN strands st ON s.strand_id = st.id LEFT JOIN school_years sy ON s.school_year_id = sy.id WHERE s.id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$student) {
                    throw new Exception('Student not found.');
                }

                // Add profile picture URL to response
                if (!empty($student['profile_picture']) && file_exists(__DIR__ . '/' . $student['profile_picture'])) {
                    $student['profile_picture_url'] = $student['profile_picture'];
                } else {
                    $student['profile_picture_url'] = null;
                }

                $response['success'] = true;
                $response['student'] = $student;
                $response['school_name'] = getSystemConfig('school_name') ?? getSystemConfig('system_name') ?? 'Not Assigned';
                ob_end_clean();
                echo json_encode($response);
                exit;

            default:
                throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        if (isset($mysqli) && $mysqli && $mysqli->errno) {
            $mysqli->rollback();
        }
        $response['message'] = $e->getMessage();
        ob_end_clean();
        echo json_encode($response);
        exit;
    }
}

// Fetch all students from database with strand info
$students = [];
$sql = "SELECT s.*, st.name as strand_name, st.code as strand_code, sy.year_label as school_year,
        (SELECT COUNT(id) FROM student_assessments WHERE student_id = s.id) as assessment_count,
        (SELECT status FROM student_assessments WHERE student_id = s.id ORDER BY COALESCE(completed_at, started_at, created_at) DESC LIMIT 1) as latest_assessment_status
        FROM students s
        LEFT JOIN strands st ON s.strand_id = st.id
        LEFT JOIN school_years sy ON s.school_year_id = sy.id
        WHERE s.status != 'deleted'
        ORDER BY s.first_name ASC, s.last_name ASC";

$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Fetch strands for filter dropdown
$strands = [];
$strandResult = $mysqli->query("SELECT * FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY name");
if ($strandResult) {
    while ($row = $strandResult->fetch_assoc()) {
        $strands[] = $row;
    }
}

// Get total count
$totalStudents = count($students);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* Modern Structured Toolbar for Manage Students */
        .page-header {
            margin-bottom: 1.25rem;
            width: 100%;
        }

        .header-actions {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.85rem !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Top Row: Search on Left, Summary + CTA on Right */
        .toolbar-top-row {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 1rem !important;
            width: 100% !important;
            flex-wrap: wrap !important;
            box-sizing: border-box !important;
        }

        .search-box-wrapper {
            display: flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            flex: 1 1 340px !important;
            max-width: 440px !important;
            min-width: 260px !important;
        }

        .search-box {
            position: relative !important;
            flex: 1 !important;
            min-width: 0 !important;
            display: flex !important;
            align-items: center !important;
        }

        .search-box i {
            position: absolute !important;
            left: 0.9rem !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            color: #94a3b8 !important;
            font-size: 0.9rem !important;
            pointer-events: none !important;
            z-index: 2 !important;
        }

        .search-box input {
            width: 100% !important;
            height: 40px !important;
            box-sizing: border-box !important;
            padding: 0.5rem 0.85rem 0.5rem 2.45rem !important;
            border: 1px solid rgba(251, 191, 36, 0.25) !important;
            border-radius: 8px !important;
            background: rgba(30, 41, 59, 0.7) !important;
            color: #e2e8f0 !important;
            font-size: 0.85rem !important;
            transition: all 0.2s ease !important;
        }

        .search-box input:focus {
            outline: none !important;
            border-color: #fbbf24 !important;
            background: rgba(30, 41, 59, 0.9) !important;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15) !important;
        }

        .search-box input::placeholder {
            color: #64748b !important;
        }

        .search-box-wrapper .btn-search,
        .btn-search {
            height: 40px !important;
            padding: 0 1.15rem !important;
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.4rem !important;
            border-radius: 8px !important;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
            color: #0f172a !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25) !important;
        }

        .btn-search:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.35) !important;
        }

        .header-right-group {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            margin-left: auto !important;
            flex-shrink: 0 !important;
            flex-wrap: wrap !important;
        }

        /* Highlighted Total Students Badge */
        .total-students-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.55rem !important;
            height: 40px !important;
            padding: 0 0.95rem !important;
            box-sizing: border-box !important;
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.14) 0%, rgba(245, 158, 11, 0.06) 100%) !important;
            border: 1px solid rgba(251, 191, 36, 0.4) !important;
            border-radius: 8px !important;
            box-shadow: 0 0 14px rgba(251, 191, 36, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
        }

        .total-students-badge .badge-icon {
            color: #fbbf24 !important;
            font-size: 0.9rem !important;
        }

        .total-badge-label {
            color: #e2e8f0 !important;
            font-size: 0.84rem !important;
            font-weight: 600 !important;
            letter-spacing: 0.01em !important;
        }

        .total-badge-num {
            color: #0f172a !important;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
            font-size: 0.85rem !important;
            font-weight: 800 !important;
            padding: 0.15rem 0.55rem !important;
            border-radius: 6px !important;
            min-width: 20px !important;
            text-align: center !important;
            box-shadow: 0 2px 6px rgba(245, 158, 11, 0.35) !important;
        }

        .header-right-group #addStudentBtn,
        .header-actions .btn-primary {
            height: 40px !important;
            box-sizing: border-box !important;
            padding: 0 1.25rem !important;
            border-radius: 8px !important;
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.4rem !important;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
            color: #0f172a !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25) !important;
            margin-left: 0 !important;
        }

        .header-right-group #addStudentBtn:hover,
        .header-actions .btn-primary:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 14px rgba(251, 191, 36, 0.4) !important;
        }

        /* Filter Bar Row */
        .filter-bar-row {
            display: flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 0.65rem !important;
            width: 100% !important;
            padding: 0.55rem 0.85rem !important;
            background: rgba(18, 25, 43, 0.65) !important;
            border: 1px solid rgba(148, 163, 184, 0.14) !important;
            border-radius: 10px !important;
            box-sizing: border-box !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2) !important;
        }

        .filter-group-header {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.4rem !important;
            color: #94a3b8 !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            margin-right: 0.25rem !important;
            flex-shrink: 0 !important;
        }

        .filter-group-header i {
            color: #fbbf24 !important;
            font-size: 0.85rem !important;
        }

        .filter-dropdowns {
            display: flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 0.5rem !important;
            flex: 1 1 auto !important;
            min-width: 0 !important;
        }

        .filter-select {
            height: 36px !important;
            box-sizing: border-box !important;
            padding: 0 1.85rem 0 0.75rem !important;
            background: #1e293b !important;
            border: 1px solid #334155 !important;
            border-radius: 8px !important;
            color: #e2e8f0 !important;
            font-size: 0.82rem !important;
            font-weight: 500 !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 0.65rem center !important;
        }

        .filter-select:hover,
        .filter-select:focus {
            border-color: rgba(251, 191, 36, 0.5) !important;
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.12) !important;
            outline: none !important;
        }

        .filter-select option {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        #filterStatus {
            min-width: 155px !important;
        }

        #filterAssessment {
            min-width: 175px !important;
        }

        #filterGrade {
            min-width: 115px !important;
        }

        #filterStrand {
            min-width: 125px !important;
        }

        .btn-clear {
            height: 36px !important;
            box-sizing: border-box !important;
            padding: 0 0.85rem !important;
            background: rgba(30, 41, 59, 0.7) !important;
            border: 1px solid rgba(251, 191, 36, 0.3) !important;
            border-radius: 8px !important;
            color: #cbd5e1 !important;
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }

        .btn-clear:hover {
            border-color: #fbbf24 !important;
            color: #fbbf24 !important;
            background: rgba(251, 191, 36, 0.12) !important;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .toolbar-top-row {
                gap: 0.75rem !important;
            }
            .search-box-wrapper {
                flex: 1 1 100% !important;
                max-width: 100% !important;
            }
            .header-right-group {
                width: 100% !important;
                justify-content: space-between !important;
                margin-left: 0 !important;
            }
        }

        @media (max-width: 720px) {
            .filter-bar-row {
                flex-direction: column !important;
                align-items: stretch !important;
                padding: 0.75rem !important;
            }
            .filter-dropdowns {
                flex-direction: column !important;
                width: 100% !important;
            }
            .filter-select {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
            }
            .btn-clear {
                width: 100% !important;
                justify-content: center !important;
            }
        }
        
        #addStudentModal .modal-content,
        #editStudentModal .modal-content {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 860px;
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            background: #12192b;
            border: 1px solid rgba(251,191,36,0.18);
            border-radius: 16px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.7);
            overflow: hidden;
        }

        /* Header */
        #addStudentModal .modal-header,
        #editStudentModal .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
        }
        #addStudentModal .modal-header h2,
        #editStudentModal .modal-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
        }
        #addStudentModal .modal-header h2 i,
        #editStudentModal .modal-header h2 i {
            color: #f59e0b;
            font-size: 1.05rem;
        }
        #addStudentModal .modal-close,
        #editStudentModal .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: rgba(255,255,255,0.06);
            color: #94a3b8;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s, color 0.2s;
        }
        #addStudentModal .modal-close:hover,
        #editStudentModal .modal-close:hover {
            background: rgba(239,68,68,0.18);
            color: #f87171;
        }

        /* Email column truncation */
        .email-cell {
            display: inline-block;
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
            cursor: default;
        }

        /* Body - scrollable */
        #addStudentModal .modal-body,
        #editStudentModal .modal-body {
            padding: 8px 24px 10px;
            overflow-y: auto;
            flex: 1;
        }

        /* Section heading row - compact with sleek divider line filling horizontal space */
        .student-form .form-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #f59e0b;
            margin: 0 0 6px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .student-form .form-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
            margin-left: 8px;
        }
        .student-form .form-section-title i {
            font-size: 0.85rem;
        }

        /* Form grid rows */
        .student-form .form-row {
            display: grid;
            gap: 10px 12px;
            margin-bottom: 8px;
            align-items: start;
        }
        .student-form .form-row:last-child {
            margin-bottom: 0;
        }
        .student-form .names-row  { grid-template-columns: 1fr 1fr 1fr 105px; }
        .student-form .two-col    { grid-template-columns: 1fr 1fr; }
        .student-form .three-col  { grid-template-columns: 1fr 1fr 1fr; }
        .student-form .one-col    { grid-template-columns: 1fr; }

        /* Form group */
        .student-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .student-form .form-group.full-width { grid-column: 1 / -1; }

        /* Labels */
        .student-form label {
            font-size: 11.5px;
            font-weight: 500;
            color: #94a3b8;
            letter-spacing: 0.01em;
        }
        .student-form label .required { color: #ef4444; margin-left: 2px; }

        /* Inputs, selects, textareas */
        .student-form input,
        .student-form select,
        .student-form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 11px;
            height: 36px;
            font-size: 13px;
            font-family: inherit;
            color: #e2e8f0;
            background: #0d1424;
            border: 1px solid rgba(148,163,184,0.15);
            border-radius: 8px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }
        .student-form select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 34px;
        }
        .student-form input::placeholder,
        .student-form textarea::placeholder { color: #475569; }
        .student-form input:focus,
        .student-form select:focus,
        .student-form textarea:focus {
            border-color: rgba(245,158,11,0.5);
            box-shadow: 0 0 0 3px rgba(245,158,11,0.08);
        }
        .student-form textarea {
            height: 70px;
            resize: vertical;
        }

        /* Section separator */
        .student-form .section-gap {
            margin-top: 10px;
            margin-bottom: 6px;
            border-top: none;
            padding-top: 0;
        }

        /* Footer */
        #addStudentModal .modal-footer,
        #editStudentModal .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 24px;
            border-top: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
            background: #12192b;
        }

        /* Cancel button */
        #addStudentModal .btn-cancel-modal,
        #editStudentModal .btn-cancel-modal {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #cbd5e1;
            background: #1e2a3a;
            border: 1px solid rgba(148,163,184,0.15);
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }
        #addStudentModal .btn-cancel-modal:hover,
        #editStudentModal .btn-cancel-modal:hover {
            background: #273549;
            color: #f1f5f9;
        }

        /* Submit button */
        #addStudentModal .btn-submit-modal,
        #editStudentModal .btn-submit-modal {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            color: #0f172a;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            box-shadow: 0 4px 14px rgba(245,158,11,0.3);
        }
        #addStudentModal .btn-submit-modal:hover,
        #editStudentModal .btn-submit-modal:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }
        #addStudentModal .btn-submit-modal i,
        #editStudentModal .btn-submit-modal i {
            font-size: 12px;
        }

        /* Error message - only takes space when active */
        .student-form .error-message {
            font-size: 11px;
            color: #f87171;
            min-height: 0;
            line-height: 1.2;
            margin-top: 1px;
            display: none;
        }
        .student-form .error-message:not(:empty) {
            display: block;
        }

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
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 28px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
            text-align: center;
        }
        #viewStudentModal .modal-header h2 {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
            text-align: center;
        }
        #viewStudentModal .modal-header h2 i { color: #f59e0b; }
        #viewStudentModal .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
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
        .view-info-item .vi-value.graduated{ background: rgba(56,189,248,0.12); color: #38bdf8; }
        .view-info-item .vi-value.completed   { background: rgba(34,197,94,0.12);  color: #4ade80; }
        .view-info-item .vi-value.in-progress { background: rgba(250,204,21,0.12); color: #facc15; }
        .view-info-item .vi-value.not-taken   { background: rgba(148,163,184,0.1); color: #94a3b8; }

        /* View footer */
        #viewStudentModal .modal-footer {
            display: flex; align-items: center; justify-content: flex-end;
            gap: 12px; padding: 16px 28px;
            border-top: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0; background: #12192b;
        }
        #viewStudentModal .btn-close-view {
            padding: 9px 22px; font-size: 13.5px; font-weight: 600; font-family: inherit;
            color: #cbd5e1; background: #1e2a3a;
            border: 1px solid rgba(148,163,184,0.15); border-radius: 8px;
            cursor: pointer; transition: background 0.2s;
        }
        #viewStudentModal .btn-close-view:hover { background: #273549; color: #f1f5f9; }
        #viewStudentModal .btn-edit-view {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 22px; font-size: 13.5px; font-weight: 700; font-family: inherit;
            color: #0f172a; background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none; border-radius: 8px; cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            box-shadow: 0 4px 14px rgba(62, 11, 245, 0.3);
        }
        #viewStudentModal .btn-edit-view:hover { opacity: 0.92; transform: translateY(-1px); }

        @media (max-width: 600px) {
            .view-info-grid { grid-template-columns: 1fr; }
            .view-profile-banner { flex-wrap: wrap; }
        }

        /* Responsive */
        @media (max-width: 700px) {
            #addStudentModal .modal-content,
            #editStudentModal .modal-content { border-radius: 12px; }
            #addStudentModal .modal-header,
            #editStudentModal .modal-header,
            #addStudentModal .modal-body,
            #editStudentModal .modal-body,
            #addStudentModal .modal-footer,
            #editStudentModal .modal-footer { padding-left: 16px; padding-right: 16px; }
            .student-form .names-row,
            .student-form .two-col,
            .student-form .three-col { grid-template-columns: 1fr; }
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
                    <h1>Manage Students</h1>
                </div>
                <?php 
                // Get staff name & notifications
                $userName = get_staff_name();
                $staffId = get_staff_id();
                $userType = is_counselor() ? 'counselor' : 'admin';
                
                $notifications = [];
                $unreadCount = 0;
                $adminProfilePic = null;
                
                if (!is_counselor() && $staffId) {
                    // Get admin profile picture
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
                
                if ($staffId) {
                    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
                    if ($countStmt) {
                        $countStmt->bind_param('is', $staffId, $userType);
                        $countStmt->execute();
                        $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
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

            <!-- Students Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-actions">
                        <!-- Top Toolbar Row: Search on Left, Summary + CTA on Right -->
                        <div class="toolbar-top-row">
                            <div class="search-box-wrapper">
                                <div class="search-box">
                                    <i class="fa-solid fa-search"></i>
                                    <input type="text" id="searchInput" placeholder="Search by name or school ID...">
                                </div>
                                <button class="btn-search" id="searchBtn" type="button">
                                    <i class="fa-solid fa-search"></i>
                                    Search
                                </button>
                            </div>

                            <div class="header-right-group">
                                <div class="total-students-badge" id="totalStudentsBadge" title="Total active students">
                                    <i class="fa-solid fa-users badge-icon"></i>
                                    <span class="total-badge-label">Total Students:</span>
                                    <span class="total-badge-num" id="totalStudentCount"><?php echo $totalStudents; ?></span>
                                </div>
                                <button class="btn-primary" id="addStudentBtn" type="button">
                                    <i class="fa-solid fa-plus"></i>
                                    Add Student
                                </button>
                            </div>
                        </div>

                        <!-- Filter Bar Row: Dedicated, Clean Multi-Filter Strip -->
                        <div class="filter-bar-row">
                            <div class="filter-group-header">
                                <i class="fa-solid fa-filter"></i>
                                <span>Filters:</span>
                            </div>
                            <div class="filter-dropdowns">
                                <select id="filterStatus" class="filter-select" title="Filter by Account Status">
                                    <option value="">All Account Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                    <option value="graduated">Graduated</option>
                                    <option value="pending">Pending</option>
                                </select>
                                <select id="filterAssessment" class="filter-select" title="Filter by Assessment Status">
                                    <option value="">All Assessment Status</option>
                                    <option value="completed">Completed</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="not-taken">Not Taken</option>
                                    <option value="abandoned">Abandoned</option>
                                </select>
                                <select id="filterGrade" class="filter-select" title="Filter by Grade Level">
                                    <option value="">All Grades</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                                <select id="filterStrand" class="filter-select" title="Filter by Strand">
                                    <option value="">All Strands</option>
                                    <?php foreach ($strands as $strand): ?>
                                        <option value="<?php echo strtolower($strand['code']); ?>">
                                            <?php echo htmlspecialchars($strand['code']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn-clear" id="clearFilter" type="button" title="Reset all filters">
                                    <i class="fa-solid fa-rotate-left"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="table-section">
                    <div class="table-container">
                        <table class="data-table students-table">
                            <thead>
                                <tr>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Strand</th>
                                    <th>Grade Level</th>
                                    <th>Assessment Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): 
                                    $fullName = getStudentDisplayName($student);

                                    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                    $strandCode = strtolower($student['strand_code'] ?? 'none');
                                    $strandName = $student['strand_name'] ?? 'N/A';
                                    $gradeDisplay = $student['grade_level'] ? $student['grade_level'] : 'N/A';
                                    $gradeNum = 0;
                                    if (!empty($student['grade_level'])) {
                                        $gradeNum = (strpos($student['grade_level'], '11') !== false) ? 11 : ((strpos($student['grade_level'], '12') !== false) ? 12 : 0);
                                    }
                                    
                                    // Assessment status
                                    $latestStatus = $student['latest_assessment_status'] ?? '';
                                    
                                    if ($latestStatus === 'in_progress') {
                                        $assessmentStatusClass = 'in-progress';
                                        $assessmentStatusText = 'Ongoing';
                                    } elseif ($latestStatus === 'completed') {
                                        $assessmentStatusClass = 'completed';
                                        $assessmentStatusText = 'Completed';
                                    } elseif ($latestStatus === 'abandoned') {
                                        $assessmentStatusClass = 'abandoned';
                                        $assessmentStatusText = 'Abandoned';
                                    } else {
                                        $assessmentStatusClass = 'not-taken';
                                        $assessmentStatusText = 'Not Taken';
                                    }

                                    $accountStatus = strtolower($student['status'] ?? 'pending');
                                    $accountStatusLabel = ucfirst($accountStatus);
                                ?>
                                <?php 
                                    // Calculate profile picture
                                    $profilePicture = null;
                                    if (!empty($student['profile_picture']) && file_exists(__DIR__ . '/' . $student['profile_picture'])) {
                                        $profilePicture = $student['profile_picture'];
                                    }
                                ?>
                                <tr data-id="<?php echo $student['id']; ?>" data-status="<?php echo htmlspecialchars($accountStatus); ?>" data-email="<?php echo htmlspecialchars($student['email']); ?>" data-student-id="<?php echo htmlspecialchars($student['student_id']); ?>" data-school-year="<?php echo htmlspecialchars($student['school_year'] ?? 'Not Assigned'); ?>" data-school-name="<?php echo htmlspecialchars(getSystemConfig('school_name') ?? getSystemConfig('system_name') ?? 'Not Assigned'); ?>" data-strand="<?php echo $strandCode; ?>" data-grade="<?php echo $gradeNum; ?>" data-first-name="<?php echo htmlspecialchars($student['first_name']); ?>" data-middle-name="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>" data-last-name="<?php echo htmlspecialchars($student['last_name']); ?>" data-suffix="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>" data-full-name="<?php echo $fullName; ?>" data-initials="<?php echo htmlspecialchars($initials); ?>" data-gender="<?php echo htmlspecialchars(ucfirst($student['gender'] ?? '')); ?>" data-address="<?php echo htmlspecialchars($student['address'] ?? ''); ?>" data-grade-display="<?php echo htmlspecialchars($gradeDisplay); ?>" data-strand-name="<?php echo htmlspecialchars(strtoupper($strandCode)); ?>" data-strand-code="<?php echo htmlspecialchars($strandCode); ?>" data-assessment-status="<?php echo htmlspecialchars($assessmentStatusText); ?>" data-assessment-class="<?php echo htmlspecialchars($assessmentStatusClass); ?>" data-account-status="<?php echo htmlspecialchars($accountStatusLabel); ?>" data-created="<?php echo htmlspecialchars(!empty($student['created_at']) ? date('F d, Y', strtotime($student['created_at'])) : ''); ?>" data-profile-picture="<?php echo htmlspecialchars($profilePicture ?? ''); ?>">
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar"><?php echo $initials; ?></div>
                                            <span class="student-name"><?php echo $fullName; ?></span>
                                        </div>
                                    </td>
                                    <td><span class="email-cell" title="<?php echo htmlspecialchars($student['email']); ?>"><?php echo htmlspecialchars($student['email']); ?></span></td>
                                    <td><span class="strand-badge <?php echo $strandCode; ?>"><?php echo htmlspecialchars(strtoupper($strandCode)); ?></span></td>
                                    <td><?php echo $gradeDisplay; ?></td>
                                    <td><span class="status <?php echo $assessmentStatusClass; ?>"><?php echo $assessmentStatusText; ?></span></td>
                                    <td><span class="account-status <?php echo htmlspecialchars($accountStatus); ?>"><?php echo htmlspecialchars($accountStatusLabel); ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action view" data-id="<?php echo $student['id']; ?>" title="View">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <button class="btn-action edit" data-id="<?php echo $student['id']; ?>" title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <button class="btn-action delete" data-id="<?php echo $student['id']; ?>" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem;">No students found. Add your first student!</td>
                                </tr>
                                <?php endif; ?>
                                <tr id="noFilterResults" style="display: none;">
                                    <td colspan="7" style="text-align: center; padding: 2.5rem 1rem; color: #94a3b8;">
                                        <i class="fa-solid fa-filter-circle-xmark" style="font-size: 1.8rem; margin-bottom: 0.6rem; display: block; color: #64748b;"></i>
                                        No students match the selected filter criteria.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Add Student Modal -->
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
                            <input type="text" id="address" name="address" placeholder="Enter complete address">
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="form-section-title section-gap">
                        <i class="fa-solid fa-graduation-cap"></i>
                        Academic Information
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
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="strand">Strand <span class="required">*</span></label>
                            <select id="strand" name="strand" required data-base-option="true">
                                <option value="">Select strand</option>
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

    <!-- Edit Student Modal -->
    <div class="modal" id="editStudentModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-pen"></i> Edit Student</h2>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editStudentForm" class="student-form">
                    <input type="hidden" id="editStudentId" name="studentId">

                    <!-- Personal Information -->
                    <div class="form-section-title">
                        <i class="fa-solid fa-circle-user"></i>
                        Personal Information
                    </div>

                    <div class="form-row names-row">
                        <div class="form-group">
                            <label for="editFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editFirstName" name="firstName" placeholder="Enter first name" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editMiddleName">Middle Name</label>
                            <input type="text" id="editMiddleName" name="middleName" placeholder="Enter middle name">
                        </div>
                        <div class="form-group">
                            <label for="editLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editLastName" name="lastName" placeholder="Enter last name" required>
                            <span class="error-message"></span>
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
                            <input type="email" id="editEmail" name="email" placeholder="Enter email address" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editGender">Gender <span class="required">*</span></label>
                            <select id="editGender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editAddress">Address <span style="font-size: 0.85em; font-weight: normal; color: #94a3b8;">(Optional)</span></label>
                            <input type="text" id="editAddress" name="address" placeholder="Enter complete address">
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="form-section-title section-gap">
                        <i class="fa-solid fa-graduation-cap"></i>
                        Academic Information
                    </div>

                    <div class="form-row three-col">
                        <div class="form-group">
                            <label for="editSchoolId">Student ID <span class="required">*</span></label>
                            <input type="tel" id="editSchoolId" name="schoolId" maxlength="12" placeholder="Enter student ID" pattern="[0-9]*" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,this.maxLength)" required>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editGradeLevel">Grade Level <span class="required">*</span></label>
                            <select id="editGradeLevel" name="gradeLevel" required>
                                <option value="">Select grade level</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editStrand">Strand <span class="required">*</span></label>
                            <select id="editStrand" name="strand" required data-base-option="true">
                                <option value="">Select strand</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                    </div>

                    <div class="form-row two-col">
                        <div class="form-group">
                            <label for="editStatus">Account Status <span class="required">*</span></label>
                            <select id="editStatus" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="graduated">Graduated</option>
                            </select>
                            <span class="error-message"></span>
                        </div>
                        <div class="form-group">
                            <label for="editNewPassword">New Password</label>
                            <div style="position: relative;">
                                <input type="password" id="editNewPassword" name="newPassword" placeholder="Leave blank to keep current" style="padding-right: 40px;">
                                <span class="toggle-password" id="toggleEditPassword"><i class="fa-solid fa-eye"></i></span>
                            </div>
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

                <!-- Avatar Banner -->
                <div class="view-profile-banner">
                    <div class="view-avatar" id="viewAvatar">--</div>
                    <div class="view-banner-info">
                        <h3 id="viewFullName">-</h3>
                        <div class="view-banner-meta">
                            <span class="view-id">ID: <span id="viewStudentId">-</span></span>
                            <span class="view-strand-badge" id="viewStrandBadge">-</span>
                        </div>
                    </div>
                </div>

                <!-- Sections -->
                <div class="view-sections">

                    <!-- Personal Information -->
                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-circle-user"></i> Personal Information
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">Full Name</span>
                                <span class="vi-value" id="viewName">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Gender</span>
                                <span class="vi-value" id="viewGender">-</span>
                            </div>


                        </div>
                    </div>

                    <hr class="view-section-divider">

                    <!-- Academic Information -->
                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-graduation-cap"></i> Academic Information
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">School Year</span>
                                <span class="vi-value" id="viewSchoolYear">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Student ID</span>
                                <span class="vi-value" id="viewSchoolId">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Grade Level</span>
                                <span class="vi-value" id="viewGrade">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Strand</span>
                                <span class="vi-value" id="viewStrand">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">School</span>
                                <span class="vi-value" id="viewSchool">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Assessment Status</span>
                                <span class="vi-value" id="viewAssessmentStatus">-</span>
                            </div>
                        </div>
                    </div>

                    <hr class="view-section-divider">

                    <!-- Contact & Account -->
                    <div class="view-section">
                        <div class="view-section-title">
                            <i class="fa-solid fa-address-card"></i> Contact & Account
                        </div>
                        <div class="view-info-grid">
                            <div class="view-info-item">
                                <span class="vi-label">Email</span>
                                <span class="vi-value" id="viewEmail">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Account Status</span>
                                <span class="vi-value status-badge" id="viewAccountStatus">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Address</span>
                                <span class="vi-value" id="viewAddress">-</span>
                            </div>
                            <div class="view-info-item">
                                <span class="vi-label">Created Date</span>
                                <span class="vi-value" id="viewCreatedDate">-</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-close-view" id="closeView">Close</button>
                <button type="button" class="btn-edit-view" id="viewEditBtn">
                    <i class="fa-solid fa-pen"></i> Edit Student
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-exclamation-triangle"></i> Confirm Delete</h2>
                <button class="modal-close" id="closeDeleteModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm">
                    <div class="delete-icon">
                        <i class="fa-solid fa-trash-alt"></i>
                    </div>
                    <p class="delete-message">Are you sure you want to delete this student?</p>
                    <p class="delete-warning">This action cannot be undone.</p>
                    <div class="delete-student-info">
                        <span class="student-name">Juan Dela Cruz</span>
                        <span class="student-email">juan.delacruz@email.com</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete">Delete Student</button>
            </div>
        </div>
    </div>

    <!-- Status Modal -->
    <div class="modal" id="statusModal" style="z-index: 10000;">
        <div class="modal-overlay"></div>
        <div class="modal-content" style="max-width: 400px; text-align: center; border-radius: 12px; padding: 20px;">
            <div class="modal-body" style="padding: 1.5rem 1rem;">
                <div id="statusIcon" style="font-size: 3.5rem; margin-bottom: 1.2rem;"></div>
                <h2 id="statusTitle" style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;"></h2>
                <p id="statusMessage" style="color: #cbd5e1; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5; white-space: pre-wrap;"></p>
                <button type="button" class="btn-primary" id="statusOkBtn" style="width: 100%; justify-content: center; padding: 0.75rem;">OK</button>
            </div>
        </div>
    </div>

    <script src="admin.js"></script>
    <script>
        function showStatusModal(title, message, isSuccess, callback = null) {
            const modal = document.getElementById('statusModal');
            const icon = document.getElementById('statusIcon');
            const titleEl = document.getElementById('statusTitle');
            const msgEl = document.getElementById('statusMessage');
            const okBtn = document.getElementById('statusOkBtn');

            if (isSuccess) {
                icon.innerHTML = '<i class="fa-solid fa-check-circle" style="color: #10b981;"></i>';
            } else {
                icon.innerHTML = '<i class="fa-solid fa-times-circle" style="color: #ef4444;"></i>';
            }

            titleEl.textContent = title;
            msgEl.textContent = message;
            modal.classList.add('active');

            const handleClose = () => {
                modal.classList.remove('active');
                okBtn.removeEventListener('click', handleClose);
                if (callback) callback();
            };

            okBtn.addEventListener('click', handleClose);
        }

        document.addEventListener('DOMContentLoaded', function() {
            function setupGradeStrandFilter(gradeSelectId, strandSelectId) {
                const gradeSelect = document.getElementById(gradeSelectId);
                const strandSelect = document.getElementById(strandSelectId);
                if (!gradeSelect || !strandSelect) return;

                function loadStrands() {
                    const selectedGrade = gradeSelect.value;
                    
                    if (selectedGrade === '' || selectedGrade === '0') {
                        strandSelect.innerHTML = '<option value="">Select Grade First</option>';
                        strandSelect.value = '';
                        return;
                    }

                    // Fetch strands from server
                    const formData = new FormData();
                    formData.append('action', 'get_strands_by_grade');
                    formData.append('gradeLevel', selectedGrade);

                    console.log('Sending grade:', selectedGrade);

                    fetch('manage_students.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error('HTTP error, status: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Strands response:', data);
                        if (data.success && data.strands) {
                            // Clear existing options except the placeholder
                            strandSelect.innerHTML = '<option value="">Select Strand</option>';

                            // Add fetched strands as options
                            data.strands.forEach(strand => {
                                const option = document.createElement('option');
                                option.value = strand.code;
                                option.textContent = strand.code;
                                strandSelect.appendChild(option);
                            });

                            strandSelect.value = '';
                        } else {
                            console.error('Invalid response data:', data);
                            strandSelect.innerHTML = '<option value="">No strands available</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading strands:', error);
                        strandSelect.innerHTML = '<option value="">Error loading strands</option>';
                    });
                }

                gradeSelect.addEventListener('change', loadStrands);
            }

            setupGradeStrandFilter('gradeLevel', 'strand');
            setupGradeStrandFilter('editGradeLevel', 'editStrand');

            // Filter functionality for student list
            const searchInput = document.getElementById('searchInput');
            const searchBtn = document.getElementById('searchBtn');
            const filterStatus = document.getElementById('filterStatus');
            const filterAssessment = document.getElementById('filterAssessment');
            const filterGrade = document.getElementById('filterGrade');
            const filterStrand = document.getElementById('filterStrand');
            const clearFilter = document.getElementById('clearFilter');
            const studentRows = document.querySelectorAll('.students-table tbody tr');

            // Function to load strands based on selected grade filter
            function loadFilterStrands() {
                const selectedGrade = filterGrade?.value || '';
                
                if (selectedGrade === '') {
                    // Show all strands when "All Grades" is selected
                    const formData = new FormData();
                    formData.append('action', 'get_all_strands');

                    fetch('manage_students.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.strands) {
                            filterStrand.innerHTML = '<option value="">All Strands</option>';
                            data.strands.forEach(strand => {
                                const option = document.createElement('option');
                                option.value = strand.code.toLowerCase();
                                option.textContent = strand.code;
                                filterStrand.appendChild(option);
                            });
                            filterStrand.value = '';
                            applyFilters();
                        }
                    })
                    .catch(error => console.error('Error loading strands:', error));
                } else {
                    // Load strands for selected grade
                    const formData = new FormData();
                    formData.append('action', 'get_strands_by_grade');
                    formData.append('gradeLevel', selectedGrade);

                    fetch('manage_students.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.strands) {
                            filterStrand.innerHTML = '<option value="">All Strands</option>';
                            data.strands.forEach(strand => {
                                const option = document.createElement('option');
                                option.value = strand.code.toLowerCase();
                                option.textContent = strand.code;
                                filterStrand.appendChild(option);
                            });
                            filterStrand.value = '';
                            applyFilters();
                        }
                    })
                    .catch(error => console.error('Error loading strands:', error));
                }
            }

            function applyFilters() {
                const searchTerm = (searchInput?.value || '').toLowerCase().trim();
                const statusValue = (filterStatus?.value || '').toLowerCase();
                const assessmentValue = (filterAssessment?.value || '').toLowerCase();
                const gradeValue = filterGrade?.value || '';
                const strandValue = (filterStrand?.value || '').toLowerCase();

                const rows = document.querySelectorAll('.students-table tbody tr[data-id]');
                let visibleCount = 0;
                rows.forEach(row => {
                    // Use data attributes which are always populated
                    const fullName = (row.getAttribute('data-full-name') || '').toLowerCase();
                    const email = (row.getAttribute('data-email') || '').toLowerCase();
                    const schoolId = (row.getAttribute('data-student-id') || '').toLowerCase();
                    const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
                    const rowGrade = row.getAttribute('data-grade') || '';
                    const rowStrand = row.getAttribute('data-strand') || '';
                    const rowAssessClass = (row.getAttribute('data-assessment-class') || '').toLowerCase();
                    const rowAssessStatus = (row.getAttribute('data-assessment-status') || '').toLowerCase();

                    // Search matches if search term is empty OR if any field contains the search term
                    const matchesSearch = !searchTerm
                        || fullName.includes(searchTerm)
                        || email.includes(searchTerm)
                        || schoolId.includes(searchTerm);
                    const matchesStatus = !statusValue || rowStatus === statusValue;
                    
                    let matchesAssessment = true;
                    if (assessmentValue) {
                        if (assessmentValue === 'ongoing' || assessmentValue === 'in-progress') {
                            matchesAssessment = (rowAssessClass === 'in-progress' || rowAssessStatus === 'ongoing');
                        } else {
                            matchesAssessment = (rowAssessClass === assessmentValue || rowAssessStatus === assessmentValue);
                        }
                    }

                    const matchesGrade = !gradeValue || rowGrade === gradeValue;
                    const matchesStrand = !strandValue || rowStrand.includes(strandValue);

                    const isVisible = matchesSearch && matchesStatus && matchesAssessment && matchesGrade && matchesStrand;
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });

                const countEl = document.getElementById('totalStudentCount');
                if (countEl) {
                    countEl.textContent = visibleCount;
                }

                const noResultsRow = document.getElementById('noFilterResults');
                if (noResultsRow) {
                    noResultsRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
                }
            }

            // Event listeners
            if (searchBtn) searchBtn.addEventListener('click', applyFilters);
            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
            }
            if (filterStatus) filterStatus.addEventListener('change', applyFilters);
            if (filterAssessment) filterAssessment.addEventListener('change', applyFilters);
            if (filterGrade) filterGrade.addEventListener('change', function() {
                loadFilterStrands();
            });
            if (filterStrand) filterStrand.addEventListener('change', applyFilters);

            // Load initial strands on page load
            loadFilterStrands();

            if (clearFilter) {
                clearFilter.addEventListener('click', function() {
                    if (searchInput) searchInput.value = '';
                    if (filterStatus) filterStatus.value = '';
                    if (filterAssessment) filterAssessment.value = '';
                    if (filterGrade) filterGrade.value = '';
                    if (filterStrand) filterStrand.value = '';
                    loadFilterStrands();
                    applyFilters();
                });
            }
        });
    </script>
    <script>
        // -- View Student Modal ------------------------------------------------
        (function() {
            const toggleEditPwd = document.getElementById('toggleEditPassword');
            const editPwdInput = document.getElementById('editNewPassword');
            if (toggleEditPwd && editPwdInput) {
                toggleEditPwd.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    if (editPwdInput.type === 'password') {
                        editPwdInput.type = 'text';
                        icon.className = 'fa-solid fa-eye-slash';
                    } else {
                        editPwdInput.type = 'password';
                        icon.className = 'fa-solid fa-eye';
                    }
                });
            }

            const viewModal   = document.getElementById('viewStudentModal');
            const editModal   = document.getElementById('editStudentModal');
            const deleteModal = document.getElementById('deleteModal');
            const addModal    = document.getElementById('addStudentModal');

            let currentViewId = null;

            function openModal(el)  { if (el) el.classList.add('active'); }
            function closeModal(el) { if (el) el.classList.remove('active'); }

            // Open view modal and populate with the clicked row's data
            document.querySelectorAll('.btn-action.view').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const row = this.closest('tr');
                    currentViewId = row.dataset.id;

                    const fullName      = row.dataset.fullName        || '-';
                    const firstName     = row.dataset.firstName       || '';
                    const middleName    = row.dataset.middleName      || '';
                    const lastName      = row.dataset.lastName        || '';
                    const suffix        = row.dataset.suffix          || '';
                    const initials      = row.dataset.initials        || '?';
                    const studentId     = row.dataset.studentId       || '-';
                    const strandCode    = row.dataset.strandCode      || '';
                    const strandName    = row.dataset.strandName      || '-';
                    const gradeDisplay  = row.dataset.gradeDisplay    || '-';
                    const gender        = row.dataset.gender          || '-';


                    const email         = row.dataset.email           || '-';
                    const address       = row.dataset.address         || 'Not set';
                    const assessText    = row.dataset.assessmentStatus|| '-';
                    const assessClass   = row.dataset.assessmentClass || '';
                    const accountStatus = row.dataset.accountStatus   || '-';
                    const accountClass  = row.dataset.status          || '';
                    const createdDate   = row.dataset.created         || '-';
                    const profilePicture = row.dataset.profilePicture || '';
                    const schoolYear    = row.dataset.schoolYear      || '-';
                    const schoolName    = row.dataset.schoolName      || '-';

                    // Construct full name with full middle name for the details view
                    const nameParts = [];
                    if (firstName) nameParts.push(firstName);
                    if (middleName) nameParts.push(middleName);
                    if (lastName) nameParts.push(lastName);
                    let fullMiddleName = nameParts.join(' ');
                    if (suffix) fullMiddleName += ' ' + suffix;

                    // Avatar banner
                    const viewAvatar = document.getElementById('viewAvatar');
                    if (profilePicture) {
                        viewAvatar.innerHTML = '<img src="' + profilePicture.replace(/"/g, '&quot;') + '" alt="Student Avatar">';
                    } else {
                        viewAvatar.textContent = initials;
                    }
                    document.getElementById('viewFullName').textContent  = fullMiddleName || fullName;
                    document.getElementById('viewStudentId').textContent = studentId;

                    const badge = document.getElementById('viewStrandBadge');
                    badge.textContent = strandName;
                    badge.className   = 'view-strand-badge';

                    // Personal
                    document.getElementById('viewName').textContent      = fullMiddleName || fullName;

                    document.getElementById('viewGender').textContent    = gender;


                    // Academic
                    document.getElementById('viewSchoolYear').textContent = schoolYear;
                    document.getElementById('viewSchoolId').textContent = studentId;
                    document.getElementById('viewGrade').textContent    = gradeDisplay;
                    document.getElementById('viewStrand').textContent   = strandName;
                    document.getElementById('viewSchool').textContent     = schoolName;

                    const aStatus = document.getElementById('viewAssessmentStatus');
                    aStatus.textContent = assessText;
                    aStatus.className   = 'vi-value';

                    // Contact & Account
                    document.getElementById('viewEmail').textContent   = email;
                    document.getElementById('viewAddress').textContent = address;

                    const acStatus = document.getElementById('viewAccountStatus');
                    acStatus.textContent = accountStatus;
                    acStatus.className   = 'vi-value status-badge ' + accountClass.toLowerCase();

                    document.getElementById('viewCreatedDate').textContent = createdDate;

                    openModal(viewModal);
                });
            });

            // Close view modal
            document.getElementById('closeViewModal')?.addEventListener('click', () => closeModal(viewModal));
            document.getElementById('closeView')?.addEventListener('click',      () => closeModal(viewModal));
            viewModal?.querySelector('.modal-overlay')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) closeModal(viewModal); });

            // "Edit Student" button inside view modal -> open edit modal for same student
            document.getElementById('viewEditBtn')?.addEventListener('click', function() {
                closeModal(viewModal);
                const btn = document.querySelector('.btn-action.edit[data-id="' + currentViewId + '"]');
                if (btn) btn.click();
            });

            // Add student button
            document.getElementById('addStudentBtn')?.addEventListener('click', () => openModal(addModal));
            document.getElementById('closeAddModal')?.addEventListener('click', () => closeModal(addModal));
            document.getElementById('cancelAdd')?.addEventListener('click',     () => closeModal(addModal));
            addModal?.querySelector('.modal-overlay')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) closeModal(addModal); });
        })();
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
            document.getElementById('notificationDropdown').style.display = 'none';
        });

        // Prevent dropdown close when clicking inside
        document.getElementById('notificationDropdown').addEventListener('click', function(e) {
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
                body: 'action=mark_all_read&caller_type=admin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    const badge = document.getElementById('notificationBadge'); if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
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
                        body: 'action=mark_read&id=' + notifId + '&caller_type=admin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.classList.remove('unread');
                            const badge = document.getElementById('notificationBadge');
                            const currentCount = parseInt(badge.textContent);
                            if (currentCount > 0) {
                                badge.textContent = currentCount - 1;
                            }
                        }
                    });
                }
            });
        });
    </script>
    <script>
        // -- Add / Edit / Delete Student Form Handlers ----------------------
        (function() {
            const addModal    = document.getElementById('addStudentModal');
            const editModal   = document.getElementById('editStudentModal');
            const deleteModal = document.getElementById('deleteModal');

            function openModal(el)  { if (el) el.classList.add('active'); }
            function closeModal(el) { if (el) el.classList.remove('active'); }

            // -- AUTO-POPULATE ADD STUDENT ON ID BLUR ----------------------
            const addSchoolIdInput = document.getElementById('schoolId');
            if (addSchoolIdInput) {
                addSchoolIdInput.addEventListener('blur', async function() {
                    const val = this.value.trim();
                    if (val.length >= 4) {
                        const fd = new FormData();
                        fd.append('student_id', val);
                        try {
                            const res = await fetch('check_student_id.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.exists && data.grade_level) {
                                const gradeSelect = document.getElementById('gradeLevel');
                                const gradeVal = data.grade_level.includes('11') ? '11' : (data.grade_level.includes('12') ? '12' : '');
                                if (gradeVal && gradeSelect) {
                                    gradeSelect.value = gradeVal;
                                    gradeSelect.dispatchEvent(new Event('change'));
                                }
                                
                                setTimeout(() => {
                                    const strandSelect = document.getElementById('strand');
                                    if (strandSelect && (data.strand_code || data.strand_id)) {
                                        let optionFound = false;
                                        for (let i = 0; i < strandSelect.options.length; i++) {
                                            const opt = strandSelect.options[i];
                                            if (opt.value == data.strand_id || opt.value == data.strand_code || opt.dataset.code == data.strand_code) {
                                                strandSelect.value = opt.value;
                                                optionFound = true;
                                                break;
                                            }
                                        }
                                        if (!optionFound) {
                                            strandSelect.value = data.strand_id || data.strand_code;
                                        }
                                    }
                                }, 600);
                            }
                        } catch (e) {
                            console.error('Error auto-populating student info', e);
                        }
                    }
                });
            }

            // -- ADD STUDENT ------------------------------------------------
            const addForm = document.getElementById('addStudentForm');
            addForm?.addEventListener('submit', function(e) {
                e.preventDefault();
                const fd = new FormData(addForm);
                fd.append('action', 'add_student');
                const btn = addForm.closest('.modal-content').querySelector('.btn-submit-modal');
                if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }

                fetch('manage_students.php', { method: 'POST', body: fd })
                    .then(async r => {
                        const text = await r.text();
                        try {
                            return JSON.parse(text);
                        } catch (err) {
                            console.error('Server response:', text);
                            throw new Error('Server error: ' + (text.replace(/<[^>]*>?/gm, '').trim().substring(0, 100) || 'Invalid server response'));
                        }
                    })
                    .then(data => {
                        if (data.success) {
                            let msg = 'Student added successfully!';
                            if (data.email_sent) {
                                msg += '\n\nA welcome email with login credentials has been sent to the student.';
                            } else if (data.generated_password) {
                                msg += '\n\nEmail could not be sent. Please share the temporary password manually:\n\n' + data.generated_password;
                            }
                            closeModal(addModal);
                            addForm.reset();
                            showStatusModal('Success', msg, true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to add student.', false);
                        }
                    })
                    .catch(err => showStatusModal('Error', err.message || 'Network error. Please try again.', false))
                    .finally(() => {
                        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Add Student'; }
                    });
            });

            // -- EDIT BUTTON - populate edit modal -------------------------
            document.querySelectorAll('.btn-action.edit').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const row = this.closest('tr');
                    document.getElementById('editStudentId').value    = row.dataset.id;
                    document.getElementById('editFirstName').value    = row.dataset.firstName  || '';
                    document.getElementById('editMiddleName').value   = row.dataset.middleName || '';
                    document.getElementById('editLastName').value     = row.dataset.lastName   || '';
                    document.getElementById('editSuffix').value       = row.dataset.suffix     || '';
                    document.getElementById('editEmail').value        = row.dataset.email      || '';

                    document.getElementById('editGender').value       = (row.dataset.gender    || '').toLowerCase();
                    document.getElementById('editAddress').value      = row.dataset.address    || '';
                    document.getElementById('editStatus').value       = (row.dataset.status    || 'active').toLowerCase();

                    // Grade level
                    const gradeRaw = row.dataset.grade || '';
                    const gradeSelect = document.getElementById('editGradeLevel');
                    gradeSelect.value = gradeRaw; // '11' or '12'



                    // Trigger grade change to load strands, then set strand value
                    const strandCode = row.dataset.strandCode || '';
                    gradeSelect.dispatchEvent(new Event('change'));
                    // Wait for strands to load then set value
                    setTimeout(function() {
                        document.getElementById('editStrand').value = strandCode.toUpperCase() || strandCode;
                        if (!document.getElementById('editStrand').value) {
                            document.getElementById('editStrand').value = strandCode;
                        }
                    }, 600);

                    openModal(editModal);
                });
            });

            document.getElementById('closeEditModal')?.addEventListener('click', () => closeModal(editModal));
            document.getElementById('cancelEdit')?.addEventListener('click',     () => closeModal(editModal));
            editModal?.querySelector('.modal-overlay')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) closeModal(editModal); });

            // -- EDIT FORM SUBMIT ------------------------------------------
            const editForm = document.getElementById('editStudentForm');
            editForm?.addEventListener('submit', function(e) {
                e.preventDefault();
                const fd = new FormData(editForm);
                fd.append('action', 'edit_student');
                fd.append('id', document.getElementById('editStudentId').value);
                const btn = editForm.closest('.modal-content').querySelector('.btn-submit-modal');
                if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

                fetch('manage_students.php', { method: 'POST', body: fd })
                    .then(async r => {
                        const text = await r.text();
                        try {
                            return JSON.parse(text);
                        } catch (err) {
                            console.error('Server response:', text);
                            throw new Error('Server error: ' + (text.replace(/<[^>]*>?/gm, '').trim().substring(0, 100) || 'Invalid server response'));
                        }
                    })
                    .then(data => {
                        if (data.success) {
                            closeModal(editModal);
                            showStatusModal('Success', 'Student updated successfully!', true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to update student.', false);
                        }
                    })
                    .catch(err => showStatusModal('Error', err.message || 'Network error. Please try again.', false))
                    .finally(() => {
                        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes'; }
                    });
            });

            // -- DELETE ----------------------------------------------------
            let deleteStudentId = null;

            document.querySelectorAll('.btn-action.delete').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const row = this.closest('tr');
                    deleteStudentId = row.dataset.id;
                    const nameEl  = deleteModal?.querySelector('.student-name');
                    const emailEl = deleteModal?.querySelector('.student-email');
                    if (nameEl)  nameEl.textContent  = row.dataset.fullName || 'Student';
                    if (emailEl) emailEl.textContent = row.dataset.email    || '';
                    openModal(deleteModal);
                });
            });

            document.getElementById('closeDeleteModal')?.addEventListener('click', () => closeModal(deleteModal));
            document.getElementById('cancelDelete')?.addEventListener('click',     () => closeModal(deleteModal));
            deleteModal?.querySelector('.modal-overlay')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) closeModal(deleteModal); });

            document.getElementById('confirmDelete')?.addEventListener('click', function() {
                if (!deleteStudentId) return;
                this.disabled = true;
                this.textContent = 'Deleting...';
                const fd = new FormData();
                fd.append('action', 'delete_student');
                fd.append('id', deleteStudentId);

                fetch('manage_students.php', { method: 'POST', body: fd })
                    .then(async r => {
                        const text = await r.text();
                        try {
                            return JSON.parse(text);
                        } catch (err) {
                            console.error('Server response:', text);
                            throw new Error('Server error: ' + (text.replace(/<[^>]*>?/gm, '').trim().substring(0, 100) || 'Invalid server response'));
                        }
                    })
                    .then(data => {
                        if (data.success) {
                            closeModal(deleteModal);
                            showStatusModal('Success', 'Student deleted successfully!', true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to delete student.', false);
                            this.disabled = false;
                            this.textContent = 'Delete Student';
                        }
                    })
                    .catch(err => {
                        showStatusModal('Error', err.message || 'Network error. Please try again.', false);
                        this.disabled = false;
                        this.textContent = 'Delete Student';
                    });
            });



        })();
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
