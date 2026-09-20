<?php
session_start();
require_once '../config.php';
require_once '../system_config.php';
require_once '../includes/audit.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$action = $_POST['action'] ?? '';
if ($action !== 'preview' && $action !== 'execute') {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit();
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV file.']);
    exit();
}

$activeSchoolYearId = getCurrentSchoolYearId($mysqli);
if (!$activeSchoolYearId) {
    echo json_encode(['success' => false, 'message' => 'No active school year found.']);
    exit();
}

// 1. Fetch all Grade 12 students for the current school year to map old IDs
$studentQuery = $mysqli->prepare("
    SELECT id, student_id AS old_id, first_name, last_name, status 
    FROM students 
    WHERE grade_level = 'Grade 12' 
      AND school_year_id = ?
");
$studentQuery->bind_param('i', $activeSchoolYearId);
$studentQuery->execute();
$studentResult = $studentQuery->get_result();

$grade12Students = [];
$oldIdToDbId = [];
while ($row = $studentResult->fetch_assoc()) {
    $grade12Students[$row['id']] = $row;
    $oldIdToDbId[$row['old_id']] = $row['id'];
}
$studentQuery->close();

// 2. Fetch all existing student IDs across the ENTIRE system to check for global duplicates
$globalIdsQuery = $mysqli->query("SELECT id, student_id FROM students");
$allSystemStudentIds = [];
while ($row = $globalIdsQuery->fetch_assoc()) {
    $allSystemStudentIds[$row['student_id']] = $row['id'];
}

$file = $_FILES['csv_file']['tmp_name'];
$handle = fopen($file, "r");

if ($handle === FALSE) {
    echo json_encode(['success' => false, 'message' => 'Failed to read the CSV file.']);
    exit();
}

$rowsData = [];
$totalCount = 0;
$validCount = 0;
$errorCount = 0;

$rowNum = 0;
$newIdsInCsv = [];
$oldIdsInCsv = [];
$validatedMappings = [];

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $rowNum++;
    
    // Skip header row
    if ($rowNum === 1 && stripos(implode(' ', $data), 'First Name') !== false) {
        continue;
    }
    
    // Ensure row has at least 4 columns (First, Last, Old, New)
    if (count($data) < 4) {
        continue; // Skip completely empty or invalid rows
    }
    
    $totalCount++;
    $firstName = trim($data[0] ?? '');
    $lastName = trim($data[1] ?? '');
    $oldId = trim($data[2] ?? '');
    $newId = trim($data[3] ?? '');
    
    $nameDisplay = $firstName . ' ' . $lastName;
    $statusMsg = 'Valid';
    $isValid = true;
    
    // Validation 1: Blank New ID
    if ($newId === '') {
        $isValid = false;
        $statusMsg = 'New ID is blank';
    } 
    // Validation 2: New ID Format (digits only, 10-12 chars)
    elseif (!preg_match('/^\d{10,12}$/', $newId)) {
        $isValid = false;
        $statusMsg = 'New ID must be 10-12 digits';
    }
    // Validation 3: Duplicate New ID in the CSV file itself
    elseif (isset($newIdsInCsv[$newId])) {
        $isValid = false;
        $statusMsg = 'Duplicate New ID in CSV';
    }
    // Validation 4: Duplicate Old ID in the CSV file itself
    elseif (isset($oldIdsInCsv[$oldId])) {
        $isValid = false;
        $statusMsg = 'Duplicate Old ID in CSV';
    }
    // Validation 5: Old ID doesn't belong to an active/promoted Grade 12 student
    elseif (!isset($oldIdToDbId[$oldId])) {
        $isValid = false;
        $statusMsg = 'Old ID not found in current Grade 12 roster';
    } 
    else {
        // Validation 6: New ID already exists in the database
        $studentDbId = $oldIdToDbId[$oldId];
        
        // If the New ID exists in the DB, and it's NOT already owned by this exact student
        if (isset($allSystemStudentIds[$newId]) && $allSystemStudentIds[$newId] != $studentDbId) {
            $isValid = false;
            $statusMsg = 'New ID belongs to another student';
        }
    }
    
    if ($isValid) {
        $newIdsInCsv[$newId] = true;
        $oldIdsInCsv[$oldId] = true;
        $validCount++;
        $validatedMappings[] = [
            'db_id' => $oldIdToDbId[$oldId],
            'old_id' => $oldId,
            'new_id' => $newId
        ];
    } else {
        $errorCount++;
    }
    
    $rowsData[] = [
        'name' => htmlspecialchars($nameDisplay),
        'old_id' => htmlspecialchars($oldId),
        'new_id' => htmlspecialchars($newId),
        'status' => $isValid ? 'Valid' : 'Error',
        'message' => $statusMsg
    ];
}
fclose($handle);

if ($totalCount === 0) {
    echo json_encode(['success' => false, 'message' => 'The uploaded CSV file contains no valid data rows.']);
    exit();
}

if ($action === 'preview') {
    echo json_encode([
        'success' => true,
        'total' => $totalCount,
        'valid' => $validCount,
        'errors' => $errorCount,
        'rows' => $rowsData
    ]);
    exit();
}

if ($action === 'execute') {
    if ($errorCount > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot execute update. The CSV contains errors. Please fix them and re-upload.']);
        exit();
    }
    
    if ($validCount === 0) {
        echo json_encode(['success' => false, 'message' => 'No valid rows to update.']);
        exit();
    }

    $mysqli->begin_transaction();
    try {
        $updateStudentStmt = $mysqli->prepare("UPDATE students SET student_id = ? WHERE id = ?");
        
        // As per the plan: Update only the student's Grade 12 valid_student_ids record for the target school year
        // using the existing students.id, target school_year_id, and grade_level = 'Grade 12'
        $updateValidIdsStmt = $mysqli->prepare("
            UPDATE valid_student_ids 
            SET student_id = ? 
            WHERE registered_student_id = ? 
              AND school_year_id = ? 
              AND grade_level = 'Grade 12'
        ");
        
        foreach ($validatedMappings as $mapping) {
            $dbId = $mapping['db_id'];
            $newId = $mapping['new_id'];
            
            // 1. Update students table
            $updateStudentStmt->bind_param('si', $newId, $dbId);
            $updateStudentStmt->execute();
            
            // 2. Update valid_student_ids table for Grade 12
            $updateValidIdsStmt->bind_param('sii', $newId, $dbId, $activeSchoolYearId);
            $updateValidIdsStmt->execute();
        }
        
        $updateStudentStmt->close();
        $updateValidIdsStmt->close();
        
        // Audit log
        $adminId = $_SESSION['admin_id'];
        log_activity(
            $adminId,
            'admin',
            'bulk_update_grade12_ids',
            'students',
            0,
            "Updated Grade 12 school-issued Student IDs for {$validCount} promoted students."
        );
        
        $mysqli->commit();
        
        echo json_encode(['success' => true, 'message' => "Successfully updated $validCount Grade 12 Student IDs."]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error during update: ' . $e->getMessage()]);
    }
}
