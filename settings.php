<?php
// Settings Page - UI Only
session_start();

// Include database config first (needed by system_config.php)
require_once 'config.php';
require_once 'system_config.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/audit.php';

// Ensure school_name column exists in system_settings (safe to run on every load - no-op if already present)
if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_error) {
    $colCheck = $mysqli->query("SELECT setting_key FROM system_settings WHERE setting_key = 'school_name' LIMIT 1");
    if ($colCheck && $colCheck->num_rows === 0) {
        $mysqli->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('school_name', '')");
    }
}

// Reload system config from database to ensure latest data
if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_error) {
    $result = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            // Try to decode JSON for complex values
            $decoded = json_decode($value, true);
            $systemConfig[$key] = ($decoded !== null && json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
        }
    }
}

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// Check for restore status messages
$restoreSuccess = false;
$restoreError = null;
if (isset($_SESSION['restore_status'])) {
    $restoreSuccess = $_SESSION['restore_status']['success'];
    $restoreError = $_SESSION['restore_status']['error'];
    unset($_SESSION['restore_status']);
}

$systemInfoSaved = false;
$systemInfoError = false;

$schoolYearSaved = false;
$schoolYearError = false;

$csvUploadCount = false;
$csvUploadErrorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_system_info') {
    $name       = trim((string)($_POST['system_name']  ?? ''));
    $schoolName = trim((string)($_POST['school_name']  ?? ''));
    $email      = trim((string)($_POST['email']        ?? ''));
    $contact    = trim((string)($_POST['contact']      ?? ''));
    $address    = trim((string)($_POST['address']      ?? ''));
    $schoolYear = trim((string)($_POST['school_year']  ?? ''));

    if ($name === '' || $email === '' || $contact === '' || $address === '') {
        $systemInfoError = true;
    } else {
        $configUpdates = [
            'name'        => $name,
            'short_name'  => $name,
            'school_name' => $schoolName,
            'email'       => $email,
            'contact'     => $contact,
            'address'     => $address,
            'school_year' => $schoolYear
        ];
        
        // Handle logo upload with strict validation
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowedLogoTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg'
            ];
            $fileType = mime_content_type($_FILES['logo']['tmp_name']);
            if (array_key_exists($fileType, $allowedLogoTypes)) {
                $isValidImage = ($fileType === 'image/svg+xml') || (@getimagesize($_FILES['logo']['tmp_name']) !== false);
                if ($isValidImage) {
                    $uploadDir = __DIR__ . '/uploads/logo/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    if (!file_exists($uploadDir . '.htaccess')) {
                        @file_put_contents($uploadDir . '.htaccess', "<FilesMatch \"\.(php|phtml|php3|php4|php5|php7|phps|pl|py|jsp|asp|htm|html|shtml|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
                    }
                    $fileExt = $allowedLogoTypes[$fileType];
                    $fileName = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                        $configUpdates['logo'] = 'uploads/logo/' . $fileName;
                    }
                }
            }
        }
        
        $oldSchoolYear = $systemConfig['school_year'] ?? '';
        
        $ok = setSystemConfig($configUpdates);

        if ($ok) {
            $systemInfoSaved = true;
            
            // If the selected school year is different from the previously active one,
            // trigger the internal transition seamlessly.
            if ($schoolYear !== '' && $schoolYear !== $oldSchoolYear) {
                $newYearId = 0;
                // Use the loaded systemConfig which has the previous state
                foreach ($systemConfig['school_years'] ?? [] as $sy) {
                    if (($sy['year'] ?? '') === $schoolYear) {
                        $newYearId = (int)($sy['id'] ?? 0);
                        break;
                    }
                }
                
                if ($newYearId > 0) {
                    if (!defined('INTERNAL_TRANSITION_CALL')) define('INTERNAL_TRANSITION_CALL', true);
                    
                    // Backup original POST
                    $originalPost = $_POST;
                    $_POST['new_year_id'] = $newYearId;
                    $_POST['action'] = 'execute';
                    
                    // Run the transition API internally
                    include __DIR__ . '/api/school_year_transition.php';
                    
                    // Restore original POST
                    $_POST = $originalPost;
                    
                    // Update our in-memory config so the page renders the new state
                    $systemConfig['school_year'] = $schoolYear;
                    $systemConfig['school_years'] = array_map(function($sy) use ($newYearId) {
                        if ((int)($sy['id'] ?? 0) === $newYearId) { $sy['status'] = 'current'; }
                        elseif (($sy['status'] ?? '') === 'current') { $sy['status'] = 'archived'; }
                        return $sy;
                    }, $systemConfig['school_years'] ?? []);
                }
            }
        } else {
            $systemInfoError = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_school_year') {
    $newYear = trim((string)($_POST['school_year'] ?? ''));

    if ($newYear === '') {
        $schoolYearError = true;
    } elseif (!preg_match('/^(\d{4})-(\d{4})$/', $newYear, $matches)) {
        $schoolYearError = true;
    } else {
        $year1 = (int)$matches[1];
        $year2 = (int)$matches[2];
        // Enforce range 2024 to 2100, and second year must be exactly year1 + 1
        if ($year1 < 2024 || $year1 > 2099 || $year2 < 2025 || $year2 > 2100 || $year2 - $year1 !== 1) {
            $schoolYearError = true;
        } else {
            $currentYears = $systemConfig['school_years'] ?? [];
            $exists = false;
            $maxId = 0;
            foreach ($currentYears as $sy) {
                if (($sy['year'] ?? '') === $newYear) {
                    $exists = true;
                }
                $id = (int)($sy['id'] ?? 0);
                if ($id > $maxId) {
                    $maxId = $id;
                }
            }

            if ($exists) {
                $schoolYearError = true;
            } else {
                $currentYears[] = [
                    'id' => $maxId + 1,
                    'year' => $newYear,
                    'status' => 'inactive'
                ];

                $ok = setSystemConfig([
                    'school_years' => $currentYears
                ]);

                if ($ok) {
                    // Ensure systemConfig is updated with new data
                    $systemConfig['school_years'] = $currentYears;
                    
                    // Also save it to the school_years relational table as inactive (is_current = 0)
                    if (isset($mysqli)) {
                        $syRow = $mysqli->query("SELECT id FROM school_years WHERE year_label = '" . $mysqli->real_escape_string($newYear) . "' LIMIT 1");
                        if ($syRow && $syRow->num_rows === 0) {
                            $insStmt = $mysqli->prepare("INSERT INTO school_years (year_label, is_current, created_at) VALUES (?, 0, NOW())");
                            if ($insStmt) {
                                $insStmt->bind_param('s', $newYear);
                                $insStmt->execute();
                                $insStmt->close();
                            }
                        }
                    }

                    $schoolYearSaved = true;
                    $_SESSION['school_year_added'] = true;
                    header('Location: settings.php');
                    exit();
                } else {
                    $schoolYearError = true;
                    $_SESSION['school_year_error'] = true;
                    header('Location: settings.php');
                    exit();
                }
            }
        }
    }
}

if (isset($_SESSION['school_year_added'])) {
    $schoolYearSaved = true;
    unset($_SESSION['school_year_added']);
}
if (isset($_SESSION['school_year_error'])) {
    $schoolYearError = true;
    unset($_SESSION['school_year_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_valid_ids') {
    $csvUploadCount = 0;
    $csvUploadErrorMsg = '';
    
    // Find current school year DB ID
    $currentYearLabel = getSystemConfig('school_year');
    $syDbId = 0;
    if ($currentYearLabel !== '') {
        $syLookup = $mysqli->query("SELECT id FROM school_years WHERE year_label = '" . $mysqli->real_escape_string($currentYearLabel) . "' LIMIT 1");
        if ($syLookup && $syLookup->num_rows > 0) {
            $syDbId = (int)$syLookup->fetch_assoc()['id'];
        }
    }
    
    if ($syDbId === 0) {
        $csvUploadErrorMsg = 'No active school year found in the database. Please set a school year as current first.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $csvUploadErrorMsg = 'Please select a valid CSV file to upload.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            $rowNum = 0;
            $inserted = 0;
            $insStmt = $mysqli->prepare("INSERT IGNORE INTO valid_student_ids (student_id, school_year_id, grade_level, strand_code, is_registered, created_at) VALUES (?, ?, 'Grade 11', ?, 0, NOW())");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $rowNum++;
                // Skip header row if it contains 'student'
                if ($rowNum === 1 && stripos(implode(' ', $data), 'student') !== false) {
                    continue;
                }
                
                $studentId = trim((string)($data[0] ?? ''));
                $strandCode = trim((string)($data[1] ?? ''));
                
                if ($studentId !== '') {
                    $insStmt->bind_param('sis', $studentId, $syDbId, $strandCode);
                    $insStmt->execute();
                    if ($insStmt->affected_rows > 0) {
                        $inserted++;
                    }
                }
            }
            fclose($handle);
            $insStmt->close();
            
            $csvUploadCount = $inserted;
            if ($inserted === 0) {
                $csvUploadErrorMsg = 'No new IDs were added. Either the file was empty or all IDs already exist in the database.';
            }
        } else {
            $csvUploadErrorMsg = 'Failed to read the uploaded CSV file.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_current_school_year') {
    // NOTE: The actual transition logic now lives in api/school_year_transition.php
    // This handler is kept only for non-JS fallback (plain form submit).
    // The JS modal calls the API directly via AJAX.
    $id = (int)($_POST['id'] ?? 0);
    $currentYears = $systemConfig['school_years'] ?? [];
    $selectedYear = '';

    foreach ($currentYears as &$sy) {
        if ((int)($sy['id'] ?? 0) === $id) {
            $sy['status'] = 'current';
            $selectedYear = (string)($sy['year'] ?? '');
        } else {
            if (($sy['status'] ?? '') === 'current') {
                $sy['status'] = 'archived';
            }
        }
    }
    unset($sy);

    if ($selectedYear === '') {
        $schoolYearError = true;
    } else {
        // Delegate to the transition API (reuse its logic by including it inline)
        $_POST['new_year_id'] = $id;
        $_POST['action']      = 'execute';
        if (!defined('INTERNAL_TRANSITION_CALL')) define('INTERNAL_TRANSITION_CALL', true);
        ob_start();
        include __DIR__ . '/api/school_year_transition.php';
        $apiRaw = ob_get_clean();
        $apiResult = json_decode($apiRaw, true);

        if (!empty($apiResult['success'])) {
            $systemConfig['school_years'] = array_map(function($sy) use ($id) {
                if ((int)($sy['id'] ?? 0) === $id) { $sy['status'] = 'current'; }
                elseif (($sy['status'] ?? '') === 'current') { $sy['status'] = 'archived'; }
                return $sy;
            }, $systemConfig['school_years'] ?? []);
            $systemConfig['school_year'] = $selectedYear;
            $schoolYearSaved = true;
        } else {
            $schoolYearError = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_school_year') {
    $id = (int)($_POST['id'] ?? 0);
    $currentYears = $systemConfig['school_years'] ?? [];

    $found = false;
    foreach ($currentYears as &$sy) {
        if ((int)($sy['id'] ?? 0) === $id) {
            $sy['status'] = 'archived';
            $found = true;
        }
    }
    unset($sy);

    if (!$found) {
        $schoolYearError = true;
    } else {
        $ok = setSystemConfig([
            'school_years' => $currentYears
        ]);
        if ($ok) {
            // Ensure systemConfig is updated with new data
            $systemConfig['school_years'] = $currentYears;
            $schoolYearSaved = true;
        } else {
            $schoolYearError = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unarchive_school_year') {
    $id = (int)($_POST['id'] ?? 0);
    $currentYears = $systemConfig['school_years'] ?? [];

    $found = false;
    foreach ($currentYears as &$sy) {
        if ((int)($sy['id'] ?? 0) === $id && ($sy['status'] ?? '') === 'archived') {
            $sy['status'] = 'inactive';
            $found = true;
        }
    }
    unset($sy);

    if (!$found) {
        $schoolYearError = true;
    } else {
        $ok = setSystemConfig([
            'school_years' => $currentYears
        ]);
        if ($ok) {
            $systemConfig['school_years'] = $currentYears;
            $schoolYearSaved = true;
        } else {
            $schoolYearError = true;
        }
    }
}

// Database Backup Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup_database') {
    // Check admin is logged in
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
        header('Location: admin_login.php');
        exit;
    }

    // -- RBAC: Super Admin only --
    if (!isSuperAdmin()) {
        header('HTTP/1.1 403 Forbidden');
        echo "Forbidden: Super Admin access required.";
        exit;
    }

    // Require config to get $mysqli connection
    require_once 'config.php';

    // Get database info from config variables
    $host = $db_host;
    $user = $db_user;
    $pass = $db_pass;
    $name = $db_name;

    // Get all tables
    $tables = array();
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }

    // Build SQL content
    $sql = "-- Database Backup for " . getSystemConfig('name') . "\n";
    $sql .= "-- Generated on " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Host: $host\n";
    $sql .= "-- Database: $name\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql .= "START TRANSACTION;\n";
    $sql .= "SET time_zone = \"+00:00\";\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    // Process each table
    foreach ($tables as $table) {
        // Get table structure
        $sql .= "--\n-- Table structure for table `$table`\n--\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $result = $mysqli->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_array();
        $sql .= $row[1] . ";\n\n";

        // Get table data
        $sql .= "--\n-- Dumping data for table `$table`\n--\n";
        $result = $mysqli->query("SELECT * FROM `$table`");
        if ($result->num_rows > 0) {
            $fields = $result->fetch_fields();
            $sql .= "INSERT INTO `$table` (";
            $fieldNames = array();
            foreach ($fields as $field) {
                $fieldNames[] = "`" . $field->name . "`";
            }
            $sql .= implode(", ", $fieldNames) . ") VALUES\n";

            $rows = array();
            while ($data = $result->fetch_assoc()) {
                $values = array();
                foreach ($data as $value) {
                    if (is_null($value)) {
                        $values[] = "NULL";
                    } else {
                        $values[] = "'" . $mysqli->real_escape_string($value) . "'";
                    }
                }
                $rows[] = "(" . implode(", ", $values) . ")";
            }
            $sql .= implode(",\n", $rows) . ";\n\n";
        } else {
            $sql .= "-- No data available for table `$table`\n\n";
        }
    }

    $sql .= "COMMIT;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    $backupTimestamp = date('M j, Y g:i A');
    notify_admin(
        'Backup Successful',
        'Backup Successful - Database backup completed at ' . $backupTimestamp . '.',
        'success',
        'settings.php'
    );

    // Download the file
    $fileName = "backup_" . getSystemConfig('short_name') . "_" . date('Y_m_d_H_i_s') . ".sql";
    
    // Audit log
    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
    log_activity($userId, $userType, 'Database Backup', 'system_settings', null, "Admin performed a database backup", null, $fileName);
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// Database Restore Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_database') {
    // Check admin is logged in
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
        header('Location: admin_login.php');
        exit;
    }

    // -- RBAC: Super Admin only --
    if (!isSuperAdmin()) {
        header('HTTP/1.1 403 Forbidden');
        echo "Forbidden: Super Admin access required.";
        exit;
    }

    require_once 'config.php';

    $restoreSuccess = false;
    $restoreError = null;

    // Check if file was uploaded
    if (!isset($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
        $restoreError = "Error uploading file. Please try again.";
    } else {
        // Validate file type
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $_FILES['restore_file']['tmp_name']);
        $allowedTypes = ['text/plain', 'application/octet-stream', 'application/sql', 'text/x-sql'];
        
        $fileExt = strtolower(pathinfo($_FILES['restore_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($mimeType, $allowedTypes) && $fileExt !== 'sql') {
            $restoreError = "Invalid file type. Please upload a valid SQL file (.sql).";
        } else {
            // Read the file
            $sqlFile = file_get_contents($_FILES['restore_file']['tmp_name']);
            if ($sqlFile === false) {
                $restoreError = "Failed to read the uploaded file.";
            } else {
                // Execute the SQL
                // Split into individual statements (basic, handles semicolons within statements)
                $statements = [];
                $currentStatement = '';
                $delimiter = ';';
                $lines = explode("\n", $sqlFile);
                $inString = false;
                $stringChar = '';
                
                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    
                    // Skip comments
                    if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '#') === 0) {
                        continue;
                    }
                    
                    // Handle DELIMITER statements
                    if (strtoupper(substr($trimmedLine, 0, 10)) === 'DELIMITER ') {
                        $delimiter = substr($trimmedLine, 10);
                        continue;
                    }
                    
                    $i = 0;
                    $len = strlen($line);
                    while ($i < $len) {
                        $char = $line[$i];
                        
                        if ($inString) {
                            $currentStatement .= $char;
                            if ($char === $stringChar && ($i === 0 || $line[$i-1] !== '\\')) {
                                $inString = false;
                            }
                        } else {
                            if ($char === "'" || $char === '"') {
                                $inString = true;
                                $stringChar = $char;
                                $currentStatement .= $char;
                            } else if (substr($line, $i, strlen($delimiter)) === $delimiter) {
                                $statements[] = $currentStatement;
                                $currentStatement = '';
                                $i += strlen($delimiter) - 1;
                            } else {
                                $currentStatement .= $char;
                            }
                        }
                        $i++;
                    }
                    $currentStatement .= "\n";
                }
                
                if (!empty(trim($currentStatement))) {
                    $statements[] = $currentStatement;
                }
                
                // Execute each statement
                $mysqli->query("SET FOREIGN_KEY_CHECKS = 0;");
                $mysqli->begin_transaction();
                try {
                    foreach ($statements as $statement) {
                        $trimmedStmt = trim($statement);
                        if (!empty($trimmedStmt)) {
                            // If it's a CREATE TABLE statement, drop the table first
                            // to support older backups that didn't include DROP TABLE IF EXISTS
                            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $trimmedStmt, $matches)) {
                                $tableName = $matches[1];
                                $mysqli->query("DROP TABLE IF EXISTS `$tableName`");
                            }
                            $mysqli->query($trimmedStmt);
                        }
                    }
                    
                    // Mark all deleted students as active after restore
                    $mysqli->query("UPDATE students SET status = 'active' WHERE status = 'deleted'");
                    
                    // Mark all deleted questions as active after restore
                    $mysqli->query("UPDATE questions_career SET is_active = 1 WHERE is_active = 0");
                    $mysqli->query("UPDATE questions_personality SET is_active = 1 WHERE is_active = 0");
                    $mysqli->query("UPDATE questions_skills SET is_active = 1 WHERE is_active = 0");
                    $mysqli->query("UPDATE questions_strand SET is_active = 1 WHERE is_active = 0");
                    
                    $mysqli->commit();
                    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1;");
                    $restoreSuccess = true;
                    
                    // Audit log
                    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                    $uploadName = $_FILES['restore_file']['name'] ?? 'Unknown file';
                    log_activity($userId, $userType, 'Database Restore', 'system_settings', null, "Admin restored database from backup ({$uploadName})", null, null);

                    notify_admin(
                        'Restore Completed',
                        'Restore Completed - Database has been restored from backup.',
                        'success',
                        'settings.php'
                    );
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $restoreError = "Database error during restore: " . $e->getMessage();
                }
            }
        }
    }

    // Store status in session to show after redirect
    $_SESSION['restore_status'] = [
        'success' => $restoreSuccess,
        'error' => $restoreError
    ];
    
    // Redirect back to settings page
    header('Location: settings.php');
    exit;
}

// Admin Profile Actions
$profileSaved = false;
$profileError = false;
$profileErrorMessage = 'Failed to update profile. Please check your input.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $suffix = trim((string)($_POST['suffix'] ?? ''));
    
    $email = trim((string)($_POST['email'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_new_password'] ?? '';
    
    $adminId = (int)$_SESSION['admin_id'];

    if ($firstName === '' || $lastName === '' || $email === '') {
        $profileError = true;
        $profileErrorMessage = 'First Name, Last Name, and Email are required.';
    } else {
        $passwordValid = true;
        $hashedNewPassword = null;

        if ($newPassword !== '' || $currentPassword !== '') {
            if ($newPassword === '' || $currentPassword === '' || $newPassword !== $confirmPassword) {
                $passwordValid = false;
                $profileError = true;
                $profileErrorMessage = 'Current password is required, and new passwords must match.';
            } else {
                $stmt = $mysqli->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $adminId);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin = $result->fetch_assoc();
                $stmt->close();

                if ($admin && (password_verify($currentPassword, $admin['password']) || $currentPassword === $admin['password'])) {
                    if (password_verify($newPassword, $admin['password']) || $newPassword === $admin['password']) {
                        $passwordValid = false;
                        $profileError = true;
                        $profileErrorMessage = 'This is your current password. Please choose a new password.';
                    } else {
                        $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    }
                } else {
                    $passwordValid = false;
                    $profileError = true;
                    $profileErrorMessage = 'Current password is incorrect.';
                }
            }
        }

        if ($passwordValid) {
            // Handle profile picture upload
            $profilePicture = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/admin/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileName = 'admin_' . $adminId . '_' . time() . '_' . basename($_FILES['profile_picture']['name']);
                $uploadPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadPath)) {
                    $profilePicture = 'uploads/admin/' . $fileName;
                }
            }

            $sql = "UPDATE admins SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, contact = ?";
            $params = [$firstName, $middleName, $lastName, $suffix, $email, $contact];
            $types = "ssssss";

            if ($profilePicture) {
                $sql .= ", profile_picture = ?";
                $params[] = $profilePicture;
                $types .= "s";
            }
            if ($hashedNewPassword) {
                $sql .= ", password = ?";
                $params[] = $hashedNewPassword;
                $types .= "s";
            }

            $sql .= " WHERE id = ?";
            $params[] = $adminId;
            $types .= "i";

            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $nameParts = array_filter([$firstName, $middleName, $lastName]);
                $fullName = implode(' ', $nameParts);
                if (!empty($suffix)) {
                    $fullName .= ' ' . $suffix;
                }
                $_SESSION['admin_name'] = $fullName;
                $_SESSION['admin_email'] = $email;
                $_SESSION['email'] = $email;
                $profileSaved = true;
            } else {
                $profileError = true;
            }
            $stmt->close();
        }
    }
}

// -- Determine current admin's role (used throughout the page) ------------------------
$currentAdminRole = $_SESSION['admin_role'] ?? 'Admin';
$isSuperAdmin = ($currentAdminRole === 'super_admin');

function normalizeAdminEmail($email) {
    return strtolower(trim((string) $email));
}

function normalizeAdminFullName($name) {
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);
    return ucwords(strtolower($name));
}

function normalizeAdminUsername($username) {
    $username = strtolower(trim((string) $username));
    $username = preg_replace('/[^a-z0-9_]/', '_', $username);
    $username = preg_replace('/_+/', '_', $username);
    $username = trim($username, '_');

    return substr($username, 0, 50);
}

function resolveAdminUsername($mysqli, $usernameInput, $email, $fullName) {
    $base = normalizeAdminUsername($usernameInput);

    if ($base === '') {
        $emailLocal = strstr(normalizeAdminEmail($email), '@', true);
        $base = normalizeAdminUsername($emailLocal ?: $fullName);
    }

    if ($base === '') {
        $base = 'admin';
    }

    $candidate = $base;
    $suffix = 1;

    while (true) {
        $check = $mysqli->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        if (!$check) {
            return $candidate;
        }

        $check->bind_param('s', $candidate);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            return $candidate;
        }

        $suffix++;
        $candidate = substr($base, 0, 45) . '_' . $suffix;
    }
}

function handleAddAdmin($mysqli, array $input) {
    $firstName = trim((string)($input['first_name'] ?? ''));
    $middleName = trim((string)($input['middle_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $suffix = trim((string)($input['suffix'] ?? ''));
    $fullNameParts = array_filter([$firstName, $middleName, $lastName], 'strlen');
    $fullName = normalizeAdminFullName(implode(' ', $fullNameParts));
    if (!empty($suffix)) $fullName .= ' ' . $suffix;
    $email = normalizeAdminEmail($input['email'] ?? '');
    $contact = trim((string)($input['contact'] ?? ''));
    $usernameInput = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($firstName === '' || $lastName === '' || $email === '') {
        return ['success' => false, 'message' => 'Full name and email are required.'];
    }

    if (!isValidEmailAddress($email)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $emailCheck = $mysqli->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
    if (!$emailCheck) {
        return ['success' => false, 'message' => 'Database error while checking email.'];
    }

    $emailCheck->bind_param('s', $email);
    $emailCheck->execute();
    if ($emailCheck->get_result()->num_rows > 0) {
        $emailCheck->close();
        return ['success' => false, 'message' => 'An admin with this email already exists.'];
    }
    $emailCheck->close();

    $username = resolveAdminUsername($mysqli, $usernameInput, $email, $fullName);
    
    // Auto-generate password
    $rawPassword = bin2hex(random_bytes(6));
    $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);
    $contactValue = $contact !== '' ? $contact : null;

    $stmt = $mysqli->prepare('INSERT INTO admins (username, password, email, first_name, middle_name, last_name, suffix, contact, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error while adding admin.'];
    }

    // Validate and pick role from input
    $allowedRoles = ['Admin', 'super_admin'];
    $role = isset($input['role']) && in_array(trim($input['role']), $allowedRoles, true)
        ? trim($input['role'])
        : 'Admin';
    $stmt->bind_param('sssssssss', $username, $hashedPassword, $email, $firstName, $middleName, $lastName, $suffix, $contactValue, $role);

    if ($stmt->execute()) {
        $adminId = (int) $mysqli->insert_id;
        $stmt->close();
        
        // Mark account as requiring a forced password change on first login
        $mcpStmt = $mysqli->prepare("UPDATE admins SET must_change_password = 1 WHERE id = ?");
        if ($mcpStmt) {
            $mcpStmt->bind_param('i', $adminId);
            $mcpStmt->execute();
            $mcpStmt->close();
        }

        // Send email
        require_once __DIR__ . '/includes/mailer.php';
        $emailSent = send_admin_created_admin_email([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $email,
            'username' => $username,
            'password' => $rawPassword
        ]);

        return [
            'success' => true,
            'message' => 'Admin added successfully.',
            'id' => $adminId,
            'username' => $username,
            'email_sent' => $emailSent,
            'generated_password' => !$emailSent ? $rawPassword : null,
        ];
    }

    $error = $stmt->error;
    $stmt->close();
    return ['success' => false, 'message' => 'Failed to add admin: ' . $error];
}

// Change Password Action merged into update_profile above
$passwordChanged = false;
$passwordChangeError = false;

// Handle AJAX requests for admins, counselors, and strands
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (strpos($_POST['action'], 'admin') !== false || strpos($_POST['action'], 'counselor') !== false || strpos($_POST['action'], 'strand') !== false)) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    $action = $_POST['action'];

    switch ($action) {
        case 'add_admin':
            // -- RBAC: Super Admin only --
            if (!isSuperAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: Super Admin access required.']);
                exit;
            }
            $result = handleAddAdmin($mysqli, $_POST);
            $response['success'] = $result['success'];
            $response['message'] = $result['message'];
            if (!empty($result['id'])) {
                $response['id'] = $result['id'];
            }
            if (!empty($result['username'])) {
                $response['username'] = $result['username'];
            }
            if (isset($result['email_sent'])) {
                $response['email_sent'] = $result['email_sent'];
            }
            if (!empty($result['generated_password'])) {
                $response['generated_password'] = $result['generated_password'];
            }
            echo json_encode($response);
            exit;

        case 'get_strands_by_grade':
            $gradeLevel = (int)($_POST['gradeLevel'] ?? 0);
            if ($gradeLevel !== 11 && $gradeLevel !== 12) {
                $response['message'] = 'Invalid grade level';
                echo json_encode($response);
                exit;
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
            echo json_encode($response);
            exit;

        case 'get_counselors':
            $result = $mysqli->query("SELECT id, CONCAT(first_name, ' ', IF(middle_name IS NOT NULL AND TRIM(middle_name) != '', CONCAT(UPPER(SUBSTRING(TRIM(middle_name), 1, 1)), '. '), ''), last_name, IF(suffix IS NOT NULL AND suffix != '', CONCAT(' ', suffix), '')) AS name, email FROM counselors WHERE status = 'active' LIMIT 100");
            $counselors = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $counselors[] = $row;
                }
            }
            $response['success'] = true;
            $response['counselors'] = $counselors;
            echo json_encode($response);
            exit;

        case 'add_counselor':
            $firstName = normalizeAdminFullName($_POST['first_name'] ?? '');
            $middleName = normalizeAdminFullName($_POST['middle_name'] ?? '');
            $lastName = normalizeAdminFullName($_POST['last_name'] ?? '');
            $suffix = trim((string)($_POST['suffix'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            
            if ($firstName === '' || $lastName === '' || $email === '') {
                $response['message'] = 'All fields are required';
            } else {
                // Auto-generate a random temporary password (12-char hex)
                $rawPassword = bin2hex(random_bytes(6));
                $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);
                $nameParts = array_filter([$firstName, $middleName, $lastName], 'strlen');
                $name = implode(' ', $nameParts);

                $stmt = $mysqli->prepare("INSERT INTO counselors (first_name, middle_name, last_name, suffix, email, password, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('ssssss', $firstName, $middleName, $lastName, $suffix, $email, $hashedPassword);
                if ($stmt->execute()) {
                    $insertedId = $mysqli->insert_id;
                    
                    // Mark account as requiring a forced password change on first login
                    $mcpStmt = $mysqli->prepare("UPDATE counselors SET must_change_password = 1 WHERE id = ?");
                    if ($mcpStmt) {
                        $mcpStmt->bind_param('i', $insertedId);
                        $mcpStmt->execute();
                        $mcpStmt->close();
                    }

                    // Send email
                    require_once __DIR__ . '/includes/mailer.php';
                    $emailSent = send_admin_created_counselor_email([
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'password' => $rawPassword
                    ]);

                    // Audit log
                    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                    $descriptionText = "Admin added counselor {$name}";
                    log_activity($userId, $userType, 'Added Counselor', 'counselors', $insertedId, $descriptionText, null, json_encode([
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'suffix' => $suffix,
                        'email' => $email,

                        'status' => 'active'
                    ]));

                    $response['success'] = true;
                    $response['message'] = 'Counselor added successfully';
                    $response['id'] = $insertedId;
                    $response['email_sent'] = $emailSent;
                    $response['generated_password'] = $rawPassword;

                    notify_admin(
                        'Counselor Account Created',
                        'Counselor Account Created - ' . $name . ' added.',
                        'success',
                        'settings.php'
                    );
                } else {
                    $response['message'] = 'Failed to add counselor: ' . $stmt->error;
                }
                $stmt->close();
            }
            echo json_encode($response);
            exit;

        case 'get_counselor':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, suffix, email, status FROM counselors WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $counselor = $result->fetch_assoc();
            $stmt->close();
            if ($counselor) {
                $midInitial = !empty($counselor['middle_name']) ? strtoupper(substr(trim($counselor['middle_name']), 0, 1)) . '.' : '';
                $cNameParts = array_filter([$counselor['first_name'], $midInitial, $counselor['last_name']], 'strlen');
                $counselor['name'] = implode(' ', $cNameParts);
                if (!empty($counselor['suffix'])) $counselor['name'] .= ' ' . $counselor['suffix'];
                $response['success'] = true;
                $response['counselor'] = $counselor;
            } else {
                $response['message'] = 'Counselor not found';
            }
            echo json_encode($response);
            exit;

        case 'edit_counselor':
            $id = (int)($_POST['id'] ?? 0);
            $firstName = normalizeAdminFullName($_POST['first_name'] ?? '');
            $middleName = normalizeAdminFullName($_POST['middle_name'] ?? '');
            $lastName = normalizeAdminFullName($_POST['last_name'] ?? '');
            $suffix = trim((string)($_POST['suffix'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            $status = trim((string)($_POST['status'] ?? 'active'));

            if ($id <= 0 || $firstName === '' || $lastName === '' || $email === '') {
                $response['message'] = 'All fields are required';
            } elseif (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
                $response['message'] = 'Invalid account status';
            } else {
                // Fetch complete old counselor row before editing
                $oldCounselor = null;
                $oldFullStmt = $mysqli->prepare('SELECT * FROM counselors WHERE id = ? LIMIT 1');
                if ($oldFullStmt) {
                    $oldFullStmt->bind_param('i', $id);
                    $oldFullStmt->execute();
                    $oldCounselor = $oldFullStmt->get_result()->fetch_assoc();
                    $oldFullStmt->close();
                }

                $stmt = $mysqli->prepare("UPDATE counselors SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, status = ? WHERE id = ?");
                $stmt->bind_param('ssssssi', $firstName, $middleName, $lastName, $suffix, $email, $status, $id);
                if ($stmt->execute()) {
                    // Fetch new counselor row for comparison
                    $newCounselor = null;
                    $newFullStmt = $mysqli->prepare('SELECT * FROM counselors WHERE id = ? LIMIT 1');
                    if ($newFullStmt) {
                        $newFullStmt->bind_param('i', $id);
                        $newFullStmt->execute();
                        $newCounselor = $newFullStmt->get_result()->fetch_assoc();
                        $newFullStmt->close();
                    }

                    // Identify changed fields
                    $oldChanges = [];
                    $newChanges = [];
                    if ($oldCounselor && $newCounselor) {
                        foreach ($newCounselor as $key => $val) {
                            if ($key === 'password') {
                                continue;
                            }
                            if (array_key_exists($key, $oldCounselor) && $oldCounselor[$key] !== $val) {
                                $oldChanges[$key] = $oldCounselor[$key];
                                $newChanges[$key] = $val;
                            }
                        }
                    }

                    // Audit log
                    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                    $counselorFullName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName);
                    $descriptionText = "Admin edited counselor #{$id} ({$counselorFullName})";
                    log_activity(
                        $userId,
                        $userType,
                        'Edited Counselor',
                        'counselors',
                        $id,
                        $descriptionText,
                        !empty($oldChanges) ? json_encode($oldChanges) : null,
                        !empty($newChanges) ? json_encode($newChanges) : null
                    );

                    $response['success'] = true;
                    $response['message'] = 'Counselor updated successfully';

                    if (isset($oldCounselor['status']) && $status === 'active' && $oldCounselor['status'] !== 'active') {
                        notify_counselor(
                            $id,
                            'Account Reactivated',
                            'Your counselor account has been reactivated.',
                            'success',
                            'counselor_dashboard.php'
                        );
                    }
                } else {
                    $response['message'] = 'Failed to update counselor';
                }
                $stmt->close();
            }
            echo json_encode($response);
            exit;

        case 'toggle_admin_status':
            // -- RBAC: Super Admin only --
            if (!isSuperAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: Super Admin access required.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response['message'] = 'Invalid admin ID';
                echo json_encode($response);
                exit;
            }
            if ($id === (int)$_SESSION['admin_id']) {
                $response['message'] = 'You cannot deactivate your own account';
                echo json_encode($response);
                exit;
            }

            $stmt = $mysqli->prepare('SELECT status, role FROM admins WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $adminData = $res->fetch_assoc();
            $stmt->close();

            if ($adminData) {
                // Prevent disabling the last active super_admin
                if ($adminData['role'] === 'super_admin' && $adminData['status'] === 'active') {
                    $saCount = $mysqli->query("SELECT COUNT(*) AS cnt FROM admins WHERE role = 'super_admin' AND status = 'active'");
                    $saRow = $saCount ? $saCount->fetch_assoc() : null;
                    if ($saRow && (int)$saRow['cnt'] <= 1) {
                        $response['message'] = 'Cannot deactivate the last active Super Admin.';
                        echo json_encode($response);
                        exit;
                    }
                }
                $newStatus = ($adminData['status'] === 'active') ? 'inactive' : 'active';
                $updateStmt = $mysqli->prepare('UPDATE admins SET status = ? WHERE id = ?');
                $updateStmt->bind_param('si', $newStatus, $id);
                if ($updateStmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Administrator status updated to ' . $newStatus;
                    $response['newStatus'] = $newStatus;
                } else {
                    $response['message'] = 'Failed to update status';
                }
                $updateStmt->close();
            } else {
                $response['message'] = 'Admin not found';
            }
            echo json_encode($response);
            exit;

        case 'get_admin':
            // -- RBAC: Super Admin only --
            if (!isSuperAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: Super Admin access required.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, suffix, username, email, contact, role, status FROM admins WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            $stmt->close();
            if ($admin) {
                $response['success'] = true;
                $response['admin'] = $admin;
            } else {
                $response['message'] = 'Administrator not found';
            }
            echo json_encode($response);
            exit;

        case 'edit_admin':
            // -- RBAC: Super Admin only --
            if (!isSuperAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: Super Admin access required.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $firstName = normalizeAdminFullName($_POST['first_name'] ?? '');
            $middleName = normalizeAdminFullName($_POST['middle_name'] ?? '');
            $lastName = normalizeAdminFullName($_POST['last_name'] ?? '');
            $suffix = trim((string)($_POST['suffix'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $contact = trim((string)($_POST['contact'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'active'));
            $newRole = trim((string)($_POST['role'] ?? ''));

            if ($id <= 0 || $firstName === '' || $lastName === '' || $email === '' || $username === '') {
                $response['message'] = 'All required fields must be filled';
                echo json_encode($response);
                exit;
            }

            if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
                $response['message'] = 'Invalid account status';
                echo json_encode($response);
                exit;
            }

            // Validate role - only super_admin or Admin allowed
            if ($newRole !== '' && !in_array($newRole, ['super_admin', 'Admin'], true)) {
                $response['message'] = 'Invalid role specified.';
                echo json_encode($response);
                exit;
            }

            if ($id === (int)($_SESSION['admin_id'] ?? 0) && $status !== 'active') {
                $response['message'] = 'You cannot deactivate your own administrator account';
                echo json_encode($response);
                exit;
            }

            // Prevent self-demotion from super_admin
            if ($id === (int)($_SESSION['admin_id'] ?? 0) && $newRole !== '' && $newRole !== 'super_admin') {
                $response['message'] = 'You cannot change your own role.';
                echo json_encode($response);
                exit;
            }

            // Prevent demoting the last super_admin
            if ($newRole === 'Admin') {
                $saCountRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM admins WHERE role = 'super_admin' AND id != " . $id);
                $saCountRow = $saCountRes ? $saCountRes->fetch_assoc() : null;
                if ($saCountRow && (int)$saCountRow['cnt'] === 0) {
                    $response['message'] = 'Cannot demote the last Super Admin.';
                    echo json_encode($response);
                    exit;
                }
            }

            // Check if username/email already exists for another admin
            $checkStmt = $mysqli->prepare('SELECT id FROM admins WHERE (email = ? OR username = ?) AND id != ? LIMIT 1');
            $checkStmt->bind_param('ssi', $email, $username, $id);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $response['message'] = 'Email or Username already exists for another administrator.';
                $checkStmt->close();
                echo json_encode($response);
                exit;
            }
            $checkStmt->close();

            // Build UPDATE with optional role change
            if ($newRole !== '') {
                $updateStmt = $mysqli->prepare("UPDATE admins SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, contact = ?, username = ?, status = ?, role = ? WHERE id = ?");
                $updateStmt->bind_param('sssssssssi', $firstName, $middleName, $lastName, $suffix, $email, $contact, $username, $status, $newRole, $id);
            } else {
                $updateStmt = $mysqli->prepare("UPDATE admins SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, contact = ?, username = ?, status = ? WHERE id = ?");
                $updateStmt->bind_param('ssssssssi', $firstName, $middleName, $lastName, $suffix, $email, $contact, $username, $status, $id);
            }
            if ($updateStmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Administrator updated successfully';
            } else {
                $response['message'] = 'Failed to update administrator';
            }
            $updateStmt->close();
            echo json_encode($response);
            exit;

        case 'delete_admin':
            // -- RBAC: Super Admin only --
            if (!isSuperAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: Super Admin access required.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response['message'] = 'Invalid admin ID';
                echo json_encode($response);
                exit;
            }
            if ($id === (int)($_SESSION['admin_id'] ?? 0)) {
                $response['message'] = 'You cannot delete your own administrator account.';
                echo json_encode($response);
                exit;
            }
            // Prevent deleting last super_admin
            $targetRoleRes = $mysqli->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
            $targetRoleRes->bind_param('i', $id);
            $targetRoleRes->execute();
            $targetAdminRow = $targetRoleRes->get_result()->fetch_assoc();
            $targetRoleRes->close();
            if ($targetAdminRow && $targetAdminRow['role'] === 'super_admin') {
                $saCount2 = $mysqli->query("SELECT COUNT(*) AS cnt FROM admins WHERE role = 'super_admin'");
                $saRow2 = $saCount2 ? $saCount2->fetch_assoc() : null;
                if ($saRow2 && (int)$saRow2['cnt'] <= 1) {
                    $response['message'] = 'Cannot delete the last Super Admin.';
                    echo json_encode($response);
                    exit;
                }
            }
            $delStmt = $mysqli->prepare('DELETE FROM admins WHERE id = ?');
            $delStmt->bind_param('i', $id);
            if ($delStmt->execute() && $delStmt->affected_rows > 0) {
                $response['success'] = true;
                $response['message'] = 'Administrator deleted successfully.';
                // Audit log
                $userId = (int)($_SESSION['admin_id'] ?? 0);
                log_activity($userId, 'admin', 'Deleted Admin', 'admins', $id, 'Super Admin deleted administrator #' . $id, null, null);
            } else {
                $response['message'] = 'Failed to delete administrator or not found.';
            }
            $delStmt->close();
            echo json_encode($response);
            exit;

        case 'delete_counselor':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response['message'] = 'Invalid counselor ID';
            } else {
                // Fetch counselor details before delete/deactivate
                $oldCounselor = null;
                $oldFullStmt = $mysqli->prepare('SELECT * FROM counselors WHERE id = ? LIMIT 1');
                if ($oldFullStmt) {
                    $oldFullStmt->bind_param('i', $id);
                    $oldFullStmt->execute();
                    $oldCounselor = $oldFullStmt->get_result()->fetch_assoc();
                    $oldFullStmt->close();
                }

                $stmt = $mysqli->prepare("UPDATE counselors SET status = 'inactive' WHERE id = ?");
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    // Audit log
                    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                    $counselorFullName = trim(($oldCounselor['first_name'] ?? '') . ' ' . ($oldCounselor['last_name'] ?? ''));
                    $descriptionText = "Admin deactivated counselor {$counselorFullName} (ID: {$id})";
                    log_activity($userId, $userType, 'Deleted Counselor', 'counselors', $id, $descriptionText, json_encode($oldCounselor), null);

                    $response['success'] = true;
                    $response['message'] = 'Counselor deleted successfully';
                } else {
                    $response['message'] = 'Failed to delete counselor';
                }
                $stmt->close();
            }
            echo json_encode($response);
            exit;
    }
}

// Fetch counselors from database
$counselors = [];
$result = $mysqli->query("SELECT id, first_name, middle_name, last_name, email, status FROM counselors ORDER BY status ASC, id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $midInitial = !empty($row['middle_name']) ? strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.' : '';
        $cNameParts = array_filter([$row['first_name'], $midInitial, $row['last_name']], 'strlen');
        $row['name'] = implode(' ', $cNameParts);
        if (!empty($row['suffix'])) {
            $row['name'] .= ' ' . $row['suffix'];
        }
        $counselors[] = $row;
    }
}

// Fetch all operational admins from database for Admin Directory (only loaded for Super Admin, excludes super_admin accounts)
$adminsList = [];
if ($isSuperAdmin) {
    $adminRes = $mysqli->query("SELECT id, username, email, first_name, middle_name, last_name, suffix, contact, role, profile_picture, status FROM admins WHERE role != 'super_admin' ORDER BY id ASC");
    if ($adminRes) {
        while ($row = $adminRes->fetch_assoc()) {
            $midInitial = !empty($row['middle_name']) ? strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.' : '';
            $aNameParts = array_filter([$row['first_name'] ?? '', $midInitial, $row['last_name'] ?? ''], 'strlen');
            $row['name'] = !empty($aNameParts) ? implode(' ', $aNameParts) : ($row['username'] ?? 'Admin');
            if (!empty($row['suffix'])) {
                $row['name'] .= ' ' . $row['suffix'];
            }
            $adminsList[] = $row;
        }
    }
}

// Get current admin info for profile form
$currentAdmin = [];
$adminId = (int)$_SESSION['admin_id'];
$stmt = $mysqli->prepare("SELECT id, username, first_name, middle_name, last_name, suffix, email, contact, profile_picture, role FROM admins WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$result = $stmt->get_result();
$currentAdmin = $result->fetch_assoc() ?? ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix' => '', 'email' => '', 'profile_picture' => null, 'role' => 'Admin'];
$stmt->close();
$profilePicUrl = !empty($currentAdmin['profile_picture']) && file_exists(__DIR__ . '/' . $currentAdmin['profile_picture']) ? $currentAdmin['profile_picture'] : 'assets/images/default-avatar.png';
$adminProfilePic = $currentAdmin['profile_picture'];

// Use system config for system info
$systemInfo = $systemConfig;

// Ensure school_years array exists
if (empty($systemInfo['school_years']) || !is_array($systemInfo['school_years'])) {
    $systemInfo['school_years'] = [];
}

// Ensure school_year is set
if (empty($systemInfo['school_year'])) {
    $systemInfo['school_year'] = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .student-id-card-full {
            grid-column: 1 / -1 !important;
        }
        .student-id-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 1.5rem !important;
        }
        @media (max-width: 860px) {
            .student-id-grid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <?php echo getSystemLogo('logo-icon'); ?>
                    <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
                </div>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            
                        <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="nav-item">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage_students.php" class="nav-item">
                    <i class="fa-solid fa-users"></i>
                    <span>Manage Students</span>
                </a>

                <!-- Assessments Group -->
                <div class="nav-group">
                    <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-clipboard-check group-icon"></i>
                        <span>Assessments</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu">
                        <a href="manage_questions.php" class="nav-subitem">
                            <i class="fa-solid fa-circle-question"></i>
                            Manage Questions
                        </a>
                        <a href="ongoing_assessments.php" class="nav-subitem">
                            <i class="fa-solid fa-spinner"></i>
                            Ongoing Assessments
                        </a>
                        <a href="admin_assessment_results.php" class="nav-subitem">
                            <i class="fa-solid fa-file-circle-check"></i>
                            Assessment History
                        </a>
                        <a href="admin-assessments.php" class="nav-subitem">
                            <i class="fa-solid fa-eye"></i>
                            Assessment Answers
                        </a>
                    </div>
                </div>

                <!-- Career Management Group -->
                <div class="nav-group">
                    <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-briefcase group-icon"></i>
                        <span>Career Management</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu">
                        <a href="manage_clusters.php" class="nav-subitem">
                            <i class="fa-solid fa-layer-group"></i>
                            Manage Career Clusters
                        </a>
                        <a href="manage_courses.php" class="nav-subitem">
                            <i class="fa-solid fa-book-open"></i>
                            Manage Courses
                        </a>
                        <a href="manage_schools.php" class="nav-subitem">
                            <i class="fa-solid fa-school"></i>
                            Manage Schools
                        </a>
                        <a href="manage_jobs.php" class="nav-subitem">
                            <i class="fa-solid fa-hard-hat"></i>
                            Manage Jobs
                        </a>
                    </div>
                </div>

                <a href="reports.php" class="nav-item">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="activity_logs.php" class="nav-item">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Activity Logs</span>
                </a>
                <a href="settings.php" class="nav-item active">
                    <i class="fa-solid fa-gear"></i>
                    <span>Settings</span>
                </a>

                <div class="nav-separator"></div>

                <a href="logout.php?role=admin" class="nav-item logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Settings</h1>
                </div>
                <?php 
                // Get admin name
                $userName = $_SESSION['admin_name'] ?? 'Admin User';
                
                // Get notifications
                $notifications = [];
                $unreadCount = 0;
                $adminId = $_SESSION['admin_id'] ?? null;
                
                if ($adminId) {
                    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
                    $countStmt->bind_param('i', $adminId);
                    $countStmt->execute();
                    $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                    $countStmt->close();
                    
                    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
                    $notifStmt->bind_param('i', $adminId);
                    $notifStmt->execute();
                    $result = $notifStmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $notifications[] = $row;
                    }
                    $notifStmt->close();
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
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php if ($adminProfilePic && file_exists(__DIR__ . '/' . $adminProfilePic)): ?>
                                <img src="<?php echo htmlspecialchars($adminProfilePic); ?>" alt="Admin" class="avatar-img">
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
                
                <!-- Settings Tabs -->
                <div class="settings-tabs">
                    <button class="tab-btn active" data-tab="account">
                        <i class="fa-solid fa-user-shield"></i>
                        Admin Account
                    </button>
                    <button class="tab-btn" data-tab="counselors">
                        <i class="fa-solid fa-user-tie"></i>
                        Counselors
                    </button>
                    <button class="tab-btn" data-tab="system">
                        <i class="fa-solid fa-server"></i>
                        System Info
                    </button>
                    <button class="tab-btn" data-tab="schoolyear">
                        <i class="fa-solid fa-calendar"></i>
                        School Year
                    </button>
                    <?php if ($isSuperAdmin): ?>
                    <button class="tab-btn" data-tab="backup">
                        <i class="fa-solid fa-database"></i>
                        Database Backup
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Admin Account Tab -->
                <div class="tab-content active" id="account-tab">
                    <div class="account-tab-layout">
                        <!-- My Profile & Account Card (Full Width) -->
                        <div class="settings-card admin-profile-card">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-user-gear"></i> My Profile & Account</h3>
                            </div>
                            <div class="card-body">
                                <?php if ($profileSaved): ?>
                                <div class="alert-box success">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <div>
                                        <strong>Profile Updated</strong>
                                        <p>Your profile information and settings have been saved successfully.</p>
                                    </div>
                                </div>
                                <?php elseif ($profileError): ?>
                                <div class="alert-box error">
                                    <i class="fa-solid fa-circle-exclamation"></i>
                                    <div>
                                        <strong>Update Failed</strong>
                                        <p><?php echo htmlspecialchars($profileErrorMessage ?? 'Failed to update profile. Please check your input.'); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <form id="editProfileForm" class="settings-form" method="post" action="settings.php" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <!-- Profile Picture Hero Section -->
                                    <div class="admin-profile-hero">
                                        <div class="profile-hero-left">
                                            <div class="profile-picture-preview" id="profilePreviewContainer" title="Click to upload a new profile photo">
                                                <img id="profilePreview" src="<?php echo htmlspecialchars($profilePicUrl); ?>" alt="Profile Picture">
                                                <div class="profile-picture-overlay" id="uploadOverlay">
                                                    <i class="fa-solid fa-camera"></i>
                                                </div>
                                                <div class="avatar-edit-badge" title="Upload Photo">
                                                    <i class="fa-solid fa-camera"></i>
                                                </div>
                                            </div>
                                            <div class="admin-hero-info">
                                                <div class="admin-hero-header-row">
                                                    <h4 class="admin-hero-name">
                                                        <?php 
                                                        $midInitial = !empty($currentAdmin['middle_name']) ? strtoupper(substr(trim($currentAdmin['middle_name']), 0, 1)) . '.' : '';
                                                        $nameParts = array_filter([$currentAdmin['first_name'] ?? '', $midInitial, $currentAdmin['last_name'] ?? ''], 'strlen');
                                                        echo htmlspecialchars(!empty($nameParts) ? implode(' ', $nameParts) . (!empty($currentAdmin['suffix']) ? ' ' . $currentAdmin['suffix'] : '') : ($userName ?? 'Administrator'));
                                                        ?>
                                                    </h4>
                                                </div>
                                                <div class="admin-hero-meta">
                                                    <?php
                                                    $myRole = $currentAdmin['role'] ?? 'Admin';
                                                    $myRoleLabel = ($myRole === 'super_admin') ? 'Super Administrator' : 'Administrator';
                                                    $myRoleIcon = ($myRole === 'super_admin') ? 'fa-crown' : 'fa-shield-halved';
                                                    $myRoleClass = ($myRole === 'super_admin') ? 'role-badge-super' : 'role-badge-admin';
                                                    ?>
                                                    <span class="role-badge <?php echo $myRoleClass; ?>"><i class="fa-solid <?php echo $myRoleIcon; ?>"></i> <?php echo htmlspecialchars($myRoleLabel); ?></span>
                                                </div>
                                                <div class="profile-picture-actions">
                                                    <input type="file" id="profilePicture" name="profile_picture" accept="image/*" hidden>
                                                    <button type="button" class="btn-hero-photo" id="uploadPictureBtn">
                                                        <i class="fa-solid fa-cloud-arrow-up"></i> Change Photo
                                                    </button>
                                                    <button type="button" class="btn-hero-remove" id="removePictureBtn">
                                                        <i class="fa-solid fa-trash-can"></i> Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Section 1: Personal Information (Expanded 3 Columns matching Image 1) -->
                                    <div class="settings-form-section">
                                        <div class="section-title">
                                            <i class="fa-solid fa-user-pen" style="color: #f59e0b;"></i>
                                            <span>Personal Information</span>
                                        </div>
                                        <p class="section-hint">Update your public administrator display details and verified contact info.</p>

                                        <!-- Row 1: First Name, Middle Name, Last Name (3 Columns) -->
                                        <div class="form-row-3cols">
                                            <div class="form-group">
                                                <label for="profileFirstName">First Name <span class="required" style="color: #ef4444;">*</span></label>
                                                <div class="input-icon-wrapper">
                                                    <input type="text" id="profileFirstName" name="first_name" value="<?php echo htmlspecialchars($currentAdmin['first_name'] ?? ''); ?>" placeholder="e.g. Juan" required>
                                                    <i class="fa-solid fa-user lead-icon"></i>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="profileMiddleName">Middle Name</label>
                                                <div class="input-icon-wrapper">
                                                    <input type="text" id="profileMiddleName" name="middle_name" value="<?php echo htmlspecialchars($currentAdmin['middle_name'] ?? ''); ?>" placeholder="Optional">
                                                    <i class="fa-solid fa-user-tag lead-icon"></i>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="profileLastName">Last Name <span class="required" style="color: #ef4444;">*</span></label>
                                                <div class="input-icon-wrapper">
                                                    <input type="text" id="profileLastName" name="last_name" value="<?php echo htmlspecialchars($currentAdmin['last_name'] ?? ''); ?>" placeholder="e.g. Dela Cruz" required>
                                                    <i class="fa-solid fa-user lead-icon"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Row 2: Name Suffix, Email Address, Contact Number (3 Columns) -->
                                        <div class="form-row-3cols">
                                            <div class="form-group">
                                                <label for="profileSuffix">Name Suffix</label>
                                                <select id="profileSuffix" name="suffix" class="custom-select-field">
                                                    <option value="">None (N/A)</option>
                                                    <option value="Jr." <?php echo ($currentAdmin['suffix'] ?? '') === 'Jr.' ? 'selected' : ''; ?>>Jr.</option>
                                                    <option value="Sr." <?php echo ($currentAdmin['suffix'] ?? '') === 'Sr.' ? 'selected' : ''; ?>>Sr.</option>
                                                    <option value="II" <?php echo ($currentAdmin['suffix'] ?? '') === 'II' ? 'selected' : ''; ?>>II</option>
                                                    <option value="III" <?php echo ($currentAdmin['suffix'] ?? '') === 'III' ? 'selected' : ''; ?>>III</option>
                                                    <option value="IV" <?php echo ($currentAdmin['suffix'] ?? '') === 'IV' ? 'selected' : ''; ?>>IV</option>
                                                    <option value="V" <?php echo ($currentAdmin['suffix'] ?? '') === 'V' ? 'selected' : ''; ?>>V</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="profileEmail">Email Address <span class="required" style="color: #ef4444;">*</span></label>
                                                <div class="input-icon-wrapper">
                                                    <input type="email" id="profileEmail" name="email" value="<?php echo htmlspecialchars($currentAdmin['email']); ?>" placeholder="admin@domain.com" required>
                                                    <i class="fa-solid fa-envelope lead-icon"></i>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="profileContact">Contact Number</label>
                                                <div class="input-icon-wrapper">
                                                    <input type="tel" id="profileContact" name="contact" value="<?php echo htmlspecialchars($currentAdmin['contact'] ?? ''); ?>" placeholder="e.g. 09171234567" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                                                    <i class="fa-solid fa-phone lead-icon"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Section 2: Security & Password -->
                                    <div class="settings-form-section">
                                        <div class="section-title">
                                            <i class="fa-solid fa-lock" style="color: #f59e0b;"></i>
                                            <span>Password & Security</span>
                                        </div>
                                        <p class="section-hint">Leave password fields blank if you do not want to change your current password.</p>
                                        
                                        <div class="form-row" style="margin-bottom: 1.25rem;">
                                            <div class="form-group" style="width: 100%;">
                                                <label for="profileCurrentPassword">Current Password</label>
                                                <div class="input-icon-wrapper password-input-box">
                                                    <input type="password" id="profileCurrentPassword" name="current_password" placeholder="Enter current password to verify">
                                                    <i class="fa-solid fa-key lead-icon"></i>
                                                    <button type="button" class="toggle-password" data-target="profileCurrentPassword" title="Toggle password visibility">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-row-2cols">
                                            <div class="form-group">
                                                <label for="profileNewPassword">New Password</label>
                                                <div class="input-icon-wrapper password-input-box">
                                                    <input type="password" id="profileNewPassword" name="new_password" placeholder="Minimum 8 characters" minlength="8">
                                                    <i class="fa-solid fa-shield-halved lead-icon"></i>
                                                    <button type="button" class="toggle-password" data-target="profileNewPassword" title="Toggle password visibility">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="profileConfirmPassword">Confirm New Password</label>
                                                <div class="input-icon-wrapper password-input-box">
                                                    <input type="password" id="profileConfirmPassword" name="confirm_new_password" placeholder="Re-enter new password" minlength="8">
                                                    <i class="fa-solid fa-check-double lead-icon"></i>
                                                    <button type="button" class="toggle-password" data-target="profileConfirmPassword" title="Toggle password visibility">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-actions-bar">
                                        <button type="submit" class="btn-save-primary">
                                            <i class="fa-solid fa-floppy-disk"></i>
                                            <span>Save Changes</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php if ($isSuperAdmin): ?>
                        <!-- System Administrators Directory Card (Full Width Below) -->
                        <div class="settings-card admin-directory-card">
                            <div class="card-header counselors-header">
                                <h3>
                                    <i class="fa-solid fa-users-shield"></i>
                                    System Administrators
                                    <span class="count-badge"><?php echo count($adminsList); ?></span>
                                </h3>
                                <button class="btn-primary" id="addAdminBtn">
                                    <i class="fa-solid fa-plus"></i>
                                    Add Admin
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="admin-notice-banner">
                                    <i class="fa-solid fa-crown"></i>
                                    <div>
                                        <strong>Super Administrator Privileges</strong>
                                        <p>Super Administrators have full system privileges, including administrator and system settings management.</p>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="data-table admins-mgmt-table">
                                        <colgroup>
                                            <col style="width:30%"><!-- Administrator -->
                                            <col style="width:28%"><!-- Email -->
                                            <col style="width:16%"><!-- Role -->
                                            <col style="width:12%"><!-- Status -->
                                            <col style="width:14%"><!-- Actions -->
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>Administrator</th>
                                                <th>Email</th>
                                                <th>Role</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($adminsList)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 2.5rem 1rem;">
                                                <i class="fa-solid fa-users-slash" style="font-size: 1.75rem; margin-bottom: 0.6rem; display: block; color: #64748b;"></i>
                                                <span style="font-size: 0.92rem; color: #cbd5e1; font-weight: 500;">No standard administrator accounts found.</span>
                                                <p style="font-size: 0.8rem; color: #64748b; margin: 0.25rem 0 0 0;">Click <strong>+ Add Admin</strong> to create an administrator account.</p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($adminsList as $admin):
                                            $isMe = ((int)$admin['id'] === $adminId);
                                            $pic = (!empty($admin['profile_picture']) && file_exists(__DIR__ . '/' . $admin['profile_picture']))
                                                ? htmlspecialchars($admin['profile_picture'])
                                                : 'assets/images/default-avatar.png';
                                            $adminRole = $admin['role'] ?? 'Admin';
                                            $isSA = ($adminRole === 'super_admin');
                                            $stat = $admin['status'] ?? 'active';
                                            $statClass = ($stat === 'inactive' || $stat === 'suspended') ? 'inactive' : 'active';
                                        ?>
                                        <tr class="admin-row<?php echo $isMe ? ' is-current-user' : ''; ?>"
                                            data-id="<?php echo $admin['id']; ?>"
                                            data-firstname="<?php echo htmlspecialchars($admin['first_name'] ?? ''); ?>"
                                            data-middlename="<?php echo htmlspecialchars($admin['middle_name'] ?? ''); ?>"
                                            data-lastname="<?php echo htmlspecialchars($admin['last_name'] ?? ''); ?>"
                                            data-suffix="<?php echo htmlspecialchars($admin['suffix'] ?? ''); ?>"
                                            data-username="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($admin['email']); ?>"
                                            data-contact="<?php echo htmlspecialchars($admin['contact'] ?? ''); ?>"
                                            data-role="<?php echo htmlspecialchars($adminRole); ?>"
                                            data-status="<?php echo htmlspecialchars($stat); ?>">
                                            <td>
                                                <div class="admin-name-cell">
                                                    <div class="admin-mini-avatar">
                                                        <img src="<?php echo $pic; ?>" alt="" onerror="this.onerror=null;this.src='assets/images/default-avatar.png';">
                                                        <?php if ($isMe): ?><span class="online-dot" title="You"></span><?php endif; ?>
                                                    </div>
                                                    <div class="admin-name-text">
                                                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                                                            <span class="admin-row-name"><?php echo htmlspecialchars($admin['name']); ?></span>
                                                            <?php if ($isMe): ?><span class="badge-you"><i class="fa-solid fa-user-check"></i> You</span><?php endif; ?>
                                                        </div>
                                                        <div class="admin-row-meta">
                                                            <small>@<?php echo htmlspecialchars($admin['username'] ?? ''); ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="color:#94a3b8;font-size:0.875rem;"><?php echo htmlspecialchars($admin['email']); ?></td>
                                            <td>
                                                <?php if ($isSA): ?>
                                                <span class="role-pill super">
                                                    <i class="fa-solid fa-crown"></i> Super Admin
                                                </span>
                                                <?php else: ?>
                                                <span class="role-pill normal">
                                                    <i class="fa-solid fa-shield-halved"></i> Admin
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="account-status <?php echo $statClass; ?>"><?php echo ucfirst($stat); ?></span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action view admin-view-btn"
                                                            data-id="<?php echo $admin['id']; ?>"
                                                            title="View Administrator">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <button class="btn-action edit admin-edit-btn"
                                                            data-id="<?php echo $admin['id']; ?>"
                                                            title="Edit Administrator">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </button>
                                                    <?php if (!$isMe): ?>
                                                    <button class="btn-action delete admin-delete-btn"
                                                            data-id="<?php echo $admin['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($admin['name']); ?>"
                                                            title="Delete Administrator">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="admin-security-tips" style="margin-top:1.25rem;">
                                    <h4><i class="fa-solid fa-lightbulb"></i> Security Best Practices</h4>
                                    <ul>
                                        <li><i class="fa-solid fa-check text-success"></i> Keep your contact details up to date for urgent system alerts.</li>
                                        <li><i class="fa-solid fa-check text-success"></i> Use strong alphanumeric passwords with special characters.</li>
                                        <li><i class="fa-solid fa-check text-success"></i> All administrative actions are recorded in the <a href="activity_logs.php" style="color: #fbbf24; text-decoration: underline;">Activity Logs</a>.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php endif; // $isSuperAdmin ?>

                    </div>
                </div>

                <!-- Counselors Tab -->
                <div class="tab-content" id="counselors-tab">
                    <div class="settings-card">
                        <div class="card-header counselors-header">
                            <h3><i class="fa-solid fa-user-tie"></i> Counselor Management</h3>
                            <button class="btn-primary" id="addCounselorBtn">
                                <i class="fa-solid fa-plus"></i>
                                Add Counselor
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="data-table counselors-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>

                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($counselors as $counselor): ?>
                                        <tr data-id="<?php echo $counselor['id']; ?>">
                                            <td><?php echo $counselor['name']; ?></td>
                                            <td><?php echo $counselor['email']; ?></td>

                                            <td>
                                                <?php
                                                $counselorStatus = strtolower($counselor['status'] ?? 'active');
                                                $counselorStatusLabel = ucfirst($counselorStatus);
                                                ?>
                                                <span class="account-status <?php echo htmlspecialchars($counselorStatus); ?>">
                                                    <?php echo htmlspecialchars($counselorStatusLabel); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action view" data-id="<?php echo $counselor['id']; ?>" title="View">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <button class="btn-action edit" data-id="<?php echo $counselor['id']; ?>" title="Edit">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </button>
                                                    <button class="btn-action delete" data-id="<?php echo $counselor['id']; ?>" title="Delete">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Info Tab -->
                <div class="tab-content" id="system-tab">
                    <div class="settings-card">
                        <div class="card-header">
                            <h3><i class="fa-solid fa-server"></i> System Information</h3>
                        </div>
                        <div class="card-body">
                            <form id="systemInfoForm" class="settings-form" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_system_info">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="systemName">System Name <span class="required">*</span></label>
                                        <input type="text" id="systemName" name="system_name" value="<?php echo htmlspecialchars($systemInfo['name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="schoolNameField">School Name</label>
                                        <input type="text" id="schoolNameField" name="school_name" value="<?php echo htmlspecialchars($systemInfo['school_name'] ?? ''); ?>" placeholder="e.g. Pangasinan National High School">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="systemEmail">Email Address <span class="required">*</span></label>
                                        <input type="email" id="systemEmail" name="email" value="<?php echo htmlspecialchars($systemInfo['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="systemContact">Contact Number <span class="required">*</span></label>
                                        <input type="text" id="systemContact" name="contact" value="<?php echo htmlspecialchars($systemInfo['contact'] ?? ''); ?>" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="schoolYear">Active School Year</label>
                                        <input type="text" id="schoolYear" name="school_year" value="<?php echo htmlspecialchars($systemInfo['school_year'] ?? 'Not Set'); ?>" readonly style="background: rgba(15, 23, 42, 0.5); cursor: not-allowed; color: var(--text-muted);" title="The active school year can only be changed from the 'School Year' tab.">
                                    </div>
                                    <div class="form-group">
                                        <label for="systemAddress">Address <span class="required">*</span></label>
                                        <textarea id="systemAddress" name="address" rows="3" required><?php echo htmlspecialchars($systemInfo['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group logo-upload-group">
                                        <label for="systemLogo">Logo</label>
                                        <div class="logo-upload-wrapper">
                                            <input type="file" id="systemLogo" name="logo" accept="image/*">
                                            <div class="logo-preview" id="logoPreview">
                                                <i class="fa-solid fa-image"></i>
                                                <span>No logo selected</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn-primary">
                                        <i class="fa-solid fa-save"></i>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- School Year Management Tab -->
                <div class="tab-content" id="schoolyear-tab">
                    <div class="settings-grid">
                        <!-- Add School Year Card -->
                        <div class="settings-card">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-calendar-plus"></i> Add School Year</h3>
                            </div>
                            <div class="card-body">
                                <form id="addSchoolYearForm" class="settings-form" method="post">
                                    <input type="hidden" name="action" value="add_school_year">
                                    <?php if ($schoolYearSaved && isset($_POST['action']) && $_POST['action'] === 'add_school_year'): ?>
                                    <div class="success-message" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 14px;">
                                        <i class="fa-solid fa-check-circle"></i> School year added successfully!
                                    </div>
                                    <?php elseif ($schoolYearError && isset($_POST['action']) && $_POST['action'] === 'add_school_year'): ?>
                                    <div class="error-message" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 14px;">
                                        <i class="fa-solid fa-exclamation-circle"></i> Invalid school year range or format (needs to be YYYY-YYYY, e.g. 2026-2027, from 2024 to 2100).
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-group">
                                        <label for="schoolYearName">School Year <span class="required">*</span></label>
                                        <input type="text" id="schoolYearName" name="school_year" placeholder="e.g. 2026-2027" pattern="20[2-9]\d-(20[2-9]\d|2100)" title="Please enter a valid school year range between 2024 and 2100 in YYYY-YYYY format (e.g. 2026-2027)." required>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary">
                                            <i class="fa-solid fa-plus"></i>
                                            Add School Year
                                        </button>
                                    </div>
                                </form>
                                
                            </div>
                        </div>

                        <!-- School Years List Card -->
                        <div class="settings-card school-years-card">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-list"></i> School Years</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="data-table school-years-table">
                                        <thead>
                                            <tr>
                                                <th>School Year</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($systemInfo['school_years'])): ?>
                                            <tr>
                                                <td colspan="3" style="text-align: center; padding: 1.5rem; color: var(--text-secondary);">No school years added yet. Use the form above to add one.</td>
                                            </tr>
                                            <?php else: ?>
                                            <?php 
                                            $activeStart = (int)substr(getSystemConfig('school_year'), 0, 4);
                                            foreach ($systemInfo['school_years'] as $sy): 
                                                $syStart = (int)substr($sy['year'], 0, 4);
                                                $canTransition = ($syStart > $activeStart);
                                            ?>
                                            <tr data-id="<?php echo $sy['id']; ?>">
                                                <td><?php echo htmlspecialchars($sy['year']); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $sy['status']; ?>">
                                                        <?php echo ucfirst($sy['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($sy['status'] === 'current'): ?>
                                                        <button class="btn-action archive" data-id="<?php echo $sy['id']; ?>" title="Archive Year">
                                                            <i class="fa-solid fa-box-archive"></i>
                                                        </button>
                                                        <?php elseif ($sy['status'] === 'archived'): ?>
                                                        <button class="btn-action unarchive" data-id="<?php echo $sy['id']; ?>" title="Unarchive Year">
                                                            <i class="fa-solid fa-box-open"></i>
                                                        </button>
                                                        <?php if ($canTransition): ?>
                                                        <button class="btn-action set-current" data-id="<?php echo $sy['id']; ?>" title="Set as Current">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                        <?php else: ?>
                                                        <button class="btn-action set-current" disabled style="opacity:0.3; cursor:not-allowed;" title="Cannot transition to a past year. Restore DB instead.">
                                                            <i class="fa-solid fa-ban"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php else: ?>
                                                        <?php if ($canTransition): ?>
                                                        <button class="btn-action set-current" data-id="<?php echo $sy['id']; ?>" title="Set as Current">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                        <?php else: ?>
                                                        <button class="btn-action set-current" disabled style="opacity:0.3; cursor:not-allowed;" title="Cannot transition to a past year. Restore DB instead.">
                                                            <i class="fa-solid fa-ban"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <button class="btn-action archive" data-id="<?php echo $sy['id']; ?>" title="Archive Year">
                                                            <i class="fa-solid fa-box-archive"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Student ID Management Card -->
                        <div class="settings-card student-id-card-full" style="grid-column: 1 / -1;">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-users" style="color: #6366f1;"></i> Student ID Management</h3>
                            </div>
                            <div class="card-body">
                                <p class="settings-description" style="margin-bottom: 1.5rem;">
                                    Manage student IDs for the active school year (<strong><?php echo htmlspecialchars(getSystemConfig('school_year')); ?></strong>).
                                </p>
                                
                                <div class="student-id-grid" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem;">
                                    <!-- Incoming Grade 11 Subcard -->
                                    <div class="student-id-subcard" style="background: rgba(15, 23, 42, 0.35); border: 1px solid rgba(148, 163, 184, 0.15); border-radius: 12px; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease;">
                                        <div>
                                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                                                <div style="width: 38px; height: 38px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                                                    <i class="fa-solid fa-user-plus"></i>
                                                </div>
                                                <div>
                                                    <h4 style="color: #f1f5f9; font-size: 1.05rem; font-weight: 600; margin: 0;">Incoming Grade 11</h4>
                                                    <span style="font-size: 0.75rem; color: #38bdf8; font-weight: 500;">Pre-registration</span>
                                                </div>
                                            </div>
                                            <p style="color: #94a3b8; font-size: 0.875rem; line-height: 1.5; margin: 0 0 1rem 0;">
                                                Register new students for the new school year
                                            </p>
                                            <div style="font-size: 0.8rem; color: #cbd5e1; background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.2); padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 1.5rem;">
                                                <i class="fa-solid fa-circle-info" style="color: #38bdf8; margin-right: 0.35rem;"></i> Format: <code>Student ID, Strand Code</code>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                            <button type="button" class="btn-secondary" onclick="downloadStudentIdTemplate()" style="flex: 1; min-width: 130px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1rem; border-radius: 8px; font-size: 0.875rem; background: rgba(255,255,255,0.05); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.12); cursor: pointer; transition: all 0.2s;">
                                                <i class="fa-solid fa-download"></i>
                                                Download
                                            </button>
                                            <button type="button" class="btn-primary" id="openUploadGrade11Btn" style="flex: 1; min-width: 130px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.875rem; cursor: pointer; transition: all 0.2s;">
                                                <i class="fa-solid fa-file-arrow-up"></i>
                                                Upload CSV
                                            </button>
                                        </div>
                                    </div>


                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isSuperAdmin): ?>
                <!-- Database Backup Tab -->
                <div class="tab-content" id="backup-tab">
                    <div class="settings-grid">
                        <!-- Backup Card -->
                        <div class="settings-card">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-download"></i> Create Backup</h3>
                            </div>
                            <div class="card-body">
                                <p class="settings-description">
                                    Create a complete backup of your database, including all student records, assessment data, questions, courses, schools, and system settings.
                                </p>
                                <div class="backup-section">
                                    <div class="backup-info">
                                        <div class="info-item">
                                            <i class="fa-solid fa-info-circle"></i>
                                            <span>Backup includes all database tables</span>
                                        </div>
                                        <div class="info-item">
                                            <i class="fa-solid fa-clock"></i>
                                            <span>Backup generated: <?php echo date('Y-m-d H:i:s'); ?></span>
                                        </div>
                                    </div>
                                    <form id="backupForm" method="post" action="settings.php">
                                        <input type="hidden" name="action" value="backup_database">
                                        <div class="form-actions">
                                            <button type="submit" class="btn-primary" id="backupBtn">
                                                <i class="fa-solid fa-download"></i>
                                                Download Backup
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Restore Card -->
                        <div class="settings-card">
                            <div class="card-header">
                                <h3><i class="fa-solid fa-upload"></i> Restore Database</h3>
                            </div>
                            <div class="card-body">
                                <?php if ($restoreSuccess): ?>
                                <div class="success-message" style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                                    <i class="fa-solid fa-check-circle"></i> Database restored successfully!
                                </div>
                                <?php endif; ?>
                                <?php if ($restoreError): ?>
                                <div class="error-message" style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                                    <i class="fa-solid fa-exclamation-circle"></i> <?php echo htmlspecialchars($restoreError); ?>
                                </div>
                                <?php endif; ?>
                                
                                <p class="settings-description" style="color: #dc2626;">
                                    <strong>Warning:</strong> Restoring will overwrite all existing data. This action cannot be undone!
                                </p>
                                <form id="restoreForm" method="post" action="settings.php" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="restore_database">
                                    <div class="form-group">
                                        <label for="restore_file">Select Backup File</label>
                                        <input type="file" id="restore_file" name="restore_file" accept=".sql" required style="margin-top: 0.5rem;">
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary" id="restoreBtn">
                                            <i class="fa-solid fa-upload"></i>
                                            Restore Database
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Upload Grade 11 Valid IDs Modal -->
    <div class="modal" id="uploadGrade11Modal">
        <div class="modal-overlay"></div>
        <div class="modal-content" style="max-width: 580px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-plus"></i> Batch Import Incoming Grade 11 IDs</h2>
                <button class="modal-close" id="closeUploadGrade11Modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="uploadValidIdsForm" method="post" action="settings.php" enctype="multipart/form-data" class="settings-form">
                    <input type="hidden" name="action" value="upload_valid_ids">
                    
                    <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.2); padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; color: #cbd5e1;">
                        <i class="fa-solid fa-circle-info" style="color: #38bdf8; margin-right: 0.4rem;"></i>
                        Upload a CSV with <code>Student ID, Strand Code</code> (e.g. <code>400123450008, HUMSS</code>).
                        Existing IDs will be safely ignored.
                    </div>

                    <div class="form-row" style="margin-bottom: 1.5rem;">
                        <div class="csv-upload-wrapper">
                            <input type="file" id="csvFileInput" name="csv_file" accept=".csv" required>
                            <div class="csv-upload-content">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>Drag &amp; Drop your CSV here</span>
                                <small>or click to browse files</small>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer" style="padding: 0; margin-top: 1rem; border-top: none; display: flex; justify-content: flex-end; gap: 0.75rem;">
                        <button type="button" class="btn-secondary" id="cancelUploadGrade11">Cancel</button>
                        <button type="submit" class="btn-primary" style="padding: 0 20px; height: 40px; border-radius: 8px; font-size: 14px; font-weight: 500;">
                            <i class="fa-solid fa-upload"></i> Upload CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <!-- Add Admin Modal -->
    <div class="modal" id="addAdminModal">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-shield"></i> Add Admin</h2>
                <button class="close-btn" id="closeAddAdminModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addAdminForm" class="modal-form counselor-form">
                    <!-- Row 1: First Name + Middle Name -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="adminFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="adminFirstName" name="first_name" placeholder="First name" required>
                        </div>
                        <div class="form-group">
                            <label for="adminMiddleName">Middle Name</label>
                            <input type="text" id="adminMiddleName" name="middle_name" placeholder="Middle name">
                        </div>
                    </div>
                    <!-- Row 2: Last Name + Suffix -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="adminLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="adminLastName" name="last_name" placeholder="Last name" required>
                        </div>
                        <div class="form-group">
                            <label for="adminSuffix">Suffix</label>
                            <select id="adminSuffix" name="suffix">
                                <option value="">N/A</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 3: Username + Email + Contact -->
                    <div class="form-row three-cols">
                        <div class="form-group">
                            <label for="adminUsername">Username <span class="required">*</span></label>
                            <input type="text" id="adminUsername" name="username" placeholder="e.g. juan_admin" pattern="[a-zA-Z0-9_]+" maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label for="adminEmail">Email <span class="required">*</span></label>
                            <input type="email" id="adminEmail" name="email" placeholder="e.g. juan@school.edu" required>
                        </div>
                        <div class="form-group">
                            <label for="adminContact">Contact Number</label>
                            <input type="tel" id="adminContact" name="contact" placeholder="e.g. 09171234567" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                        </div>
                    </div>
                    <!-- Row 4: Role -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="adminRole">Role <span class="required">*</span></label>
                            <select id="adminRole" name="role" required>
                                <option value="Admin">Administrator (standard operations)</option>
                                <option value="super_admin">Super Administrator (full system access)</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAddAdmin">Cancel</button>
                <button type="submit" form="addAdminForm" class="btn-primary"><i class="fa-solid fa-user-plus"></i> Add Admin</button>
            </div>
        </div>
    </div>

    <!-- View Admin Modal -->
    <div class="modal" id="viewAdminModal">
        <div class="modal-content" style="max-width: 540px; background: #111827; color: #f1f5f9; border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 20px 50px rgba(0,0,0,0.6); border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 1.25rem 1.5rem;">
                <h2 style="color: #fbbf24; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin: 0;">
                    <i class="fa-solid fa-user-shield"></i> Administrator Profile Details
                </h2>
                <button class="close-btn" id="closeViewAdminModal" style="font-size: 1.5rem; background: none; border: none; color: #64748b; cursor: pointer; transition: color 0.2s ease;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <div style="text-align: center; margin-bottom: 0.5rem;">
                    <div style="width: 70px; height: 70px; border-radius: 50%; background: rgba(251, 191, 36, 0.1); border: 2px solid #fbbf24; display: inline-flex; align-items: center; justify-content: center; color: #fbbf24; font-size: 2rem;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr; gap: 0.85rem; font-size: 0.95rem;">
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">First Name:</span>
                        <span id="viewAdminFirstName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Middle Name:</span>
                        <span id="viewAdminMiddleName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Last Name:</span>
                        <span id="viewAdminLastName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Suffix:</span>
                        <span id="viewAdminSuffix" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Username:</span>
                        <span id="viewAdminUsername" style="font-weight: 600; color: #fbbf24;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Email:</span>
                        <span id="viewAdminEmail" style="font-weight: 600; color: #60a5fa;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Contact Number:</span>
                        <span id="viewAdminContact" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">System Role:</span>
                        <span id="viewAdminRole" style="font-weight: 600; color: #10b981;">Administrator</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; gap: 0.75rem; background: rgba(15, 23, 42, 0.2);">
                <button type="button" class="btn btn-primary" id="editAdminBtn" style="background: #3b82f6;"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
                <button type="button" class="btn btn-secondary" id="closeViewAdminBtn" style="padding: 0.6rem 1.2rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #94a3b8; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s ease;">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Admin Modal -->
    <div class="modal" id="editAdminModal">
        <div class="modal-content" style="max-width:680px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-pen"></i> Edit Admin</h2>
                <button class="close-btn" id="closeEditAdminModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editAdminForm" class="modal-form counselor-form">
                    <input type="hidden" id="editAdminId" name="id">
                    <!-- Row 1: First Name + Middle Name -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAdminFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editAdminFirstName" name="first_name" placeholder="First name" required>
                        </div>
                        <div class="form-group">
                            <label for="editAdminMiddleName">Middle Name</label>
                            <input type="text" id="editAdminMiddleName" name="middle_name" placeholder="Middle name">
                        </div>
                    </div>
                    <!-- Row 2: Last Name + Suffix -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAdminLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editAdminLastName" name="last_name" placeholder="Last name" required>
                        </div>
                        <div class="form-group">
                            <label for="editAdminSuffix">Suffix</label>
                            <select id="editAdminSuffix" name="suffix">
                                <option value="">N/A</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 3: Username + Email -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAdminUsername">Username <span class="required">*</span></label>
                            <input type="text" id="editAdminUsername" name="username" placeholder="Username" pattern="[a-zA-Z0-9_]+" maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label for="editAdminEmail">Email <span class="required">*</span></label>
                            <input type="email" id="editAdminEmail" name="email" placeholder="Email address" required>
                        </div>
                    </div>
                    <!-- Row 4: Contact + Status -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAdminContact">Contact Number</label>
                            <input type="tel" id="editAdminContact" name="contact" placeholder="Contact number" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                        </div>
                        <div class="form-group">
                            <label for="editAdminStatus">Status <span class="required">*</span></label>
                            <select id="editAdminStatus" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 5: Role -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAdminRole">Role <span class="required">*</span></label>
                            <select id="editAdminRole" name="role" required>
                                <option value="Admin">Administrator (standard operations)</option>
                                <option value="super_admin">Super Administrator (full system access)</option>
                            </select>
                            <small id="editAdminRoleHint" style="color:#94a3b8;font-size:0.78rem;"></small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEditAdmin">Cancel</button>
                <button type="submit" form="editAdminForm" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete Admin Confirmation Modal -->
    <div class="modal" id="deleteAdminModal">
        <div class="modal-content" style="max-width:440px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-trash-can"></i> Delete Administrator</h2>
                <button class="close-btn" id="closeDeleteAdminModal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-message">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Are you sure you want to permanently delete this administrator?</p>
                    <p class="delete-counselor-name" id="deleteAdminName" style="font-weight:700;color:#fbbf24;"></p>
                    <small>This action cannot be undone. The admin account and its access will be removed.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDeleteAdmin">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDeleteAdmin">Delete</button>
            </div>
        </div>
    </div>

    <!-- View Admin Modal -->
    <div class="modal" id="viewAdminModal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-id-card"></i> Administrator Details</h2>
                <button class="close-btn" id="closeViewAdminModal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="counselor-profile-header" style="text-align:center;margin-bottom:1.25rem;">
                    <div style="width:72px;height:72px;border-radius:50%;overflow:hidden;margin:0 auto 0.75rem;border:3px solid rgba(251,191,36,0.4);">
                        <img id="viewAdminAvatar" src="assets/images/default-avatar.png" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='assets/images/default-avatar.png';">
                    </div>
                    <h3 id="viewAdminFullName" style="font-size:1.1rem;font-weight:700;color:#f1f5f9;margin:0;"></h3>
                    <span id="viewAdminRoleBadge" style="display:inline-block;margin-top:0.4rem;"></span>
                </div>
                <div class="counselor-profile-details">
                    <div class="profile-detail-row">
                        <span class="detail-label"><i class="fa-solid fa-at"></i> Username</span>
                        <span class="detail-value" id="viewAdminUsername">-</span>
                    </div>
                    <div class="profile-detail-row">
                        <span class="detail-label"><i class="fa-solid fa-envelope"></i> Email</span>
                        <span class="detail-value" id="viewAdminEmail">-</span>
                    </div>
                    <div class="profile-detail-row">
                        <span class="detail-label"><i class="fa-solid fa-phone"></i> Contact</span>
                        <span class="detail-value" id="viewAdminContact">-</span>
                    </div>
                    <div class="profile-detail-row">
                        <span class="detail-label"><i class="fa-solid fa-circle-dot"></i> Status</span>
                        <span class="detail-value" id="viewAdminStatus">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="closeViewAdminBtn">Close</button>
                <button type="button" class="btn-primary" id="viewAdminEditBtn"><i class="fa-solid fa-pen"></i> Edit</button>
            </div>
        </div>
    </div>

    <!-- Add Counselor Modal -->
    <div class="modal" id="addCounselorModal">
        <div class="modal-content" style="max-width:680px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-plus"></i> Add Counselor</h2>
                <button class="close-btn" id="closeCounselorModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCounselorForm" class="modal-form counselor-form">
                    <!-- Row 1: First Name + Middle Name + Last Name + Suffix -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="counselorFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="counselorFirstName" name="first_name" placeholder="First name" required>
                        </div>
                        <div class="form-group">
                            <label for="counselorMiddleName">Middle Name</label>
                            <input type="text" id="counselorMiddleName" name="middle_name" placeholder="Middle name">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="counselorLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="counselorLastName" name="last_name" placeholder="Last name" required>
                        </div>
                        <div class="form-group">
                            <label for="counselorSuffix">Suffix</label>
                            <select id="counselorSuffix" name="suffix">
                                <option value="">N/A</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 2: Email + Contact -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="counselorEmail">Email <span class="required">*</span></label>
                            <input type="email" id="counselorEmail" name="email" placeholder="e.g. juan@school.edu" required>
                        </div>

                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAddCounselor">Cancel</button>
                <button type="submit" form="addCounselorForm" class="btn-primary">Add Counselor</button>
            </div>
        </div>
    </div>

    <!-- Edit Counselor Modal -->
    <div class="modal" id="editCounselorModal">
        <div class="modal-content" style="max-width:680px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Counselor</h2>
                <button class="close-btn" id="closeEditCounselorModal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editCounselorForm" class="modal-form counselor-form">
                    <input type="hidden" id="editCounselorId" name="id">
                    <!-- Row 1: First Name + Middle Name + Last Name + Suffix -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editCounselorFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editCounselorFirstName" name="first_name" placeholder="First name" required>
                        </div>
                        <div class="form-group">
                            <label for="editCounselorMiddleName">Middle Name</label>
                            <input type="text" id="editCounselorMiddleName" name="middle_name" placeholder="Middle name">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editCounselorLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editCounselorLastName" name="last_name" placeholder="Last name" required>
                        </div>
                        <div class="form-group">
                            <label for="editCounselorSuffix">Suffix</label>
                            <select id="editCounselorSuffix" name="suffix">
                                <option value="">N/A</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    <!-- Row 2: Email + Status -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editCounselorEmail">Email <span class="required">*</span></label>
                            <input type="email" id="editCounselorEmail" name="email" placeholder="Email address" required>
                        </div>
                        <div class="form-group">
                            <label for="editCounselorStatus">Status <span class="required">*</span></label>
                            <select id="editCounselorStatus" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEditCounselor">Cancel</button>
                <button type="submit" form="editCounselorForm" class="btn-primary">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- View Counselor Modal -->
    <div class="modal" id="viewCounselorModal">
        <div class="modal-content" style="max-width: 580px; background: #111827; color: #f1f5f9; border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 20px 50px rgba(0,0,0,0.6); border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 1.25rem 1.5rem;">
                <h2 style="color: #fbbf24; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin: 0;">
                    <i class="fa-solid fa-user-tie"></i> Guidance Counselor Profile Details
                </h2>
                <button class="close-btn" id="closeViewCounselorModal" style="font-size: 1.5rem; background: none; border: none; color: #64748b; cursor: pointer; transition: color 0.2s ease;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <div style="text-align: center; margin-bottom: 0.5rem;">
                    <div style="width: 70px; height: 70px; border-radius: 50%; background: rgba(251, 191, 36, 0.1); border: 2px solid #fbbf24; display: inline-flex; align-items: center; justify-content: center; color: #fbbf24; font-size: 2rem;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr; gap: 0.85rem; font-size: 0.95rem;">
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">First Name:</span>
                        <span id="viewCounselorFirstName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Middle Name:</span>
                        <span id="viewCounselorMiddleName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Last Name:</span>
                        <span id="viewCounselorLastName" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Suffix:</span>
                        <span id="viewCounselorSuffix" style="font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span style="color: #94a3b8; font-weight: 500;">Email:</span>
                        <span id="viewCounselorEmail" style="font-weight: 600; color: #60a5fa;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #94a3b8; font-weight: 500;">Status:</span>
                        <span id="viewCounselorStatus" style="font-weight: 700; text-transform: uppercase; font-size: 0.85rem;">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; background: rgba(15, 23, 42, 0.2);">
                <button type="button" class="btn btn-secondary" id="closeViewCounselorBtn" style="padding: 0.6rem 1.2rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #94a3b8; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s ease;">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Counselor Modal -->
    <div class="modal" id="deleteCounselorModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-trash-can"></i> Delete Counselor</h2>
                <button class="close-btn" id="closeDeleteCounselorModal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-message">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Are you sure you want to delete this counselor?</p>
                    <p class="delete-counselor-name" id="deleteCounselorName"></p>
                    <small>This action cannot be undone.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDeleteCounselor">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDeleteCounselor">Delete</button>
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

    <script src="script.js"></script>
    <script src="admin.js"></script>
    <script>
        // Toggle password visibility
        function togglePasswordVisibility(button) {
            const input = button.previousElementSibling;
            const icon = button.querySelector('i');
            
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

        // Settings Page JavaScript
        document.addEventListener('DOMContentLoaded', function() {
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

            // Name inputs normalization
            function normalizeNameInput(value) {
                if (!value) return '';
                let cleaned = value.trim().replace(/\s+/g, ' ');
                return cleaned.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
            }

            document.querySelectorAll('#profileFirstName, #profileMiddleName, #profileLastName, #adminFirstName, #adminMiddleName, #adminLastName, #counselorFirstName, #counselorMiddleName, #counselorLastName, #editCounselorFirstName, #editCounselorMiddleName, #editCounselorLastName').forEach(input => {
                input.addEventListener('blur', function() {
                    this.value = normalizeNameInput(this.value);
                });
            });
            // Tab switching
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab') + '-tab';
                    
                    // Remove active from all tabs
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active to clicked tab
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });

            // Password visibility toggle
            document.querySelectorAll('.toggle-password').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });

            // Logo preview
            const logoInput = document.getElementById('systemLogo');
            const logoPreview = document.getElementById('logoPreview');
            
            if (logoInput) {
                logoInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            logoPreview.innerHTML = `<img src="${e.target.result}" alt="Logo Preview">`;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        logoPreview.innerHTML = '<i class="fa-solid fa-image"></i><span>No logo selected</span>';
                    }
                });
            }

            // Profile picture upload
            const profilePictureInput = document.getElementById('profilePicture');
            const uploadPictureBtn = document.getElementById('uploadPictureBtn');
            const uploadOverlay = document.getElementById('uploadOverlay');
            const removePictureBtn = document.getElementById('removePictureBtn');
            const profilePreview = document.getElementById('profilePreview');
            const defaultAvatar = 'assets/images/default-avatar.png';

            if (uploadPictureBtn && profilePictureInput) {
                uploadPictureBtn.addEventListener('click', function() {
                    profilePictureInput.click();
                });
            }

            if (uploadOverlay && profilePictureInput) {
                uploadOverlay.addEventListener('click', function() {
                    profilePictureInput.click();
                });
            }

            const avatarEditBadge = document.querySelector('.avatar-edit-badge');
            if (avatarEditBadge && profilePictureInput) {
                avatarEditBadge.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profilePictureInput.click();
                });
            }

            // Handle profile picture file selection
            if (profilePictureInput) {
                profilePictureInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        // Validate file type
                        if (!file.type.startsWith('image/')) {
                            alert('Please select an image file (JPG, PNG, GIF)');
                            return;
                        }
                        // Validate file size (max 5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File size should be less than 5MB');
                            return;
                        }

                        const reader = new FileReader();
                        reader.onload = function(e) {
                            if (profilePreview) {
                                profilePreview.src = e.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            // Remove picture
            if (removePictureBtn && profilePreview && profilePictureInput) {
                removePictureBtn.addEventListener('click', function() {
                    profilePreview.src = defaultAvatar;
                    profilePictureInput.value = '';
                });
            }

            // Add Counselor Modal
            const addCounselorModal = document.getElementById('addCounselorModal');
            const addCounselorBtn = document.getElementById('addCounselorBtn');
            const closeCounselorModal = document.getElementById('closeCounselorModal');
            const cancelAddCounselor = document.getElementById('cancelAddCounselor');
            
            function openAddCounselorModal() {
                addCounselorModal.classList.add('active');
            }
            
            function closeAddCounselorModalFn() {
                addCounselorModal.classList.remove('active');
                document.getElementById('addCounselorForm').reset();
            }
            
            
            addCounselorBtn.addEventListener('click', openAddCounselorModal);
            closeCounselorModal.addEventListener('click', closeAddCounselorModalFn);
            cancelAddCounselor.addEventListener('click', closeAddCounselorModalFn);
            
            // Edit Counselor Modal
            const editCounselorModal = document.getElementById('editCounselorModal');
            const closeEditCounselorModal = document.getElementById('closeEditCounselorModal');
            const cancelEditCounselor = document.getElementById('cancelEditCounselor');
            
            function closeEditCounselorModalFn() {
                editCounselorModal.classList.remove('active');
            }
            
            closeEditCounselorModal.addEventListener('click', closeEditCounselorModalFn);
            cancelEditCounselor.addEventListener('click', closeEditCounselorModalFn);
            
            // Open View Modal
            const viewCounselorModal = document.getElementById('viewCounselorModal');
            const closeViewCounselorModal = document.getElementById('closeViewCounselorModal');
            const closeViewCounselorBtn = document.getElementById('closeViewCounselorBtn');

            function closeViewCounselorModalFn() {
                if (viewCounselorModal) viewCounselorModal.classList.remove('active');
            }

            if (closeViewCounselorModal) closeViewCounselorModal.addEventListener('click', closeViewCounselorModalFn);
            if (closeViewCounselorBtn) closeViewCounselorBtn.addEventListener('click', closeViewCounselorModalFn);

            document.querySelectorAll('.counselors-table .btn-action.view').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const id = this.getAttribute('data-id');

                    // Fetch counselor data
                    const fd = new FormData();
                    fd.append('action', 'get_counselor');
                    fd.append('id', id);
                    try {
                        const res = await fetch('settings.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success && data.counselor) {
                            const c = data.counselor;
                            document.getElementById('viewCounselorFirstName').textContent = c.first_name || '-';
                            document.getElementById('viewCounselorMiddleName').textContent = c.middle_name || '-';
                            document.getElementById('viewCounselorLastName').textContent = c.last_name || '-';
                            document.getElementById('viewCounselorSuffix').textContent = c.suffix || '-';
                            document.getElementById('viewCounselorEmail').textContent = c.email || '-';

                            
                            const statusEl = document.getElementById('viewCounselorStatus');
                            if (statusEl) {
                                statusEl.textContent = c.status || '-';
                                statusEl.style.color = c.status === 'active' ? '#10b981' : (c.status === 'suspended' ? '#f59e0b' : '#ef4444');
                            }
                        }
                    } catch (e) {
                        console.error('Failed to load counselor:', e);
                    }
                    if (viewCounselorModal) viewCounselorModal.classList.add('active');
                });
            });

            // Open Edit Modal
            document.querySelectorAll('.counselors-table .btn-action.edit').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const id = this.getAttribute('data-id');
                    document.getElementById('editCounselorId').value = id;

                    // Fetch counselor data
                    const fd = new FormData();
                    fd.append('action', 'get_counselor');
                    fd.append('id', id);
                    try {
                        const res = await fetch('settings.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success && data.counselor) {
                            const c = data.counselor;
                            document.getElementById('editCounselorFirstName').value = c.first_name || '';
                            document.getElementById('editCounselorMiddleName').value = c.middle_name || '';
                            document.getElementById('editCounselorLastName').value = c.last_name || '';
                            document.getElementById('editCounselorSuffix').value = c.suffix || '';
                            document.getElementById('editCounselorEmail').value = c.email || '';

                            const editStatusSelect = document.getElementById('editCounselorStatus');
                            if (editStatusSelect) {
                                const allowed = ['active', 'inactive', 'suspended'];
                                editStatusSelect.value = allowed.includes(c.status) ? c.status : 'active';
                            }
                        }
                    } catch (e) {
                        console.error('Failed to load counselor:', e);
                    }
                    editCounselorModal.classList.add('active');
                });
            });

            // Delete Counselor Modal
            const deleteCounselorModal = document.getElementById('deleteCounselorModal');
            const closeDeleteCounselorModal = document.getElementById('closeDeleteCounselorModal');
            const cancelDeleteCounselor = document.getElementById('cancelDeleteCounselor');
            const confirmDeleteCounselor = document.getElementById('confirmDeleteCounselor');
            
            function closeDeleteCounselorModalFn() {
                deleteCounselorModal.classList.remove('active');
            }
            
            closeDeleteCounselorModal.addEventListener('click', closeDeleteCounselorModalFn);
            cancelDeleteCounselor.addEventListener('click', closeDeleteCounselorModalFn);
            
            // Open Delete Modal
            let deleteCounselorId = null;
            document.querySelectorAll('.counselors-table .btn-action.delete').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    deleteCounselorId = id;
                    const row = this.closest('tr');
                    const name = row?.querySelector('td')?.textContent || 'Counselor #' + id;
                    document.getElementById('deleteCounselorName').textContent = name;
                    deleteCounselorModal.classList.add('active');
                });
            });

            // Close modals on overlay click
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });

            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('settings.php', { method: 'POST', body: formData });
                return res.json();
            }

            // -- Admin Management (Super Admin only) ---------------------------

            // Handle Edit Admin Modal (opened via inline Edit button in table)
            const editAdminModal = document.getElementById('editAdminModal');
            const closeEditAdminModal = document.getElementById('closeEditAdminModal');
            const cancelEditAdmin = document.getElementById('cancelEditAdmin');
            const editAdminForm = document.getElementById('editAdminForm');
            const currentAdminIdOnPage = <?php echo json_encode($adminId); ?>;

            function closeEditAdminModalFn() {
                if (editAdminModal) editAdminModal.classList.remove('active');
            }

            if (closeEditAdminModal) closeEditAdminModal.addEventListener('click', closeEditAdminModalFn);
            if (cancelEditAdmin) cancelEditAdmin.addEventListener('click', closeEditAdminModalFn);

            // Open Edit from inline table button
            document.querySelectorAll('.admin-edit-btn').forEach(btn => {
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const row = this.closest('tr');
                    const id = row.getAttribute('data-id');

                    // Pre-fill from data attrs
                    document.getElementById('editAdminId').value = id;
                    document.getElementById('editAdminFirstName').value = row.getAttribute('data-firstname') || '';
                    document.getElementById('editAdminMiddleName').value = row.getAttribute('data-middlename') || '';
                    document.getElementById('editAdminLastName').value = row.getAttribute('data-lastname') || '';
                    document.getElementById('editAdminSuffix').value = row.getAttribute('data-suffix') || '';
                    document.getElementById('editAdminUsername').value = row.getAttribute('data-username') || '';
                    document.getElementById('editAdminEmail').value = row.getAttribute('data-email') || '';
                    document.getElementById('editAdminContact').value = row.getAttribute('data-contact') || '';
                    document.getElementById('editAdminStatus').value = row.getAttribute('data-status') || 'active';
                    const currentRole = row.getAttribute('data-role') || 'Admin';
                    document.getElementById('editAdminRole').value = currentRole;

                    // Disable role change for self
                    const roleSelect = document.getElementById('editAdminRole');
                    const roleHint = document.getElementById('editAdminRoleHint');
                    if (parseInt(id) === currentAdminIdOnPage) {
                        roleSelect.disabled = true;
                        if (roleHint) roleHint.textContent = 'You cannot change your own role.';
                    } else {
                        roleSelect.disabled = false;
                        if (roleHint) roleHint.textContent = '';
                    }

                    // Fetch latest via AJAX
                    try {
                        const fd = new FormData();
                        fd.append('action', 'get_admin');
                        fd.append('id', id);
                        const data = await apiPost(fd);
                        if (data.success && data.admin) {
                            const a = data.admin;
                            document.getElementById('editAdminFirstName').value = a.first_name || '';
                            document.getElementById('editAdminMiddleName').value = a.middle_name || '';
                            document.getElementById('editAdminLastName').value = a.last_name || '';
                            document.getElementById('editAdminSuffix').value = a.suffix || '';
                            document.getElementById('editAdminUsername').value = a.username || '';
                            document.getElementById('editAdminEmail').value = a.email || '';
                            document.getElementById('editAdminContact').value = a.contact || '';
                            document.getElementById('editAdminStatus').value = a.status || 'active';
                            if (parseInt(id) !== currentAdminIdOnPage) {
                                document.getElementById('editAdminRole').value = a.role || 'Admin';
                            }
                        }
                    } catch (err) {
                        console.error('Failed to fetch admin data:', err);
                    }

                    if (editAdminModal) editAdminModal.classList.add('active');
                });
            });

            if (editAdminForm) {
                editAdminForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const fd = new FormData(this);
                    fd.append('action', 'edit_admin');
                    // If role select was disabled (editing self), don't send role
                    const roleSelect = document.getElementById('editAdminRole');
                    if (roleSelect && roleSelect.disabled) {
                        fd.delete('role');
                    }
                    try {
                        const data = await apiPost(fd);
                        if (data.success) {
                            closeEditAdminModalFn();
                            showStatusModal('Success', data.message || 'Administrator updated successfully.', true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to update administrator.', false);
                        }
                    } catch (err) {
                        showStatusModal('Error', 'An error occurred while updating the administrator.', false);
                    }
                });
            }

            // View Admin Modal
            const viewAdminModal    = document.getElementById('viewAdminModal');
            const closeViewAdminModal = document.getElementById('closeViewAdminModal');
            const closeViewAdminBtn = document.getElementById('closeViewAdminBtn');
            const viewAdminEditBtn  = document.getElementById('editAdminBtn');
            let viewAdminCurrentId  = null;

            function closeViewAdminModalFn() {
                if (viewAdminModal) viewAdminModal.classList.remove('active');
            }
            if (closeViewAdminModal) closeViewAdminModal.addEventListener('click', closeViewAdminModalFn);
            if (closeViewAdminBtn)   closeViewAdminBtn.addEventListener('click', closeViewAdminModalFn);

            // "Edit" button inside view modal - opens edit modal
            if (viewAdminEditBtn) {
                viewAdminEditBtn.addEventListener('click', function() {
                    closeViewAdminModalFn();
                    // Trigger edit for the same row
                    const targetBtn = document.querySelector(`.admin-edit-btn[data-id="${viewAdminCurrentId}"]`);
                    if (targetBtn) targetBtn.click();
                });
            }

            document.querySelectorAll('.admin-view-btn').forEach(btn => {
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const row = this.closest('tr');
                    viewAdminCurrentId = this.getAttribute('data-id');

                    const role   = row.getAttribute('data-role') || 'Admin';

                    const fNameEl   = document.getElementById('viewAdminFirstName');
                    const mNameEl   = document.getElementById('viewAdminMiddleName');
                    const lNameEl   = document.getElementById('viewAdminLastName');
                    const suffixEl  = document.getElementById('viewAdminSuffix');
                    const usernameEl = document.getElementById('viewAdminUsername');
                    const emailEl   = document.getElementById('viewAdminEmail');
                    const contactEl = document.getElementById('viewAdminContact');
                    const roleEl    = document.getElementById('viewAdminRole');

                    if (fNameEl) fNameEl.textContent = row.getAttribute('data-firstname') || '-';
                    if (mNameEl) mNameEl.textContent = row.getAttribute('data-middlename') || '-';
                    if (lNameEl) lNameEl.textContent = row.getAttribute('data-lastname') || '-';
                    if (suffixEl) suffixEl.textContent = row.getAttribute('data-suffix') || '-';
                    if (usernameEl) usernameEl.textContent = '@' + (row.getAttribute('data-username') || '-');
                    if (emailEl)   emailEl.textContent   = row.getAttribute('data-email') || '-';
                    if (contactEl) contactEl.textContent = row.getAttribute('data-contact') || '-';
                    if (roleEl) {
                        if (role === 'super_admin') {
                            roleEl.innerHTML = '<span style="color: #fbbf24;"><i class="fa-solid fa-crown"></i> Super Admin</span>';
                        } else {
                            roleEl.innerHTML = '<span style="color: #10b981;"><i class="fa-solid fa-shield-halved"></i> Administrator</span>';
                        }
                    }

                    // Fetch fresh data via AJAX
                    try {
                        const fd = new FormData();
                        fd.append('action', 'get_admin');
                        fd.append('id', viewAdminCurrentId);
                        const data = await apiPost(fd);
                        if (data.success && data.admin) {
                            const a = data.admin;
                            if (fNameEl) fNameEl.textContent = a.first_name || '-';
                            if (mNameEl) mNameEl.textContent = a.middle_name || '-';
                            if (lNameEl) lNameEl.textContent = a.last_name || '-';
                            if (suffixEl) suffixEl.textContent = a.suffix || '-';
                            if (usernameEl) usernameEl.textContent = '@' + (a.username || '-');
                            if (emailEl)   emailEl.textContent   = a.email || '-';
                            if (contactEl) contactEl.textContent = a.contact || '-';
                            if (roleEl) {
                                if (a.role === 'super_admin') {
                                    roleEl.innerHTML = '<span style="color: #fbbf24;"><i class="fa-solid fa-crown"></i> Super Admin</span>';
                                } else {
                                    roleEl.innerHTML = '<span style="color: #10b981;"><i class="fa-solid fa-shield-halved"></i> Administrator</span>';
                                }
                            }
                        }
                    } catch (err) {
                        console.error('Failed to load admin details:', err);
                    }

                    if (viewAdminModal) viewAdminModal.classList.add('active');
                });
            });

            // Delete admin (inline button)
            const deleteAdminModal = document.getElementById('deleteAdminModal');
            const confirmDeleteAdmin = document.getElementById('confirmDeleteAdmin');
            const cancelDeleteAdmin = document.getElementById('cancelDeleteAdmin');
            const closeDeleteAdminModal = document.getElementById('closeDeleteAdminModal');
            let deleteAdminTargetId = null;

            function closeDeleteAdminModalFn() {
                if (deleteAdminModal) deleteAdminModal.classList.remove('active');
                deleteAdminTargetId = null;
            }
            if (cancelDeleteAdmin) cancelDeleteAdmin.addEventListener('click', closeDeleteAdminModalFn);
            if (closeDeleteAdminModal) closeDeleteAdminModal.addEventListener('click', closeDeleteAdminModalFn);

            document.querySelectorAll('.admin-delete-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    deleteAdminTargetId = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name') || 'Administrator #' + deleteAdminTargetId;
                    const nameEl = document.getElementById('deleteAdminName');
                    if (nameEl) nameEl.textContent = name;
                    if (deleteAdminModal) deleteAdminModal.classList.add('active');
                });
            });

            if (confirmDeleteAdmin) {
                confirmDeleteAdmin.addEventListener('click', async function() {
                    if (!deleteAdminTargetId) return;
                    const fd = new FormData();
                    fd.append('action', 'delete_admin');
                    fd.append('id', deleteAdminTargetId);
                    try {
                        const data = await apiPost(fd);
                        closeDeleteAdminModalFn();
                        if (data.success) {
                            showStatusModal('Success', data.message || 'Administrator deleted.', true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to delete administrator.', false);
                        }
                    } catch (err) {
                        closeDeleteAdminModalFn();
                        showStatusModal('Error', 'An error occurred.', false);
                    }
                });
            }

            // -- Add Admin Modal -------------------------------------------------
            const addAdminModal    = document.getElementById('addAdminModal');
            const addAdminBtn      = document.getElementById('addAdminBtn');
            const closeAddAdminBtn = document.getElementById('closeAddAdminModal');
            const cancelAddAdmin   = document.getElementById('cancelAddAdmin');

            function openAddAdminModal() {
                if (addAdminModal) addAdminModal.classList.add('active');
            }
            function closeAddAdminModalFn() {
                if (addAdminModal) {
                    addAdminModal.classList.remove('active');
                    document.getElementById('addAdminForm').reset();
                    adminUsernameEdited = false;
                }
            }

            if (addAdminBtn)      addAdminBtn.addEventListener('click', openAddAdminModal);
            if (closeAddAdminBtn) closeAddAdminBtn.addEventListener('click', closeAddAdminModalFn);
            if (cancelAddAdmin)   cancelAddAdmin.addEventListener('click', closeAddAdminModalFn);

            function normalizeAdminUsernameInput(value) {
                return value
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9_]/g, '_')
                    .replace(/_+/g, '_')
                    .replace(/^_+|_+$/g, '')
                    .slice(0, 50);
            }

            const adminUsernameInput = document.getElementById('adminUsername');
            let adminUsernameEdited = false;

            if (adminUsernameInput) {
                adminUsernameInput.addEventListener('input', function() {
                    adminUsernameEdited = this.value.trim() !== '';
                    this.value = normalizeAdminUsernameInput(this.value);
                });
            }

            // Add Admin via AJAX
            const addAdminForm = document.getElementById('addAdminForm');
            if (addAdminForm) {
                addAdminForm.addEventListener('submit', async function(e) {
                    e.preventDefault();

                    const fd = new FormData(this);
                    fd.append('action', 'add_admin');

                    if (adminUsernameInput) {
                        fd.set('username', normalizeAdminUsernameInput(adminUsernameInput.value));
                    }

                    try {
                        const data = await apiPost(fd);
                        if (data.success) {
                            closeAddAdminModalFn();
                            let msg = 'Administrator added successfully!';
                            if (data.email_sent) {
                                msg += '\n\nA welcome email with login credentials has been sent to the administrator.';
                            } else if (data.generated_password) {
                                msg += '\n\nEmail could not be sent. Please share the temporary password manually:\n\n' + data.generated_password;
                            }
                            showStatusModal('Success', msg, true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to add administrator.', false);
                        }
                    } catch (err) {
                        showStatusModal('Error', 'An error occurred while adding the administrator.', false);
                    }
                });
            }

            // Add Counselor via AJAX
            document.getElementById('addCounselorForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const fd = new FormData(this);
                fd.append('action', 'add_counselor');
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        let msg = 'Counselor added successfully!';
                        if (data.email_sent) {
                            msg += '\n\nA welcome email with login credentials has been sent to the counselor.';
                        } else if (data.generated_password) {
                            msg += '\n\nEmail could not be sent. Please share the temporary password manually:\n\n' + data.generated_password;
                        }
                        showStatusModal('Success', msg, true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add counselor.', false);
                    }
                } catch (err) {
                    showStatusModal('Error', 'Error adding counselor.', false);
                }
            });

            // Edit Counselor via AJAX
            document.getElementById('editCounselorForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const fd = new FormData(this);
                fd.append('action', 'edit_counselor');
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        showStatusModal('Success', 'Counselor updated successfully.', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update counselor.', false);
                    }
                } catch (err) {
                    showStatusModal('Error', 'Error updating counselor.', false);
                }
            });

            // Delete Counselor via AJAX
            confirmDeleteCounselor.addEventListener('click', async function() {
                if (!deleteCounselorId) return;
                const fd = new FormData();
                fd.append('action', 'delete_counselor');
                fd.append('id', deleteCounselorId);
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        showStatusModal('Success', 'Counselor deleted successfully.', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to delete counselor.', false);
                    }
                } catch (err) {
                    showStatusModal('Error', 'Error deleting counselor.', false);
                }
                closeDeleteCounselorModalFn();
            });

            const systemInfoSaved = <?php echo $systemInfoSaved ? 'true' : 'false'; ?>;
            const systemInfoError = <?php echo $systemInfoError ? 'true' : 'false'; ?>;
            if (systemInfoSaved) {
                showStatusModal('Success', 'System information saved successfully.', true);
            } else if (systemInfoError) {
                showStatusModal('Error', 'Failed to save system information. Please complete all required fields and try again.', false);
            }

            const schoolYearSaved = <?php echo $schoolYearSaved ? 'true' : 'false'; ?>;
            const schoolYearError = <?php echo $schoolYearError ? 'true' : 'false'; ?>;
            if (schoolYearSaved) {
                showStatusModal('Success', 'School year saved successfully.', true);
            } else if (schoolYearError) {
                showStatusModal('Error', 'Failed to save school year. It may already exist or the input is invalid.', false);
            }

            const csvUploadCount = <?php echo ($csvUploadCount !== false) ? $csvUploadCount : 'false'; ?>;
            const csvUploadErrorMsg = "<?php echo addslashes($csvUploadErrorMsg ?? ''); ?>";
            
            if (csvUploadCount !== false) {
                if (csvUploadCount > 0) {
                    showStatusModal('Success', `Successfully imported ${csvUploadCount} new valid student IDs!`, true);
                } else if (csvUploadErrorMsg) {
                    showStatusModal('Error', csvUploadErrorMsg, false);
                }
            } else if (csvUploadErrorMsg) {
                showStatusModal('Error', csvUploadErrorMsg, false);
            }

            // CSV File Input UI Handler
            const csvInput = document.getElementById('csvFileInput');
            if (csvInput) {
                csvInput.addEventListener('change', function(e) {
                    const span = this.parentElement.querySelector('.csv-upload-content span');
                    const small = this.parentElement.querySelector('.csv-upload-content small');
                    const icon = this.parentElement.querySelector('.csv-upload-content i');
                    if (e.target.files[0]) {
                        span.textContent = e.target.files[0].name;
                        small.textContent = 'File selected - click Upload CSV to import';
                        icon.className = 'fa-solid fa-file-csv';
                        icon.style.color = '#22c55e';
                        this.parentElement.style.borderColor = '#22c55e';
                        this.parentElement.style.background = 'rgba(34, 197, 94, 0.05)';
                    } else {
                        span.textContent = 'Drag & Drop your CSV here';
                        small.textContent = 'or click to browse files';
                        icon.className = 'fa-solid fa-cloud-arrow-up';
                        icon.style.color = '#f59e0b';
                        this.parentElement.style.borderColor = 'rgba(148, 163, 184, 0.3)';
                        this.parentElement.style.background = 'rgba(15, 23, 42, 0.4)';
                    }
                });
            }

            // School Year Management
            document.querySelectorAll('.school-years-table .btn-action.archive').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    showCustomConfirm(
                        'Archive School Year',
                        'Are you sure you want to archive this school year?',
                        () => {
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = `<input type="hidden" name="action" value="archive_school_year"><input type="hidden" name="id" value="${id}">`;
                            document.body.appendChild(form);
                            form.submit();
                        },
                        true
                    );
                });
            });

            document.querySelectorAll('.school-years-table .btn-action.unarchive').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    showCustomConfirm(
                        'Unarchive School Year',
                        'Unarchive this school year? It will be available again but not set as current.',
                        () => {
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = `<input type="hidden" name="action" value="unarchive_school_year"><input type="hidden" name="id" value="${id}">`;
                            document.body.appendChild(form);
                            form.submit();
                        },
                        false
                    );
                });
            });

            document.querySelectorAll('.school-years-table .btn-action.set-current').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id   = parseInt(this.getAttribute('data-id'), 10);
                    const row  = this.closest('tr');
                    const yearLabel = row ? row.querySelector('td:first-child')?.textContent?.trim() : 'this year';
                    openTransitionModal(id, yearLabel);
                });
            });

            // -- Transition Modal Logic ----------------------------------------
            const transitionModal  = document.getElementById('transitionModal');
            const transitionOverlay = document.getElementById('transitionOverlay');
            let pendingYearId = null;

            function openTransitionModal(yearId, yearLabel) {
                pendingYearId = yearId;

                // Reset UI
                document.getElementById('transitionLoading').style.display = 'block';
                document.getElementById('transitionPreview').style.display  = 'none';
                document.getElementById('transitionError').style.display    = 'none';
                document.getElementById('transitionConfirmCheck').checked   = false;
                document.getElementById('backupConfirmCheck').checked       = false;
                document.getElementById('confirmTransitionBtn').disabled    = true;
                document.getElementById('transitionYearLabel').textContent  = yearLabel;
                document.getElementById('transitionFooter').style.display   = 'flex';

                transitionModal.style.display = 'flex';
                transitionModal.classList.add('active');

                // Fetch preview
                const fd = new FormData();
                fd.append('action', 'preview');
                fd.append('new_year_id', yearId);
                fetch('api/school_year_transition.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('transitionLoading').style.display = 'none';
                        if (!data.success) {
                            document.getElementById('transitionErrorMsg').textContent = data.message || 'Failed to load preview.';
                            document.getElementById('transitionError').style.display  = 'block';
                            document.getElementById('transitionFooter').style.display = 'none';
                            return;
                        }
                        document.getElementById('g12Count').textContent      = data.grade12_count;
                        document.getElementById('g11Count').textContent      = data.grade11_count;
                        document.getElementById('archiveCount').textContent  = data.archive_count;
                        document.getElementById('transitionPreview').style.display = 'block';
                    })
                    .catch(() => {
                        document.getElementById('transitionLoading').style.display = 'none';
                        document.getElementById('transitionErrorMsg').textContent  = 'Network error. Please try again.';
                        document.getElementById('transitionError').style.display   = 'block';
                        document.getElementById('transitionFooter').style.display  = 'none';
                    });
            }

            function closeTransitionModal() {
                transitionModal.classList.remove('active');
                transitionModal.style.display = 'none';
                pendingYearId = null;
            }

            document.getElementById('closeTransitionModal').addEventListener('click', closeTransitionModal);
            document.getElementById('cancelTransitionBtn').addEventListener('click', closeTransitionModal);
            transitionOverlay.addEventListener('click', closeTransitionModal);

            // Checkbox enables/disables confirm button
            function checkTransitionRequirements() {
                const confirmChecked = document.getElementById('transitionConfirmCheck').checked;
                const backupChecked = document.getElementById('backupConfirmCheck').checked;
                document.getElementById('confirmTransitionBtn').disabled = !(confirmChecked && backupChecked);
            }
            document.getElementById('transitionConfirmCheck').addEventListener('change', checkTransitionRequirements);
            document.getElementById('backupConfirmCheck').addEventListener('change', checkTransitionRequirements);

            // Execute transition
            document.getElementById('confirmTransitionBtn').addEventListener('click', function() {
                if (!pendingYearId) return;
                const btn = this;

                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin btn-spinner"></i> Processing...';

                const fd = new FormData();
                fd.append('action', 'execute');
                fd.append('new_year_id', pendingYearId);
                fetch('api/school_year_transition.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            closeTransitionModal();
                            // Show success toast then reload
                            showTransitionToast('Transition complete! SY ' + (data.year || '') + ' is now active.', 'success');
                            setTimeout(() => location.reload(), 2200);
                        } else {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fa-solid fa-rotate btn-icon"></i> Confirm Transition';
                            showTransitionToast(data.message || 'Transition failed.', 'error');
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-rotate btn-icon"></i> Confirm Transition';
                        showTransitionToast('Network error. Please try again.', 'error');
                    });
            });

            function showTransitionToast(msg, type) {
                const toast = document.createElement('div');
                toast.style.cssText = `
                    position:fixed;bottom:2rem;right:2rem;z-index:99999;
                    background:${type === 'success' ? 'linear-gradient(135deg,#22c55e,#16a34a)' : 'linear-gradient(135deg,#ef4444,#dc2626)'};
                    color:#fff;padding:.9rem 1.5rem;border-radius:12px;
                    font-weight:600;font-size:.9rem;box-shadow:0 8px 32px rgba(0,0,0,.35);
                    display:flex;align-items:center;gap:.6rem;
                    animation:slideInToast .3s ease;
                `;
                toast.textContent = msg;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            }

            // -- UPLOAD GRADE 11 IDS ---------------------------------------
            const uploadGrade11Modal = document.getElementById('uploadGrade11Modal');
            const openUploadGrade11Btn = document.getElementById('openUploadGrade11Btn');
            const closeUploadGrade11Modal = document.getElementById('closeUploadGrade11Modal');
            const cancelUploadGrade11 = document.getElementById('cancelUploadGrade11');

            if (openUploadGrade11Btn && uploadGrade11Modal) {
                openUploadGrade11Btn.addEventListener('click', () => {
                    uploadGrade11Modal.style.display = 'flex';
                    uploadGrade11Modal.classList.add('active');
                });
            }

            const closeUploadGrade11 = () => {
                if (uploadGrade11Modal) {
                    uploadGrade11Modal.style.display = 'none';
                    uploadGrade11Modal.classList.remove('active');
                }
            };

            if (closeUploadGrade11Modal) closeUploadGrade11Modal.addEventListener('click', closeUploadGrade11);
            if (cancelUploadGrade11) cancelUploadGrade11.addEventListener('click', closeUploadGrade11);
            if (uploadGrade11Modal) {
                uploadGrade11Modal.querySelector('.modal-overlay')?.addEventListener('click', (e) => {
                    if (e.target === e.currentTarget) closeUploadGrade11();
                });
            }



        });
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

            // Handle Restore Form Submission
            const restoreForm = document.getElementById('restoreForm');
            if (restoreForm) {
                restoreForm.addEventListener('submit', function(e) {
                    if (!this.dataset.confirmed) {
                        e.preventDefault();
                        showCustomConfirm(
                            'Restore Database',
                            'Are you sure you want to restore the database? This will overwrite all existing data!',
                            () => {
                                this.dataset.confirmed = 'true';
                                this.submit();
                            },
                            true
                        );
                    }
                });
            }
        });

        function showCustomConfirm(title, text, onConfirm, isDanger = false) {
            document.getElementById('customConfirmTitle').textContent = title;
            document.getElementById('customConfirmText').textContent = text;
            const icon = document.getElementById('customConfirmIcon');
            const confirmBtn = document.getElementById('btnOkCustomConfirm');
            
            if (isDanger) {
                icon.className = 'fa-solid fa-triangle-exclamation';
                icon.style.color = '#ef4444';
                confirmBtn.style.background = '#ef4444';
            } else {
                icon.className = 'fa-solid fa-circle-question';
                icon.style.color = '#f59e0b';
                confirmBtn.style.background = '#f59e0b';
            }

            const modal = document.getElementById('customConfirmModal');
            modal.classList.add('show');

            const cancelBtn = document.getElementById('btnCancelCustomConfirm');
            
            // Remove old event listeners by replacing the node
            const newCancelBtn = cancelBtn.cloneNode(true);
            cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
            
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newCancelBtn.addEventListener('click', () => {
                modal.classList.remove('show');
            });
            
            newConfirmBtn.addEventListener('click', () => {
                modal.classList.remove('show');
                onConfirm();
            });
        }
    </script>

    <!-- Custom Confirm Modal -->
    <div class="logout-modal-overlay" id="customConfirmModal">
        <div class="logout-modal">
            <div class="logout-modal-icon"><i class="fa-solid fa-triangle-exclamation" id="customConfirmIcon" style="color: #f59e0b;"></i></div>
            <h3 id="customConfirmTitle">Confirm Action</h3>
            <p id="customConfirmText" style="margin-bottom: 1.5rem;">Are you sure?</p>
            <div class="logout-modal-actions">
                <button type="button" class="btn-logout-cancel" id="btnCancelCustomConfirm">Cancel</button>
                <button type="button" class="btn-logout-confirm" id="btnOkCustomConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>

    <!-- ================================================================== -->
    <!-- School Year Transition Preview Modal                              -->
    <!-- ================================================================== -->
    <div class="modal" id="transitionModal" style="display:none;">
        <div class="modal-overlay" id="transitionOverlay"></div>
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h2 style="display:flex;align-items:center;gap:.6rem;">
                    <i class="fa-solid fa-rotate" style="color:#f59e0b;"></i>
                    School Year Transition
                </h2>
                <button class="modal-close" id="closeTransitionModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Loading state -->
                <div id="transitionLoading" style="text-align:center;padding:2rem;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;color:#f59e0b;"></i>
                    <p style="margin-top:.75rem;color:var(--text-secondary,#94a3b8);">Loading preview&hellip;</p>
                </div>

                <!-- Preview content (shown after AJAX) -->
                <div id="transitionPreview" style="display:none;">
                    <p style="margin-bottom:1.2rem;color:var(--text-secondary,#94a3b8);font-size:.9rem;">
                        You are activating: <strong id="transitionYearLabel" style="color:var(--text-primary,#f1f5f9);"></strong>
                    </p>

                    <!-- Outcome cards -->
                    <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.4rem;">
                        <div style="display:flex;align-items:center;gap:1rem;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:.8rem 1rem;">
                            <div style="width:36px;height:36px;border-radius:8px;background:rgba(239,68,68,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-lock" style="color:#ef4444;font-size:1.15rem;"></i>
                            </div>
                            <div>
                                <div style="font-weight:700;color:#f1f5f9;">
                                    <span id="g12Count">&hellip;</span> Grade 12 students
                                </div>
                                <div style="font-size:.8rem;color:#94a3b8;">Set to read-only (can still view past results, cannot retake assessment)</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:1rem;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:.8rem 1rem;">
                            <div style="width:36px;height:36px;border-radius:8px;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-arrow-trend-up" style="color:#22c55e;font-size:1.15rem;"></i>
                            </div>
                            <div>
                                <div style="font-weight:700;color:#f1f5f9;">
                                    <span id="g11Count">&hellip;</span> Grade 11 students
                                </div>
                                <div style="font-size:.8rem;color:#94a3b8;">Promoted to Grade 12 with a fresh assessment slate</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:1rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:.8rem 1rem;">
                            <div style="width:36px;height:36px;border-radius:8px;background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-box-archive" style="color:#f59e0b;font-size:1.15rem;"></i>
                            </div>
                            <div>
                                <div style="font-weight:700;color:#f1f5f9;">
                                    <span id="archiveCount">&hellip;</span> students' assessments archived
                                </div>
                                <div style="font-size:.8rem;color:#94a3b8;">Old results preserved for counselors; students start with count = 0</div>
                            </div>
                        </div>
                    </div>

                    <!-- Backup Confirmation -->
                    <label style="display:flex;align-items:flex-start;gap:.7rem;cursor:pointer;padding:.85rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.25);border-radius:10px;margin-bottom:1rem;">
                        <input type="checkbox" id="backupConfirmCheck" style="margin-top:2px;accent-color:#f59e0b;width:16px;height:16px;flex-shrink:0;">
                        <span style="font-size:.875rem;color:#f1f5f9;line-height:1.4;">
                            I confirm that I have <strong>backed up the database</strong> recently. 
                            <a href="#" onclick="document.querySelector('[data-tab=\'backup\']').click(); closeTransitionModal(); event.preventDefault();" style="color:#f59e0b;text-decoration:underline;">Go to Backup Tool</a>
                        </span>
                    </label>

                    <!-- Confirmation checkbox -->
                    <label style="display:flex;align-items:flex-start;gap:.7rem;cursor:pointer;padding:.85rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:10px;">
                        <input type="checkbox" id="transitionConfirmCheck" style="margin-top:2px;accent-color:#ef4444;width:16px;height:16px;flex-shrink:0;">
                        <span style="font-size:.875rem;color:#f1f5f9;line-height:1.4;">
                            I understand this action <strong>cannot be undone</strong>. All Grade 12 accounts will be marked as graduated, and all Grade 11 students will be promoted to Grade 12.
                        </span>
                    </label>
                </div>

                <!-- Error state -->
                <div id="transitionError" style="display:none;text-align:center;padding:1.5rem;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size:2rem;color:#ef4444;"></i>
                    <p id="transitionErrorMsg" style="margin-top:.75rem;color:#ef4444;"></p>
                </div>
            </div>
            <div class="modal-footer" id="transitionFooter">
                <button type="button" class="btn-secondary" id="cancelTransitionBtn">Cancel</button>
                <button type="button" class="btn-danger" id="confirmTransitionBtn" disabled
                        style="display:flex;align-items:center;gap:.5rem;">
                    <i class="fa-solid fa-rotate"></i> Confirm Transition
                </button>
            </div>
        </div>
    </div>

    <style>
    /* ==========================================================================
       Admin Management Table - Expanded Full-Width Layout
       ========================================================================== */
    .admins-mgmt-table {
        table-layout: fixed;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    /* Column widths matching colgroup */
    .admins-mgmt-table colgroup col:nth-child(1) { width: 30%; }
    .admins-mgmt-table colgroup col:nth-child(2) { width: 28%; }
    .admins-mgmt-table colgroup col:nth-child(3) { width: 16%; }
    .admins-mgmt-table colgroup col:nth-child(4) { width: 12%; }
    .admins-mgmt-table colgroup col:nth-child(5) { width: 14%; }

    .admins-mgmt-table th {
        background: rgba(15, 23, 42, 0.6);
        color: #94a3b8;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 0.9rem 1.1rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        white-space: nowrap;
    }
    .admins-mgmt-table td {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        vertical-align: middle;
        padding: 0.9rem 1.1rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.06);
    }
    .admins-mgmt-table tbody tr:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    /* Name cell - avatar + text side-by-side */
    .admin-name-cell {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        overflow: hidden;
        min-width: 0;
    }
    .admin-mini-avatar {
        position: relative;
        width: 38px;
        height: 38px;
        min-width: 38px;
        border-radius: 50%;
        overflow: hidden;
        border: 2px solid rgba(148, 163, 184, 0.25);
        flex-shrink: 0;
        background: rgba(15, 23, 42, 0.8);
    }
    .admin-mini-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    /* Text block: name + @username */
    .admin-name-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
        overflow: hidden;
        gap: 0.15rem;
    }
    .admin-row-name {
        font-weight: 600;
        font-size: 0.92rem;
        color: #f8fafc;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: inline-block;
    }
    .admin-row-meta {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        overflow: hidden;
    }
    .admin-row-meta small {
        color: #64748b;
        font-size: 0.78rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* Role pill badges */
    .role-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .role-pill.super {
        background: rgba(251, 191, 36, 0.15);
        border: 1px solid rgba(251, 191, 36, 0.35);
        color: #fbbf24;
    }
    .role-pill.normal {
        background: rgba(96, 165, 250, 0.12);
        border: 1px solid rgba(96, 165, 250, 0.25);
        color: #60a5fa;
    }
    .admin-row.is-current-user {
        background: linear-gradient(90deg, rgba(251, 191, 36, 0.08), transparent) !important;
    }
    .admins-mgmt-table .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: nowrap;
        align-items: center;
    }
    .admins-mgmt-table .btn-action[disabled] {
        opacity: 0.3;
        cursor: not-allowed;
        pointer-events: none;
    }
    </style>
    <style>
    /* -- Counselor form 2-column grid -- */
    .counselor-form .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .counselor-form .form-row.three-cols {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .counselor-form .form-group {
        display: flex;
        flex-direction: column;
        gap: .45rem;
        margin-bottom: 0;
    }
    .counselor-form label {
        font-size: .82rem;
        font-weight: 600;
        color: var(--text-secondary, #94a3b8);
        letter-spacing: .02em;
        text-transform: uppercase;
    }
    .counselor-form input,
    .counselor-form select {
        width: 100%;
        padding: .65rem .9rem;
        background: var(--input-bg, rgba(15,23,42,.5));
        border: 1px solid rgba(148,163,184,.2);
        border-radius: 9px;
        color: var(--text-primary, #f1f5f9);
        font-size: .88rem;
        font-family: inherit;
        transition: border-color .2s, box-shadow .2s;
        box-sizing: border-box;
    }
    .counselor-form input:focus,
    .counselor-form select:focus {
        outline: none;
        border-color: #f59e0b;
        box-shadow: 0 0 0 3px rgba(245,158,11,.12);
    }
    .counselor-form input::placeholder { color: rgba(148,163,184,.55); }
    .counselor-form select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right .85rem center;
        padding-right: 2.2rem;
        cursor: pointer;
    }
    .counselor-form select option { background: #1e293b; color: #f1f5f9; }
    /* Password wrapper inside a grid cell */
    .counselor-form .password-input {
        position: relative;
        display: flex;
        align-items: center;
    }
    .counselor-form .password-input input {
        padding-right: 2.6rem;
    }
    .counselor-form .password-input .toggle-password {
        position: absolute;
        right: .7rem;
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 0;
        font-size: .9rem;
        line-height: 1;
        transition: color .2s;
    }
    .counselor-form .password-input .toggle-password:hover { color: #f59e0b; }
    .counselor-form .required { color: #f59e0b; }
    @media (max-width: 520px) {
        .counselor-form .form-row,
        .counselor-form .form-row.three-cols { grid-template-columns: 1fr; }
    }
    
    /* CSV Upload Drag & Drop Styling */
    .csv-upload-wrapper {
        position: relative;
        width: 100%;
        padding: 2.5rem 1.5rem;
        background: rgba(15, 23, 42, 0.4);
        border: 2px dashed rgba(148, 163, 184, 0.3);
        border-radius: 12px;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
        overflow: hidden;
    }
    .csv-upload-wrapper:hover {
        background: rgba(15, 23, 42, 0.6);
        border-color: #f59e0b;
    }
    .csv-upload-wrapper input[type="file"] {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        opacity: 0;
        cursor: pointer;
        z-index: 10;
    }
    .csv-upload-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        pointer-events: none;
    }
    .csv-upload-content i {
        font-size: 3rem;
        color: #f59e0b;
        margin-bottom: 0.5rem;
        transition: color 0.3s;
    }
    .csv-upload-content span {
        font-size: 1.15rem;
        font-weight: 600;
        color: #f1f5f9;
    }
    .csv-upload-content small {
        font-size: 0.9rem;
        color: #94a3b8;
    }
    </style>

    <style>
    /* ==========================================================================
       Admin Account Tab Redesign Styles (Stacked & Expanded Full-Width)
       ========================================================================== */
    .account-tab-layout {
        display: flex;
        flex-direction: column;
        gap: 1.75rem;
        width: 100%;
    }
    .account-tab-layout .settings-card {
        width: 100%;
    }

    .admin-profile-card,
    .admin-directory-card {
        background: rgba(30, 41, 59, 0.7);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(148, 163, 184, 0.12);
        border-radius: 14px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25);
    }

    /* 3-Column and 2-Column Form Rows matching Image 1 */
    .form-row-3cols {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.25rem;
    }
    .form-row-2cols {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.25rem;
    }
    @media (max-width: 992px) {
        .form-row-3cols {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 640px) {
        .form-row-3cols,
        .form-row-2cols {
            grid-template-columns: 1fr;
        }
    }

    /* Input Icon Wrapper and Lead Icons matching Image 1 */
    .input-icon-wrapper {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
    }
    .input-icon-wrapper i.lead-icon {
        position: absolute;
        left: 1rem;
        color: #64748b;
        font-size: 0.95rem;
        pointer-events: none;
        transition: color 0.2s ease;
        z-index: 2;
    }
    .input-icon-wrapper input {
        width: 100%;
        background: rgba(15, 23, 42, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 0.8rem 1rem 0.8rem 2.75rem;
        color: #ffffff;
        font-size: 0.92rem;
        font-family: inherit;
        transition: all 0.25s ease;
        box-sizing: border-box;
    }
    .input-icon-wrapper input:focus,
    .custom-select-field:focus {
        outline: none;
        border-color: #f59e0b;
        background: rgba(15, 23, 42, 0.95);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
    }
    .input-icon-wrapper input:focus + .lead-icon,
    .input-icon-wrapper:focus-within i.lead-icon {
        color: #fbbf24;
    }
    .custom-select-field {
        width: 100%;
        background: rgba(15, 23, 42, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 0.8rem 1rem;
        color: #ffffff;
        font-size: 0.92rem;
        font-family: inherit;
        transition: all 0.25s ease;
        box-sizing: border-box;
    }

    /* Password Input Box with eye toggle */
    .password-input-box {
        position: relative;
    }
    .password-input-box input {
        padding-right: 2.75rem;
    }
    .password-input-box .toggle-password {
        position: absolute;
        right: 0.85rem;
        background: transparent;
        border: none;
        color: #64748b;
        cursor: pointer;
        padding: 0.4rem;
        font-size: 0.95rem;
        transition: color 0.2s ease;
        z-index: 2;
    }
    .password-input-box .toggle-password:hover {
        color: #fbbf24;
    }

    /* Save Changes Button Bar matching Image 1 */
    .form-actions-bar {
        display: flex;
        justify-content: flex-end;
        margin-top: 1.5rem;
        padding-top: 0.5rem;
    }
    .btn-save-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #0f172a;
        font-weight: 700;
        font-size: 0.95rem;
        padding: 0.75rem 1.75rem;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(245, 158, 11, 0.35);
        transition: all 0.25s ease;
    }
    .btn-save-primary:hover {
        background: linear-gradient(135deg, #fbbf24, #f59e0b);
        box-shadow: 0 6px 20px rgba(245, 158, 11, 0.5);
        transform: translateY(-1px);
    }
    .btn-save-primary:active {
        transform: translateY(1px);
    }
    
    .alert-box {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        padding: 1rem 1.25rem;
        border-radius: 10px;
        margin-bottom: 1.25rem;
        font-size: 0.9rem;
    }
    .alert-box i {
        font-size: 1.2rem;
        margin-top: 2px;
    }
    .alert-box strong {
        display: block;
        font-size: 0.95rem;
        margin-bottom: 0.2rem;
    }
    .alert-box p {
        margin: 0;
        opacity: 0.9;
        font-size: 0.85rem;
    }
    .alert-box.success {
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.25);
        color: #34d399;
    }
    .alert-box.error {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.25);
        color: #f87171;
    }

    /* Ultra-Modern Admin Profile Hero Card */
    .admin-profile-hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1.5rem;
        padding: 1.5rem 1.75rem;
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(30, 41, 59, 0.65) 100%) !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
        border-top: 1px solid rgba(255, 255, 255, 0.15) !important;
        border-radius: 16px !important;
        margin-bottom: 1.75rem !important;
        box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1) !important;
        backdrop-filter: blur(12px);
        position: relative;
        overflow: hidden;
    }
    .admin-profile-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, #fbbf24 30%, #38bdf8 70%, transparent);
        opacity: 0.8;
    }
    .profile-hero-left {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex: 1;
        min-width: 0;
    }
    .admin-profile-hero .profile-picture-preview {
        position: relative;
        width: 86px;
        height: 86px;
        min-width: 86px;
        border-radius: 50%;
        padding: 3px;
        background: linear-gradient(135deg, #fbbf24, #d97706);
        box-shadow: 0 0 20px rgba(251, 191, 36, 0.3), 0 4px 12px rgba(0, 0, 0, 0.4);
        cursor: pointer;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        flex-shrink: 0;
    }
    .admin-profile-hero .profile-picture-preview:hover {
        transform: scale(1.03);
        box-shadow: 0 0 25px rgba(251, 191, 36, 0.45), 0 6px 16px rgba(0, 0, 0, 0.5);
    }
    .admin-profile-hero .profile-picture-preview img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        background: #0f172a;
        display: block;
    }
    .admin-profile-hero .profile-picture-overlay {
        position: absolute;
        inset: 3px;
        border-radius: 50%;
        background: rgba(15, 23, 42, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.25rem;
        opacity: 0;
        transition: opacity 0.2s ease;
        backdrop-filter: blur(2px);
    }
    .admin-profile-hero .profile-picture-preview:hover .profile-picture-overlay {
        opacity: 1;
    }
    .avatar-edit-badge {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #3b82f6;
        border: 2px solid #0f172a;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        box-shadow: 0 2px 6px rgba(0,0,0,0.5);
        cursor: pointer;
        transition: background 0.2s ease, transform 0.2s ease;
    }
    .avatar-edit-badge:hover {
        background: #2563eb;
        transform: scale(1.1);
    }
    .admin-hero-info {
        flex: 1;
        min-width: 0;
    }
    .admin-hero-name {
        font-size: 1.35rem;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: -0.01em;
        margin: 0 0 0.45rem 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .admin-hero-meta {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
        margin-bottom: 0.85rem;
    }
    .role-badge-super {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(217, 119, 6, 0.12) 100%);
        border: 1px solid rgba(245, 158, 11, 0.4);
        color: #fbbf24;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
    }
    .role-badge-admin {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        background: linear-gradient(135deg, rgba(56, 189, 248, 0.18) 0%, rgba(14, 165, 233, 0.1) 100%);
        border: 1px solid rgba(56, 189, 248, 0.35);
        color: #38bdf8;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .username-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #94a3b8;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .hero-email-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        color: #cbd5e1;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .admin-profile-hero .profile-picture-actions {
        display: inline-flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 0.65rem !important;
        width: auto !important;
        margin: 0 !important;
    }
    .btn-hero-photo {
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.45rem !important;
        padding: 0.45rem 1rem !important;
        background: rgba(59, 130, 246, 0.15) !important;
        border: 1px solid rgba(59, 130, 246, 0.35) !important;
        border-radius: 8px !important;
        color: #93c5fd !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        width: auto !important;
    }
    .btn-hero-photo:hover {
        background: #3b82f6 !important;
        border-color: #3b82f6 !important;
        color: #ffffff !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35) !important;
    }
    .btn-hero-remove {
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.45rem !important;
        padding: 0.45rem 0.9rem !important;
        background: rgba(239, 68, 68, 0.08) !important;
        border: 1px solid rgba(239, 68, 68, 0.22) !important;
        border-radius: 8px !important;
        color: #f87171 !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        width: auto !important;
    }
    .btn-hero-remove:hover {
        background: rgba(239, 68, 68, 0.2) !important;
        border-color: rgba(239, 68, 68, 0.45) !important;
        color: #fca5a5 !important;
        transform: translateY(-1px) !important;
    }
    @media (max-width: 640px) {
        .admin-profile-hero {
            flex-direction: column !important;
            text-align: center !important;
            padding: 1.25rem !important;
        }
        .profile-hero-left {
            flex-direction: column !important;
            text-align: center !important;
        }
        .admin-hero-meta {
            justify-content: center !important;
        }
        .admin-profile-hero .profile-picture-actions {
            justify-content: center !important;
        }
    }

    /* Form Section Dividers */
    .settings-form-section {
        background: rgba(15, 23, 42, 0.3);
        border: 1px solid rgba(148, 163, 184, 0.08);
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
    }
    .settings-form-section .section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
        font-weight: 600;
        color: #f1f5f9;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.1);
    }
    .settings-form-section .section-title i {
        color: #fbbf24;
    }
    .settings-form-section .section-hint {
        font-size: 0.8rem;
        color: #94a3b8;
        margin: -0.5rem 0 1rem 0;
    }

    /* Count Badge */
    .count-badge {
        background: rgba(251, 191, 36, 0.15);
        color: #fbbf24;
        border: 1px solid rgba(251, 191, 36, 0.3);
        padding: 0.15rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        margin-left: 0.5rem;
    }

    /* Notice Banner */
    .admin-notice-banner {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        padding: 0.9rem 1.1rem;
        background: rgba(56, 189, 248, 0.08);
        border: 1px solid rgba(56, 189, 248, 0.2);
        border-radius: 10px;
        color: #e2e8f0;
        margin-bottom: 1.25rem;
        font-size: 0.85rem;
    }
    .admin-notice-banner i {
        font-size: 1.1rem;
        color: #38bdf8;
        margin-top: 2px;
    }
    .admin-notice-banner strong {
        display: block;
        color: #f8fafc;
        margin-bottom: 0.15rem;
    }
    .admin-notice-banner p {
        margin: 0;
        color: #94a3b8;
        font-size: 0.8rem;
        line-height: 1.4;
    }

    /* Admin List Item Cards */
    .admin-list-wrapper {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        max-height: 480px;
        overflow-y: auto;
        padding-right: 4px;
    }
    .admin-user-card {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.9rem 1rem;
        background: rgba(15, 23, 42, 0.5);
        border: 1px solid rgba(148, 163, 184, 0.12);
        border-radius: 10px;
        transition: all 0.2s ease;
    }
    .admin-user-card:hover {
        background: rgba(15, 23, 42, 0.75);
        border-color: rgba(251, 191, 36, 0.3);
        transform: translateY(-1px);
    }
    .admin-user-card.is-current-user {
        border-color: rgba(251, 191, 36, 0.35);
        background: linear-gradient(135deg, rgba(251, 191, 36, 0.05), rgba(15, 23, 42, 0.5));
    }
    .admin-user-avatar {
        position: relative;
        width: 44px;
        height: 44px;
        min-width: 44px;
        border-radius: 50%;
        overflow: hidden;
        border: 2px solid rgba(148, 163, 184, 0.2);
    }
    .admin-user-card.is-current-user .admin-user-avatar {
        border-color: #fbbf24;
    }
    .admin-user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .online-dot {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 10px;
        height: 10px;
        background: #10b981;
        border: 2px solid #0f172a;
        border-radius: 50%;
    }
    .admin-user-details {
        flex: 1;
        min-width: 0;
    }
    .admin-user-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.25rem;
    }
    .admin-user-name {
        font-weight: 600;
        font-size: 0.95rem;
        color: #f1f5f9;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .badge-you {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: rgba(245, 158, 11, 0.2);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.4);
        padding: 0.1rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .admin-user-meta {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        flex-wrap: wrap;
        font-size: 0.75rem;
        color: #94a3b8;
    }
    .admin-user-meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .admin-user-meta i {
        color: #64748b;
    }
    .admin-user-badge {
        flex-shrink: 0;
    }

    /* Security Tips */
    .admin-security-tips {
        background: rgba(15, 23, 42, 0.3);
        border: 1px solid rgba(148, 163, 184, 0.08);
        border-radius: 10px;
        padding: 1rem 1.1rem;
    }
    .admin-security-tips h4 {
        font-size: 0.85rem;
        font-weight: 600;
        color: #e2e8f0;
        margin: 0 0 0.5rem 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .admin-security-tips h4 i {
        color: #fbbf24;
    }
    .admin-security-tips ul {
        margin: 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }
    .admin-security-tips li {
        font-size: 0.78rem;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .admin-security-tips li i {
        font-size: 0.7rem;
        color: #10b981;
    }
    </style>

    <style>
    /* Transition modal button danger state */
    #confirmTransitionBtn:not(:disabled) {
        background: linear-gradient(135deg, #ef4444, #dc2626) !important;
        border-color: transparent !important;
        cursor: pointer !important;
    }
    #confirmTransitionBtn:disabled {
        opacity: .45;
        cursor: not-allowed !important;
    }
    /* Spinner animation for confirm button loading state */
    #confirmTransitionBtn .btn-spinner { display: none; }
    #confirmTransitionBtn.loading .btn-spinner { display: inline-block; }
    #confirmTransitionBtn.loading .btn-icon { display: none; }
    @keyframes slideInToast {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .student-id-subcard:hover {
        border-color: rgba(148, 163, 184, 0.3) !important;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.25);
    }
    </style>
<script>
function downloadStudentIdTemplate() {
    const csvContent = "Student ID,Strand Code\n400123450008,HUMSS\n";
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'student_ids_template.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
</script>

</body>
</html>
