<?php
// Manage Courses - Backend Added

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    $action = $_POST['action'];
    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
    
    switch ($action) {
        case 'add_course':
            $courseName = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $clusterId = !empty($_POST['cluster_id']) ? (int)$_POST['cluster_id'] : null;
            $strandId = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            $possibleCareers = trim($_POST['possible_careers'] ?? '');
            $selectedJobIds = array_map('intval', (array)($_POST['job_ids'] ?? []));
            
            if (empty($courseName) || empty($description)) {
                $response['message'] = 'Course name and description are required';
                echo json_encode($response);
                exit;
            }
            
            try {
                $stmt = $mysqli->prepare("
                    INSERT INTO courses (course_name, description, cluster_id, strand_id, possible_careers, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param('ssiis', $courseName, $description, $clusterId, $strandId, $possibleCareers);
                
                if ($stmt->execute()) {
                    $newCourseId = $mysqli->insert_id;
                    // Sync course_jobs
                    if (!empty($selectedJobIds)) {
                        $insJob = $mysqli->prepare("INSERT IGNORE INTO course_jobs (course_id, job_id) VALUES (?, ?)");
                        foreach ($selectedJobIds as $jid) {
                            if ($jid > 0) { 
                                $insJob->bind_param('ii', $newCourseId, $jid); 
                                $insJob->execute(); 
                                
                                // Fetch job title
                                $jTitle = '';
                                $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                                if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                                log_activity($userId, $userType, 'Link Job to Course', 'course_jobs', null, "Admin linked job {$jTitle} to {$courseName}", null, json_encode(['course_name' => $courseName, 'job_title' => $jTitle, 'job_id' => $jid]));
                            }
                        }
                        $insJob->close();
                    }

                    // Sync course_schools
                    $selectedSchoolIds = array_map('intval', (array)($_POST['school_ids'] ?? []));
                    if (!empty($selectedSchoolIds)) {
                        $insSchool = $mysqli->prepare("INSERT IGNORE INTO course_schools (course_id, school_id, is_specialization, notes) VALUES (?, ?, ?, '')");
                        foreach ($selectedSchoolIds as $sid) {
                            if ($sid > 0) {
                                $isSpec = isset($_POST['is_specialization_' . $sid]) ? 1 : 0;
                                $insSchool->bind_param('iii', $newCourseId, $sid, $isSpec);
                                $insSchool->execute();
                                
                                // Fetch school name
                                $sName = '';
                                $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                                if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                                $linkAction = $isSpec ? "marked {$sName} as specialization for" : "linked {$sName} to";
                                log_activity($userId, $userType, 'Link School to Course', 'course_schools', null, "Admin {$linkAction} {$courseName}", null, json_encode(['course_name' => $courseName, 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                            }
                        }
                        $insSchool->close();
                    }
                    
                    $clusterName = 'General';
                    if (!empty($clusterId)) {
                        $cQ = $mysqli->query("SELECT name FROM clusters WHERE id = {$clusterId}");
                        if ($cQ && ($cR = $cQ->fetch_assoc())) {
                            $clusterName = $cR['name'];
                        }
                    }
                    
                    $descriptionText = "Admin added {$courseName} under {$clusterName} cluster";
                    log_activity($userId, $userType, 'Added Course', 'courses', $newCourseId, $descriptionText, null, json_encode(['course_name' => $courseName, 'description' => $description, 'cluster_id' => $clusterId]));

                    $response['success'] = true;
                    $response['message'] = 'Course added successfully';
                    $response['id'] = $newCourseId;
                } else {
                    $response['message'] = 'Failed to add course: ' . $stmt->error;
                }
                $stmt->close();
            } catch (Throwable $e) {
                $response['message'] = 'Failed to add course: ' . $e->getMessage();
            }
            echo json_encode($response);
            exit;
            
        case 'get_course':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("SELECT * FROM courses WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $course = $result->fetch_assoc();
            $stmt->close();
            
            if ($course) {
                $response['success'] = true;
                $response['course'] = $course;
            } else {
                $response['message'] = 'Course not found';
            }
            echo json_encode($response);
            exit;

        case 'get_course_details':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("
                SELECT c.*, s.name as strand_name, s.code as strand_code, cl.name as cluster_name
                FROM courses c
                LEFT JOIN strands s ON c.strand_id = s.id
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE c.id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $course = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($course) {
                // Fetch linked jobs
                $jobStmt = $mysqli->prepare("
                    SELECT j.job_title 
                    FROM course_jobs cj
                    JOIN jobs j ON cj.job_id = j.id
                    WHERE cj.course_id = ?
                    ORDER BY j.job_title
                ");
                $jobStmt->bind_param('i', $courseId);
                $jobStmt->execute();
                $jobRes = $jobStmt->get_result();
                $jobs = [];
                while ($jRow = $jobRes->fetch_assoc()) {
                    $jobs[] = $jRow['job_title'];
                }
                $jobStmt->close();
                
                // Fetch linked schools
                $schoolStmt = $mysqli->prepare("
                    SELECT s.name, MAX(cs.is_specialization) AS is_specialization
                    FROM course_schools cs
                    JOIN schools s ON cs.school_id = s.id
                    WHERE cs.course_id = ?
                    GROUP BY s.id, s.name
                    ORDER BY MAX(cs.is_specialization) DESC, s.name
                ");
                $schoolStmt->bind_param('i', $courseId);
                $schoolStmt->execute();
                $schoolRes = $schoolStmt->get_result();
                $schools = [];
                while ($sRow = $schoolRes->fetch_assoc()) {
                    $schools[] = [
                        'name' => $sRow['name'],
                        'is_specialization' => (int)$sRow['is_specialization']
                    ];
                }
                $schoolStmt->close();
                
                $response['success'] = true;
                $response['course'] = $course;
                $response['jobs'] = $jobs;
                $response['schools'] = $schools;
            } else {
                $response['message'] = 'Course not found';
            }
            echo json_encode($response);
            exit;
            
        case 'edit_course':
            $courseId = (int)($_POST['id'] ?? 0);
            $courseName = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $clusterId = !empty($_POST['cluster_id']) ? (int)$_POST['cluster_id'] : null;
            $strandId = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            $possibleCareers = trim($_POST['possible_careers'] ?? '');
            $selectedJobIds = array_map('intval', (array)($_POST['job_ids'] ?? []));
            
            if ($courseId <= 0 || empty($courseName) || empty($description)) {
                $response['message'] = 'Invalid data provided';
                echo json_encode($response);
                exit;
            }
            
            // Fetch old course row
            $oldCourse = null;
            $cQuery = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
            if ($cQuery) {
                $cQuery->bind_param('i', $courseId);
                $cQuery->execute();
                $oldCourse = $cQuery->get_result()->fetch_assoc();
                $cQuery->close();
            }

            // Fetch old linked job IDs
            $oldJobIds = [];
            $jobQuery = $mysqli->prepare("SELECT job_id FROM course_jobs WHERE course_id = ?");
            if ($jobQuery) {
                $jobQuery->bind_param('i', $courseId);
                $jobQuery->execute();
                $jobRes = $jobQuery->get_result();
                while ($row = $jobRes->fetch_assoc()) {
                    $oldJobIds[] = (int)$row['job_id'];
                }
                $jobQuery->close();
            }

            // Fetch old linked schools
            $oldSchoolLinks = [];
            $schQuery = $mysqli->prepare("SELECT school_id, MAX(is_specialization) AS is_specialization FROM course_schools WHERE course_id = ? GROUP BY school_id");
            if ($schQuery) {
                $schQuery->bind_param('i', $courseId);
                $schQuery->execute();
                $schRes = $schQuery->get_result();
                while ($row = $schRes->fetch_assoc()) {
                    $oldSchoolLinks[(int)$row['school_id']] = (int)$row['is_specialization'];
                }
                $schQuery->close();
            }

            try {
                $stmt = $mysqli->prepare("
                    UPDATE courses SET course_name = ?, description = ?, cluster_id = ?, strand_id = ?, 
                    possible_careers = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssiisi', $courseName, $description, $clusterId, $strandId, $possibleCareers, $courseId);
                
                if ($stmt->execute()) {
                    // Sync course_jobs: delete all then re-insert selected
                    $delJobs = $mysqli->prepare("DELETE FROM course_jobs WHERE course_id = ?");
                    $delJobs->bind_param('i', $courseId);
                    $delJobs->execute();
                    $delJobs->close();
                    if (!empty($selectedJobIds)) {
                        $insJob = $mysqli->prepare("INSERT IGNORE INTO course_jobs (course_id, job_id) VALUES (?, ?)");
                        foreach ($selectedJobIds as $jid) {
                            if ($jid > 0) { $insJob->bind_param('ii', $courseId, $jid); $insJob->execute(); }
                        }
                        $insJob->close();
                    }

                // Sync course_schools: delete all then re-insert selected
                $selectedSchoolIds = array_map('intval', (array)($_POST['school_ids'] ?? []));
                $delSchools = $mysqli->prepare("DELETE FROM course_schools WHERE course_id = ?");
                $delSchools->bind_param('i', $courseId);
                $delSchools->execute();
                $delSchools->close();
                if (!empty($selectedSchoolIds)) {
                    $insSchool = $mysqli->prepare("INSERT IGNORE INTO course_schools (course_id, school_id, is_specialization, notes) VALUES (?, ?, ?, '')");
                    foreach ($selectedSchoolIds as $sid) {
                        if ($sid > 0) {
                            $isSpec = isset($_POST['is_specialization_' . $sid]) ? 1 : 0;
                            $insSchool->bind_param('iii', $courseId, $sid, $isSpec);
                            $insSchool->execute();
                            
                            // Log school link/specialization edits
                            $sName = '';
                            $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                            if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                            
                            $wasLinked = isset($oldSchoolLinks[$sid]);
                            $oldSpec = $wasLinked ? $oldSchoolLinks[$sid] : null;
                            
                            if (!$wasLinked) {
                                $linkAction = $isSpec ? "marked {$sName} as specialization for" : "linked {$sName} to";
                                log_activity($userId, $userType, 'Link School to Course', 'course_schools', null, "Admin {$linkAction} " . ($newCourse['course_name'] ?? $courseName), null, json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                            } elseif ($oldSpec !== $isSpec) {
                                $linkAction = $isSpec ? "marked {$sName} as specialization for" : "removed specialization tag of {$sName} from";
                                log_activity($userId, $userType, 'Update School Specialization', 'course_schools', null, "Admin {$linkAction} " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $oldSpec]), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                            }
                        }
                    }
                    $insSchool->close();
                }
                
                // Log unlinking of schools that are no longer selected
                foreach ($oldSchoolLinks as $sid => $oldSpec) {
                    if (!in_array($sid, $selectedSchoolIds)) {
                        $sName = '';
                        $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                        if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                        log_activity($userId, $userType, 'Unlink School from Course', 'course_schools', null, "Admin unlinked school {$sName} from " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid]), null);
                    }
                }

                // Fetch new course row for changes
                $newCourse = null;
                $cQuery2 = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
                if ($cQuery2) {
                    $cQuery2->bind_param('i', $courseId);
                    $cQuery2->execute();
                    $newCourse = $cQuery2->get_result()->fetch_assoc();
                    $cQuery2->close();
                }

                // Log changes in course fields (We now log full objects instead of just diffs)
                $oldChanges = $oldCourse;
                $newChanges = $newCourse;
                
                $descriptionText = "Admin edited course #{$courseId} (" . ($newCourse['course_name'] ?? $courseName) . ")";
                log_activity(
                    $userId,
                    $userType,
                    'Edited Course',
                    'courses',
                    $courseId,
                    $descriptionText,
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );

                // Log job links/unlinks
                // Added jobs: in selectedJobIds but not oldJobIds
                $addedJobs = array_diff($selectedJobIds, $oldJobIds);
                foreach ($addedJobs as $jid) {
                    if ($jid > 0) {
                        $jTitle = '';
                        $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                        if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                        log_activity($userId, $userType, 'Link Job to Course', 'course_jobs', null, "Admin linked job {$jTitle} to " . ($newCourse['course_name'] ?? $courseName), null, json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'job_title' => $jTitle, 'job_id' => $jid]));
                    }
                }
                // Removed jobs: in oldJobIds but not selectedJobIds
                $removedJobs = array_diff($oldJobIds, $selectedJobIds);
                foreach ($removedJobs as $jid) {
                    if ($jid > 0) {
                        $jTitle = '';
                        $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                        if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                        log_activity($userId, $userType, 'Unlink Job from Course', 'course_jobs', null, "Admin unlinked job {$jTitle} from " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'job_title' => $jTitle, 'job_id' => $jid]), null);
                    }
                }

                $response['success'] = true;
                $response['message'] = 'Course updated successfully';
            } else {
                $response['message'] = 'Failed to update course: ' . $stmt->error;
            }
            $stmt->close();
            } catch (Throwable $e) {
                $response['message'] = 'Failed to update course: ' . $e->getMessage();
            }
            echo json_encode($response);
            exit;

        case 'get_course_jobs':
            // Returns job IDs currently linked to a course (used to pre-check boxes on edit)
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT job_id FROM course_jobs WHERE course_id = ?");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $jobIds = [];
            while ($row = $result->fetch_assoc()) { $jobIds[] = (int)$row['job_id']; }
            $stmt->close();
            $response['success'] = true;
            $response['job_ids'] = $jobIds;
            echo json_encode($response); exit;

        case 'get_course_schools':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT school_id, MAX(is_specialization) AS is_specialization FROM course_schools WHERE course_id = ? GROUP BY school_id");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $schools = [];
            while ($row = $result->fetch_assoc()) { 
                $schools[] = [
                    'school_id' => (int)$row['school_id'],
                    'is_specialization' => (int)$row['is_specialization']
                ]; 
            }
            $stmt->close();
            $response['success'] = true;
            $response['schools'] = $schools;
            echo json_encode($response); exit;

        case 'add_job_inline':
            // Quick-add a new job without leaving the course modal
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            if (empty($title)) {
                $response['message'] = 'Job title is required.';
                echo json_encode($response); exit;
            }
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? LIMIT 1");
            $chk->bind_param('s', $title);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) {
                // Return existing job so it can be checked
                $chk->close();
                $existing = $mysqli->prepare("SELECT id, job_title FROM jobs WHERE job_title = ? LIMIT 1");
                $existing->bind_param('s', $title);
                $existing->execute();
                $job = $existing->get_result()->fetch_assoc();
                $existing->close();
                $response['success'] = true;
                $response['already_existed'] = true;
                $response['job'] = $job;
                echo json_encode($response); exit;
            }
            $chk->close();
            $stmt = $mysqli->prepare("INSERT INTO jobs (job_title, description, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('ss', $title, $desc);
            if ($stmt->execute()) {
                $newId = $mysqli->insert_id;
                $response['success'] = true;
                $response['message'] = 'Job created and selected.';
                $response['job'] = ['id' => $newId, 'job_title' => $title];
            } else {
                $response['message'] = 'Failed to create job.';
            }
            $stmt->close();
            echo json_encode($response); exit;

        case 'check_course_associations':
            $courseId = (int)($_POST['id'] ?? 0);
            $checkSchools = $mysqli->prepare("SELECT COUNT(*) as count FROM course_schools WHERE course_id = ?");
            $checkSchools->bind_param('i', $courseId);
            $checkSchools->execute();
            $schoolCount = (int)($checkSchools->get_result()->fetch_assoc()['count'] ?? 0);
            $checkSchools->close();

            $checkJobs = $mysqli->prepare("SELECT COUNT(*) as count FROM course_jobs WHERE course_id = ?");
            $checkJobs->bind_param('i', $courseId);
            $checkJobs->execute();
            $jobCount = (int)($checkJobs->get_result()->fetch_assoc()['count'] ?? 0);
            $checkJobs->close();

            $totalAssociations = $schoolCount + $jobCount;
            echo json_encode([
                'success' => true,
                'count' => $totalAssociations,
                'school_count' => $schoolCount,
                'job_count' => $jobCount
            ]);
            exit;

        case 'delete_course':
            $courseId = (int)($_POST['id'] ?? 0);
            $unlinkAssociations = !empty($_POST['unlink_associations']) || !empty($_POST['unlink_courses']);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            // Check if course is associated with any schools or jobs
            $checkS = $mysqli->prepare("SELECT COUNT(*) as count FROM course_schools WHERE course_id = ?");
            $checkS->bind_param('i', $courseId);
            $checkS->execute();
            $schoolCount = (int)($checkS->get_result()->fetch_assoc()['count'] ?? 0);
            $checkS->close();

            $checkJ = $mysqli->prepare("SELECT COUNT(*) as count FROM course_jobs WHERE course_id = ?");
            $checkJ->bind_param('i', $courseId);
            $checkJ->execute();
            $jobCount = (int)($checkJ->get_result()->fetch_assoc()['count'] ?? 0);
            $checkJ->close();

            $totalAssociations = $schoolCount + $jobCount;
            if ($totalAssociations > 0 && !$unlinkAssociations) {
                $response['has_associations'] = true;
                $response['count'] = $totalAssociations;
                $response['message'] = 'This course is currently associated with one or more schools or careers. Please confirm unlinking.';
                echo json_encode($response);
                exit;
            }
            
            // Fetch course details before deleting
            $oldCourse = null;
            $cQuery = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
            if ($cQuery) {
                $cQuery->bind_param('i', $courseId);
                $cQuery->execute();
                $oldCourse = $cQuery->get_result()->fetch_assoc();
                $cQuery->close();
            }

            // Database transaction: unlink course_schools, course_jobs, course_competencies, then delete course
            $mysqli->begin_transaction();
            try {
                if ($schoolCount > 0) {
                    $delCS = $mysqli->prepare("DELETE FROM course_schools WHERE course_id = ?");
                    $delCS->bind_param('i', $courseId);
                    if (!$delCS->execute()) {
                        throw new Exception('Failed to remove school associations: ' . $delCS->error);
                    }
                    $delCS->close();
                }

                if ($jobCount > 0) {
                    $delCJ = $mysqli->prepare("DELETE FROM course_jobs WHERE course_id = ?");
                    $delCJ->bind_param('i', $courseId);
                    if (!$delCJ->execute()) {
                        throw new Exception('Failed to remove career associations: ' . $delCJ->error);
                    }
                    $delCJ->close();
                }

                $delCC = $mysqli->prepare("DELETE FROM course_competencies WHERE course_id = ?");
                $delCC->bind_param('i', $courseId);
                $delCC->execute();
                $delCC->close();

                $stmt = $mysqli->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->bind_param('i', $courseId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete course: ' . $stmt->error);
                }
                $stmt->close();

                $mysqli->commit();

                $cName = $oldCourse['course_name'] ?? 'Unknown Course';
                $descriptionText = "Admin removed {$cName} from the system" . ($totalAssociations > 0 ? " (unlinked {$totalAssociations} association(s))" : "");
                log_activity($userId, $userType, 'Deleted Course', 'courses', $courseId, $descriptionText, json_encode($oldCourse), null);

                $response['success'] = true;
                $response['message'] = 'Course deleted successfully';
            } catch (Exception $ex) {
                $mysqli->rollback();
                $response['success'] = false;
                $response['message'] = $ex->getMessage();
            }
            echo json_encode($response);
            exit;
    }
}

// Get courses with related data
$courses = [];
$result = $mysqli->query("
    SELECT c.*, s.name as strand_name, s.code as strand_code, cl.name as cluster_name,
    (SELECT COUNT(*) FROM course_schools WHERE course_id = c.id) as school_count,
    (SELECT COUNT(*) FROM course_jobs WHERE course_id = c.id) as job_count
    FROM courses c
    LEFT JOIN strands s ON c.strand_id = s.id
    LEFT JOIN clusters cl ON c.cluster_id = cl.id
    ORDER BY c.course_name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get dropdown data
$strands  = $mysqli->query("SELECT id, name, code FROM strands ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$clusters = $mysqli->query("SELECT id, name FROM clusters ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$allJobs  = $mysqli->query("SELECT id, job_title FROM jobs ORDER BY job_title")->fetch_all(MYSQLI_ASSOC) ?? [];
$allSchools = $mysqli->query("SELECT id, name, city, province, region FROM schools ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$hollandTraits = ['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'];
$bigFiveTraits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css?v=<?php echo filemtime('admin.css'); ?>">
    <style>
        /* -- Jobs multi-select inside course modal -- */
        .jobs-multiselect {
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 10px;
            max-height: 120px;
            overflow-y: auto;
            padding: .25rem;
        }
        .job-checkbox-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .3rem .5rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background .15s;
            user-select: none;
        }
        .job-checkbox-item:hover { background: rgba(245,158,11,.08); }
        .job-checkbox-item input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 14px; height: 14px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .job-checkbox-item label {
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-primary, #f1f5f9);
            font-weight: 500;
            line-height: 1.3;
        }
        .inline-add-job-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .78rem;
            font-weight: 600;
            color: #f59e0b;
            background: rgba(245,158,11,.12);
            border: 1px dashed rgba(245,158,11,.45);
            border-radius: 8px;
            padding: .28rem .7rem;
            cursor: pointer;
            transition: background .2s, border-color .2s;
            margin-top: .35rem;
        }
        .inline-add-job-btn:hover { background: rgba(245,158,11,.22); border-color: rgba(245,158,11,.7); }
        .quick-add-job-form {
            display: none;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 10px;
            padding: .85rem;
            margin-top: .6rem;
        }
        .quick-add-job-form.visible { display: block; }
        .quick-add-job-form .qa-row { margin-bottom: .6rem; }
        .quick-add-job-form input,
        .quick-add-job-form textarea {
            font-size: .82rem;
            font-family: inherit;
            resize: none;
        }
        .quick-add-job-form input:focus,
        .quick-add-job-form textarea:focus { outline: none; border-color: #f59e0b; }
        .quick-add-job-form .qa-actions { display: flex; gap: .5rem; justify-content: flex-end; }
        .qa-save-btn {
            padding: .35rem .9rem;
            background: linear-gradient(135deg,#f59e0b,#d97706);
            border: none; border-radius: 8px;
            color: #fff; font-size: .78rem; font-weight: 700;
            cursor: pointer; transition: opacity .2s;
        }
        .qa-save-btn:hover { opacity: .85; }
        .qa-cancel-btn {
            padding: .35rem .8rem;
            background: transparent;
            border: 1px solid var(--border-color, rgba(255,255,255,.15));
            border-radius: 8px;
            color: var(--text-secondary,#94a3b8);
            font-size: .78rem; cursor: pointer;
        }
        .qa-cancel-btn:hover { border-color: #f59e0b; color: #f59e0b; }
        /* Compact modal body scroll so modal never overflows viewport */
        #addCourseModal .modal-body,
        #editCourseModal .modal-body {
            max-height: 68vh;
            overflow-y: auto;
            padding: 1rem 1.25rem;
        }
        /* Cluster + Description side-by-side */
        .course-form .cluster-desc-row {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .school-checkbox-item:hover { background: rgba(245,158,11,.04); }
        .school-checkbox-item:last-child { border-bottom: none; }
        .school-left, .school-right {
            display: flex;
            align-items: center;
            gap: .5rem;
            user-select: none;
        }
        .school-left input[type="checkbox"],
        .school-right input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 14px; height: 14px;
            cursor: pointer;
        }
        .school-left label,
        .school-right label {
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-primary, #f1f5f9);
            font-weight: 500;
        }
        .school-right label {
            color: var(--text-secondary, #94a3b8);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.78rem;
        }
        .school-right input[type="checkbox"]:checked + label {
            color: #fbbf24;
            font-weight: 600;
        }
        .school-right input[type="checkbox"]:checked + label i {
            color: #fbbf24;
        }
        .school-right input[type="checkbox"]:disabled + label {
            opacity: 0.4;
            cursor: not-allowed;
        }
        /* -- Schools multi-select inside course modal -- */
        .schools-multiselect {
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 10px;
            max-height: 150px;
            overflow-y: auto;
            padding: .25rem;
        }
        .school-checkbox-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .4rem .6rem;
            border-radius: 6px;
            transition: background .15s;
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }
        /* -- Modal Design Customizations (Matching screenshot layout with dark theme) -- */
        #addCourseModal .modal-content, #editCourseModal .modal-content {
            max-width: 780px !important;
            border-radius: 16px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
            overflow: hidden !important;
        }
        .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
        }
        .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .header-titles h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            border: none;
            padding: 0;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        .modal-header-custom .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }
        .input-with-icon {
            position: relative;
            width: 100%;
        }
        .input-with-icon i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.9rem;
            pointer-events: none;
        }
        .input-with-icon.textarea-icon i {
            top: 1rem;
            transform: none;
        }
        .input-with-icon input, .input-with-icon select, .input-with-icon textarea {
            padding-left: 2.6rem !important;
            background: #0b1120 !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 10px !important;
            color: #f8fafc !important;
        }
        .input-with-icon input:focus, .input-with-icon select:focus, .input-with-icon textarea:focus {
            border-color: rgba(99, 102, 241, 0.5) !important;
        }
        .modal-section-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            margin-top: 1.25rem;
        }
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .section-header-left {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .section-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .section-icon-badge.purple {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
        }
        .section-icon-badge.green {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        .section-title-text {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
        }
        .section-subtitle-text {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 1px 0 0 0;
        }
        .selection-count-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .selection-count-badge.purple {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .selection-count-badge.green {
            background: rgba(16, 185, 129, 0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .section-search-box {
            position: relative;
            margin: 0.85rem 0 0.65rem 0;
        }
        .section-search-box i.search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.8rem;
            pointer-events: none;
        }
        .section-search-box input {
            width: 100%;
            padding: 0.55rem 1rem 0.55rem 2.4rem;
            background: #0b1120;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: #f8fafc;
            font-size: 0.85rem;
            outline: none;
            box-sizing: border-box;
        }
        .section-search-box input:focus {
            border-color: rgba(99, 102, 241, 0.4);
        }
        .list-table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem 0.75rem;
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .custom-multiselect-list {
            max-height: 170px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 0.3rem 0;
        }
        .custom-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .custom-list-item:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        .custom-list-item-left {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .custom-list-item-left input[type="checkbox"] {
            accent-color: #6366f1;
            width: 15px;
            height: 15px;
            cursor: pointer;
        }
        .custom-list-item-left label {
            font-size: 0.85rem;
            color: #cbd5e1;
            font-weight: 500;
            cursor: pointer;
        }
        .spec-star-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            color: #475569;
            padding: 2px 6px;
            transition: color 0.2s, transform 0.15s;
        }
        .spec-star-btn:hover:not(:disabled) {
            transform: scale(1.15);
            color: #fbbf24;
        }
        .spec-star-btn.active {
            color: #f59e0b;
        }
        .spec-star-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        .btn-dashed-add-custom {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.06);
            border: 1px dashed rgba(16, 185, 129, 0.4);
            border-radius: 8px;
            color: #34d399;
            padding: 0.6rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.85rem;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-dashed-add-custom:hover {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.7);
        }

        /* -- Traits & Alignments Redesign ------------------- */
        .traits-redesign-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        @media (max-width: 900px) {
            .traits-redesign-grid {
                grid-template-columns: 1fr;
            }
        }
        .trait-group-card {
            background: #080d1a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .trait-group-card:hover {
            border-color: rgba(255, 255, 255, 0.14);
        }
        .trait-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .trait-group-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: 0.01em;
        }
        .trait-group-icon {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        .trait-group-card.holland .trait-group-icon {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
        }
        .trait-group-card.bigfive .trait-group-icon {
            background: rgba(129, 140, 248, 0.18);
            color: #a5b4fc;
        }
        .trait-group-card.strand .trait-group-icon {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
        }
        .trait-category-tag {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .trait-category-tag.holland {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .trait-category-tag.bigfive {
            background: rgba(129, 140, 248, 0.1);
            color: #818cf8;
            border: 1px solid rgba(129, 140, 248, 0.25);
        }
        .trait-category-tag.strand {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .trait-pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .trait-pill {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            user-select: none;
            transition: all 0.18s ease;
        }
        .trait-pill:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.16);
            color: #f1f5f9;
            transform: translateY(-1px);
        }
        .trait-pill input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .trait-pill-check {
            width: 15px;
            height: 15px;
            border-radius: 4px;
            border: 1.5px solid rgba(255, 255, 255, 0.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            color: transparent;
            background: transparent;
            transition: all 0.18s ease;
            flex-shrink: 0;
        }
        .trait-pill-text {
            font-size: 0.82rem;
            line-height: 1;
        }

        /* Holland Active */
        .trait-group-card.holland .trait-pill:has(input:checked),
        .trait-group-card.holland .trait-pill.is-checked {
            background: rgba(245, 158, 11, 0.14);
            border-color: rgba(245, 158, 11, 0.5);
            color: #fef08a;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.12);
        }
        .trait-group-card.holland .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.holland .trait-pill.is-checked .trait-pill-check {
            background: #f59e0b;
            border-color: #f59e0b;
            color: #0f172a;
        }

        /* Big Five Active */
        .trait-group-card.bigfive .trait-pill:has(input:checked),
        .trait-group-card.bigfive .trait-pill.is-checked {
            background: rgba(129, 140, 248, 0.14);
            border-color: rgba(129, 140, 248, 0.5);
            color: #e0e7ff;
            box-shadow: 0 0 12px rgba(129, 140, 248, 0.12);
        }
        .trait-group-card.bigfive .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.bigfive .trait-pill.is-checked .trait-pill-check {
            background: #6366f1;
            border-color: #6366f1;
            color: #ffffff;
        }

        /* Strand Active */
        .trait-group-card.strand .trait-pill:has(input:checked),
        .trait-group-card.strand .trait-pill.is-checked {
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.5);
            color: #a7f3d0;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.12);
        }
        .trait-group-card.strand .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.strand .trait-pill.is-checked .trait-pill-check {
            background: #10b981;
            border-color: #10b981;
            color: #0f172a;
        }

        /* ==========================================================================
           -- PREMIUM VIEW COURSE MODAL REDESIGN --
           ========================================================================== */
        #viewCourseModal .modal-content {
            max-width: 860px !important;
            width: 95% !important;
            max-height: 88vh !important;
            border-radius: 20px !important;
            background: linear-gradient(150deg, #111a2e 0%, #0c1322 60%, #070c16 100%) !important;
            border: 1px solid rgba(245, 158, 11, 0.25) !important;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.85), 0 0 50px rgba(245, 158, 11, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.12) !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            text-align: left !important;
            padding: 0 !important;
            animation: viewModalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes viewModalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.96) translateY(8px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Modal Custom Scrollbars */
        #viewCourseModal ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        #viewCourseModal ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 4px;
        }
        #viewCourseModal ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.25);
            border-radius: 4px;
        }
        #viewCourseModal ::-webkit-scrollbar-thumb:hover {
            background: rgba(245, 158, 11, 0.5);
        }

        /* Header (Permanent Anchored Hero) */
        #viewCourseModal .modal-header-custom {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 1.4rem 1.75rem 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(180deg, rgba(19, 29, 49, 0.98) 0%, rgba(14, 22, 38, 0.95) 100%);
            backdrop-filter: blur(12px);
            flex-shrink: 0;
            gap: 1.25rem;
        }
        #viewCourseModal .header-left-box {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            min-width: 0;
            flex: 1;
        }
        #viewCourseModal .icon-box-header {
            width: 50px;
            height: 50px;
            min-width: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.25), rgba(217, 119, 6, 0.12));
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #fbbf24;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            margin-top: 2px;
        }
        #viewCourseModal .header-titles {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }
        #viewCourseModal .header-eyebrow {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.35rem;
        }
        #viewCourseModal .program-badge {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #fbbf24;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        #viewCourseModal .course-id-chip {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.15rem 0.5rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            font-family: monospace;
        }
        #viewCourseModal .course-header-name {
            font-size: 1.45rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0 0 0.65rem 0;
            line-height: 1.25;
            letter-spacing: -0.01em;
            word-break: break-word;
        }
        #viewCourseModal .header-meta-tags {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }
        #viewCourseModal .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.28rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
        }
        #viewCourseModal .meta-pill.cluster-pill {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
        }
        #viewCourseModal .meta-pill.strand-pill {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }
        #viewCourseModal .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        #viewCourseModal .modal-close:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            transform: scale(1.05);
        }

        /* Modal Body */
        #viewCourseModal .view-modal-body {
            padding: 1.5rem 1.75rem;
            overflow-y: auto;
            overflow-x: hidden !important;
            display: flex;
            flex-direction: column;
            gap: 1.35rem;
            box-sizing: border-box;
        }

        /* Program Overview Card */
        #viewCourseModal .course-overview-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.5) 0%, rgba(15, 23, 42, 0.7) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.15rem 1.35rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }
        #viewCourseModal .overview-header {
            margin-bottom: 0.55rem;
        }
        #viewCourseModal .overview-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #fbbf24;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        #viewCourseModal .course-description-box {
            color: #cbd5e1;
            font-size: 0.92rem;
            line-height: 1.65;
            margin: 0;
            white-space: pre-line;
            word-break: break-word;
        }

        /* Two-Column Details Grid */
        #viewCourseModal .view-details-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 1.25rem;
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 768px) {
            #viewCourseModal .view-details-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        /* Card Section */
        #viewCourseModal .view-card-section {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.65) 0%, rgba(11, 18, 33, 0.75) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.2rem;
            display: flex;
            flex-direction: column;
            min-width: 0; /* Prevents CSS Grid blowout */
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            transition: border-color 0.2s;
        }
        #viewCourseModal .view-card-section:hover {
            border-color: rgba(255, 255, 255, 0.14);
        }
        #viewCourseModal .view-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            gap: 0.5rem;
        }
        #viewCourseModal .view-card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin: 0;
            min-width: 0;
        }
        #viewCourseModal .card-title-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        #viewCourseModal .card-title-icon.jobs-icon {
            background: rgba(129, 140, 248, 0.2);
            color: #818cf8;
            border: 1px solid rgba(129, 140, 248, 0.3);
        }
        #viewCourseModal .card-title-icon.schools-icon {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.3);
        }
        #viewCourseModal .selection-count-badge {
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }
        #viewCourseModal .selection-count-badge.purple {
            background: rgba(129, 140, 248, 0.15);
            border: 1px solid rgba(129, 140, 248, 0.3);
            color: #a5b4fc;
        }
        #viewCourseModal .selection-count-badge.gold {
            background: rgba(251, 191, 36, 0.15);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: #fbbf24;
        }

        /* Card List Container */
        #viewCourseModal .view-card-list {
            max-height: 250px;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            padding-right: 0.35rem;
        }

        /* Job Item Card */
        #viewCourseModal .job-item-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.85rem;
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            color: #f1f5f9;
            font-size: 0.88rem;
            font-weight: 500;
            min-width: 0;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        #viewCourseModal .job-item-card:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(129, 140, 248, 0.35);
            transform: translateX(4px);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        #viewCourseModal .job-bullet-icon {
            width: 22px;
            height: 22px;
            min-width: 22px;
            border-radius: 6px;
            background: rgba(129, 140, 248, 0.15);
            color: #818cf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
        }
        #viewCourseModal .job-title-text {
            word-break: break-word;
            line-height: 1.35;
            min-width: 0;
        }

        /* School Item Card */
        #viewCourseModal .school-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem 0.95rem;
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            min-width: 0;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            flex-wrap: wrap;
        }
        #viewCourseModal .school-item-card:hover {
            background: rgba(30, 41, 59, 0.75);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
        }
        #viewCourseModal .school-item-card.is-specialized {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.12) 0%, rgba(30, 41, 59, 0.5) 100%);
            border: 1px solid rgba(251, 191, 36, 0.35);
            border-left: 3px solid #f59e0b;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.08);
        }
        #viewCourseModal .school-info-wrap {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            flex: 1 1 200px;
        }
        #viewCourseModal .school-bullet-icon {
            width: 26px;
            height: 26px;
            min-width: 26px;
            border-radius: 7px;
            background: rgba(255, 255, 255, 0.06);
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
        #viewCourseModal .school-bullet-icon.gold {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
        }
        #viewCourseModal .school-name-text {
            color: #f1f5f9;
            font-size: 0.88rem;
            font-weight: 600;
            line-height: 1.35;
            word-break: break-word;
            min-width: 0;
        }
        #viewCourseModal .specialization-tag {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.25), rgba(245, 158, 11, 0.15));
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.4);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Empty State */
        #viewCourseModal .empty-list-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.75rem 1rem;
            text-align: center;
            color: #64748b;
            background: rgba(15, 23, 42, 0.3);
            border: 1px dashed rgba(255, 255, 255, 0.08);
            border-radius: 10px;
        }
        #viewCourseModal .empty-list-card i {
            font-size: 1.6rem;
            margin-bottom: 0.5rem;
            color: #475569;
        }
        #viewCourseModal .empty-list-card p {
            font-size: 0.84rem;
            margin: 0;
            line-height: 1.4;
        }

        /* Modal Footer */
        #viewCourseModal .view-modal-footer {
            padding: 1.15rem 1.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(180deg, rgba(14, 22, 38, 0.95) 0%, rgba(9, 15, 27, 0.98) 100%);
            backdrop-filter: blur(10px);
            flex-shrink: 0;
            gap: 1rem;
        }
        #viewCourseModal .footer-left-info {
            display: flex;
            align-items: center;
        }
        #viewCourseModal .system-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
        }
        #viewCourseModal .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px #10b981;
        }
        #viewCourseModal .footer-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        #viewCourseModal .btn-close-view-modal {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            padding: 0.65rem 1.35rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        #viewCourseModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.2);
        }
        #viewCourseModal .btn-edit-course-modal {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #0f172a;
            border: none;
            padding: 0.65rem 1.45rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        #viewCourseModal .btn-edit-course-modal:hover {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            box-shadow: 0 6px 22px rgba(245, 158, 11, 0.5);
            transform: translateY(-2px);
        }

        /* -- Redesigned Schools / Careers Selector Section (Add & Edit Course Modals) -- */
        #addCourseModal .modal-content,
        #editCourseModal .modal-content {
            max-width: 840px !important;
            width: 95% !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
            text-align: left !important;
        }
        #addCourseModal .modal-body,
        #editCourseModal .modal-body {
            text-align: left !important;
            padding: 1.5rem !important;
        }
        .courses-offered-section {
            margin-top: 1.5rem;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            text-align: left;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }
        .co-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .co-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .co-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .co-header-icon.purple {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border-color: rgba(99, 102, 241, 0.3);
        }
        .co-header-icon.green {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.3);
        }
        .co-header-titles {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .co-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .co-header-subtitle {
            font-size: 0.78rem;
            color: #94a3b8;
            margin: 0.15rem 0 0 0;
        }
        .co-header-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .co-count-badge {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            letter-spacing: 0.02em;
        }
        .co-btn-clear-all {
            background: none;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0.3rem 0.65rem;
            border-radius: 6px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .co-btn-clear-all:hover {
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.12);
        }
        .co-search-box {
            position: relative;
            margin-bottom: 0.9rem;
        }
        .co-search-input {
            width: 100% !important;
            padding: 0.75rem 1rem 0.75rem 2.85rem !important;
            background: rgba(15, 23, 42, 0.85) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 10px !important;
            color: #f8fafc !important;
            font-size: 0.9rem !important;
            font-family: inherit !important;
            transition: all 0.2s ease !important;
            box-sizing: border-box !important;
        }
        .co-search-input:focus {
            outline: none !important;
            border-color: #fbbf24 !important;
            background: rgba(15, 23, 42, 0.95) !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15) !important;
        }
        .co-search-input::placeholder {
            color: #64748b !important;
        }
        .co-search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.92rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .co-search-box:focus-within .co-search-icon {
            color: #fbbf24;
        }
        .co-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 10px;
            max-height: 220px;
            overflow-y: auto;
            z-index: 1050;
            box-shadow: 0 16px 36px -4px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
        }
        .co-dropdown.open { display: block; }
        .co-dropdown::-webkit-scrollbar { width: 6px; }
        .co-dropdown::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.5); border-radius: 10px; }
        .co-dropdown::-webkit-scrollbar-thumb { background: rgba(251, 191, 36, 0.35); border-radius: 10px; }
        .co-dropdown::-webkit-scrollbar-thumb:hover { background: rgba(251, 191, 36, 0.7); }
        .co-dropdown-item {
            padding: 0.65rem 0.95rem;
            font-size: 0.88rem;
            color: #cbd5e1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: all 0.15s ease;
            text-align: left;
        }
        .co-dropdown-item:last-child { border-bottom: none; }
        .co-dropdown-item:hover {
            background: rgba(245, 158, 11, 0.12);
            color: #fbbf24;
        }
        .co-dropdown-item.already-selected {
            color: #475569;
            cursor: default;
            background: rgba(15, 23, 42, 0.4);
        }
        .co-dropdown-item.already-selected:hover {
            background: rgba(15, 23, 42, 0.4);
            color: #475569;
        }
        .co-dropdown-item .item-text-box {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
            overflow: hidden;
        }
        .co-dropdown-item .item-text-box i {
            color: #60a5fa;
            font-size: 0.82rem;
            flex-shrink: 0;
        }
        .co-dropdown-item .item-text-box span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .co-dropdown-item .item-actions-box {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .co-dropdown-item .item-spec-btn {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.22rem 0.55rem;
            border-radius: 6px;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
        }
        .co-dropdown-item .item-spec-btn:hover {
            background: rgba(251, 191, 36, 0.18);
            border-color: rgba(251, 191, 36, 0.4);
            color: #fbbf24;
            transform: scale(1.02);
        }
        .co-dropdown-item .item-spec-btn.is-spec {
            color: #fbbf24;
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.25), rgba(245, 158, 11, 0.12));
            border-color: rgba(251, 191, 36, 0.5);
            font-weight: 700;
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.15);
        }
        .co-dropdown-item .item-spec-btn.is-spec:hover {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.35), rgba(245, 158, 11, 0.2));
            border-color: rgba(251, 191, 36, 0.7);
        }
        .co-dropdown-item .item-add-btn {
            font-size: 0.72rem;
            font-weight: 700;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-color: rgba(245, 158, 11, 0.3);
            padding: 0.22rem 0.55rem;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .co-dropdown-item .item-add-btn:hover {
            background: rgba(245, 158, 11, 0.25);
            border-color: rgba(245, 158, 11, 0.5);
        }
        .co-dropdown-item.already-selected .item-add-btn {
            color: #64748b;
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            cursor: default;
        }
        .co-dropdown-empty {
            padding: 0.85rem 1rem;
            font-size: 0.84rem;
            color: #64748b;
            font-style: italic;
            text-align: center;
        }
        .co-chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
            min-height: 44px;
            align-items: center;
        }
        .co-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(217, 119, 6, 0.06));
            border: 1px solid rgba(245, 158, 11, 0.28);
            color: #fde68a;
            border-radius: 9px;
            padding: 0.45rem 0.75rem 0.45rem 0.85rem;
            font-size: 0.84rem;
            font-weight: 500;
            line-height: 1.35;
            text-align: left;
            transition: all 0.2s ease;
            max-width: 100%;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }
        .co-chip:hover {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(217, 119, 6, 0.1));
            border-color: rgba(245, 158, 11, 0.45);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.15);
        }
        .co-chip.is-specialized {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.22), rgba(217, 119, 6, 0.14)) !important;
            border: 1px solid rgba(245, 158, 11, 0.65) !important;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.25) !important;
        }
        .co-chip-icon {
            color: #fbbf24;
            font-size: 0.82rem;
            flex-shrink: 0;
        }
        .co-chip-name {
            word-break: break-word;
        }
        .co-spec-badge {
            font-size: 0.68rem;
            font-weight: 700;
            color: #0f172a;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            padding: 0.18rem 0.5rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-left: 0.15rem;
            letter-spacing: 0.02em;
            box-shadow: 0 2px 6px rgba(245, 158, 11, 0.35);
            flex-shrink: 0;
        }
        .co-chip-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-left: auto;
            flex-shrink: 0;
        }
        .co-chip-star {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #94a3b8;
            cursor: pointer;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.76rem;
            transition: all 0.2s ease;
            flex-shrink: 0;
            padding: 0;
        }
        .co-chip-star:hover {
            background: rgba(251, 191, 36, 0.25);
            border-color: rgba(251, 191, 36, 0.5);
            color: #fbbf24;
            transform: scale(1.1);
        }
        .co-chip-star.active {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #0f172a;
            border-color: #fbbf24;
            box-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }
        .co-chip-remove {
            background: rgba(245, 158, 11, 0.15);
            border: none;
            color: #f59e0b;
            cursor: pointer;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            transition: all 0.15s ease;
            flex-shrink: 0;
            padding: 0;
        }
        .co-chip-remove:hover {
            background: rgba(239, 68, 68, 0.25);
            color: #f87171;
            transform: scale(1.15);
        }
        .co-empty-state {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px dashed rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #64748b;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            text-align: center;
        }
        .co-empty-state i {
            color: #475569;
            font-size: 1rem;
        }

        /* Search bar and clear button alignment */
        .search-filter {
            display: flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            flex: 0 1 auto !important;
            min-width: 0 !important;
        }
        .search-box-wrapper {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.45rem !important;
            flex: 0 1 auto !important;
            min-width: fit-content !important;
            max-width: none !important;
        }
        .search-box-wrapper .search-box {
            position: relative !important;
            width: 280px !important;
            min-width: 220px !important;
            max-width: 360px !important;
            flex: 1 1 auto !important;
            display: flex !important;
            align-items: center !important;
        }
        .search-box-wrapper .search-box input {
            width: 100% !important;
            height: 38px !important;
            box-sizing: border-box !important;
            padding: 0.5rem 0.75rem 0.5rem 2.25rem !important;
        }
        .search-box-wrapper .btn-search {
            height: 38px !important;
            box-sizing: border-box !important;
            padding: 0 1rem !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            margin: 0 !important;
            flex-shrink: 0 !important;
            white-space: nowrap !important;
        }
        .search-filter .btn-clear {
            height: 38px !important;
            box-sizing: border-box !important;
            padding: 0 0.85rem !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            margin: 0 !important;
            flex-shrink: 0 !important;
            white-space: nowrap !important;
            align-self: center !important;
        }
    </style>
</head>
<body>
    <!-- Dashboard Container -->
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
                    <button class="nav-group-toggle open active-group" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-briefcase group-icon"></i>
                        <span>Career Management</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu open">
                        <a href="manage_clusters.php" class="nav-subitem">
                            <i class="fa-solid fa-layer-group"></i>
                            Manage Career Clusters
                        </a>
                        <a href="manage_courses.php" class="nav-subitem active">
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
                <a href="settings.php" class="nav-item">
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
                    <h1>Manage Courses</h1>
                </div>
                <?php 
                // Get admin name
                $userName = $_SESSION['admin_name'] ?? 'Admin User';
                
                // Get notifications
                $notifications = [];
                $unreadCount = 0;
                $adminId = $_SESSION['admin_id'] ?? null;
                $adminProfilePic = null;
                
                if ($adminId) {
                    // Get admin profile picture
                    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                    $profileStmt->bind_param('i', $adminId);
                    $profileStmt->execute();
                    $profileResult = $profileStmt->get_result();
                    $adminData = $profileResult->fetch_assoc();
                    $adminProfilePic = $adminData['profile_picture'] ?? null;
                    $profileStmt->close();
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

            <!-- Courses Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-actions">
                        <div class="search-filter">
                            <div class="search-box-wrapper">
                                <div class="search-box">
                                    <i class="fa-solid fa-search"></i>
                                    <input type="text" id="searchInput" placeholder="Search courses, clusters, strand...">
                                </div>
                                <button class="btn-search" id="searchBtn">
                                    <i class="fa-solid fa-search"></i>
                                    Search
                                </button>
                            </div>
                            <button class="btn-clear" id="clearFilter">
                                <i class="fa-solid fa-times"></i>
                                Clear
                            </button>
                        </div>
                        <div class="header-info">
                            <span class="count-badge">
                                <i class="fa-solid fa-book"></i>
                                <span id="totalCourses"><?php echo count($courses); ?></span> Courses
                            </span>
                        </div>
                        <button class="btn-primary" id="addCourseBtn">
                            <i class="fa-solid fa-plus"></i>
                            Add Course
                        </button>
                    </div>
                </div>

                <!-- Courses Table -->
                <div class="table-section">
                    <div class="table-container">
                        <table class="data-table courses-table">
                            <thead>
                                <tr>
                                    <th>Course Name</th>
                                    <th>Career Cluster</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="4" class="no-data">
                                        <i class="fa-solid fa-inbox"></i>
                                        <p>No courses found. Click "Add Course" to create your first course.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($courses as $course): ?>
                                    <tr data-id="<?php echo $course['id']; ?>">
                                        <td class="course-name">
                                            <?php echo htmlspecialchars($course['course_name']); ?>
                                        </td>
                                        <td>
                                            <?php if ($course['cluster_name']): ?>
                                            <span class="cluster-badge"><?php echo htmlspecialchars($course['cluster_name']); ?></span>
                                            <?php else: ?>
                                            <span class="cluster-badge">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="description-cell"><?php echo htmlspecialchars($course['description'] ?: '-'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action view" data-id="<?php echo $course['id']; ?>" title="View">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-action edit" data-id="<?php echo $course['id']; ?>" title="Edit">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-action delete" data-id="<?php echo $course['id']; ?>" data-total-associations="<?php echo (int)(($course['school_count'] ?? 0) + ($course['job_count'] ?? 0)); ?>" title="Delete">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
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
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Add Course Modal -->
    <div class="modal" id="addCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Add New Course</h2>
                        <p>Create a new course and connect it with schools and careers.</p>
                    </div>
                </div>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <form id="addCourseForm" class="course-form">
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="courseName" style="font-weight:600; margin-bottom:0.4rem; display:block;">Course Name <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <input type="text" id="courseName" name="course_name" required placeholder="Enter course name">
                            </div>
                        </div>
                    </div>
                    <div class="cluster-desc-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                        <div class="form-group">
                            <label for="cluster" style="font-weight:600; margin-bottom:0.4rem; display:block;">Career Cluster</label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-border-all"></i>
                                <select id="cluster" name="cluster_id">
                                    <option value="">Select cluster (optional)</option>
                                    <?php foreach ($clusters as $cluster): ?>
                                    <option value="<?php echo $cluster['id']; ?>"><?php echo htmlspecialchars($cluster['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="strand" style="font-weight:600; margin-bottom:0.4rem; display:block;">SHS Strand (Optional)</label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-book-open"></i>
                                <select id="strand" name="strand_id">
                                    <option value="">Select strand (optional)</option>
                                    <?php foreach ($strands as $st): ?>
                                    <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name'] . ' (' . $st['code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="description" style="font-weight:600; margin-bottom:0.4rem; display:block;">Description <span class="required">*</span></label>
                            <div class="input-with-icon textarea-icon">
                                <i class="fa-solid fa-file-lines"></i>
                                <textarea id="description" name="description" rows="2" required placeholder="Enter course description..."></textarea>
                            </div>
                        </div>
                    </div>


                    <!-- Available Schools Section (Add Course) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon purple">
                                    <i class="fa-solid fa-landmark"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Available Schools</span>
                                    <span class="co-header-subtitle">Search institutions &amp; click the star <i class="fa-solid fa-star" style="color:#fbbf24; font-size:0.75rem;"></i> to mark as <strong>Center of Specialization</strong> (Best in Course)</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="addSchoolCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="addSchoolClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="addSchoolSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="addSchoolSearchInput" placeholder="Search schools by name or city (e.g. Pangasinan State University)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="addSchoolSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="addSchoolDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="addSchoolChips">
                            <div class="co-empty-state" id="addSchoolNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No schools linked yet. Type in the box above to search and add offering schools.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Possible Careers Section (Add Course) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon green">
                                    <i class="fa-solid fa-briefcase"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Possible Careers</span>
                                    <span class="co-header-subtitle">Search &amp; link career pathways that apply to this course</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="addJobCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="addJobClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="addJobSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="addJobSearchInput" placeholder="Search careers by name (e.g. Software Engineer)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="addJobSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="addJobDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="addJobChips">
                            <div class="co-empty-state" id="addJobNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No careers linked yet. Type in the box above to search and link careers.</span>
                            </div>
                        </div>
                        <button type="button" class="btn-dashed-add-custom" id="addModalQuickAddBtn" style="margin-top: 0.85rem;">
                            <i class="fa-solid fa-plus"></i> Add new job / career
                        </button>
                        <!-- Quick-add inline form -->
                        <div class="quick-add-job-form" id="addModalQuickForm" style="margin-top: 0.75rem;">
                            <div class="qa-row">
                                <input type="text" id="qaAddTitle" placeholder="Job title *" autocomplete="off">
                            </div>
                            <div class="qa-row">
                                <textarea id="qaAddDesc" rows="2" placeholder="Short description (optional)"></textarea>
                            </div>
                            <div class="qa-actions">
                                <button type="button" class="qa-cancel-btn" id="qaAddCancel">Cancel</button>
                                <button type="button" class="qa-save-btn" id="qaAddSave">Save &amp; Select</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" id="cancelAdd" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-primary" form="addCourseForm" style="display:inline-flex; align-items:center; gap:0.4rem; background: #6366f1; border-color: #6366f1;">
                    <i class="fa-solid fa-floppy-disk"></i> Add Course
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div class="modal" id="editCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Edit Course</h2>
                        <p>Modify course information, offering schools, and linked careers.</p>
                    </div>
                </div>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <form id="editCourseForm" class="course-form">
                    <input type="hidden" id="editCourseId" name="id">
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="editCourseName" style="font-weight:600; margin-bottom:0.4rem; display:block;">Course Name <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <input type="text" id="editCourseName" name="course_name" required placeholder="Enter course name">
                            </div>
                        </div>
                    </div>
                    <div class="cluster-desc-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                        <div class="form-group">
                            <label for="editCluster" style="font-weight:600; margin-bottom:0.4rem; display:block;">Career Cluster</label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-border-all"></i>
                                <select id="editCluster" name="cluster_id">
                                    <option value="">Select cluster (optional)</option>
                                    <?php foreach ($clusters as $cluster): ?>
                                    <option value="<?php echo $cluster['id']; ?>"><?php echo htmlspecialchars($cluster['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="editStrand" style="font-weight:600; margin-bottom:0.4rem; display:block;">SHS Strand (Optional)</label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-book-open"></i>
                                <select id="editStrand" name="strand_id">
                                    <option value="">Select strand (optional)</option>
                                    <?php foreach ($strands as $st): ?>
                                    <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name'] . ' (' . $st['code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="editDescription" style="font-weight:600; margin-bottom:0.4rem; display:block;">Description <span class="required">*</span></label>
                            <div class="input-with-icon textarea-icon">
                                <i class="fa-solid fa-file-lines"></i>
                                <textarea id="editDescription" name="description" rows="2" required placeholder="Enter course description..."></textarea>
                            </div>
                        </div>
                    </div>


                    <!-- Available Schools Section (Edit Course) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon purple">
                                    <i class="fa-solid fa-landmark"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Available Schools</span>
                                    <span class="co-header-subtitle">Search institutions &amp; click the star <i class="fa-solid fa-star" style="color:#fbbf24; font-size:0.75rem;"></i> to mark as <strong>Center of Specialization</strong> (Best in Course)</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="editSchoolCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="editSchoolClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="editSchoolSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="editSchoolSearchInput" placeholder="Search schools by name or city (e.g. Pangasinan State University)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="editSchoolSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="editSchoolDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="editSchoolChips">
                            <div class="co-empty-state" id="editSchoolNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No schools linked yet. Type in the box above to search and add offering schools.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Possible Careers Section (Edit Course) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon green">
                                    <i class="fa-solid fa-briefcase"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Possible Careers</span>
                                    <span class="co-header-subtitle">Search &amp; link career pathways that apply to this course</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="editJobCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="editJobClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="editJobSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="editJobSearchInput" placeholder="Search careers by name (e.g. Software Engineer)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="editJobSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="editJobDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="editJobChips">
                            <div class="co-empty-state" id="editJobNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No careers linked yet. Type in the box above to search and link careers.</span>
                            </div>
                        </div>
                        <button type="button" class="btn-dashed-add-custom" id="editModalQuickAddBtn" style="margin-top: 0.85rem;">
                            <i class="fa-solid fa-plus"></i> Add new job / career
                        </button>
                        <!-- Quick-add inline form -->
                        <div class="quick-add-job-form" id="editModalQuickForm" style="margin-top: 0.75rem;">
                            <div class="qa-row">
                                <input type="text" id="qaEditTitle" placeholder="Job title *" autocomplete="off">
                            </div>
                            <div class="qa-row">
                                <textarea id="qaEditDesc" rows="2" placeholder="Short description (optional)"></textarea>
                            </div>
                            <div class="qa-actions">
                                <button type="button" class="qa-cancel-btn" id="qaEditCancel">Cancel</button>
                                <button type="button" class="qa-save-btn" id="qaEditSave">Save &amp; Select</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" id="cancelEdit" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-primary" form="editCourseForm" style="display:inline-flex; align-items:center; gap:0.4rem; background: #6366f1; border-color: #6366f1;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-exclamation-triangle"></i> <span id="deleteModalTitleText">Confirm Delete</span></h2>
                <button class="modal-close" id="closeDeleteModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm">
                    <div class="delete-icon">
                        <i class="fa-solid fa-trash-alt"></i>
                    </div>
                    <p class="delete-message" id="deleteCourseMessage">Are you sure you want to delete this course?</p>
                    <p class="delete-warning" id="deleteCourseWarning">This action cannot be undone.</p>
                    <div class="delete-course-info">
                        <span class="course-name" id="deleteCourseName">Computer Science</span>
                        <span class="course-cluster" id="deleteCourseCluster">Information Technology</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete">Delete</button>
            </div>
        </div>
    </div>

    <!-- View Course Modal -->
    <div class="modal" id="viewCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <!-- Modal Header (Anchored Course Hero) -->
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div class="header-titles">
                        <div class="header-eyebrow">
                            <span class="program-badge"><i class="fa-solid fa-certificate"></i> Program Specifications</span>
                            <span class="course-id-chip" id="viewCourseIdBadge">ID: #0</span>
                        </div>
                        <h2 id="viewCourseName" class="course-header-name">Course Title</h2>
                        <div class="header-meta-tags">
                            <span class="meta-pill cluster-pill" id="viewCourseClusterPill">
                                <i class="fa-solid fa-layer-group"></i>
                                <span id="viewCourseCluster">Cluster Name</span>
                            </span>
                            <span class="meta-pill strand-pill" id="viewCourseStrandPill">
                                <i class="fa-solid fa-compass"></i>
                                <span id="viewCourseStrand">SHS Strand</span>
                            </span>
                        </div>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal" title="Close Modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div class="view-modal-body">
                <!-- Course Overview / Description Section -->
                <div class="course-overview-card">
                    <div class="overview-header">
                        <div class="overview-title">
                            <i class="fa-solid fa-file-lines"></i>
                            Program Overview & Objectives
                        </div>
                    </div>
                    <p class="course-description-box" id="viewCourseDescription">No description available for this program.</p>
                </div>

                <!-- 2-Column Info: Linked Jobs & Offering Schools -->
                <div class="view-details-grid">
                    <!-- Linked Jobs -->
                    <div class="view-card-section jobs-section">
                        <div class="view-card-header">
                            <h4 class="view-card-title">
                                <span class="card-title-icon jobs-icon">
                                    <i class="fa-solid fa-briefcase"></i>
                                </span>
                                <span>Career Pathways & Jobs</span>
                            </h4>
                            <span class="selection-count-badge purple" id="viewJobsCount">0 Jobs</span>
                        </div>
                        <div class="view-card-list" id="viewCourseJobsList">
                            <!-- Jobs populated dynamically -->
                        </div>
                    </div>

                    <!-- Offering Schools -->
                    <div class="view-card-section schools-section">
                        <div class="view-card-header">
                            <h4 class="view-card-title">
                                <span class="card-title-icon schools-icon">
                                    <i class="fa-solid fa-building-columns"></i>
                                </span>
                                <span>Offering Institutions</span>
                            </h4>
                            <span class="selection-count-badge gold" id="viewSchoolsCount">0 Schools</span>
                        </div>
                        <div class="view-card-list" id="viewCourseSchoolsList">
                            <!-- Schools populated dynamically -->
                        </div>
                    </div>
                </div>
            </div>

            <div class="view-modal-footer">
                <div class="footer-left-info">
                    <span class="system-status-indicator">
                        <span class="status-dot"></span>
                        Verified Academic Offering
                    </span>
                </div>
                <div class="footer-actions">
                    <button type="button" class="btn-close-view-modal" id="closeViewCourseBtn">Close</button>
                    <button type="button" class="btn-edit-course-modal" id="viewEditCourseBtn">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Course
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Remove School Confirmation Modal -->
    <div class="modal" id="removeSchoolModal" style="z-index: 10050;">
        <div class="modal-overlay" id="removeSchoolOverlay"></div>
        <div class="modal-content" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); color: #a5b4fc; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-landmark"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;">Remove School?</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;">Do you want to unlink this school from this course?</p>
                <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #c7d2fe; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="removeSchoolTargetName">
                    School Name
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button type="button" class="btn-secondary" id="cancelRemoveSchoolBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                    <button type="button" class="btn-danger" id="confirmRemoveSchoolBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; font-weight: 600; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Remove</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Center of Specialization / Best in Course Confirmation Modal -->
    <div class="modal" id="specializationModal" style="z-index: 10050;">
        <div class="modal-overlay" id="specModalOverlay"></div>
        <div class="modal-content" style="max-width: 450px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-star"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;" id="specModalTitle">Center of Specialization</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;" id="specModalMessage">Do you want to mark this school as Center of Specialization (Best in Course)?</p>
                <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #fde68a; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="specModalTargetName">
                    School Name
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button type="button" class="btn-secondary" id="cancelSpecModalBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                    <button type="button" class="btn-primary" id="confirmSpecModalBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #0f172a; border: none; font-weight: 700; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Remove Career Confirmation Modal -->
    <div class="modal" id="removeJobModal" style="z-index: 10050;">
        <div class="modal-overlay" id="removeJobOverlay"></div>
        <div class="modal-content" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;">Remove Career?</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;">Do you want to unlink this career pathway from this course?</p>
                <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #a7f3d0; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="removeJobTargetName">
                    Career Title
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button type="button" class="btn-secondary" id="cancelRemoveJobBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                    <button type="button" class="btn-danger" id="confirmRemoveJobBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; font-weight: 600; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Remove</button>
                </div>
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
                <p id="statusMessage" style="color: #cbd5e1; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5;"></p>
                <button type="button" class="btn-primary" id="statusOkBtn" style="width: 100%; justify-content: center; padding: 0.75rem;">OK</button>
            </div>
        </div>
    </div>

    <script src="admin.js"></script>
    <script>
        // Manage Courses JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            function escHtml(str) {
                if (!str) return '';
                const d = document.createElement('div');
                d.textContent = str;
                return d.innerHTML;
            }
            window.escHtml = escHtml;
            const escHtmlGlobal = escHtml;

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
            // Modal Elements
            const addModal = document.getElementById('addCourseModal');
            const editModal = document.getElementById('editCourseModal');
            const deleteModal = document.getElementById('deleteModal');
            const viewModal = document.getElementById('viewCourseModal');
            const closeViewModal = document.getElementById('closeViewModal');
            const closeViewCourseBtn = document.getElementById('closeViewCourseBtn');
            const viewEditCourseBtn = document.getElementById('viewEditCourseBtn');

            // Close Modals

            document.getElementById('closeDeleteModal').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });
            document.getElementById('cancelDelete').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });

            // Close View Modal
            function closeView() {
                viewModal.classList.remove('active');
            }
            if (closeViewModal) closeViewModal.addEventListener('click', closeView);
            if (closeViewCourseBtn) closeViewCourseBtn.addEventListener('click', closeView);

            // View Button Handlers
            document.querySelectorAll('.btn-action.view').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const courseId = btn.dataset.id;
                    const fd = new FormData();
                    fd.append('action', 'get_course_details');
                    fd.append('id', courseId);
                    
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.course) {
                            const course = data.course;
                            
                            // ID Badge & Name
                            const idBadge = document.getElementById('viewCourseIdBadge');
                            if (idBadge) idBadge.textContent = 'ID: #' + (course.id || '0');

                            const nameEl = document.getElementById('viewCourseName');
                            if (nameEl) nameEl.textContent = course.course_name || '-';
                            
                            // Cluster Pill
                            const clusterEl = document.getElementById('viewCourseCluster');
                            const clusterPill = document.getElementById('viewCourseClusterPill');
                            if (course.cluster_name) {
                                if (clusterEl) clusterEl.textContent = course.cluster_name;
                                if (clusterPill) clusterPill.style.display = 'inline-flex';
                            } else {
                                if (clusterEl) clusterEl.textContent = 'General Field';
                                if (clusterPill) clusterPill.style.display = 'inline-flex';
                            }

                            // Strand Pill
                            const strandEl = document.getElementById('viewCourseStrand');
                            const strandPill = document.getElementById('viewCourseStrandPill');
                            const strandDisplay = course.strand_code ? `${course.strand_code}${course.strand_name ? ' • ' + course.strand_name : ''}` : (course.strand_name || 'Academic Track');
                            if (strandEl) strandEl.textContent = strandDisplay;
                            if (strandPill) strandPill.style.display = 'inline-flex';
                            
                            // Description
                            const descEl = document.getElementById('viewCourseDescription');
                            if (descEl) descEl.textContent = course.description || 'No detailed course description has been registered for this program.';
                            
                            // Set data-id on Edit Course button inside view modal
                            viewEditCourseBtn.setAttribute('data-id', course.id);
                            
                            // Render Jobs
                            const jobsList = document.getElementById('viewCourseJobsList');
                            const jobsCountEl = document.getElementById('viewJobsCount');
                            jobsList.innerHTML = '';
                            if (data.jobs && data.jobs.length > 0) {
                                if (jobsCountEl) jobsCountEl.textContent = `${data.jobs.length} Career${data.jobs.length > 1 ? 's' : ''}`;
                                data.jobs.forEach(job => {
                                    const item = document.createElement('div');
                                    item.className = 'job-item-card';
                                    item.innerHTML = `
                                        <span class="job-bullet-icon"><i class="fa-solid fa-arrow-right"></i></span>
                                        <span class="job-title-text" title="${escHtml(job)}">${escHtml(job)}</span>
                                    `;
                                    jobsList.appendChild(item);
                                });
                            } else {
                                if (jobsCountEl) jobsCountEl.textContent = '0 Careers';
                                jobsList.innerHTML = `
                                    <div class="empty-list-card">
                                        <i class="fa-solid fa-briefcase"></i>
                                        <p>No career pathways currently linked to this program.</p>
                                    </div>
                                `;
                            }
                            
                            // Render Schools
                            const schoolsList = document.getElementById('viewCourseSchoolsList');
                            const schoolsCountEl = document.getElementById('viewSchoolsCount');
                            schoolsList.innerHTML = '';
                            if (data.schools && data.schools.length > 0) {
                                if (schoolsCountEl) schoolsCountEl.textContent = `${data.schools.length} Institution${data.schools.length > 1 ? 's' : ''}`;
                                data.schools.forEach(school => {
                                    const item = document.createElement('div');
                                    const isSpec = Number(school.is_specialization) === 1;
                                    item.className = isSpec ? 'school-item-card is-specialized' : 'school-item-card';
                                    
                                    let content = `
                                        <div class="school-info-wrap">
                                            <span class="school-bullet-icon ${isSpec ? 'gold' : ''}">
                                                <i class="fa-solid fa-building-columns"></i>
                                            </span>
                                            <span class="school-name-text" title="${escHtml(school.name)}">${escHtml(school.name)}</span>
                                        </div>
                                    `;
                                    if (isSpec) {
                                        content += `
                                            <span class="specialization-tag">
                                                <i class="fa-solid fa-star"></i> Center of Specialization
                                            </span>
                                        `;
                                    }
                                    item.innerHTML = content;
                                    schoolsList.appendChild(item);
                                });
                            } else {
                                if (schoolsCountEl) schoolsCountEl.textContent = '0 Institutions';
                                schoolsList.innerHTML = `
                                    <div class="empty-list-card">
                                        <i class="fa-solid fa-school"></i>
                                        <p>No offering institutions registered for this program.</p>
                                    </div>
                                `;
                            }
                            
                            viewModal.classList.add('active');
                        } else {
                            alert(data.message || 'Failed to load course details');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Error loading course details');
                    }
                });
            });

            // Edit button inside View Modal
            viewEditCourseBtn.addEventListener('click', function() {
                const courseId = this.getAttribute('data-id');
                closeView();
                // Find and trigger the corresponding edit button on the main course list
                const editBtn = document.querySelector(`.btn-action.edit[data-id="${courseId}"]`);
                if (editBtn) {
                    editBtn.click();
                }
            });

            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('manage_courses.php', { method: 'POST', body: formData });
                return res.json();
            }

            // Available Schools & Careers Data
            const allSchools = <?php echo json_encode(array_values($allSchools)); ?>;
            let allJobs = <?php echo json_encode(array_values($allJobs)); ?>;

            // State maps:
            // Schools: schoolId -> { name: string, city: string, is_specialization: number }
            let addSelectedSchools = {};
            let editSelectedSchools = {};

            // Careers: jobId -> { job_title: string }
            let addSelectedJobs = {};
            let editSelectedJobs = {};

            let pendingRemoveSchoolContext = null; // { prefix: 'add'|'edit', id: number }
            let pendingSpecContext = null;         // { prefix: 'add'|'edit', id: number }
            let pendingRemoveJobContext = null;    // { prefix: 'add'|'edit', id: number }

            // -- Available Schools Manager ----------------------------
            function setupCourseSchoolsManager(prefix) {
                const isAdd = prefix === 'add';
                const searchInput = document.getElementById(isAdd ? 'addSchoolSearchInput' : 'editSchoolSearchInput');
                const clearSearchBtn = document.getElementById(isAdd ? 'addSchoolSearchClear' : 'editSchoolSearchClear');
                const dropdown    = document.getElementById(isAdd ? 'addSchoolDropdown' : 'editSchoolDropdown');
                const chips       = document.getElementById(isAdd ? 'addSchoolChips' : 'editSchoolChips');
                const noneHint    = document.getElementById(isAdd ? 'addSchoolNoneHint' : 'editSchoolNoneHint');
                const countBadge  = document.getElementById(isAdd ? 'addSchoolCountBadge' : 'editSchoolCountBadge');
                const clearAllBtn = document.getElementById(isAdd ? 'addSchoolClearAllBtn' : 'editSchoolClearAllBtn');

                function getSelectedMap() {
                    return isAdd ? addSelectedSchools : editSelectedSchools;
                }

                function renderDropdown(query) {
                    const q = (query || '').toLowerCase().trim();
                    const filtered = allSchools.filter(s => {
                        const name = (s.name || '').toLowerCase();
                        const city = (s.city || '').toLowerCase();
                        return name.includes(q) || city.includes(q);
                    });
                    dropdown.innerHTML = '';
                    const selectedMap = getSelectedMap();

                    if (filtered.length === 0) {
                        dropdown.innerHTML = '<div class="co-dropdown-empty"><i class="fa-solid fa-circle-exclamation" style="margin-right:0.4rem;"></i>No schools match your search.</div>';
                    } else {
                        filtered.slice(0, 50).forEach(s => {
                            const isSelected = selectedMap.hasOwnProperty(s.id);
                            const isSpec = isSelected && selectedMap[s.id].is_specialization === 1;
                            const item = document.createElement('div');
                            item.className = 'co-dropdown-item' + (isSelected ? ' already-selected' : '');
                            
                            const cityStr = s.city ? ` <small style="color:#64748b; font-size:0.8em;">(${escHtmlGlobal(s.city)})</small>` : '';
                            item.innerHTML = `
                                <div class="item-text-box">
                                    <i class="fa-solid fa-landmark"></i>
                                    <span title="${escHtmlGlobal(s.name)}">${escHtmlGlobal(s.name)}${cityStr}</span>
                                </div>
                                <div class="item-actions-box">
                                    <button type="button" class="item-spec-btn ${isSpec ? 'is-spec' : ''}" title="${isSpec ? 'Center of Specialization active - Click to toggle' : 'Mark as Center of Specialization'}">
                                        <i class="fa-${isSpec ? 'solid' : 'regular'} fa-star"></i>
                                        <span>Best in Course</span>
                                    </button>
                                    ${isSelected ? 
                                        `<span class="item-add-btn" style="cursor:default; margin-right:4px;"><i class="fa-solid fa-check"></i> Added</span>
                                         <button type="button" class="item-remove-btn" style="background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;"><i class="fa-solid fa-xmark"></i> Remove</button>` 
                                    : 
                                        `<span class="item-add-btn" style="cursor:pointer;"><i class="fa-solid fa-plus"></i> Add</span>`
                                    }
                                </div>
                            `;

                            // Specialization / Best in Course button handler
                            const specBtn = item.querySelector('.item-spec-btn');
                            if (specBtn) {
                                specBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    if (!selectedMap.hasOwnProperty(s.id)) {
                                        addSchool(s.id, s.name, 1, s.city);
                                        renderDropdown(searchInput ? searchInput.value : '');
                                    } else {
                                        promptToggleSpecialization(prefix, s.id);
                                    }
                                });
                            }

                            // Add button handler
                            const addBtn = item.querySelector('.item-add-btn');
                            if (addBtn && !isSelected) {
                                addBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    addSchool(s.id, s.name, 0, s.city);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            // Remove button handler in dropdown
                            const removeBtn = item.querySelector('.item-remove-btn');
                            if (removeBtn) {
                                removeBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    removeSchool(s.id);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            // Click text to add if not selected
                            item.addEventListener('mousedown', function(e) {
                                if (e.target.closest('.item-spec-btn') || e.target.closest('.item-add-btn') || e.target.closest('.item-remove-btn')) return;
                                e.preventDefault();
                                if (selectedMap.hasOwnProperty(s.id)) return;
                                addSchool(s.id, s.name, 0, s.city);
                                renderDropdown(searchInput ? searchInput.value : '');
                            });

                            dropdown.appendChild(item);
                        });
                    }
                    dropdown.classList.add('open');
                }

                function renderChips() {
                    const selectedMap = getSelectedMap();
                    Array.from(chips.children).forEach(el => {
                        if (el !== noneHint) el.remove();
                    });
                    const ids = Object.keys(selectedMap);

                    if (countBadge) {
                        const specCount = ids.filter(id => selectedMap[id].is_specialization === 1).length;
                        const specText = specCount > 0 ? ` (${specCount} Best in Course)` : '';
                        countBadge.innerHTML = `<i class="fa-solid fa-layer-group"></i> ${ids.length} Selected${specText}`;
                    }
                    if (clearAllBtn) {
                        clearAllBtn.style.display = ids.length > 0 ? 'inline-flex' : 'none';
                    }

                    if (ids.length === 0) {
                        noneHint.style.display = 'flex';
                    } else {
                        noneHint.style.display = 'none';
                        ids.forEach(id => {
                            const itemData = selectedMap[id];
                            const name = itemData.name;
                            const city = itemData.city;
                            const isSpec = itemData.is_specialization === 1;

                            const chip = document.createElement('div');
                            chip.className = 'co-chip' + (isSpec ? ' is-specialized' : '');
                            const cityLabel = city ? ` (${escHtmlGlobal(city)})` : '';
                            chip.innerHTML = `
                                <i class="fa-solid fa-landmark co-chip-icon"></i>
                                <span class="co-chip-name" title="${escHtmlGlobal(name)}">${escHtmlGlobal(name)}${cityLabel}</span>
                                ${isSpec ? '<span class="co-spec-badge"><i class="fa-solid fa-star"></i> Center of Specialization</span>' : ''}
                                <div class="co-chip-actions">
                                    <button type="button" class="co-chip-star ${isSpec ? 'active' : ''}" data-id="${id}" title="${isSpec ? 'Center of Specialization active - Click to unmark' : 'Click to mark as Center of Specialization'}">
                                        <i class="fa-${isSpec ? 'solid' : 'regular'} fa-star"></i>
                                    </button>
                                    <button type="button" class="co-chip-remove" data-id="${id}" title="Remove school">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                                <input type="hidden" name="school_ids[]" value="${id}">
                                ${isSpec ? `<input type="hidden" name="is_specialization_${id}" value="1">` : ''}
                            `;

                            chip.querySelector('.co-chip-star').addEventListener('click', function(e) {
                                e.stopPropagation();
                                promptToggleSpecialization(prefix, parseInt(this.getAttribute('data-id')));
                            });

                            chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                                e.stopPropagation();
                                promptRemoveSchool(prefix, parseInt(this.getAttribute('data-id')));
                            });

                            chips.appendChild(chip);
                        });
                    }
                }

                function addSchool(id, name, is_specialization = 0, city = '') {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    if (!selectedMap.hasOwnProperty(id)) {
                        selectedMap[id] = {
                            name: name,
                            city: city,
                            is_specialization: parseInt(is_specialization) || 0
                        };
                        renderChips();
                    }
                }

                function toggleSpecialization(id) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    if (selectedMap.hasOwnProperty(id)) {
                        selectedMap[id].is_specialization = selectedMap[id].is_specialization === 1 ? 0 : 1;
                        renderChips();
                        if (dropdown && dropdown.classList.contains('open') && searchInput) {
                            renderDropdown(searchInput.value);
                        }
                    }
                }

                function removeSchool(id) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    delete selectedMap[id];
                    renderChips();
                    if (dropdown && dropdown.classList.contains('open') && searchInput) {
                        renderDropdown(searchInput.value);
                    }
                }

                function clearSchools() {
                    if (isAdd) {
                        addSelectedSchools = {};
                    } else {
                        editSelectedSchools = {};
                    }
                    if (searchInput) searchInput.value = '';
                    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
                    if (dropdown) dropdown.classList.remove('open');
                    renderChips();
                }

                if (dropdown) {
                    dropdown.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                    });
                }

                if (searchInput) {
                    searchInput.addEventListener('focus', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('input', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('blur', function() {
                        setTimeout(() => dropdown.classList.remove('open'), 180);
                    });
                    searchInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && dropdown) {
                            dropdown.classList.remove('open');
                        }
                    });
                }

                if (clearSearchBtn) {
                    clearSearchBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (searchInput) {
                            searchInput.value = '';
                            searchInput.focus();
                            renderDropdown('');
                            this.style.display = 'none';
                        }
                    });
                }

                if (clearAllBtn) {
                    clearAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        clearSchools();
                    });
                }

                return {
                    addSchool,
                    toggleSpecialization,
                    removeSchool,
                    clearSchools,
                    renderChips
                };
            }

            // -- Possible Careers Manager -----------------------------
            function setupCourseJobsManager(prefix) {
                const isAdd = prefix === 'add';
                const searchInput = document.getElementById(isAdd ? 'addJobSearchInput' : 'editJobSearchInput');
                const clearSearchBtn = document.getElementById(isAdd ? 'addJobSearchClear' : 'editJobSearchClear');
                const dropdown    = document.getElementById(isAdd ? 'addJobDropdown' : 'editJobDropdown');
                const chips       = document.getElementById(isAdd ? 'addJobChips' : 'editJobChips');
                const noneHint    = document.getElementById(isAdd ? 'addJobNoneHint' : 'editJobNoneHint');
                const countBadge  = document.getElementById(isAdd ? 'addJobCountBadge' : 'editJobCountBadge');
                const clearAllBtn = document.getElementById(isAdd ? 'addJobClearAllBtn' : 'editJobClearAllBtn');

                function getSelectedMap() {
                    return isAdd ? addSelectedJobs : editSelectedJobs;
                }

                function renderDropdown(query) {
                    const q = (query || '').toLowerCase().trim();
                    const filtered = allJobs.filter(j => (j.job_title || '').toLowerCase().includes(q));
                    dropdown.innerHTML = '';
                    const selectedMap = getSelectedMap();

                    if (filtered.length === 0) {
                        dropdown.innerHTML = '<div class="co-dropdown-empty"><i class="fa-solid fa-circle-exclamation" style="margin-right:0.4rem;"></i>No careers match your search.</div>';
                    } else {
                        filtered.slice(0, 50).forEach(j => {
                            const isSelected = selectedMap.hasOwnProperty(j.id);
                            const item = document.createElement('div');
                            item.className = 'co-dropdown-item' + (isSelected ? ' already-selected' : '');
                            item.innerHTML = `
                                <div class="item-text-box">
                                    <i class="fa-solid fa-briefcase"></i>
                                    <span title="${escHtmlGlobal(j.job_title)}">${escHtmlGlobal(j.job_title)}</span>
                                </div>
                                <div class="item-actions-box">
                                    ${isSelected ? 
                                        `<span class="item-add-btn" style="cursor:default; margin-right:4px;"><i class="fa-solid fa-check"></i> Added</span>
                                         <button type="button" class="item-remove-btn" style="background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;"><i class="fa-solid fa-xmark"></i> Remove</button>` 
                                    : 
                                        `<span class="item-add-btn" style="cursor:pointer;"><i class="fa-solid fa-plus"></i> Add</span>`
                                    }
                                </div>
                            `;

                            const addBtn = item.querySelector('.item-add-btn');
                            if (addBtn && !isSelected) {
                                addBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    addJob(j.id, j.job_title);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            const removeBtn = item.querySelector('.item-remove-btn');
                            if (removeBtn) {
                                removeBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    removeJob(j.id);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            item.addEventListener('mousedown', function(e) {
                                if (e.target.closest('.item-add-btn') || e.target.closest('.item-remove-btn')) return;
                                e.preventDefault();
                                if (selectedMap.hasOwnProperty(j.id)) return;
                                addJob(j.id, j.job_title);
                                renderDropdown(searchInput ? searchInput.value : '');
                            });

                            dropdown.appendChild(item);
                        });
                    }
                    dropdown.classList.add('open');
                }

                function renderChips() {
                    const selectedMap = getSelectedMap();
                    Array.from(chips.children).forEach(el => {
                        if (el !== noneHint) el.remove();
                    });
                    const ids = Object.keys(selectedMap);

                    if (countBadge) {
                        countBadge.innerHTML = `<i class="fa-solid fa-layer-group"></i> ${ids.length} Selected`;
                    }
                    if (clearAllBtn) {
                        clearAllBtn.style.display = ids.length > 0 ? 'inline-flex' : 'none';
                    }

                    if (ids.length === 0) {
                        noneHint.style.display = 'flex';
                    } else {
                        noneHint.style.display = 'none';
                        ids.forEach(id => {
                            const itemData = selectedMap[id];
                            const title = itemData.job_title;

                            const chip = document.createElement('div');
                            chip.className = 'co-chip';
                            chip.innerHTML = `
                                <i class="fa-solid fa-briefcase co-chip-icon"></i>
                                <span class="co-chip-name" title="${escHtmlGlobal(title)}">${escHtmlGlobal(title)}</span>
                                <div class="co-chip-actions">
                                    <button type="button" class="co-chip-remove" data-id="${id}" title="Remove career">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                                <input type="hidden" name="job_ids[]" value="${id}">
                            `;

                            chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                                e.stopPropagation();
                                promptRemoveJob(prefix, parseInt(this.getAttribute('data-id')));
                            });

                            chips.appendChild(chip);
                        });
                    }
                }

                function addJob(id, job_title) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    if (!selectedMap.hasOwnProperty(id)) {
                        selectedMap[id] = { job_title: job_title };
                        renderChips();
                    }
                }

                function removeJob(id) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    delete selectedMap[id];
                    renderChips();
                    if (dropdown && dropdown.classList.contains('open') && searchInput) {
                        renderDropdown(searchInput.value);
                    }
                }

                function clearJobs() {
                    if (isAdd) {
                        addSelectedJobs = {};
                    } else {
                        editSelectedJobs = {};
                    }
                    if (searchInput) searchInput.value = '';
                    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
                    if (dropdown) dropdown.classList.remove('open');
                    renderChips();
                }

                if (dropdown) {
                    dropdown.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                    });
                }

                if (searchInput) {
                    searchInput.addEventListener('focus', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('input', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('blur', function() {
                        setTimeout(() => dropdown.classList.remove('open'), 180);
                    });
                    searchInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && dropdown) {
                            dropdown.classList.remove('open');
                        }
                    });
                }

                if (clearSearchBtn) {
                    clearSearchBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (searchInput) {
                            searchInput.value = '';
                            searchInput.focus();
                            renderDropdown('');
                            this.style.display = 'none';
                        }
                    });
                }

                if (clearAllBtn) {
                    clearAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        clearJobs();
                    });
                }

                return {
                    addJob,
                    removeJob,
                    clearJobs,
                    renderChips
                };
            }

            // Instantiate Managers
            const addSchoolsManager  = setupCourseSchoolsManager('add');
            const editSchoolsManager = setupCourseSchoolsManager('edit');
            const addJobsManager     = setupCourseJobsManager('add');
            const editJobsManager    = setupCourseJobsManager('edit');

            // -- Confirmation Modals Handling -------------------------
            // Remove School Prompt
            const removeSchoolModal = document.getElementById('removeSchoolModal');
            const removeSchoolTargetName = document.getElementById('removeSchoolTargetName');
            const cancelRemoveSchoolBtn = document.getElementById('cancelRemoveSchoolBtn');
            const confirmRemoveSchoolBtn = document.getElementById('confirmRemoveSchoolBtn');
            const removeSchoolOverlay = document.getElementById('removeSchoolOverlay');

            function promptRemoveSchool(prefix, id) {
                id = parseInt(id);
                const map = prefix === 'add' ? addSelectedSchools : editSelectedSchools;
                if (!map.hasOwnProperty(id)) return;
                pendingRemoveSchoolContext = { prefix, id };
                if (removeSchoolTargetName) {
                    removeSchoolTargetName.textContent = map[id].name + (map[id].city ? ` (${map[id].city})` : '');
                }
                if (removeSchoolModal) {
                    removeSchoolModal.classList.add('active');
                }
            }

            function closeRemoveSchoolModal() {
                if (removeSchoolModal) {
                    removeSchoolModal.classList.remove('active');
                }
                pendingRemoveSchoolContext = null;
            }

            if (cancelRemoveSchoolBtn) cancelRemoveSchoolBtn.addEventListener('click', closeRemoveSchoolModal);
            if (removeSchoolOverlay) removeSchoolOverlay.addEventListener('click', closeRemoveSchoolModal);
            if (confirmRemoveSchoolBtn) {
                confirmRemoveSchoolBtn.addEventListener('click', function() {
                    if (pendingRemoveSchoolContext) {
                        const mgr = pendingRemoveSchoolContext.prefix === 'add' ? addSchoolsManager : editSchoolsManager;
                        mgr.removeSchool(pendingRemoveSchoolContext.id);
                    }
                    closeRemoveSchoolModal();
                });
            }

            // Center of Specialization Prompt
            const specializationModal = document.getElementById('specializationModal');
            const specModalTitle = document.getElementById('specModalTitle');
            const specModalMessage = document.getElementById('specModalMessage');
            const specModalTargetName = document.getElementById('specModalTargetName');
            const confirmSpecModalBtn = document.getElementById('confirmSpecModalBtn');
            const cancelSpecModalBtn = document.getElementById('cancelSpecModalBtn');
            const specModalOverlay = document.getElementById('specModalOverlay');

            function promptToggleSpecialization(prefix, id) {
                id = parseInt(id);
                const map = prefix === 'add' ? addSelectedSchools : editSelectedSchools;
                if (!map.hasOwnProperty(id)) return;
                pendingSpecContext = { prefix, id };
                const school = map[id];
                const isCurrentlySpec = school.is_specialization === 1;

                if (specModalTargetName) {
                    specModalTargetName.textContent = school.name + (school.city ? ` (${school.city})` : '');
                }

                if (!isCurrentlySpec) {
                    if (specModalTitle) specModalTitle.textContent = 'Center of Specialization';
                    if (specModalMessage) specModalMessage.textContent = 'Do you want to mark this school as Center of Specialization (Best in Course) for this course?';
                    if (confirmSpecModalBtn) {
                        confirmSpecModalBtn.textContent = 'Mark as Best in Course';
                        confirmSpecModalBtn.style.background = 'linear-gradient(135deg, #fbbf24, #f59e0b)';
                        confirmSpecModalBtn.style.color = '#0f172a';
                    }
                } else {
                    if (specModalTitle) specModalTitle.textContent = 'Remove Specialization?';
                    if (specModalMessage) specModalMessage.textContent = 'Do you want to remove Center of Specialization (Best in Course) status for this school?';
                    if (confirmSpecModalBtn) {
                        confirmSpecModalBtn.textContent = 'Remove Status';
                        confirmSpecModalBtn.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
                        confirmSpecModalBtn.style.color = '#0f172a';
                    }
                }

                if (specializationModal) {
                    specializationModal.classList.add('active');
                }
            }

            function closeSpecModal() {
                if (specializationModal) {
                    specializationModal.classList.remove('active');
                }
                pendingSpecContext = null;
            }

            if (cancelSpecModalBtn) cancelSpecModalBtn.addEventListener('click', closeSpecModal);
            if (specModalOverlay) specModalOverlay.addEventListener('click', closeSpecModal);
            if (confirmSpecModalBtn) {
                confirmSpecModalBtn.addEventListener('click', function() {
                    if (pendingSpecContext) {
                        const mgr = pendingSpecContext.prefix === 'add' ? addSchoolsManager : editSchoolsManager;
                        mgr.toggleSpecialization(pendingSpecContext.id);
                    }
                    closeSpecModal();
                });
            }

            // Remove Career Prompt
            const removeJobModal = document.getElementById('removeJobModal');
            const removeJobTargetName = document.getElementById('removeJobTargetName');
            const cancelRemoveJobBtn = document.getElementById('cancelRemoveJobBtn');
            const confirmRemoveJobBtn = document.getElementById('confirmRemoveJobBtn');
            const removeJobOverlay = document.getElementById('removeJobOverlay');

            function promptRemoveJob(prefix, id) {
                id = parseInt(id);
                const map = prefix === 'add' ? addSelectedJobs : editSelectedJobs;
                if (!map.hasOwnProperty(id)) return;
                pendingRemoveJobContext = { prefix, id };
                if (removeJobTargetName) {
                    removeJobTargetName.textContent = map[id].job_title;
                }
                if (removeJobModal) {
                    removeJobModal.classList.add('active');
                }
            }

            function closeRemoveJobModal() {
                if (removeJobModal) {
                    removeJobModal.classList.remove('active');
                }
                pendingRemoveJobContext = null;
            }

            if (cancelRemoveJobBtn) cancelRemoveJobBtn.addEventListener('click', closeRemoveJobModal);
            if (removeJobOverlay) removeJobOverlay.addEventListener('click', closeRemoveJobModal);
            if (confirmRemoveJobBtn) {
                confirmRemoveJobBtn.addEventListener('click', function() {
                    if (pendingRemoveJobContext) {
                        const mgr = pendingRemoveJobContext.prefix === 'add' ? addJobsManager : editJobsManager;
                        mgr.removeJob(pendingRemoveJobContext.id);
                    }
                    closeRemoveJobModal();
                });
            }

            // -- Quick-add job (Add modal) -----------------------------
            document.getElementById('addModalQuickAddBtn').addEventListener('click', () => {
                document.getElementById('addModalQuickForm').classList.add('visible');
                document.getElementById('qaAddTitle').focus();
            });
            document.getElementById('qaAddCancel').addEventListener('click', () => {
                document.getElementById('addModalQuickForm').classList.remove('visible');
                document.getElementById('qaAddTitle').value = '';
                document.getElementById('qaAddDesc').value  = '';
            });
            document.getElementById('qaAddSave').addEventListener('click', async () => {
                const title = document.getElementById('qaAddTitle').value.trim();
                if (!title) { alert('Job title is required.'); return; }
                const fd = new FormData();
                fd.append('action', 'add_job_inline');
                fd.append('job_title', title);
                fd.append('description', document.getElementById('qaAddDesc').value.trim());
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        if (!allJobs.some(j => j.id == data.job.id)) {
                            allJobs.push({ id: data.job.id, job_title: data.job.job_title });
                        }
                        addJobsManager.addJob(data.job.id, data.job.job_title);
                        document.getElementById('addModalQuickForm').classList.remove('visible');
                        document.getElementById('qaAddTitle').value = '';
                        document.getElementById('qaAddDesc').value  = '';
                    } else {
                        alert(data.message || 'Failed to add job.');
                    }
                } catch { alert('Error adding job.'); }
            });

            // -- Quick-add job (Edit modal) ----------------------------
            document.getElementById('editModalQuickAddBtn').addEventListener('click', () => {
                document.getElementById('editModalQuickForm').classList.add('visible');
                document.getElementById('qaEditTitle').focus();
            });
            document.getElementById('qaEditCancel').addEventListener('click', () => {
                document.getElementById('editModalQuickForm').classList.remove('visible');
                document.getElementById('qaEditTitle').value = '';
                document.getElementById('qaEditDesc').value  = '';
            });
            document.getElementById('qaEditSave').addEventListener('click', async () => {
                const title = document.getElementById('qaEditTitle').value.trim();
                if (!title) { alert('Job title is required.'); return; }
                const fd = new FormData();
                fd.append('action', 'add_job_inline');
                fd.append('job_title', title);
                fd.append('description', document.getElementById('qaEditDesc').value.trim());
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        if (!allJobs.some(j => j.id == data.job.id)) {
                            allJobs.push({ id: data.job.id, job_title: data.job.job_title });
                        }
                        editJobsManager.addJob(data.job.id, data.job.job_title);
                        document.getElementById('editModalQuickForm').classList.remove('visible');
                        document.getElementById('qaEditTitle').value = '';
                        document.getElementById('qaEditDesc').value  = '';
                    } else {
                        alert(data.message || 'Failed to add job.');
                    }
                } catch { alert('Error adding job.'); }
            });

            // -- Modal Open / Close Helpers ---------------------------
            function openAddModal() {
                addSchoolsManager.clearSchools();
                addJobsManager.clearJobs();
                document.getElementById('addCourseForm').reset();
                document.getElementById('addModalQuickForm').classList.remove('visible');
                addModal.classList.add('active');
            }
            document.getElementById('addCourseBtn').addEventListener('click', openAddModal);

            function closeAdd() {
                addModal.classList.remove('active');
                addSchoolsManager.clearSchools();
                addJobsManager.clearJobs();
                document.getElementById('addModalQuickForm').classList.remove('visible');
            }
            document.getElementById('closeAddModal').addEventListener('click', closeAdd);
            document.getElementById('cancelAdd').addEventListener('click', closeAdd);

            function closeEdit() {
                editModal.classList.remove('active');
                document.getElementById('editCourseForm').reset();
                editSchoolsManager.clearSchools();
                editJobsManager.clearJobs();
                document.getElementById('editModalQuickForm').classList.remove('visible');
            }
            document.getElementById('closeEditModal').addEventListener('click', closeEdit);
            document.getElementById('cancelEdit').addEventListener('click', closeEdit);

            // Edit Button Handlers
            document.querySelectorAll('.btn-action.edit').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const courseId = btn.dataset.id;
                    
                    // Fetch course data
                    const fd = new FormData();
                    fd.append('action', 'get_course');
                    fd.append('id', courseId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.course) {
                            const course = data.course;
                            document.getElementById('editCourseId').value = course.id;
                            document.getElementById('editCourseName').value = course.course_name || '';
                            document.getElementById('editCluster').value = course.cluster_id || '';
                            if (document.getElementById('editStrand')) {
                                document.getElementById('editStrand').value = course.strand_id || '';
                            }
                            document.getElementById('editDescription').value = course.description || '';

                            editSchoolsManager.clearSchools();
                            editJobsManager.clearJobs();
                            document.getElementById('editModalQuickForm').classList.remove('visible');

                            // Fetch and populate linked careers
                            const fdJobs = new FormData();
                            fdJobs.append('action', 'get_course_jobs');
                            fdJobs.append('id', courseId);
                            const jobData = await apiPost(fdJobs);
                            if (jobData.success && jobData.job_ids) {
                                jobData.job_ids.forEach(jid => {
                                    const foundJob = allJobs.find(j => j.id == jid);
                                    if (foundJob) {
                                        editJobsManager.addJob(foundJob.id, foundJob.job_title);
                                    }
                                });
                            }

                            // Fetch and populate linked schools
                            const fdSchools = new FormData();
                            fdSchools.append('action', 'get_course_schools');
                            fdSchools.append('id', courseId);
                            const schoolData = await apiPost(fdSchools);
                            if (schoolData.success && schoolData.schools) {
                                schoolData.schools.forEach(item => {
                                    const foundSchool = allSchools.find(s => s.id == item.school_id);
                                    if (foundSchool) {
                                        editSchoolsManager.addSchool(foundSchool.id, foundSchool.name, item.is_specialization, foundSchool.city);
                                    }
                                });
                            }

                            editModal.classList.add('active');
                        } else {
                            showStatusModal('Error', data.message || 'Failed to load course data', false);
                        }
                    } catch (e) {
                        showStatusModal('Error', 'Error loading course data', false);
                    }
                });
            });

            // Delete Button Handlers
            let deleteCourseId = null;
            let unlinkCourseMode = false;

            document.querySelectorAll('.btn-action.delete').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const courseName = row.querySelector('.course-name').textContent.trim();
                    deleteCourseId = btn.dataset.id;
                    unlinkCourseMode = false;

                    const titleEl = document.getElementById('deleteModalTitleText');
                    const msgEl = document.getElementById('deleteCourseMessage');
                    const nameEl = document.getElementById('deleteCourseName');
                    const warningEl = document.getElementById('deleteCourseWarning');
                    const confirmBtn = document.getElementById('confirmDelete');

                    if (nameEl) nameEl.textContent = courseName;

                    // Check associations
                    let totalCount = parseInt(btn.getAttribute('data-total-associations') || '0', 10);
                    try {
                        const fd = new FormData();
                        fd.append('action', 'check_course_associations');
                        fd.append('id', deleteCourseId);
                        const res = await apiPost(fd);
                        if (res && res.success && typeof res.count !== 'undefined') {
                            totalCount = res.count;
                        }
                    } catch (e) {}

                    if (totalCount > 0) {
                        unlinkCourseMode = true;
                        if (titleEl) titleEl.textContent = 'Delete Course?';
                        if (msgEl) msgEl.textContent = 'This course is currently associated with one or more schools or careers. Deleting this course will remove its associations. The schools and careers themselves will not be deleted.';
                        if (warningEl) warningEl.textContent = `(${totalCount} association${totalCount > 1 ? 's' : ''} will be unlinked)`;
                        if (confirmBtn) confirmBtn.textContent = 'Unlink & Delete';
                    } else {
                        unlinkCourseMode = false;
                        if (titleEl) titleEl.textContent = 'Confirm Delete';
                        if (msgEl) msgEl.textContent = 'Are you sure you want to delete this course?';
                        if (warningEl) warningEl.textContent = 'This action cannot be undone.';
                        if (confirmBtn) confirmBtn.textContent = 'Delete';
                    }

                    deleteModal.classList.add('active');
                });
            });

            // Form Validation - Add Course
            document.getElementById('addCourseForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_course');
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Course added successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error adding course', false);
                }
            });

            // Form Validation - Edit Course
            document.getElementById('editCourseForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'edit_course');
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Course updated successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error updating course', false);
                }
            });

            // Confirm Delete
            document.getElementById('confirmDelete').addEventListener('click', async () => {
                if (!deleteCourseId) return;
                
                const fd = new FormData();
                fd.append('action', 'delete_course');
                fd.append('id', deleteCourseId);
                if (unlinkCourseMode) {
                    fd.append('unlink_associations', '1');
                }
                
                deleteModal.classList.remove('active');
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        showStatusModal('Success', 'Course deleted successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to delete course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error deleting course', false);
                }
            });

            // Search Functionality
            const searchInput = document.getElementById('searchInput');
            const searchBtn = document.getElementById('searchBtn');
            const clearBtn = document.getElementById('clearFilter');

            if (searchBtn && searchInput) {
                searchBtn.addEventListener('click', () => {
                    searchInput.dispatchEvent(new Event('input'));
                });
            }

            searchInput.addEventListener('input', () => {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.courses-table tbody tr');
                let visibleCount = 0;

                rows.forEach(row => {
                    const courseName = row.querySelector('.course-name')?.textContent.toLowerCase() || '';
                    const cluster = row.querySelector('.cluster-badge')?.textContent.toLowerCase() || '';
                    const description = row.querySelector('.description-cell')?.textContent.toLowerCase() || '';
                    
                    if (courseName.includes(searchTerm) || cluster.includes(searchTerm) || 
                        description.includes(searchTerm)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                const totalSpan = document.getElementById('totalCourses');
                if (totalSpan) totalSpan.textContent = visibleCount;
            });

            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                document.querySelectorAll('.courses-table tbody tr').forEach(row => {
                    row.style.display = '';
                });
                const totalSpan = document.getElementById('totalCourses');
                if (totalSpan) totalSpan.textContent = document.querySelectorAll('.courses-table tbody tr:not(.no-data-row)').length;
            });

            // Close modals on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function() {
                    this.parentElement.classList.remove('active');
                });
            });
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
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
