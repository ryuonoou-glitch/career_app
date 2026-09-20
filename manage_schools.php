<?php
// Manage Schools Page - Backend Added

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// Helper to format city and province names cleanly into Title Case (e.g., 'City of Alaminos', 'Pangasinan')
function formatLocationName($name) {
    if (empty($name)) return '';
    $name = trim($name);
    if ($name !== strtoupper($name)) return $name;
    $words = explode(' ', strtolower($name));
    $res = [];
    foreach ($words as $w) {
        if (in_array($w, ['of', 'de', 'del', 'da'])) {
            $res[] = $w;
        } else {
            $res[] = ucfirst($w);
        }
    }
    $out = implode(' ', $res);
    return preg_replace_callback('/(sta\.|sto\.)\s*([a-z])/i', function($m) {
        return ucfirst(strtolower($m[1])) . ' ' . strtoupper($m[2]);
    }, $out);
}

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
        case 'add_school':
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $cityId = (int)($_POST['city_id'] ?? 0);
            $provinceId = (int)($_POST['province_id'] ?? 4); // default Pangasinan (4)
            $districtId = (int)($_POST['district_id'] ?? 0);
            $contact = trim($_POST['contact'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $type = trim($_POST['type'] ?? '');
            
            if (empty($name) || $cityId <= 0 || $provinceId <= 0 || $districtId <= 0) {
                $response['message'] = 'Name, city, province, and district are required';
                echo json_encode($response);
                exit;
            }
            
            // Resolve name descriptions from refcitymun and refprovince
            $cityName = '';
            $cityQ = $mysqli->prepare("SELECT citymunDesc FROM refcitymun WHERE id = ? LIMIT 1");
            if ($cityQ) {
                $cityQ->bind_param('i', $cityId);
                $cityQ->execute();
                $cityRes = $cityQ->get_result()->fetch_assoc();
                $cityName = formatLocationName($cityRes['citymunDesc'] ?? '');
                $cityQ->close();
            }
            
            $provinceName = 'Pangasinan';
            $provQ = $mysqli->prepare("SELECT provDesc FROM refprovince WHERE id = ? LIMIT 1");
            if ($provQ) {
                $provQ->bind_param('i', $provinceId);
                $provQ->execute();
                $provRes = $provQ->get_result()->fetch_assoc();
                $provinceName = formatLocationName($provRes['provDesc'] ?? 'Pangasinan');
                $provQ->close();
            }
            
            // Check if school already exists
            $check = $mysqli->prepare("SELECT id FROM schools WHERE name = ? AND address = ? AND city_id = ? LIMIT 1");
            $check->bind_param('ssi', $name, $address, $cityId);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $response['message'] = 'School with this name and address already exists';
                $check->close();
                echo json_encode($response);
                exit;
            }
            $check->close();
            
            $stmt = $mysqli->prepare("
                INSERT INTO schools (name, address, city, city_id, province, province_id, district_id, contact, email, website, type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('sssisiissss', $name, $address, $cityName, $cityId, $provinceName, $provinceId, $districtId, $contact, $email, $website, $type);
            
            if ($stmt->execute()) {
                $insertedId = $mysqli->insert_id;
                
                // Fetch new school details for region/logging
                $newSchool = null;
                $schQuery = $mysqli->prepare("SELECT * FROM schools WHERE id = ?");
                if ($schQuery) {
                    $schQuery->bind_param('i', $insertedId);
                    $schQuery->execute();
                    $newSchool = $schQuery->get_result()->fetch_assoc();
                    $schQuery->close();
                }
                
                $schoolName = $newSchool['name'] ?? $name;
                $schoolCity = $newSchool['city'] ?? $cityName;
                
                $description = "Admin added {$schoolName} ({$schoolCity})";
                log_activity($userId, $userType, 'Added School', 'schools', $insertedId, $description, null, json_encode($newSchool));

                // -- Sync course_schools for newly added school --
                $submittedCourseIds = [];
                if (!empty($_POST['offered_course_ids'])) {
                    $raw = $_POST['offered_course_ids'];
                    if (is_array($raw)) {
                        $submittedCourseIds = array_map('intval', $raw);
                    } else {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $submittedCourseIds = array_map('intval', $decoded);
                        } else {
                            foreach (explode(',', $raw) as $cid) {
                                $cid = (int)trim($cid);
                                if ($cid > 0) $submittedCourseIds[] = $cid;
                            }
                        }
                    }
                    $submittedCourseIds = array_unique(array_filter($submittedCourseIds));
                }

                $specializedCourseIds = [];
                if (!empty($_POST['specialized_course_ids'])) {
                    $rawSpec = $_POST['specialized_course_ids'];
                    if (is_array($rawSpec)) {
                        $specializedCourseIds = array_map('intval', $rawSpec);
                    } else {
                        $decodedSpec = json_decode($rawSpec, true);
                        if (is_array($decodedSpec)) {
                            $specializedCourseIds = array_map('intval', $decodedSpec);
                        } else {
                            foreach (explode(',', $rawSpec) as $cid) {
                                $cid = (int)trim($cid);
                                if ($cid > 0) $specializedCourseIds[] = $cid;
                            }
                        }
                    }
                    $specializedCourseIds = array_unique(array_filter($specializedCourseIds));
                }

                if (!empty($submittedCourseIds)) {
                    $insStmt = $mysqli->prepare("INSERT INTO course_schools (course_id, school_id, is_specialization) VALUES (?, ?, ?)");
                    if ($insStmt) {
                        foreach ($submittedCourseIds as $cid) {
                            $isSpec = in_array($cid, $specializedCourseIds) ? 1 : 0;
                            $insStmt->bind_param('iii', $cid, $insertedId, $isSpec);
                            $insStmt->execute();
                        }
                        $insStmt->close();
                    }
                }

                $response['success'] = true;
                $response['message'] = 'School added successfully';
                $response['id'] = $insertedId;
            } else {
                $response['message'] = 'Failed to add school: ' . $stmt->error;
            }
            $stmt->close();
            echo json_encode($response);
            exit;
            
        case 'get_school':
            $schoolId = (int)($_POST['id'] ?? 0);
            if ($schoolId <= 0) {
                $response['message'] = 'Invalid school ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("
                SELECT s.*, c.citymunDesc as city_name, p.provDesc as province_name, d.name as district_name
                FROM schools s
                LEFT JOIN refcitymun c ON s.city_id = c.id
                LEFT JOIN refprovince p ON s.province_id = p.id
                LEFT JOIN districts d ON s.district_id = d.id
                WHERE s.id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $schoolId);
            $stmt->execute();
            $result = $stmt->get_result();
            $school = $result->fetch_assoc();
            $stmt->close();
            
            if ($school) {
                $courses = [];
                $coursesStmt = $mysqli->prepare("
                    SELECT c.id AS course_id, c.course_name, MAX(cs.is_specialization) AS is_specialization 
                    FROM course_schools cs 
                    JOIN courses c ON cs.course_id = c.id 
                    WHERE cs.school_id = ?
                    GROUP BY c.id, c.course_name
                    ORDER BY MAX(cs.is_specialization) DESC, c.course_name ASC
                ");
                if ($coursesStmt) {
                    $coursesStmt->bind_param('i', $schoolId);
                    $coursesStmt->execute();
                    $coursesResult = $coursesStmt->get_result();
                    while ($cRow = $coursesResult->fetch_assoc()) {
                        $courses[] = $cRow;
                    }
                    $coursesStmt->close();
                }
                $school['courses'] = $courses;

                $response['success'] = true;
                $response['school'] = $school;
            } else {
                $response['message'] = 'School not found';
            }
            echo json_encode($response);
            exit;
            
        case 'edit_school':
            $schoolId = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $cityId = (int)($_POST['city_id'] ?? 0);
            $provinceId = (int)($_POST['province_id'] ?? 4);
            $districtId = (int)($_POST['district_id'] ?? 0);
            $contact = trim($_POST['contact'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $type = trim($_POST['type'] ?? '');
            
            if ($schoolId <= 0 || empty($name) || $cityId <= 0 || $provinceId <= 0 || $districtId <= 0) {
                $response['message'] = 'Invalid data provided (Name, city, province, and district are required)';
                echo json_encode($response);
                exit;
            }
            
            // Resolve name descriptions from refcitymun and refprovince
            $cityName = '';
            $cityQ = $mysqli->prepare("SELECT citymunDesc FROM refcitymun WHERE id = ? LIMIT 1");
            if ($cityQ) {
                $cityQ->bind_param('i', $cityId);
                $cityQ->execute();
                $cityRes = $cityQ->get_result()->fetch_assoc();
                $cityName = formatLocationName($cityRes['citymunDesc'] ?? '');
                $cityQ->close();
            }
            
            $provinceName = 'Pangasinan';
            $provQ = $mysqli->prepare("SELECT provDesc FROM refprovince WHERE id = ? LIMIT 1");
            if ($provQ) {
                $provQ->bind_param('i', $provinceId);
                $provQ->execute();
                $provRes = $provQ->get_result()->fetch_assoc();
                $provinceName = formatLocationName($provRes['provDesc'] ?? 'Pangasinan');
                $provQ->close();
            }
            
            // Check if name and address exists for another school
            $check = $mysqli->prepare("SELECT id FROM schools WHERE name = ? AND address = ? AND city_id = ? AND id != ? LIMIT 1");
            $check->bind_param('sssi', $name, $address, $cityId, $schoolId);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $response['message'] = 'School with this name and address already exists';
                $check->close();
                echo json_encode($response);
                exit;
            }
            $check->close();
            
            // Fetch old school row before edit
            $oldSchool = null;
            $schQuery = $mysqli->prepare("SELECT * FROM schools WHERE id = ?");
            if ($schQuery) {
                $schQuery->bind_param('i', $schoolId);
                $schQuery->execute();
                $oldSchool = $schQuery->get_result()->fetch_assoc();
                $schQuery->close();
            }

            $stmt = $mysqli->prepare("
                UPDATE schools SET name = ?, address = ?, city = ?, city_id = ?, province = ?, province_id = ?, district_id = ?, 
                contact = ?, email = ?, website = ?, type = ? WHERE id = ?
            ");
            $stmt->bind_param('sssisiissssi', $name, $address, $cityName, $cityId, $provinceName, $provinceId, $districtId, 
                $contact, $email, $website, $type, $schoolId);
            
            if ($stmt->execute()) {
                // Fetch new school row after edit
                $newSchool = null;
                $schQuery2 = $mysqli->prepare("SELECT * FROM schools WHERE id = ?");
                if ($schQuery2) {
                    $schQuery2->bind_param('i', $schoolId);
                    $schQuery2->execute();
                    $newSchool = $schQuery2->get_result()->fetch_assoc();
                    $schQuery2->close();
                }

                // Identify changed fields (We now log full objects instead of just diffs)
                $oldChanges = $oldSchool;
                $newChanges = $newSchool;

                // -- Sync course_schools (with Best in Course / Specialization) --
                $submittedCourseIds = [];
                if (!empty($_POST['offered_course_ids'])) {
                    $raw = $_POST['offered_course_ids'];
                    if (is_array($raw)) {
                        $submittedCourseIds = array_map('intval', $raw);
                    } else {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $submittedCourseIds = array_map('intval', $decoded);
                        } else {
                            foreach (explode(',', $raw) as $cid) {
                                $cid = (int)trim($cid);
                                if ($cid > 0) $submittedCourseIds[] = $cid;
                            }
                        }
                    }
                    $submittedCourseIds = array_unique(array_filter($submittedCourseIds));
                }

                $specializedCourseIds = [];
                if (!empty($_POST['specialized_course_ids'])) {
                    $rawSpec = $_POST['specialized_course_ids'];
                    if (is_array($rawSpec)) {
                        $specializedCourseIds = array_map('intval', $rawSpec);
                    } else {
                        $decodedSpec = json_decode($rawSpec, true);
                        if (is_array($decodedSpec)) {
                            $specializedCourseIds = array_map('intval', $decodedSpec);
                        } else {
                            foreach (explode(',', $rawSpec) as $cid) {
                                $cid = (int)trim($cid);
                                if ($cid > 0) $specializedCourseIds[] = $cid;
                            }
                        }
                    }
                    $specializedCourseIds = array_unique(array_filter($specializedCourseIds));
                }

                // Validate each submitted ID actually exists in courses table
                $validCourseIds = [];
                foreach ($submittedCourseIds as $cid) {
                    $vStmt = $mysqli->prepare("SELECT id FROM courses WHERE id = ? LIMIT 1");
                    if ($vStmt) {
                        $vStmt->bind_param('i', $cid);
                        $vStmt->execute();
                        $vStmt->store_result();
                        if ($vStmt->num_rows > 0) {
                            $validCourseIds[] = $cid;
                        }
                        $vStmt->close();
                    }
                }

                // Fetch currently linked course IDs with their existing specialization status
                $existingCourses = []; // [course_id => is_specialization]
                $exStmt = $mysqli->prepare("SELECT course_id, MAX(is_specialization) as is_spec FROM course_schools WHERE school_id = ? GROUP BY course_id");
                if ($exStmt) {
                    $exStmt->bind_param('i', $schoolId);
                    $exStmt->execute();
                    $exResult = $exStmt->get_result();
                    while ($exRow = $exResult->fetch_assoc()) {
                        $existingCourses[(int)$exRow['course_id']] = (int)$exRow['is_spec'];
                    }
                    $exStmt->close();
                }

                $existingCourseIds = array_keys($existingCourses);
                $toAdd    = array_diff($validCourseIds, $existingCourseIds);
                $toRemove = array_diff($existingCourseIds, $validCourseIds);
                $toRetain = array_intersect($validCourseIds, $existingCourseIds);

                // Add newly selected courses with specialization flag
                foreach ($toAdd as $addId) {
                    $isSpec = in_array($addId, $specializedCourseIds) ? 1 : 0;
                    $insStmt = $mysqli->prepare("INSERT IGNORE INTO course_schools (course_id, school_id, is_specialization) VALUES (?, ?, ?)");
                    if ($insStmt) {
                        $insStmt->bind_param('iii', $addId, $schoolId, $isSpec);
                        $insStmt->execute();
                        $insStmt->close();
                    }
                }

                // Update specialization for retained courses if changed
                foreach ($toRetain as $retId) {
                    $isSpec = in_array($retId, $specializedCourseIds) ? 1 : 0;
                    if ($existingCourses[$retId] !== $isSpec) {
                        $upStmt = $mysqli->prepare("UPDATE course_schools SET is_specialization = ? WHERE course_id = ? AND school_id = ?");
                        if ($upStmt) {
                            $upStmt->bind_param('iii', $isSpec, $retId, $schoolId);
                            $upStmt->execute();
                            $upStmt->close();
                        }
                    }
                }

                // Remove de-selected courses
                foreach ($toRemove as $remId) {
                    $delStmt = $mysqli->prepare("DELETE FROM course_schools WHERE course_id = ? AND school_id = ?");
                    if ($delStmt) {
                        $delStmt->bind_param('ii', $remId, $schoolId);
                        $delStmt->execute();
                        $delStmt->close();
                    }
                }
                // ------------------------------------------------------------

                $description = "Admin edited school #{$schoolId} (" . ($newSchool['name'] ?? $name) . ")";
                log_activity(
                    $userId,
                    $userType,
                    'Edited School',
                    'schools',
                    $schoolId,
                    $description,
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );

                $response['success'] = true;
                $response['message'] = 'School updated successfully';
            } else {
                $response['message'] = 'Failed to update school';
            }
            $stmt->close();
            echo json_encode($response);
            exit;
            
        case 'delete_school':
            $schoolId = (int)($_POST['id'] ?? 0);
            if ($schoolId <= 0) {
                $response['message'] = 'Invalid school ID';
                echo json_encode($response);
                exit;
            }
            
            // Check if school is associated with any courses
            $check = $mysqli->prepare("SELECT COUNT(*) as count FROM course_schools WHERE school_id = ?");
            $check->bind_param('i', $schoolId);
            $check->execute();
            $count = $check->get_result()->fetch_assoc()['count'];
            $check->close();
            
            if ($count > 0) {
                $response['message'] = 'Cannot delete school: It is associated with courses';
                echo json_encode($response);
                exit;
            }
            
            // Fetch school details before deleting
            $oldSchool = null;
            $schQuery = $mysqli->prepare("SELECT * FROM schools WHERE id = ?");
            if ($schQuery) {
                $schQuery->bind_param('i', $schoolId);
                $schQuery->execute();
                $oldSchool = $schQuery->get_result()->fetch_assoc();
                $schQuery->close();
            }

            $stmt = $mysqli->prepare("DELETE FROM schools WHERE id = ?");
            $stmt->bind_param('i', $schoolId);
            
            if ($stmt->execute()) {
                $schoolName = $oldSchool['name'] ?? 'Unknown School';
                $description = "Admin removed {$schoolName} from the system";
                log_activity($userId, $userType, 'Deleted School', 'schools', $schoolId, $description, json_encode($oldSchool), null);

                $response['success'] = true;
                $response['message'] = 'School deleted successfully';
            } else {
                $response['message'] = 'Failed to delete school';
            }
            $stmt->close();
            echo json_encode($response);
            exit;
    }
}

// Get all schools
$schools = [];
$result = $mysqli->query("
    SELECT s.*, c.citymunDesc as city_name, p.provDesc as province_name, d.name as district_name
    FROM schools s
    LEFT JOIN refcitymun c ON s.city_id = c.id
    LEFT JOIN refprovince p ON s.province_id = p.id
    LEFT JOIN districts d ON s.district_id = d.id
    ORDER BY s.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $schools[] = $row;
    }
}

// Load dropdown reference tables
$allProvinces = $mysqli->query("SELECT id, provDesc, provCode FROM refprovince ORDER BY provDesc")->fetch_all(MYSQLI_ASSOC) ?? [];
$pangasinanCities = $mysqli->query("SELECT id, citymunDesc FROM refcitymun WHERE provCode = '0155' ORDER BY citymunDesc")->fetch_all(MYSQLI_ASSOC) ?? [];
$allDistricts = $mysqli->query("SELECT id, name FROM districts ORDER BY id")->fetch_all(MYSQLI_ASSOC) ?? [];

// Load all available courses for the Courses Offered selector in the edit modal
$allCourses = [];
$allCoursesResult = $mysqli->query("SELECT id, course_name FROM courses ORDER BY course_name ASC");
if ($allCoursesResult) {
    while ($cRow = $allCoursesResult->fetch_assoc()) {
        $allCourses[] = $cRow;
    }
}

function getSchoolInitials($name) {
    $name = trim($name);
    if (empty($name)) return 'SC';
    
    // Check if the name starts with an acronym (e.g. "ABE", "AMA", "STI", "PSU")
    $words = preg_split('/[\s\-_,]+/', $name);
    if (!empty($words[0]) && ctype_upper($words[0]) && strlen($words[0]) >= 2 && strlen($words[0]) <= 4) {
        return substr($words[0], 0, 3);
    }
    
    // Filter out common stopwords
    $stopwords = ['of', 'and', 'the', 'in', 'for', 'at', 'de', 'la'];
    $meaningfulWords = array_values(array_filter($words, function($w) use ($stopwords) {
        return !in_array(strtolower($w), $stopwords) && strlen($w) > 0;
    }));
    
    if (count($meaningfulWords) >= 2) {
        return strtoupper(substr($meaningfulWords[0], 0, 1) . substr($meaningfulWords[1], 0, 1));
    } elseif (count($meaningfulWords) === 1) {
        return strtoupper(substr($meaningfulWords[0], 0, min(2, strlen($meaningfulWords[0]))));
    }
    return strtoupper(substr($name, 0, 2));
}

function getSchoolAvatarStyle($name) {
    $palettes = [
        ['bg' => 'linear-gradient(135deg, #0d9488, #0f766e)', 'shadow' => 'rgba(13, 148, 136, 0.35)'], // Teal
        ['bg' => 'linear-gradient(135deg, #4f46e5, #3730a3)', 'shadow' => 'rgba(79, 70, 229, 0.35)'],  // Indigo
        ['bg' => 'linear-gradient(135deg, #0284c7, #0369a1)', 'shadow' => 'rgba(2, 132, 199, 0.35)'],   // Sky
        ['bg' => 'linear-gradient(135deg, #7c3aed, #5b21b6)', 'shadow' => 'rgba(124, 58, 237, 0.35)'],  // Violet
        ['bg' => 'linear-gradient(135deg, #e11d48, #9f1239)', 'shadow' => 'rgba(225, 29, 72, 0.35)'],   // Rose
        ['bg' => 'linear-gradient(135deg, #059669, #065f46)', 'shadow' => 'rgba(5, 150, 105, 0.35)'],   // Emerald
        ['bg' => 'linear-gradient(135deg, #d97706, #92400e)', 'shadow' => 'rgba(217, 119, 6, 0.35)'],   // Amber
        ['bg' => 'linear-gradient(135deg, #ea580c, #9a3412)', 'shadow' => 'rgba(234, 88, 12, 0.35)'],   // Orange
        ['bg' => 'linear-gradient(135deg, #c026d3, #86198f)', 'shadow' => 'rgba(192, 38, 211, 0.35)'],  // Fuchsia
        ['bg' => 'linear-gradient(135deg, #2563eb, #1e40af)', 'shadow' => 'rgba(37, 99, 235, 0.35)'],   // Blue
    ];
    $idx = abs(crc32($name)) % count($palettes);
    $p = $palettes[$idx];
    return "background: {$p['bg']}; box-shadow: 0 4px 14px {$p['shadow']};";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schools - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* -- Redesigned Courses Offered Section (Add & Edit School Modals) -- */
        #addSchoolModal .modal-content,
        #editSchoolModal .modal-content {
            max-width: 820px !important;
            width: 95% !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
            text-align: left !important;
        }
        #addSchoolModal .modal-body,
        #editSchoolModal .modal-body {
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
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.5);
            background: rgba(245, 158, 11, 0.2);
            transform: scale(1.15);
        }
        .co-chip-star.active {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            border-color: #f59e0b;
            color: #0f172a;
            box-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }
        .co-chip-star.active:hover {
            background: linear-gradient(135deg, #fcd34d, #fbbf24);
            color: #0f172a;
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
        /* -- View School Modal Custom Styles -- */
        #viewSchoolModal .modal-content {
            max-width: 780px !important;
            width: 95% !important;
            max-height: 90vh !important;
            border-radius: 16px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7) !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            text-align: left !important;
            padding: 0 !important;
        }
        #viewSchoolModal .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
            text-align: center;
        }
        #viewSchoolModal .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        #viewSchoolModal .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        #viewSchoolModal .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        #viewSchoolModal .header-titles h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            padding: 0;
            border: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        #viewSchoolModal .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        #viewSchoolModal .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }
        #viewSchoolModal .view-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        #viewSchoolModal .school-hero-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }
        #viewSchoolModal .school-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }
        #viewSchoolModal .school-hero-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.3;
        }
        #viewSchoolModal .school-badges-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        #viewSchoolModal .badge-school-type {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        #viewSchoolModal .badge-school-district {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.3);
            color: #d8b4fe;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        #viewSchoolModal .school-address-box {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            color: #cbd5e1;
            font-size: 0.92rem;
            line-height: 1.5;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding-top: 0.85rem;
            margin-top: 0.5rem;
        }
        #viewSchoolModal .school-contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
        }
        #viewSchoolModal .contact-card-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        #viewSchoolModal .contact-card-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        #viewSchoolModal .contact-card-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #f1f5f9;
            word-break: break-all;
        }
        #viewSchoolModal .courses-offered-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
        }
        #viewSchoolModal .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        #viewSchoolModal .card-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        #viewSchoolModal .courses-count-badge {
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.3);
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        #viewSchoolModal .view-courses-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            padding-right: 0.25rem;
        }
        #viewSchoolModal .view-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
        }
        #viewSchoolModal .btn-edit-school-modal {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
            transition: all 0.2s;
        }
        #viewSchoolModal .btn-edit-school-modal:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
            transform: translateY(-1px);
        }
        #viewSchoolModal .btn-close-view-modal {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        #viewSchoolModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }

        /* -- Modern Manage Schools Page Layout & Cards -- */
        .schools-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .header-title-box {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-title-box h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
        }
        .schools-count-badge {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }
        .btn-add-school-main {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0.7rem 1.35rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
            transition: all 0.2s ease;
        }
        .btn-add-school-main:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
            transform: translateY(-2px);
        }

        /* -- Modern Search / Filter Toolbar Card -- */
        .schools-filter-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: flex-end;
        }
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .filter-field label {
            font-size: 0.76rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .filter-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .filter-input-wrap i {
            position: absolute;
            left: 0.85rem;
            color: #64748b;
            font-size: 0.85rem;
            pointer-events: none;
            z-index: 1;
        }
        .filter-input-wrap input,
        .filter-input-wrap select {
            width: 100%;
            padding: 0.65rem 0.85rem 0.65rem 2.25rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 9px;
            color: #f1f5f9;
            font-size: 0.88rem;
            transition: all 0.2s;
            outline: none;
            box-sizing: border-box;
        }
        .filter-input-wrap input:focus,
        .filter-input-wrap select:focus {
            border-color: #f59e0b;
            background: rgba(15, 23, 42, 0.85);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .filter-input-wrap select option {
            background: #0f172a;
            color: #f1f5f9;
        }
        .filter-btn-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-filter-search {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0.65rem 1.2rem;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-filter-search:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            transform: translateY(-1px);
        }
        .btn-filter-clear {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 0.65rem 1rem;
            border-radius: 9px;
            font-weight: 600;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-filter-clear:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #f8fafc;
        }

        /* -- Modern Schools Grid -- */
        .schools-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem;
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 1100px) {
            .schools-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 680px) {
            .schools-grid {
                grid-template-columns: 1fr;
            }
        }

        /* -- Modern School Card -- */
        .school-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.65), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
            min-height: 270px;
        }
        .school-card:hover {
            transform: translateY(-4px);
            border-color: rgba(245, 158, 11, 0.35);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45);
        }
        .school-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
        }
        .school-avatar-box {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0d9488, #0f766e);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .school-icon-badge {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0d9488, #0f766e);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .school-badges-stack {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.4rem;
        }
        .card-badge-type {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
            white-space: nowrap;
        }
        .card-badge-district {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: rgba(168, 85, 247, 0.15);
            color: #d8b4fe;
            border: 1px solid rgba(168, 85, 247, 0.3);
            white-space: nowrap;
        }
        .school-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0 0 0.5rem 0;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.7rem;
        }
        .school-location {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.82rem;
            font-weight: 600;
            color: #60a5fa;
            background: rgba(59, 130, 246, 0.1);
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            margin-bottom: 0.65rem;
            width: fit-content;
        }
        .school-address {
            font-size: 0.82rem;
            color: #94a3b8;
            line-height: 1.45;
            margin: 0 0 0.85rem 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.4rem;
        }
        .school-meta-links {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 0.85rem;
            font-size: 0.8rem;
        }
        .school-web-link {
            color: #fbbf24;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 500;
            transition: color 0.2s;
            width: fit-content;
        }
        .school-web-link:hover {
            color: #fde68a;
            text-decoration: underline;
        }
        .school-phone-text {
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* -- Card Action Buttons Row -- */
        .school-card-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-top: 0.85rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            margin-top: auto;
        }
        .btn-card-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-card-action.view {
            background: rgba(59, 130, 246, 0.12);
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.25);
            flex: 1;
        }
        .btn-card-action.view:hover {
            background: rgba(59, 130, 246, 0.25);
            color: #bfdbfe;
        }
        .btn-card-action.edit {
            background: rgba(245, 158, 11, 0.12);
            color: #fcd34d;
            border-color: rgba(245, 158, 11, 0.25);
            flex: 1;
        }
        .btn-card-action.edit:hover {
            background: rgba(245, 158, 11, 0.25);
            color: #fef08a;
        }
        .btn-card-action.delete {
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.25);
            width: 34px;
            padding: 0.45rem 0;
        }
        .btn-card-action.delete:hover {
            background: rgba(239, 68, 68, 0.25);
            color: #fecaca;
        }

        /* Empty / No Results Message */
        .no-schools-found-box {
            grid-column: 1 / -1;
            text-align: center;
            padding: 3.5rem 1.5rem;
            background: rgba(30, 41, 59, 0.4);
            border: 1px dashed rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            color: #94a3b8;
        }
        .no-schools-found-box i {
            font-size: 2.5rem;
            color: #64748b;
            margin-bottom: 0.75rem;
            display: block;
        }
        .no-schools-found-box h3 {
            font-size: 1.15rem;
            color: #f8fafc;
            margin: 0 0 0.4rem 0;
        }
        .no-schools-found-box p {
            font-size: 0.88rem;
            margin: 0 0 1rem 0;
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
                        <a href="manage_courses.php" class="nav-subitem">
                            <i class="fa-solid fa-book-open"></i>
                            Manage Courses
                        </a>
                        <a href="manage_schools.php" class="nav-subitem active">
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
                    <h1>Manage Schools</h1>
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

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                
                <!-- Page Header with Title, Count Badge & Add Button -->
                <div class="schools-page-header">
                    <div class="header-title-box">
                        <span class="schools-count-badge" id="schoolsTotalCount">
                            <i class="fa-solid fa-school"></i>
                            <span id="schoolsCountText"><?php echo count($schools); ?> Schools</span>
                        </span>
                    </div>
                    <button class="btn-add-school-main" id="addSchoolBtn">
                        <i class="fa-solid fa-plus"></i>
                        <span>Add School</span>
                    </button>
                </div>

                <!-- Modern Search & Filter Toolbar -->
                <div class="schools-filter-card">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label for="searchSchoolName">School Name</label>
                            <div class="filter-input-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="searchSchoolName" placeholder="Search by school name...">
                            </div>
                        </div>
                        <div class="filter-field">
                            <label for="searchCity">City / Municipality</label>
                            <div class="filter-input-wrap">
                                <i class="fa-solid fa-city"></i>
                                <select id="searchCity">
                                    <option value="">All Cities</option>
                                    <?php foreach ($pangasinanCities as $cityOption): ?>
                                    <option value="<?php echo htmlspecialchars($cityOption['citymunDesc']); ?>"><?php echo htmlspecialchars(formatLocationName($cityOption['citymunDesc'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-field">
                            <label for="searchProvince">Province</label>
                            <div class="filter-input-wrap">
                                <i class="fa-solid fa-location-dot"></i>
                                <select id="searchProvince">
                                    <option value="">Provinces</option>
                                    <?php foreach ($allProvinces as $provOption): ?>
                                    <option value="<?php echo htmlspecialchars($provOption['provDesc']); ?>"><?php echo htmlspecialchars(formatLocationName($provOption['provDesc'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-field">
                            <label for="searchDistrict">District</label>
                            <div class="filter-input-wrap">
                                <i class="fa-solid fa-map-location-dot"></i>
                                <select id="searchDistrict">
                                    <option value="">All Districts</option>
                                    <?php foreach ($allDistricts as $district): ?>
                                    <option value="<?php echo $district['id']; ?>"><?php echo htmlspecialchars($district['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-btn-group">
                            <button type="button" class="btn-filter-search" id="searchBtn">
                                <i class="fa-solid fa-filter"></i> Search
                            </button>
                            <button type="button" class="btn-filter-clear" id="clearBtn">
                                <i class="fa-solid fa-rotate-left"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Schools Grid -->
                <div class="schools-grid" id="schoolsGrid">
                    <?php if (empty($schools)): ?>
                    <div class="no-schools-found-box">
                        <i class="fa-solid fa-school"></i>
                        <h3>No Schools Registered</h3>
                        <p>Click "Add School" above to register your first educational institution.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($schools as $school): 
                            $street = trim($school['address'] ?? '');
                            $city = formatLocationName(trim($school['city'] ?: ($school['city_name'] ?? '')));
                            $province = formatLocationName(trim($school['province'] ?: ($school['province_name'] ?? 'Pangasinan')));
                            
                            if ($province && str_ends_with(strtolower($street), strtolower($province))) {
                                $street = trim(rtrim(substr($street, 0, -strlen($province)), ','));
                            }
                            if ($city && str_ends_with(strtolower($street), strtolower($city))) {
                                $street = trim(rtrim(substr($street, 0, -strlen($city)), ','));
                            }
                            $fullAddr = implode(', ', array_filter([$street, $city, $province]));
                        ?>
                        <div class="school-card" data-id="<?php echo $school['id']; ?>" data-district="<?php echo htmlspecialchars($school['district_id'] ?? ''); ?>">
                            <div>
                                <div class="school-card-top">
                                    <div class="school-avatar-box" style="<?php echo getSchoolAvatarStyle($school['name']); ?>">
                                        <?php echo htmlspecialchars(getSchoolInitials($school['name'])); ?>
                                    </div>
                                    <div class="school-badges-stack">
                                        <?php if (!empty($school['type'])): ?>
                                        <span class="card-badge-type"><?php echo htmlspecialchars($school['type']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($school['district_name'])): ?>
                                        <span class="card-badge-district"><i class="fa-solid fa-map-pin" style="font-size: 0.65rem;"></i> <?php echo htmlspecialchars($school['district_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <h3 class="school-name" title="<?php echo htmlspecialchars($school['name']); ?>">
                                    <?php echo htmlspecialchars($school['name']); ?>
                                </h3>

                                <div class="school-location">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <span><?php echo htmlspecialchars($city ?: '-'); ?></span>
                                </div>

                                <p class="school-address" title="<?php echo htmlspecialchars($fullAddr); ?>">
                                    <?php echo htmlspecialchars($fullAddr ?: 'No address specified'); ?>
                                </p>

                                <div class="school-meta-links">
                                    <?php if (!empty($school['website'])): ?>
                                    <a href="<?php echo htmlspecialchars($school['website']); ?>" target="_blank" rel="noopener noreferrer" class="school-web-link">
                                        <i class="fa-solid fa-globe"></i>
                                        <span>Visit Website</span>
                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; opacity: 0.8;"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($school['contact'])): ?>
                                    <span class="school-phone-text">
                                        <i class="fa-solid fa-phone" style="font-size: 0.75rem;"></i>
                                        <span><?php echo htmlspecialchars($school['contact']); ?></span>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="school-card-actions school-actions">
                                <button type="button" class="btn-card-action btn-action view" data-id="<?php echo $school['id']; ?>" title="View School Details">
                                    <i class="fa-solid fa-eye"></i>
                                    <span>View</span>
                                </button>
                                <button type="button" class="btn-card-action btn-action edit" data-id="<?php echo $school['id']; ?>" title="Edit School">
                                    <i class="fa-solid fa-pen"></i>
                                    <span>Edit</span>
                                </button>
                                <button type="button" class="btn-card-action btn-action delete" data-id="<?php echo $school['id']; ?>" title="Delete School">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="no-schools-found-box" id="noResultsMessage" style="display: none;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <h3>No Matching Schools Found</h3>
                        <p>Try adjusting your search keywords or clearing filter criteria.</p>
                        <button type="button" class="btn-filter-clear" onclick="document.getElementById('clearBtn').click();">
                            <i class="fa-solid fa-rotate-left"></i> Reset Filters
                        </button>
                    </div>
                </div>

                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Add School Modal -->
    <div class="modal" id="addSchoolModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-plus-circle"></i> Add School</h2>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="addSchoolForm" class="school-form">
                     <div class="form-row">
                         <div class="form-group">
                             <label for="addSchoolName">School Name <span class="required">*</span></label>
                             <input type="text" id="addSchoolName" name="name" placeholder="Enter school name" required>
                         </div>
                         <div class="form-group">
                             <label for="addAddress">Street <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <textarea id="addAddress" name="address" rows="2" placeholder="Building, street, or landmark"></textarea>
                         </div>
                     </div>
                     <div class="form-row three-cols">
                         <div class="form-group">
                             <label for="addCity">City/Municipality <span class="required">*</span></label>
                             <select id="addCity" name="city_id" required>
                                 <option value="">Select City/Municipality</option>
                                 <?php foreach ($pangasinanCities as $cityOption): ?>
                                 <option value="<?php echo $cityOption['id']; ?>"><?php echo htmlspecialchars(formatLocationName($cityOption['citymunDesc'])); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="form-group">
                             <label for="addProvince">Province <span class="required">*</span></label>
                             <select id="addProvince" name="province_id" required>
                                 <?php foreach ($allProvinces as $provOption): ?>
                                 <option value="<?php echo $provOption['id']; ?>" <?php echo $provOption['id'] == 4 ? 'selected' : 'disabled'; ?>><?php echo htmlspecialchars(formatLocationName($provOption['provDesc'])); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="form-group">
                             <label for="addDistrict">District <span class="required">*</span></label>
                             <select id="addDistrict" name="district_id" required>
                                 <option value="">Select District</option>
                                 <?php foreach ($allDistricts as $district): ?>
                                 <option value="<?php echo $district['id']; ?>"><?php echo htmlspecialchars($district['name']); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                     </div>
                     <div class="form-row four-cols">
                         <div class="form-group">
                             <label for="addEmail">Email <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="email" id="addEmail" name="email" placeholder="school@domain.edu">
                         </div>
                         <div class="form-group">
                             <label for="addWebsite">Website <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="url" id="addWebsite" name="website" placeholder="https://www.school.edu.ph">
                             <small class="form-hint">Enter full URL including https://</small>
                         </div>
                         <div class="form-group">
                             <label for="addContact">Phone Number <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="text" id="addContact" name="contact" placeholder="Phone number (optional)">
                         </div>
                         <div class="form-group">
                             <label for="addType">School Type</label>
                             <select id="addType" name="type">
                                 <option value="">Select Type</option>
                                 <option value="Public (SUC)">Public (SUC)</option>
                                <option value="Public (LUC)">Public (LUC)</option>
                                <option value="Public">Public</option>
                                 <option value="Private">Private</option>
                                 <option value="State University">State University</option>
                                 <option value="College">College</option>
                                 <option value="University">University</option>
                             </select>
                         </div>
                     </div>
                     <!-- -- Courses Offered Section (Add School) -- -->
                     <div class="courses-offered-section">
                         <div class="co-header-bar">
                             <div class="co-header-left">
                                 <div class="co-header-icon">
                                     <i class="fa-solid fa-graduation-cap"></i>
                                 </div>
                                 <div class="co-header-titles">
                                     <span class="co-header-title">Courses Offered</span>
                                     <span class="co-header-subtitle">Search programs &amp; click the star <i class="fa-solid fa-star" style="color:#fbbf24; font-size:0.75rem;"></i> to mark as <strong>Best in Course</strong> (Specialization)</span>
                                 </div>
                             </div>
                             <div class="co-header-right">
                                 <span class="co-count-badge" id="addCoCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                 <button type="button" class="co-btn-clear-all" id="addCoClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                             </div>
                         </div>
                         <div class="co-search-box" id="addCoSearchBox" style="position: relative;">
                             <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                             <input type="text" class="co-search-input" id="addCoSearchInput" placeholder="Search courses by name (e.g. BS Information Technology)..." autocomplete="off" style="padding-right: 35px;">
                             <button type="button" class="co-search-clear" id="addCoSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                             <div class="co-dropdown" id="addCoDropdown"></div>
                         </div>
                         <div class="co-chips-container" id="addCoChips">
                             <div class="co-empty-state" id="addCoNoneHint">
                                 <i class="fa-solid fa-folder-open"></i>
                                 <span>No courses linked yet. Type in the box above to search and add courses.</span>
                             </div>
                         </div>
                     </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAdd">Cancel</button>
                <button type="submit" form="addSchoolForm" class="btn-primary">Add School</button>
            </div>
        </div>
    </div>

    <!-- Edit School Modal -->
    <div class="modal" id="editSchoolModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit School</h2>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editSchoolForm" class="school-form">
                    <input type="hidden" id="editSchoolId" name="id">
                     <div class="form-row">
                         <div class="form-group">
                             <label for="editSchoolName">School Name <span class="required">*</span></label>
                             <input type="text" id="editSchoolName" name="name" placeholder="Enter school name" required>
                         </div>
                         <div class="form-group">
                             <label for="editAddress">Street <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <textarea id="editAddress" name="address" rows="2" placeholder="Building, street, or landmark"></textarea>
                         </div>
                     </div>
                     <div class="form-row three-cols">
                         <div class="form-group">
                             <label for="editCity">City/Municipality <span class="required">*</span></label>
                             <select id="editCity" name="city_id" required>
                                 <option value="">Select City/Municipality</option>
                                 <?php foreach ($pangasinanCities as $cityOption): ?>
                                 <option value="<?php echo $cityOption['id']; ?>"><?php echo htmlspecialchars(formatLocationName($cityOption['citymunDesc'])); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="form-group">
                             <label for="editProvince">Province <span class="required">*</span></label>
                             <select id="editProvince" name="province_id" required>
                                 <?php foreach ($allProvinces as $provOption): ?>
                                 <option value="<?php echo $provOption['id']; ?>" <?php echo $provOption['id'] == 4 ? 'selected' : 'disabled'; ?>><?php echo htmlspecialchars(formatLocationName($provOption['provDesc'])); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="form-group">
                             <label for="editDistrict">District <span class="required">*</span></label>
                             <select id="editDistrict" name="district_id" required>
                                 <option value="">Select District</option>
                                 <?php foreach ($allDistricts as $district): ?>
                                 <option value="<?php echo $district['id']; ?>"><?php echo htmlspecialchars($district['name']); ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                     </div>
                     <div class="form-row four-cols">
                         <div class="form-group">
                             <label for="editEmail">Email <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="email" id="editEmail" name="email" placeholder="school@domain.edu">
                         </div>
                         <div class="form-group">
                             <label for="editWebsite">Website <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="url" id="editWebsite" name="website" placeholder="https://www.school.edu.ph">
                             <small class="form-hint">Enter full URL including https://</small>
                         </div>
                         <div class="form-group">
                             <label for="editContact">Phone Number <small style="font-weight: normal; color: #94a3b8; font-size: 0.85em;">(Optional)</small></label>
                             <input type="text" id="editContact" name="contact" placeholder="Phone number (optional)">
                         </div>
                         <div class="form-group">
                             <label for="editType">School Type</label>
                             <select id="editType" name="type">
                                 <option value="">Select Type</option>
                                 <option value="Public (SUC)">Public (SUC)</option>
                                 <option value="Public (LUC)">Public (LUC)</option>
                                 <option value="Public">Public</option>
                                 <option value="Private">Private</option>
                                 <option value="State University">State University</option>
                                 <option value="College">College</option>
                                 <option value="University">University</option>
                             </select>
                         </div>
                     </div>

                 <!-- -- Redesigned Courses Offered Section -- -->
                 <div class="courses-offered-section">
                     <div class="co-header-bar">
                         <div class="co-header-left">
                             <div class="co-header-icon">
                                 <i class="fa-solid fa-graduation-cap"></i>
                             </div>
                             <div class="co-header-titles">
                                 <span class="co-header-title">Courses Offered</span>
                                 <span class="co-header-subtitle">Search programs & click the star <i class="fa-solid fa-star" style="color:#fbbf24; font-size:0.75rem;"></i> to mark as <strong>Best in Course</strong> (Specialization)</span>
                             </div>
                         </div>
                         <div class="co-header-right">
                             <span class="co-count-badge" id="coCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                             <button type="button" class="co-btn-clear-all" id="coClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                         </div>
                     </div>
                     <div class="co-search-box" id="coSearchBox" style="position: relative;">
                         <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                         <input type="text" class="co-search-input" id="coSearchInput" placeholder="Search courses by name (e.g. BS Information Technology)..." autocomplete="off" style="padding-right: 35px;">
                         <button type="button" class="co-search-clear" id="coSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                         <div class="co-dropdown" id="coDropdown"></div>
                     </div>
                     <div class="co-chips-container" id="coChips">
                         <div class="co-empty-state" id="coNoneHint">
                             <i class="fa-solid fa-folder-open"></i>
                             <span>No courses linked yet. Type in the box above to search and add courses.</span>
                         </div>
                     </div>
                 </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEdit">Cancel</button>
                <button type="submit" form="editSchoolForm" class="btn-primary">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteSchoolModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-trash-can"></i> Delete School</h2>
                <button class="modal-close" id="closeDeleteModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-message">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Are you sure you want to delete this school?</p>
                    <p class="delete-school-name" id="deleteSchoolName"></p>
                    <small>This action cannot be undone.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete">Delete</button>
            </div>
        </div>
    </div>

    <!-- View School Modal -->
    <div class="modal" id="viewSchoolModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-school"></i>
                    </div>
                    <div class="header-titles">
                        <h2>School Details</h2>
                        <p>Educational institution profile, contact channels, and academic offerings</p>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="view-modal-body">
                <!-- School Hero Card -->
                <div class="school-hero-card">
                    <div class="school-hero-top">
                        <h3 class="school-hero-title" id="viewName">-</h3>
                        <div class="school-badges-group">
                            <span class="badge-school-type" id="viewTypePill">
                                <i class="fa-solid fa-building-columns"></i>
                                <span id="viewType">-</span>
                            </span>
                            <span class="badge-school-district" id="viewDistrictPill">
                                <i class="fa-solid fa-map-location-dot"></i>
                                <span id="viewDistrict">-</span>
                            </span>
                        </div>
                    </div>
                    <div class="school-address-box">
                        <i class="fa-solid fa-location-dot" style="color: #f87171; margin-top: 0.2rem; flex-shrink: 0;"></i>
                        <span id="viewAddress">-</span>
                    </div>
                </div>

                <!-- Contact Details Grid (Email, Phone, Website) -->
                <div class="school-contact-grid">
                    <div class="contact-card-item">
                        <span class="contact-card-label"><i class="fa-solid fa-envelope" style="color: #818cf8;"></i> Email Address</span>
                        <span class="contact-card-value" id="viewEmail">-</span>
                    </div>
                    <div class="contact-card-item">
                        <span class="contact-card-label"><i class="fa-solid fa-phone" style="color: #34d399;"></i> Contact Number</span>
                        <span class="contact-card-value" id="viewContact">-</span>
                    </div>
                    <div class="contact-card-item">
                        <span class="contact-card-label"><i class="fa-solid fa-globe" style="color: #fbbf24;"></i> Official Website</span>
                        <span class="contact-card-value" id="viewWebsite">-</span>
                    </div>
                </div>

                <!-- Academic Courses Offered Card -->
                <div class="courses-offered-card">
                    <div class="card-header-row">
                        <h4 class="card-header-title">
                            <i class="fa-solid fa-graduation-cap" style="color: #fbbf24;"></i>
                            Academic Programs & Courses Offered
                        </h4>
                        <span class="courses-count-badge" id="viewSchoolCoursesCount">0 Courses</span>
                    </div>
                    <div class="view-courses-list" id="viewCoursesList">
                        <!-- Populated dynamically -->
                    </div>
                </div>
            </div>

            <div class="view-modal-footer">
                <button type="button" class="btn-close-view-modal" id="closeViewBtn">Close</button>
                <button type="button" class="btn-edit-school-modal" id="viewEditBtnSchool">
                    <i class="fa-solid fa-pen"></i> Edit School
                </button>
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

    <!-- Remove Course Confirmation Modal -->
    <div class="modal" id="removeCourseModal" style="z-index: 10050;">
        <div class="modal-overlay" id="removeCourseOverlay"></div>
        <div class="modal-content" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;">Remove Course?</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;">Do you want to remove this course from this school?</p>
                <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #fde68a; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="removeCourseTargetName">
                    Course Name
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button type="button" class="btn-secondary" id="cancelRemoveCourseBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                    <button type="button" class="btn-danger" id="confirmRemoveCourseBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; font-weight: 600; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Remove</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Best in Course / Specialization Confirmation Modal -->
    <div class="modal" id="specializationModal" style="z-index: 10050;">
        <div class="modal-overlay" id="specModalOverlay"></div>
        <div class="modal-content" style="max-width: 450px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-star"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;" id="specModalTitle">Add as Best in Course?</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;" id="specModalMessage">Do you want to add this as Best in Course for this school?</p>
                <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #fde68a; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="specModalCourseName">
                    Course Name
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button type="button" class="btn-secondary" id="cancelSpecModalBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                    <button type="button" class="btn-primary" id="confirmSpecModalBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #0f172a; border: none; font-weight: 700; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script src="admin.js"></script>
    <script>
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

            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('manage_schools.php', { method: 'POST', body: formData });
                return res.json();
            }

            // Modal elements
            const addSchoolModal = document.getElementById('addSchoolModal');
            const editSchoolModal = document.getElementById('editSchoolModal');
            const deleteSchoolModal = document.getElementById('deleteSchoolModal');
            const viewSchoolModal = document.getElementById('viewSchoolModal');
            
            // Button elements
            const addSchoolBtn = document.getElementById('addSchoolBtn');
            const closeAddModal = document.getElementById('closeAddModal');
            const cancelAdd = document.getElementById('cancelAdd');
            const closeEditModal = document.getElementById('closeEditModal');
            const cancelEdit = document.getElementById('cancelEdit');
            const closeDeleteModal = document.getElementById('closeDeleteModal');
            const cancelDelete = document.getElementById('cancelDelete');
            const confirmDelete = document.getElementById('confirmDelete');
            const closeViewModal = document.getElementById('closeViewModal');
            const closeViewBtn = document.getElementById('closeViewBtn');
            const viewEditBtnSchool = document.getElementById('viewEditBtnSchool');
            
            // Forms
            const addSchoolForm = document.getElementById('addSchoolForm');
            const editSchoolForm = document.getElementById('editSchoolForm');
            
            // Search elements
            const searchBtn = document.getElementById('searchBtn');
            const clearBtn = document.getElementById('clearBtn');
            const searchSchoolName = document.getElementById('searchSchoolName');
            const searchCity = document.getElementById('searchCity');
            const searchProvince = document.getElementById('searchProvince');
            const searchDistrict = document.getElementById('searchDistrict');
            
            let deleteSchoolId = null;

            // Open Add Modal
            addSchoolBtn.addEventListener('click', function() {
                addSchoolForm.reset();
                addCoursesManager.clearCourses();
                const addProv = document.getElementById('addProvince');
                if (addProv) addProv.value = '4';
                addSchoolModal.classList.add('active');
                document.getElementById('addSchoolName').focus();
            });

            // Close Add Modal
            function closeAdd() {
                addSchoolModal.classList.remove('active');
                addSchoolForm.reset();
                addCoursesManager.clearCourses();
            }
            closeAddModal.addEventListener('click', closeAdd);
            cancelAdd.addEventListener('click', closeAdd);

            // Close View Modal
            function closeView() {
                viewSchoolModal.classList.remove('active');
            }
            if (closeViewModal) closeViewModal.addEventListener('click', closeView);
            if (closeViewBtn) closeViewBtn.addEventListener('click', closeView);

            // Open View Modal
            document.querySelectorAll('.school-actions .btn-action.view').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const schoolId = this.getAttribute('data-id');
                    const fd = new FormData();
                    fd.append('action', 'get_school');
                    fd.append('id', schoolId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.school) {
                            const school = data.school;
                            document.getElementById('viewName').textContent = school.name || '-';
                            document.getElementById('viewType').textContent = school.type || '-';
                            
                            function formatLocationTitleCase(name) {
                                if (!name) return '';
                                name = name.trim();
                                if (name !== name.toUpperCase()) return name;
                                return name.toLowerCase().split(' ').map(w => {
                                    if (['of', 'de', 'del', 'da'].includes(w)) return w;
                                    return w.charAt(0).toUpperCase() + w.slice(1);
                                }).join(' ').replace(/\b(sta\.|sto\.)\s*([a-z])/gi, (m, p1, p2) => p1.charAt(0).toUpperCase() + p1.slice(1).toLowerCase() + ' ' + p2.toUpperCase());
                            }

                            let street = (school.address || '').trim();
                            const city = formatLocationTitleCase(school.city || school.city_name || '');
                            const province = formatLocationTitleCase(school.province || school.province_name || 'Pangasinan');
                            
                            if (province && street.toLowerCase().endsWith(province.toLowerCase())) {
                                street = street.slice(0, -province.length).replace(/,\s*$/, '').trim();
                            }
                            if (city && street.toLowerCase().endsWith(city.toLowerCase())) {
                                street = street.slice(0, -city.length).replace(/,\s*$/, '').trim();
                            }
                            
                            const addressParts = [street, city, province].filter(Boolean);
                            document.getElementById('viewAddress').textContent = addressParts.length > 0 ? addressParts.join(', ') : '-';
                            document.getElementById('viewDistrict').textContent = school.district_name || '-';
                            document.getElementById('viewEmail').textContent = school.email || '-';
                            document.getElementById('viewContact').textContent = school.contact || '-';
                            
                            const webLink = document.getElementById('viewWebsite');
                            if (school.website) {
                                webLink.innerHTML = `<a href="${school.website}" target="_blank" style="color: #fbbf24; text-decoration: none;"><i class="fa-solid fa-external-link-alt"></i> ${school.website}</a>`;
                            } else {
                                webLink.textContent = '-';
                            }
                            
                            if (viewEditBtnSchool) viewEditBtnSchool.setAttribute('data-id', school.id);
                            
                            function escHtml(str) {
                                if (!str) return '';
                                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                            }

                            const coursesList = document.getElementById('viewCoursesList');
                            const countBadge = document.getElementById('viewSchoolCoursesCount');
                            coursesList.innerHTML = '';
                            
                            if (school.courses && school.courses.length > 0) {
                                if (countBadge) countBadge.textContent = `${school.courses.length} Course${school.courses.length > 1 ? 's' : ''}`;
                                
                                school.courses.forEach(c => {
                                    const isSpec = parseInt(c.is_specialization) === 1;
                                    const item = document.createElement('div');
                                    if (isSpec) {
                                        item.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0.85rem; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 8px; color: #fef08a; font-size: 0.88rem;';
                                    } else {
                                        item.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.55rem 0.75rem; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; color: #e2e8f0; font-size: 0.88rem;';
                                    }
                                    
                                    let content = `
                                        <div style="display: flex; align-items: center; gap: 0.6rem; min-width: 0;">
                                            <i class="fa-solid ${isSpec ? 'fa-star' : 'fa-book-open'}" style="color: ${isSpec ? '#fbbf24' : '#60a5fa'}; font-size: 0.85rem; flex-shrink: 0;"></i>
                                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; ${isSpec ? 'font-weight: 600;' : ''}">${escHtml(c.course_name)}</span>
                                        </div>
                                    `;
                                    
                                    if (isSpec) {
                                        content += `<span class="specialization-badge" style="background: rgba(251, 191, 36, 0.18); color: #fbbf24; font-size: 0.72rem; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 999px; border: 1px solid rgba(251, 191, 36, 0.4); display: inline-flex; align-items: center; gap: 0.35rem; flex-shrink: 0; box-shadow: 0 0 10px rgba(251, 191, 36, 0.2);"><i class="fa-solid fa-star" style="font-size: 0.7rem; color: #fbbf24;"></i> Best Offered Course</span>`;
                                    }
                                    
                                    item.innerHTML = content;
                                    coursesList.appendChild(item);
                                });
                            } else {
                                if (countBadge) countBadge.textContent = '0 Courses';
                                coursesList.innerHTML = '<p style="color: #64748b; font-style: italic; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">No courses linked yet.</p>';
                            }
                            
                            viewSchoolModal.classList.add('active');
                        } else {
                            showStatusModal('Error', data.message || 'Failed to load school data', false);
                        }
                    } catch (e) {
                        showStatusModal('Error', 'Error loading school data', false);
                    }
                });
            });
 
            // Edit button inside View Modal
            if (viewEditBtnSchool) {
                viewEditBtnSchool.addEventListener('click', function() {
                    const schoolId = this.getAttribute('data-id');
                    closeView();
                    const editBtn = document.querySelector(`.school-actions .btn-action.edit[data-id="${schoolId}"]`);
                    if (editBtn) {
                        editBtn.click();
                    }
                });
            }

            // -- Courses Offered multi-select widget logic (Add & Edit) --
            const allCourses = <?php echo json_encode(array_values($allCourses)); ?>;

            // State maps: courseId -> { name: string, is_specialization: number }
            let addSelectedCourses = {};
            let editSelectedCourses = {};

            let pendingRemoveContext = null; // { prefix: 'add'|'edit', id: number }
            let pendingSpecContext = null;   // { prefix: 'add'|'edit', id: number }

            function setupSchoolCoursesManager(prefix) {
                const searchInput = document.getElementById(prefix === 'add' ? 'addCoSearchInput' : 'coSearchInput');
                const dropdown    = document.getElementById(prefix === 'add' ? 'addCoDropdown' : 'coDropdown');
                const chips       = document.getElementById(prefix === 'add' ? 'addCoChips' : 'coChips');
                const noneHint    = document.getElementById(prefix === 'add' ? 'addCoNoneHint' : 'coNoneHint');
                const countBadge  = document.getElementById(prefix === 'add' ? 'addCoCountBadge' : 'coCountBadge');
                const clearAllBtn = document.getElementById(prefix === 'add' ? 'addCoClearAllBtn' : 'coClearAllBtn');

                function getSelectedMap() {
                    return prefix === 'add' ? addSelectedCourses : editSelectedCourses;
                }

                function renderDropdown(query) {
                    const q = (query || '').toLowerCase().trim();
                    const filtered = allCourses.filter(c => c.course_name.toLowerCase().includes(q));
                    dropdown.innerHTML = '';
                    const selectedMap = getSelectedMap();

                    if (filtered.length === 0) {
                        dropdown.innerHTML = '<div class="co-dropdown-empty"><i class="fa-solid fa-circle-exclamation" style="margin-right:0.4rem;"></i>No courses match your search.</div>';
                    } else {
                        filtered.slice(0, 50).forEach(c => {
                            const isSelected = selectedMap.hasOwnProperty(c.id);
                            const isSpec = isSelected && selectedMap[c.id].is_specialization === 1;
                            const item = document.createElement('div');
                            item.className = 'co-dropdown-item' + (isSelected ? ' already-selected' : '');
                            item.innerHTML = `
                                <div class="item-text-box">
                                    <i class="fa-solid fa-book-open"></i>
                                    <span title="${escHtmlGlobal(c.course_name)}">${escHtmlGlobal(c.course_name)}</span>
                                </div>
                                <div class="item-actions-box">
                                    <button type="button" class="item-spec-btn ${isSpec ? 'is-spec' : ''}" title="${isSpec ? 'Best in Course active - Click to toggle' : 'Mark as Best in Course'}">
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
                                    if (!selectedMap.hasOwnProperty(c.id)) {
                                        // Not yet added: add it directly as Best in Course!
                                        addCourse(c.id, c.course_name, 1);
                                        renderDropdown(searchInput ? searchInput.value : '');
                                    } else {
                                        // Already added: trigger confirmation prompt to toggle
                                        promptToggleSpecialization(prefix, c.id);
                                    }
                                });
                            }

                            // Add button handler
                            const addBtn = item.querySelector('.item-add-btn');
                            if (addBtn && !isSelected) {
                                addBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    addCourse(c.id, c.course_name, 0);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            // Remove button handler
                            const removeBtn = item.querySelector('.item-remove-btn');
                            if (removeBtn) {
                                removeBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    removeCourse(c.id);
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            // Click anywhere on the text to add as standard course if not already added
                            item.addEventListener('mousedown', function(e) {
                                if (e.target.closest('.item-spec-btn') || e.target.closest('.item-add-btn') || e.target.closest('.item-remove-btn')) return;
                                e.preventDefault();
                                if (selectedMap.hasOwnProperty(c.id)) return;
                                addCourse(c.id, c.course_name, 0);
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
                        const specText = specCount > 0 ? ` (${specCount} Best Offered)` : '';
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
                            const isSpec = itemData.is_specialization === 1;

                            const chip = document.createElement('div');
                            chip.className = 'co-chip' + (isSpec ? ' is-specialized' : '');
                            chip.innerHTML = `
                                <i class="fa-solid fa-graduation-cap co-chip-icon"></i>
                                <span class="co-chip-name" title="${escHtmlGlobal(name)}">${escHtmlGlobal(name)}</span>
                                ${isSpec ? '<span class="co-spec-badge"><i class="fa-solid fa-star"></i> Best Offered Course</span>' : ''}
                                <div class="co-chip-actions">
                                    <button type="button" class="co-chip-star ${isSpec ? 'active' : ''}" data-id="${id}" title="${isSpec ? 'Best Offered Course active - Click to unmark' : 'Click to mark as Best Offered Course'}">
                                        <i class="fa-${isSpec ? 'solid' : 'regular'} fa-star"></i>
                                    </button>
                                    <button type="button" class="co-chip-remove" data-id="${id}" title="Remove course">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            `;

                            chip.querySelector('.co-chip-star').addEventListener('click', function(e) {
                                e.stopPropagation();
                                promptToggleSpecialization(prefix, parseInt(this.getAttribute('data-id')));
                            });

                            chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                                e.stopPropagation();
                                promptRemoveCourse(prefix, parseInt(this.getAttribute('data-id')));
                            });

                            chips.appendChild(chip);
                        });
                    }
                }

                function addCourse(id, name, is_specialization = 0) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    if (!selectedMap.hasOwnProperty(id)) {
                        selectedMap[id] = {
                            name: name,
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

                function removeCourse(id) {
                    id = parseInt(id);
                    const selectedMap = getSelectedMap();
                    delete selectedMap[id];
                    renderChips();
                    if (dropdown && dropdown.classList.contains('open') && searchInput) {
                        renderDropdown(searchInput.value);
                    }
                }

                function clearCourses() {
                    if (prefix === 'add') {
                        addSelectedCourses = {};
                    } else {
                        editSelectedCourses = {};
                    }
                    if (searchInput) searchInput.value = '';
                    const clearSearchBtn = document.getElementById(prefix === 'add' ? 'addCoSearchClear' : 'coSearchClear');
                    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
                    if (dropdown) dropdown.classList.remove('open');
                    renderChips();
                }

                if (dropdown) {
                    dropdown.addEventListener('mousedown', function(e) {
                        // Prevent searchInput from blurring when clicking inside dropdown or dragging scrollbar
                        e.preventDefault();
                    });
                }

                const clearSearchBtn = document.getElementById(prefix === 'add' ? 'addCoSearchClear' : 'coSearchClear');

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
                        clearCourses();
                    });
                }

                return {
                    addCourse,
                    toggleSpecialization,
                    removeCourse,
                    clearCourses,
                    renderChips
                };
            }

            const addCoursesManager = setupSchoolCoursesManager('add');
            const editCoursesManager = setupSchoolCoursesManager('edit');

            // -- Remove Course Confirmation Prompt --
            const removeCourseModal = document.getElementById('removeCourseModal');
            const removeCourseTargetName = document.getElementById('removeCourseTargetName');
            const cancelRemoveCourseBtn = document.getElementById('cancelRemoveCourseBtn');
            const confirmRemoveCourseBtn = document.getElementById('confirmRemoveCourseBtn');
            const removeCourseOverlay = document.getElementById('removeCourseOverlay');

            function promptRemoveCourse(prefix, id) {
                id = parseInt(id);
                const map = prefix === 'add' ? addSelectedCourses : editSelectedCourses;
                if (!map.hasOwnProperty(id)) return;
                pendingRemoveContext = { prefix, id };
                if (removeCourseTargetName) {
                    removeCourseTargetName.textContent = map[id].name;
                }
                if (removeCourseModal) {
                    removeCourseModal.classList.add('active');
                }
            }

            function closeRemoveCourseModal() {
                if (removeCourseModal) {
                    removeCourseModal.classList.remove('active');
                }
                pendingRemoveContext = null;
            }

            if (cancelRemoveCourseBtn) cancelRemoveCourseBtn.addEventListener('click', closeRemoveCourseModal);
            if (removeCourseOverlay) removeCourseOverlay.addEventListener('click', closeRemoveCourseModal);
            if (confirmRemoveCourseBtn) {
                confirmRemoveCourseBtn.addEventListener('click', function() {
                    if (pendingRemoveContext) {
                        const mgr = pendingRemoveContext.prefix === 'add' ? addCoursesManager : editCoursesManager;
                        mgr.removeCourse(pendingRemoveContext.id);
                    }
                    closeRemoveCourseModal();
                });
            }

            // -- Best in Course / Specialization Confirmation Prompt --
            const specializationModal = document.getElementById('specializationModal');
            const specModalTitle = document.getElementById('specModalTitle');
            const specModalMessage = document.getElementById('specModalMessage');
            const specModalCourseName = document.getElementById('specModalCourseName');
            const confirmSpecModalBtn = document.getElementById('confirmSpecModalBtn');
            const cancelSpecModalBtn = document.getElementById('cancelSpecModalBtn');
            const specModalOverlay = document.getElementById('specModalOverlay');

            function promptToggleSpecialization(prefix, id) {
                id = parseInt(id);
                const map = prefix === 'add' ? addSelectedCourses : editSelectedCourses;
                if (!map.hasOwnProperty(id)) return;
                pendingSpecContext = { prefix, id };
                const course = map[id];
                const isCurrentlySpec = course.is_specialization === 1;

                if (specModalCourseName) {
                    specModalCourseName.textContent = course.name;
                }

                if (!isCurrentlySpec) {
                    if (specModalTitle) specModalTitle.textContent = 'Mark as Best Offered Course?';
                    if (specModalMessage) specModalMessage.textContent = 'Do you want to mark this as a Best Offered Course for this school?';
                    if (confirmSpecModalBtn) {
                        confirmSpecModalBtn.textContent = 'Mark as Best Offered Course';
                        confirmSpecModalBtn.style.background = 'linear-gradient(135deg, #fbbf24, #f59e0b)';
                        confirmSpecModalBtn.style.color = '#0f172a';
                    }
                } else {
                    if (specModalTitle) specModalTitle.textContent = 'Remove Best Offered Course?';
                    if (specModalMessage) specModalMessage.textContent = 'Do you want to remove the Best Offered Course status for this program?';
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
                        const mgr = pendingSpecContext.prefix === 'add' ? addCoursesManager : editCoursesManager;
                        mgr.toggleSpecialization(pendingSpecContext.id);
                    }
                    closeSpecModal();
                });
            }

            function escHtmlGlobal(str) {
                if (!str) return '';
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            // ------------------------------------------------------------------

            // Open Edit Modal
            document.querySelectorAll('.school-actions .btn-action.edit').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const schoolId = this.getAttribute('data-id');
                    
                    // Fetch school data
                    const fd = new FormData();
                    fd.append('action', 'get_school');
                    fd.append('id', schoolId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.school) {
                            const school = data.school;
                            document.getElementById('editSchoolId').value = school.id;
                            document.getElementById('editSchoolName').value = school.name || '';
                            document.getElementById('editAddress').value = school.address || '';

                            // City
                            const editCitySelect = document.getElementById('editCity');
                            if (school.city_id && editCitySelect.querySelector(`option[value="${school.city_id}"]`)) {
                                editCitySelect.value = school.city_id;
                            } else if (school.city) {
                                let matchedCity = false;
                                for (let opt of editCitySelect.options) {
                                    if (opt.text.toLowerCase().trim() === school.city.toLowerCase().trim() ||
                                        opt.text.toLowerCase().includes(school.city.toLowerCase().trim())) {
                                        editCitySelect.value = opt.value;
                                        matchedCity = true;
                                        break;
                                    }
                                }
                                if (!matchedCity) editCitySelect.value = '';
                            } else {
                                editCitySelect.value = '';
                            }

                            // Province (guarantee fallback to Pangasinan ID 4 so it is never blank)
                            const editProvSelect = document.getElementById('editProvince');
                            if (school.province_id && editProvSelect.querySelector(`option[value="${school.province_id}"]`)) {
                                editProvSelect.value = school.province_id;
                            } else {
                                editProvSelect.value = '4';
                            }

                            // District
                            document.getElementById('editDistrict').value = school.district_id || '';
                            document.getElementById('editEmail').value = school.email || '';
                            document.getElementById('editWebsite').value = school.website || '';
                            document.getElementById('editContact').value = school.contact || '';

                            // School Type
                            const editTypeSelect = document.getElementById('editType');
                            editTypeSelect.value = school.type || '';
                            if (school.type && !editTypeSelect.value) {
                                for (let opt of editTypeSelect.options) {
                                    if (opt.value.toLowerCase() === school.type.toLowerCase() ||
                                        opt.text.toLowerCase() === school.type.toLowerCase()) {
                                        editTypeSelect.value = opt.value;
                                        break;
                                    }
                                }
                            }

                            // Pre-populate Courses Offered from existing course_schools relationships
                            editCoursesManager.clearCourses();
                            if (school.courses && school.courses.length > 0) {
                                school.courses.forEach(c => {
                                    editCoursesManager.addCourse(c.course_id, c.course_name, c.is_specialization);
                                });
                            }

                            editSchoolModal.classList.add('active');
                        } else {
                            showStatusModal('Error', data.message || 'Failed to load school data', false);
                        }
                    } catch (e) {
                        showStatusModal('Error', 'Error loading school data', false);
                    }
                });
            });

            // Open Delete Modal
            document.querySelectorAll('.school-actions .btn-action.delete').forEach(btn => {
                btn.addEventListener('click', function() {
                    const schoolId = this.getAttribute('data-id');
                    const schoolCard = this.closest('.school-card');
                    const schoolName = schoolCard.querySelector('.school-name').textContent;
                    
                    deleteSchoolId = schoolId;
                    document.getElementById('deleteSchoolName').textContent = schoolName;
                    deleteSchoolModal.classList.add('active');
                });
            });

            // Close Edit Modal
            function closeEdit() {
                editSchoolModal.classList.remove('active');
                editSchoolForm.reset();
                editCoursesManager.clearCourses();
            }
            closeEditModal.addEventListener('click', closeEdit);
            cancelEdit.addEventListener('click', closeEdit);

            // Close Delete Modal
            function closeDelete() {
                deleteSchoolModal.classList.remove('active');
                deleteSchoolId = null;
            }
            closeDeleteModal.addEventListener('click', closeDelete);
            cancelDelete.addEventListener('click', closeDelete);

            // Form Submissions
            addSchoolForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_school');

                // Append selected course IDs and specialized course IDs from Add modal
                const addIds = Object.keys(addSelectedCourses).map(Number);
                const addSpecIds = addIds.filter(id => addSelectedCourses[id].is_specialization === 1);
                formData.append('offered_course_ids', JSON.stringify(addIds));
                formData.append('specialized_course_ids', JSON.stringify(addSpecIds));
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'School added successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add school', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error adding school', false);
                }
            });

            editSchoolForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'edit_school');

                // Append selected course IDs and specialized course IDs from Edit modal
                const selectedIds = Object.keys(editSelectedCourses).map(Number);
                const specializedIds = selectedIds.filter(id => editSelectedCourses[id].is_specialization === 1);
                formData.append('offered_course_ids', JSON.stringify(selectedIds));
                formData.append('specialized_course_ids', JSON.stringify(specializedIds));
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'School updated successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update school', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error updating school', false);
                }
            });

            confirmDelete.addEventListener('click', async function() {
                if (deleteSchoolId) {
                    const fd = new FormData();
                    fd.append('action', 'delete_school');
                    fd.append('id', deleteSchoolId);
                    
                    try {
                        const data = await apiPost(fd);
                        if (data.success) {
                            showStatusModal('Success', 'School deleted successfully!', true, () => location.reload());
                        } else {
                            showStatusModal('Error', data.message || 'Failed to delete school', false);
                        }
                    } catch (e) {
                        showStatusModal('Error', 'Error deleting school', false);
                    }
                    closeDelete();
                }
            });

            // Search functionality
            function runSearch() {
                const nameQuery = (searchSchoolName.value || '').toLowerCase().trim();
                const cityQuery = (searchCity.value || '').toLowerCase().trim();
                const provinceQuery = (searchProvince.value || '').toLowerCase().trim();
                const districtQuery = (searchDistrict.value || '').toLowerCase().trim();
                
                let visibleCount = 0;
                const cards = document.querySelectorAll('.school-card');
                
                cards.forEach(card => {
                    const schoolName = (card.querySelector('.school-name')?.textContent || '').toLowerCase();
                    const city = (card.querySelector('.school-location span')?.textContent || '').toLowerCase();
                    const district = (card.getAttribute('data-district') || '').toLowerCase();
                    const address = (card.querySelector('.school-address')?.textContent || '').toLowerCase();
                    
                    const matchName = !nameQuery || schoolName.includes(nameQuery);
                    const matchCity = !cityQuery || city.includes(cityQuery);
                    const matchDistrict = !districtQuery || district === districtQuery;
                    const matchProvince = !provinceQuery || address.includes(provinceQuery);
                    
                    if (matchName && matchCity && matchProvince && matchDistrict) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                const countText = document.getElementById('schoolsCountText');
                if (countText) {
                    countText.textContent = `${visibleCount} School${visibleCount === 1 ? '' : 's'}`;
                }

                const noResultsEl = document.getElementById('noResultsMessage');
                if (noResultsEl) {
                    noResultsEl.style.display = visibleCount === 0 && cards.length > 0 ? 'block' : 'none';
                }
            }

            searchBtn.addEventListener('click', runSearch);

            // Clear search
            clearBtn.addEventListener('click', function() {
                searchSchoolName.value = '';
                searchCity.value = '';
                searchProvince.value = '';
                searchDistrict.value = '';
                runSearch();
            });

            // Allow Enter key to trigger search
            [searchSchoolName, searchCity, searchProvince, searchDistrict].forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        searchBtn.click();
                    }
                });
            });

            // Auto trigger search on dropdown change
            [searchDistrict, searchCity, searchProvince].forEach(select => {
                select.addEventListener('change', function() {
                    searchBtn.click();
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
