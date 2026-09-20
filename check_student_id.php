<?php
require_once 'config.php';

header('Content-Type: application/json');

$response = [
    'valid' => false,
    'message' => '',
    'exists' => false,
    'registered' => false,
    'grade_level' => null,
    'strand_code' => null,
    'strand_id' => null
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
    $student_id = trim($_POST['student_id']);
    
    if (empty($student_id)) {
        $response['message'] = 'Student ID is required';
        echo json_encode($response);
        exit;
    }
    
    global $mysqli;
    
    // Check if student_id exists in valid_student_ids along with grade_level and strand_code
    $stmt = $mysqli->prepare("SELECT is_registered, grade_level, strand_code, school_year_id FROM valid_student_ids WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Get active school year
    $syStmt = $mysqli->prepare("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
    $syStmt->execute();
    $syRes = $syStmt->get_result();
    $activeSyId = null;
    if ($syRow = $syRes->fetch_assoc()) {
        $activeSyId = (int)$syRow['id'];
    }
    $syStmt->close();

    if ($result->num_rows === 0) {
        $response['message'] = 'Invalid Student ID. Please contact your school administrator.';
    } else {
        $row = $result->fetch_assoc();
        
        // Prevent registration if the ID belongs to an old school year
        if ($activeSyId && $row['school_year_id'] != $activeSyId && $row['school_year_id'] !== null) {
            $response['message'] = 'This Student ID has expired and belongs to a previous school year. Please request a new ID.';
        } else {
            $response['exists'] = true;
            $response['registered'] = ($row['is_registered'] == 1);
        
        $gradeLevelDb = $row['grade_level'] ?? null;
        $strandCodeDb = $row['strand_code'] ?? null;
        $strandIdDb = null;
        
        if (!empty($strandCodeDb)) {
            $stQuery = "SELECT id, code, name, grade_level FROM strands WHERE id = ? OR code = ? OR name = ? LIMIT 1";
            $stStmt = $mysqli->prepare($stQuery);
            if ($stStmt) {
                $stStmt->bind_param("sss", $strandCodeDb, $strandCodeDb, $strandCodeDb);
                $stStmt->execute();
                $stRow = $stStmt->get_result()->fetch_assoc();
                $stStmt->close();
                if ($stRow) {
                    $strandIdDb = (int)$stRow['id'];
                    $strandCodeDb = $stRow['code'];
                    if (empty($gradeLevelDb) && !empty($stRow['grade_level'])) {
                        $gradeLevelDb = 'Grade ' . $stRow['grade_level'];
                    }
                } elseif (is_numeric($strandCodeDb)) {
                    $strandIdDb = (int)$strandCodeDb;
                }
            }
        }

        $response['grade_level'] = $gradeLevelDb;
        $response['strand_code'] = $strandCodeDb;
        $response['strand_id']   = $strandIdDb;
        
        if ($row['is_registered'] == 1) {
            $response['message'] = 'This Student ID is already registered. Please login instead.';
        } else {
            $response['valid'] = true;
            $response['message'] = 'Student ID is valid and available.';
        }
        } // close the else block for activeSyId check
    }
    
    $stmt->close();
}

echo json_encode($response);
?>
