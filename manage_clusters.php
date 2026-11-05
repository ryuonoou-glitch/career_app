<?php
// Manage Career Clusters - Backend Added

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
    
    switch ($action) {
        case 'add_cluster':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                $response['message'] = 'Cluster name is required';
                echo json_encode($response);
                exit;
            }
            
            // Check if cluster already exists
            $check = $mysqli->prepare("SELECT id FROM clusters WHERE name = ? LIMIT 1");
            $check->bind_param('s', $name);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $response['message'] = 'Cluster with this name already exists';
                $check->close();
                echo json_encode($response);
                exit;
            }
            $check->close();
            
            $hollandTraits = isset($_POST['holland_traits']) ? implode(',', (array)$_POST['holland_traits']) : '';
            $bigfiveTraits = isset($_POST['bigfive_traits']) ? implode(',', (array)$_POST['bigfive_traits']) : '';
            $shsStrands = isset($_POST['shs_strands']) ? implode(',', (array)$_POST['shs_strands']) : '';

            $stmt = $mysqli->prepare("INSERT INTO clusters (name, description, holland_traits, bigfive_traits, shs_strands) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssss', $name, $description, $hollandTraits, $bigfiveTraits, $shsStrands);
            
            if ($stmt->execute()) {
                $newClusterId = $mysqli->insert_id;
                
                // Assign selected courses to this new cluster
                $courseIds = array_map('intval', (array)($_POST['course_ids'] ?? []));
                $newCluster = ['id' => $newClusterId, 'name' => $name, 'description' => $description];
                if (!empty($courseIds)) {
                    $inList = implode(',', $courseIds);
                    $mysqli->query("UPDATE courses SET cluster_id = {$newClusterId} WHERE id IN ({$inList})");
                    $newCluster['linked_courses'] = $courseIds;
                }
                
                $adminId = $_SESSION['admin_id'] ?? 0;
                $userType = (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ? 'admin' : 'counselor';
                log_activity($adminId, $userType, 'Added Cluster', 'clusters', $newClusterId, "Admin added career cluster: {$name}", null, json_encode($newCluster));

                $response['success'] = true;
                $response['message'] = 'Career cluster added successfully';
                $response['id'] = $newClusterId;
            } else {
                $response['message'] = 'Failed to add cluster: ' . $stmt->error;
            }
            $stmt->close();
            echo json_encode($response);
            exit;
            
        case 'get_cluster':
            $clusterId = (int)($_POST['id'] ?? 0);
            if ($clusterId <= 0) {
                $response['message'] = 'Invalid cluster ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("SELECT * FROM clusters WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $clusterId);
            $stmt->execute();
            $result = $stmt->get_result();
            $cluster = $result->fetch_assoc();
            $stmt->close();
            
            if ($cluster) {
                // Courses linked count
                $stmtC = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM courses WHERE cluster_id = ?");
                $stmtC->bind_param('i', $clusterId);
                $stmtC->execute();
                $resC = $stmtC->get_result();
                $cluster['courses_linked'] = (int)($resC->fetch_assoc()['cnt'] ?? 0);
                $stmtC->close();

                // Schools offering count
                $stmtS = $mysqli->prepare("SELECT COUNT(DISTINCT cs.school_id) AS cnt FROM course_schools cs JOIN courses c ON cs.course_id = c.id WHERE c.cluster_id = ?");
                $stmtS->bind_param('i', $clusterId);
                $stmtS->execute();
                $resS = $stmtS->get_result();
                $cluster['schools_offering'] = (int)($resS->fetch_assoc()['cnt'] ?? 0);
                $stmtS->close();

                // Detailed Courses with Offering Schools & Specialization
                $coursesData = [];
                $coursesList = [];
                $stmtCourses = $mysqli->prepare("SELECT id, course_name, description FROM courses WHERE cluster_id = ? ORDER BY course_name ASC");
                if ($stmtCourses) {
                    $stmtCourses->bind_param('i', $clusterId);
                    $stmtCourses->execute();
                    $resCourses = $stmtCourses->get_result();
                    while ($cRow = $resCourses->fetch_assoc()) {
                        $courseId = (int)$cRow['id'];
                        $coursesList[] = $cRow['course_name'];
                        
                        $schoolsForCourse = [];
                        $stmtSch = $mysqli->prepare("
                            SELECT s.id AS school_id, s.name AS school_name, s.type, s.city, s.province, MAX(cs.is_specialization) AS is_specialization
                            FROM course_schools cs
                            JOIN schools s ON cs.school_id = s.id
                            WHERE cs.course_id = ?
                            GROUP BY s.id, s.name, s.type, s.city, s.province
                            ORDER BY MAX(cs.is_specialization) DESC, s.name ASC
                        ");
                        if ($stmtSch) {
                            $stmtSch->bind_param('i', $courseId);
                            $stmtSch->execute();
                            $resSch = $stmtSch->get_result();
                            while ($sRow = $resSch->fetch_assoc()) {
                                $schoolsForCourse[] = $sRow;
                            }
                            $stmtSch->close();
                        }
                        $cRow['schools'] = $schoolsForCourse;
                        $coursesData[] = $cRow;
                    }
                    $stmtCourses->close();
                }
                $cluster['courses_list'] = $coursesList;
                $cluster['courses_data'] = $coursesData;

                // Unique Schools offering courses in this cluster
                $schoolsList = [];
                $stmtAllSchools = $mysqli->prepare("
                    SELECT s.id, s.name, s.type, s.city, s.province, s.contact, s.email, s.website,
                           COUNT(DISTINCT cs.course_id) AS cluster_courses_count,
                           MAX(cs.is_specialization) AS has_specialization
                    FROM course_schools cs
                    JOIN courses c ON cs.course_id = c.id
                    JOIN schools s ON cs.school_id = s.id
                    WHERE c.cluster_id = ?
                    GROUP BY s.id, s.name, s.type, s.city, s.province, s.contact, s.email, s.website
                    ORDER BY MAX(cs.is_specialization) DESC, cluster_courses_count DESC, s.name ASC
                ");
                if ($stmtAllSchools) {
                    $stmtAllSchools->bind_param('i', $clusterId);
                    $stmtAllSchools->execute();
                    $resAllSchools = $stmtAllSchools->get_result();
                    while ($asRow = $resAllSchools->fetch_assoc()) {
                        $schoolsList[] = $asRow;
                    }
                    $stmtAllSchools->close();
                }
                $cluster['schools_list'] = $schoolsList;

                $response['success'] = true;
                $response['cluster'] = $cluster;
            } else {
                $response['message'] = 'Cluster not found';
            }
            echo json_encode($response);
            exit;
            
        case 'edit_cluster':
            $clusterId = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if ($clusterId <= 0 || empty($name)) {
                $response['message'] = 'Invalid data provided';
                echo json_encode($response);
                exit;
            }
            
            // Check if name exists for another cluster
            $check = $mysqli->prepare("SELECT id FROM clusters WHERE name = ? AND id != ? LIMIT 1");
            $check->bind_param('si', $name, $clusterId);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $response['message'] = 'Cluster with this name already exists';
                $check->close();
                echo json_encode($response);
                exit;
            }
            $check->close();
            
            $oldCluster = null;
            $oldQ = $mysqli->prepare("SELECT * FROM clusters WHERE id = ?");
            $oldQ->bind_param('i', $clusterId);
            $oldQ->execute();
            $oldCluster = $oldQ->get_result()->fetch_assoc();
            $oldQ->close();
            if ($oldCluster) {
                $oldC = [];
                $ocQ = $mysqli->query("SELECT id FROM courses WHERE cluster_id = {$clusterId}");
                if ($ocQ) { while ($ocR = $ocQ->fetch_assoc()) { $oldC[] = (int)$ocR['id']; } }
                $oldCluster['linked_courses'] = $oldC;
            }
            
            $hollandTraits = isset($_POST['holland_traits']) ? implode(',', (array)$_POST['holland_traits']) : '';
            $bigfiveTraits = isset($_POST['bigfive_traits']) ? implode(',', (array)$_POST['bigfive_traits']) : '';
            $shsStrands = isset($_POST['shs_strands']) ? implode(',', (array)$_POST['shs_strands']) : '';

            $stmt = $mysqli->prepare("UPDATE clusters SET name = ?, description = ?, holland_traits = ?, bigfive_traits = ?, shs_strands = ? WHERE id = ?");
            $stmt->bind_param('sssssi', $name, $description, $hollandTraits, $bigfiveTraits, $shsStrands, $clusterId);
            
            if ($stmt->execute()) {
                $courseIds = array_map('intval', (array)($_POST['course_ids'] ?? []));
                if (!empty($courseIds)) {
                    $inList = implode(',', $courseIds);
                    // Unassign courses no longer selected for this cluster
                    $mysqli->query("UPDATE courses SET cluster_id = NULL WHERE cluster_id = {$clusterId} AND id NOT IN ({$inList})");
                    // Assign selected courses to this cluster
                    $mysqli->query("UPDATE courses SET cluster_id = {$clusterId} WHERE id IN ({$inList})");
                } else {
                    // Unassign all courses if none selected
                    $mysqli->query("UPDATE courses SET cluster_id = NULL WHERE cluster_id = {$clusterId}");
                }
                
                $newCluster = null;
                $newQ = $mysqli->prepare("SELECT * FROM clusters WHERE id = ?");
                $newQ->bind_param('i', $clusterId);
                $newQ->execute();
                $newCluster = $newQ->get_result()->fetch_assoc();
                $newQ->close();
                if ($newCluster) {
                    $newCluster['linked_courses'] = $courseIds;
                }
                
                $adminId = $_SESSION['admin_id'] ?? 0;
                $userType = (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ? 'admin' : 'counselor';
                log_activity($adminId, $userType, 'Edited Cluster', 'clusters', $clusterId, "Admin edited career cluster: {$name}", json_encode($oldCluster), json_encode($newCluster));

                $response['success'] = true;
                $response['message'] = 'Career cluster updated successfully';
            } else {
                $response['message'] = 'Failed to update cluster';
            }
            $stmt->close();
            echo json_encode($response);
            exit;
            
        case 'check_cluster_associations':
            $clusterId = (int)($_POST['id'] ?? 0);
            $checkC = $mysqli->prepare("SELECT COUNT(*) as count FROM courses WHERE cluster_id = ?");
            $checkC->bind_param('i', $clusterId);
            $checkC->execute();
            $courseCount = (int)($checkC->get_result()->fetch_assoc()['count'] ?? 0);
            $checkC->close();

            $checkJ = $mysqli->prepare("SELECT COUNT(*) as count FROM jobs WHERE cluster_id = ?");
            $checkJ->bind_param('i', $clusterId);
            $checkJ->execute();
            $jobCount = (int)($checkJ->get_result()->fetch_assoc()['count'] ?? 0);
            $checkJ->close();

            $totalAssociations = $courseCount + $jobCount;
            echo json_encode([
                'success' => true,
                'count' => $totalAssociations,
                'course_count' => $courseCount,
                'job_count' => $jobCount
            ]);
            exit;

        case 'delete_cluster':
            $clusterId = (int)($_POST['id'] ?? 0);
            $unlinkAssociations = !empty($_POST['unlink_associations']) || !empty($_POST['unlink_courses']);
            if ($clusterId <= 0) {
                $response['message'] = 'Invalid cluster ID';
                echo json_encode($response);
                exit;
            }
            
            // Check if cluster is associated with any courses or jobs
            $checkC = $mysqli->prepare("SELECT COUNT(*) as count FROM courses WHERE cluster_id = ?");
            $checkC->bind_param('i', $clusterId);
            $checkC->execute();
            $courseCount = (int)($checkC->get_result()->fetch_assoc()['count'] ?? 0);
            $checkC->close();

            $checkJ = $mysqli->prepare("SELECT COUNT(*) as count FROM jobs WHERE cluster_id = ?");
            $checkJ->bind_param('i', $clusterId);
            $checkJ->execute();
            $jobCount = (int)($checkJ->get_result()->fetch_assoc()['count'] ?? 0);
            $checkJ->close();

            $totalAssociations = $courseCount + $jobCount;
            if ($totalAssociations > 0 && !$unlinkAssociations) {
                $response['has_associations'] = true;
                $response['count'] = $totalAssociations;
                $response['message'] = 'This cluster is currently associated with one or more courses or jobs. Please confirm unlinking.';
                echo json_encode($response);
                exit;
            }
            
            $oldCluster = null;
            $oldQ = $mysqli->prepare("SELECT * FROM clusters WHERE id = ?");
            if ($oldQ) {
                $oldQ->bind_param('i', $clusterId);
                $oldQ->execute();
                $oldCluster = $oldQ->get_result()->fetch_assoc();
                $oldQ->close();
            }
            
            // Database transaction: unassign courses, jobs, and questions, then delete cluster
            $mysqli->begin_transaction();
            try {
                if ($courseCount > 0) {
                    $unassignC = $mysqli->prepare("UPDATE courses SET cluster_id = NULL WHERE cluster_id = ?");
                    $unassignC->bind_param('i', $clusterId);
                    if (!$unassignC->execute()) {
                        throw new Exception('Failed to unassign courses: ' . $unassignC->error);
                    }
                    $unassignC->close();
                }

                if ($jobCount > 0) {
                    $unassignJ = $mysqli->prepare("UPDATE jobs SET cluster_id = NULL WHERE cluster_id = ?");
                    $unassignJ->bind_param('i', $clusterId);
                    if (!$unassignJ->execute()) {
                        throw new Exception('Failed to unassign jobs: ' . $unassignJ->error);
                    }
                    $unassignJ->close();
                }

                // Unlink questions if any
                $unassignQC = $mysqli->prepare("UPDATE questions_career SET cluster_id = NULL WHERE cluster_id = ?");
                if ($unassignQC) {
                    $unassignQC->bind_param('i', $clusterId);
                    $unassignQC->execute();
                    $unassignQC->close();
                }
                $unassignQS = $mysqli->prepare("UPDATE questions_skills SET cluster_id = NULL WHERE cluster_id = ?");
                if ($unassignQS) {
                    $unassignQS->bind_param('i', $clusterId);
                    $unassignQS->execute();
                    $unassignQS->close();
                }

                $stmt = $mysqli->prepare("DELETE FROM clusters WHERE id = ?");
                $stmt->bind_param('i', $clusterId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete cluster: ' . $stmt->error);
                }
                $stmt->close();

                $mysqli->commit();

                $adminId = $_SESSION['admin_id'] ?? 0;
                $userType = (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ? 'admin' : 'counselor';
                $cname = $oldCluster['name'] ?? '';
                log_activity($adminId, $userType, 'Deleted Cluster', 'clusters', $clusterId, "Admin deleted career cluster: {$cname}" . ($totalAssociations > 0 ? " (unlinked {$totalAssociations} association(s))" : ""), json_encode($oldCluster), null);

                $response['success'] = true;
                $response['message'] = 'Career cluster deleted successfully';
            } catch (Exception $ex) {
                $mysqli->rollback();
                $response['success'] = false;
                $response['message'] = $ex->getMessage();
            }
            echo json_encode($response);
            exit;
    }
}

if (!function_exists('getClusterIconClass')) {
    function getClusterIconClass($name) {
        $n = strtolower($name);
        if (str_contains($n, 'agri') || str_contains($n, 'farm') || str_contains($n, 'fish')) return 'fa-seedling';
        if (str_contains($n, 'archit') || str_contains($n, 'engine') || str_contains($n, 'construct')) return 'fa-compass-drafting';
        if (str_contains($n, 'art') || str_contains($n, 'media') || str_contains($n, 'communicat') || str_contains($n, 'design')) return 'fa-palette';
        if (str_contains($n, 'busin') || str_contains($n, 'manag') || str_contains($n, 'finance')) return 'fa-chart-pie';
        if (str_contains($n, 'comput') || str_contains($n, 'tech') || str_contains($n, 'it') || str_contains($n, 'softw')) return 'fa-laptop-code';
        if (str_contains($n, 'educat') || str_contains($n, 'teach')) return 'fa-graduation-cap';
        if (str_contains($n, 'health') || str_contains($n, 'medic') || str_contains($n, 'nurse')) return 'fa-heart-pulse';
        if (str_contains($n, 'hospital') || str_contains($n, 'tour') || str_contains($n, 'culin')) return 'fa-utensils';
        if (str_contains($n, 'law') || str_contains($n, 'govern') || str_contains($n, 'public')) return 'fa-gavel';
        if (str_contains($n, 'sci') || str_contains($n, 'research')) return 'fa-atom';
        if (str_contains($n, 'social') || str_contains($n, 'human')) return 'fa-hands-holding-child';
        if (str_contains($n, 'transport') || str_contains($n, 'logist')) return 'fa-truck-fast';
        return 'fa-briefcase';
    }
}

// Get all clusters
$clusters = [];
$result = $mysqli->query("
    SELECT c.*, 
    (SELECT COUNT(*) FROM courses WHERE cluster_id = c.id) as course_count,
    (SELECT COUNT(*) FROM jobs WHERE cluster_id = c.id) as job_count
    FROM clusters c ORDER BY c.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clusters[] = $row;
    }
}

$hollandTraits = ['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'];
$bigFiveTraits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];
$strands  = $mysqli->query("SELECT id, name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];

$hollandIcons = [
    'Realistic' => 'fa-wrench',
    'Investigative' => 'fa-microscope',
    'Artistic' => 'fa-palette',
    'Social' => 'fa-users',
    'Enterprising' => 'fa-chart-line',
    'Conventional' => 'fa-clipboard-check'
];
$bigFiveIcons = [
    'Openness' => 'fa-lightbulb',
    'Conscientiousness' => 'fa-bullseye',
    'Extraversion' => 'fa-bolt',
    'Agreeableness' => 'fa-heart',
    'Neuroticism' => 'fa-shield-heart'
];
$strandIcons = [
    'STEM' => 'fa-atom',
    'ABM' => 'fa-coins',
    'HUMSS' => 'fa-book-open',
    'GAS' => 'fa-compass',
    'TVL' => 'fa-gears',
    'TECHPRO' => 'fa-gears',
    'ACADPRO' => 'fa-graduation-cap'
];

// Get all courses for assignment dropdown
$allCourses = [];
$coursesRes = $mysqli->query("SELECT id, course_name, cluster_id FROM courses ORDER BY course_name ASC");
if ($coursesRes) {
    while ($cRow = $coursesRes->fetch_assoc()) {
        $allCourses[] = $cRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Career Clusters - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css?v=<?php echo filemtime('admin.css'); ?>">
    <style>
        /* ========================================================== */
        /* Redesigned View Cluster Modal                             */
        /* ========================================================== */
        #viewClusterModal .modal-content {
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 20px !important;
            padding: 0 !important;
            max-width: 820px !important;
            width: 95% !important;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8) !important;
            font-family: 'Inter', sans-serif !important;
            overflow: hidden !important;
        }

        #viewClusterModal .modal-header-custom {
            position: relative !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            padding: 1.25rem 1.75rem !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            background: rgba(15, 23, 42, 0.9) !important;
            text-align: center !important;
        }

        #viewClusterModal .header-left-box {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.85rem !important;
            text-align: center !important;
            width: 100% !important;
            padding: 0 2.5rem !important;
        }

        #viewClusterModal .icon-box-header {
            width: 42px !important;
            height: 42px !important;
            border-radius: 12px !important;
            background: rgba(251, 191, 36, 0.15) !important;
            border: 1px solid rgba(251, 191, 36, 0.3) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #fbbf24 !important;
            font-size: 1.15rem !important;
            flex-shrink: 0 !important;
        }

        #viewClusterModal .header-titles {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            text-align: center !important;
        }

        #viewClusterModal .header-titles h2 {
            font-size: 1.2rem !important;
            font-weight: 700 !important;
            color: #f8fafc !important;
            margin: 0 !important;
            line-height: 1.2 !important;
            text-align: center !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.5rem !important;
        }

        #viewClusterModal .header-titles p {
            font-size: 0.82rem !important;
            color: #94a3b8 !important;
            margin: 0.2rem 0 0 0 !important;
            text-align: center !important;
        }

        #viewClusterModal .modal-close {
            position: absolute !important;
            right: 1.25rem !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            background: none !important;
            border: none !important;
            color: #94a3b8 !important;
            font-size: 1.25rem !important;
            cursor: pointer !important;
            padding: 0.4rem !important;
            border-radius: 8px !important;
            transition: all 0.2s !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        #viewClusterModal .modal-close:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.08) !important;
            transform: none !important;
        }

        #viewClusterModal .view-modal-body {
            padding: 1.5rem 1.75rem !important;
            max-height: calc(85vh - 145px) !important;
            overflow-y: auto !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 1.25rem !important;
        }

        /* Hero Overview Card */
        .cluster-hero-overview {
            background: rgba(30, 41, 59, 0.4) !important;
            border: 1px solid rgba(255, 255, 255, 0.07) !important;
            border-radius: 14px !important;
            padding: 1.35rem !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 1rem !important;
        }

        .hero-top-row {
            display: flex !important;
            align-items: flex-start !important;
            gap: 1.1rem !important;
        }

        .cluster-avatar-badge {
            width: 58px !important;
            height: 58px !important;
            border-radius: 14px !important;
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.2), rgba(245, 158, 11, 0.05)) !important;
            border: 1.5px solid rgba(251, 191, 36, 0.4) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #fbbf24 !important;
            font-size: 1.6rem !important;
            flex-shrink: 0 !important;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.15) !important;
        }

        .hero-info-main {
            flex: 1 !important;
            min-width: 0 !important;
        }

        .cluster-category-tag {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            font-size: 0.72rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            color: #fbbf24 !important;
            margin-bottom: 0.25rem !important;
        }

        .cluster-hero-title {
            font-size: 1.4rem !important;
            font-weight: 800 !important;
            color: #ffffff !important;
            margin: 0 0 0.4rem 0 !important;
            line-height: 1.3 !important;
        }

        .cluster-hero-desc {
            font-size: 0.9rem !important;
            color: #94a3b8 !important;
            line-height: 1.55 !important;
            margin: 0 !important;
        }

        /* Alignment Tags Wrapper */
        .cluster-traits-wrapper {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 0.5rem !important;
            padding-top: 0.5rem !important;
            border-top: 1px solid rgba(255, 255, 255, 0.05) !important;
        }

        .trait-pill {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            font-size: 0.76rem !important;
            font-weight: 600 !important;
            padding: 0.25rem 0.65rem !important;
            border-radius: 999px !important;
        }

        .trait-pill.holland {
            background: rgba(245, 158, 11, 0.12) !important;
            color: #fbbf24 !important;
            border: 1px solid rgba(245, 158, 11, 0.28) !important;
        }

        .trait-pill.bigfive {
            background: rgba(129, 140, 248, 0.12) !important;
            color: #a5b4fc !important;
            border: 1px solid rgba(129, 140, 248, 0.28) !important;
        }

        .trait-pill.strand {
            background: rgba(56, 189, 248, 0.12) !important;
            color: #38bdf8 !important;
            border: 1px solid rgba(56, 189, 248, 0.28) !important;
        }

        /* Stat Grid */
        .cluster-stat-grid {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 0.85rem !important;
        }

        .c-stat-card {
            background: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            border-radius: 10px !important;
            padding: 0.85rem 1rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.85rem !important;
        }

        .c-stat-icon {
            width: 38px !important;
            height: 38px !important;
            border-radius: 10px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.05rem !important;
            flex-shrink: 0 !important;
        }

        .c-stat-info {
            display: flex !important;
            flex-direction: column !important;
        }

        .c-stat-val {
            font-size: 1.25rem !important;
            font-weight: 800 !important;
            color: #ffffff !important;
            line-height: 1.1 !important;
        }

        .c-stat-lbl {
            font-size: 0.78rem !important;
            color: #94a3b8 !important;
            font-weight: 500 !important;
        }

        /* Courses and Schools Card */
        .cluster-courses-card {
            background: rgba(30, 41, 59, 0.3) !important;
            border: 1px solid rgba(255, 255, 255, 0.07) !important;
            border-radius: 14px !important;
            padding: 1.25rem !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 1rem !important;
        }

        .courses-card-header {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
        }

        .c-card-title {
            display: flex !important;
            align-items: center !important;
            gap: 0.6rem !important;
        }

        .c-card-title h4 {
            font-size: 0.98rem !important;
            font-weight: 700 !important;
            color: #f8fafc !important;
            margin: 0 !important;
        }

        .courses-count-pill {
            background: rgba(251, 191, 36, 0.15) !important;
            color: #fbbf24 !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            padding: 0.2rem 0.65rem !important;
            border-radius: 999px !important;
            border: 1px solid rgba(251, 191, 36, 0.25) !important;
        }

        .courses-detailed-container {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.75rem !important;
            max-height: 380px !important;
            overflow-y: auto !important;
            padding-right: 0.35rem !important;
        }

        /* Single Course Item with Offering Schools */
        .course-offering-box {
            background: rgba(15, 23, 42, 0.65) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            border-radius: 10px !important;
            padding: 0.85rem 1rem !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 0.65rem !important;
            transition: border-color 0.2s !important;
        }

        .course-offering-box:hover {
            border-color: rgba(251, 191, 36, 0.2) !important;
        }

        .course-box-title-row {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 0.5rem !important;
        }

        .course-title-lbl {
            font-size: 0.92rem !important;
            font-weight: 700 !important;
            color: #f1f5f9 !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.55rem !important;
        }

        .course-title-lbl i {
            color: #60a5fa !important;
            font-size: 0.9rem !important;
        }

        .course-schools-tag-row {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 0.45rem !important;
            align-items: center !important;
        }

        .offering-school-chip {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.4rem !important;
            padding: 0.28rem 0.65rem !important;
            background: rgba(30, 41, 59, 0.7) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 8px !important;
            font-size: 0.8rem !important;
            color: #cbd5e1 !important;
            font-weight: 500 !important;
        }

        .offering-school-chip i.fa-school {
            color: #94a3b8 !important;
            font-size: 0.75rem !important;
        }

        .offering-school-chip.is-specialized {
            background: rgba(245, 158, 11, 0.1) !important;
            border: 1px solid rgba(245, 158, 11, 0.35) !important;
            color: #fef08a !important;
            font-weight: 600 !important;
        }

        .offering-school-chip.is-specialized i.fa-school {
            color: #fbbf24 !important;
        }

        .chip-spec-star-badge {
            background: rgba(251, 191, 36, 0.2) !important;
            color: #fbbf24 !important;
            font-size: 0.68rem !important;
            font-weight: 800 !important;
            padding: 0.1rem 0.4rem !important;
            border-radius: 999px !important;
            border: 1px solid rgba(251, 191, 36, 0.4) !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.2rem !important;
        }

        .no-school-pill {
            font-size: 0.78rem !important;
            color: #64748b !important;
            font-style: italic !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            padding: 0.2rem 0 !important;
        }

        .courses-loading-state, .courses-empty-state {
            text-align: center !important;
            padding: 2rem 1rem !important;
            color: #64748b !important;
            font-size: 0.88rem !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 0.5rem !important;
        }

        .courses-loading-state i, .courses-empty-state i {
            font-size: 1.5rem !important;
            color: #fbbf24 !important;
        }

        /* Modal Footer */
        #viewClusterModal .view-modal-footer {
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 0.85rem !important;
            padding: 1.1rem 1.75rem !important;
            border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
            background: rgba(15, 23, 42, 0.95) !important;
        }

        #viewClusterModal .btn-close-view-modal {
            background: rgba(148, 163, 184, 0.08) !important;
            border: 1px solid rgba(148, 163, 184, 0.2) !important;
            color: #cbd5e1 !important;
            border-radius: 8px !important;
            padding: 0.6rem 1.25rem !important;
            font-size: 0.88rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
        }

        #viewClusterModal .btn-close-view-modal:hover {
            background: rgba(148, 163, 184, 0.16) !important;
            color: #ffffff !important;
        }

        #viewClusterModal .btn-edit-cluster-modal {
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            border: none !important;
            color: #0f172a !important;
            border-radius: 8px !important;
            padding: 0.6rem 1.35rem !important;
            font-size: 0.88rem !important;
            font-weight: 700 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.45rem !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25) !important;
        }

        #viewClusterModal .btn-edit-cluster-modal:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35) !important;
        }

        /* -- Courses Assigned Section (Matching Edit School) -- */
        .courses-offered-section {
            margin-top: 1.25rem;
            background: #0b1120;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
        }
        .co-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .co-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .co-header-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .co-header-titles {
            display: flex;
            flex-direction: column;
        }
        .co-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
        }
        .co-header-subtitle {
            font-size: 0.76rem;
            color: #94a3b8;
            margin-top: 2px;
        }
        .co-header-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .co-count-badge {
            font-size: 0.75rem;
            font-weight: 700;
            color: #0f172a;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            padding: 0.25rem 0.65rem;
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
        .co-dropdown-item .item-add-btn {
            font-size: 0.72rem;
            font-weight: 700;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .co-dropdown-item.already-selected .item-add-btn {
            color: #64748b;
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
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
        .co-chip-icon {
            color: #fbbf24;
            font-size: 0.82rem;
            flex-shrink: 0;
        }
        .co-chip-name {
            word-break: break-word;
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
            margin-left: auto;
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

        /* --- ADD / EDIT CLUSTER MODAL REDESIGN --- */
        #addClusterModal .modal-content,
        #editClusterModal .modal-content {
            background-color: #111827 !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 18px !important;
            padding: 2rem !important;
            max-width: 880px !important;
            width: 92% !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6) !important;
            font-family: 'Inter', sans-serif !important;
        }

        #addClusterModal .modal-header,
        #editClusterModal .modal-header {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07) !important;
            padding-bottom: 1.25rem !important;
            margin-bottom: 1.5rem !important;
        }

        #addClusterModal .modal-header h2,
        #editClusterModal .modal-header h2 {
            display: flex !important;
            align-items: center !important;
            gap: 0.85rem !important;
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            color: #f8fafc !important;
            margin: 0 !important;
        }

        #addClusterModal .modal-header h2 i,
        #editClusterModal .modal-header h2 i {
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            color: #000000 !important;
            width: 34px !important;
            height: 34px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            font-size: 1rem !important;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3) !important;
        }

        .modal .modal-close {
            background: rgba(255, 255, 255, 0.04) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            color: #94a3b8 !important;
            width: 34px !important;
            height: 34px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            padding: 0 !important;
            transition: all 0.2s ease !important;
            overflow: hidden !important;
        }
        .modal .modal-close i {
            font-size: 1rem !important;
            color: inherit !important;
            background: none !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .modal .modal-close:hover {
            background: rgba(239, 68, 68, 0.15) !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
            color: #f87171 !important;
            transform: scale(1.08) !important;
        }

        #addClusterModal .form-group > label,
        #editClusterModal .form-group > label {
            display: block !important;
            text-align: left !important;
            font-size: 0.88rem !important;
            font-weight: 600 !important;
            color: #f1f5f9 !important;
            margin-bottom: 0.5rem !important;
        }

        #addClusterModal .form-group input[type="text"],
        #addClusterModal .form-group textarea,
        #editClusterModal .form-group input[type="text"],
        #editClusterModal .form-group textarea {
            width: 100% !important;
            background-color: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #f8fafc !important;
            border-radius: 10px !important;
            padding: 0.75rem 1rem !important;
            font-size: 0.92rem !important;
            font-family: inherit !important;
            box-sizing: border-box !important;
            transition: border-color 0.2s, box-shadow 0.2s !important;
        }

        #addClusterModal .form-group input:focus,
        #addClusterModal .form-group textarea:focus,
        #editClusterModal .form-group input:focus,
        #editClusterModal .form-group textarea:focus {
            outline: none !important;
            border-color: #fbbf24 !important;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15) !important;
            background-color: #131d33 !important;
        }

        /* ── Modern Traits & Alignments Card ────────────────────────── */
        .modal-section-card {
            background: #090e1a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            margin-top: 1.5rem;
        }
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
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
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
        }
        .section-title-text {
            font-size: 0.96rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0 0 2px 0;
            text-align: left !important;
            letter-spacing: 0.01em;
        }
        .section-subtitle-text {
            font-size: 0.78rem;
            color: #94a3b8;
            margin: 0;
            text-align: left !important;
            line-height: 1.4;
        }

        .traits-redesign-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        @media (max-width: 860px) {
            .traits-redesign-grid {
                grid-template-columns: 1fr;
            }
        }
        .trait-group-card {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .trait-group-card:hover {
            border-color: rgba(255, 255, 255, 0.16);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        .trait-group-card.holland:hover {
            border-color: rgba(245, 158, 11, 0.35);
        }
        .trait-group-card.bigfive:hover {
            border-color: rgba(99, 102, 241, 0.35);
        }
        .trait-group-card.strand:hover {
            border-color: rgba(16, 185, 129, 0.35);
        }

        .trait-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            gap: 6px;
        }
        .trait-group-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.86rem;
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
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }
        .trait-group-card.bigfive .trait-group-icon {
            background: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
        }
        .trait-group-card.strand .trait-group-icon {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }

        .trait-group-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .trait-toggle-btn {
            font-size: 0.7rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 5px;
            padding: 2px 7px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .trait-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .trait-category-tag {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .trait-category-tag.holland {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .trait-category-tag.bigfive {
            background: rgba(99, 102, 241, 0.12);
            color: #818cf8;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .trait-category-tag.strand {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .trait-pill-grid {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .trait-pill {
            position: relative;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
            flex-direction: row !important;
            gap: 0.65rem !important;
            padding: 0.55rem 0.8rem !important;
            border-radius: 9px !important;
            font-size: 0.82rem !important;
            font-weight: 500 !important;
            color: #94a3b8 !important;
            background: rgba(255, 255, 255, 0.02) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            cursor: pointer !important;
            user-select: none !important;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1) !important;
            width: 100% !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }
        .trait-pill:hover {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: rgba(255, 255, 255, 0.16) !important;
            color: #f1f5f9 !important;
            transform: translateX(3px) !important;
        }
        .trait-pill input[type="checkbox"] {
            position: absolute !important;
            opacity: 0 !important;
            width: 0 !important;
            height: 0 !important;
            pointer-events: none !important;
            margin: 0 !important;
        }
        .trait-pill-check {
            width: 18px;
            height: 18px;
            border-radius: 5px;
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
        .trait-pill-icon {
            width: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            color: #64748b;
            transition: color 0.18s ease;
            flex-shrink: 0;
        }
        .trait-pill-text {
            font-size: 0.83rem;
            font-weight: 600;
            line-height: 1.2;
            flex: 1;
            text-align: left;
        }

        /* Holland Active State */
        .trait-group-card.holland .trait-pill:has(input:checked),
        .trait-group-card.holland .trait-pill.is-checked {
            background: rgba(245, 158, 11, 0.12) !important;
            border-color: rgba(245, 158, 11, 0.5) !important;
            color: #fef08a !important;
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.12) !important;
        }
        .trait-group-card.holland .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.holland .trait-pill.is-checked .trait-pill-check {
            background: #f59e0b !important;
            border-color: #f59e0b !important;
            color: #0f172a !important;
            transform: scale(1.05);
        }
        .trait-group-card.holland .trait-pill:has(input:checked) .trait-pill-icon,
        .trait-group-card.holland .trait-pill.is-checked .trait-pill-icon {
            color: #fbbf24 !important;
        }

        /* Big Five Active State */
        .trait-group-card.bigfive .trait-pill:has(input:checked),
        .trait-group-card.bigfive .trait-pill.is-checked {
            background: rgba(99, 102, 241, 0.12) !important;
            border-color: rgba(99, 102, 241, 0.5) !important;
            color: #e0e7ff !important;
            box-shadow: 0 2px 10px rgba(99, 102, 241, 0.12) !important;
        }
        .trait-group-card.bigfive .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.bigfive .trait-pill.is-checked .trait-pill-check {
            background: #6366f1 !important;
            border-color: #6366f1 !important;
            color: #ffffff !important;
            transform: scale(1.05);
        }
        .trait-group-card.bigfive .trait-pill:has(input:checked) .trait-pill-icon,
        .trait-group-card.bigfive .trait-pill.is-checked .trait-pill-icon {
            color: #a5b4fc !important;
        }

        /* Strand Active State */
        .trait-group-card.strand .trait-pill:has(input:checked),
        .trait-group-card.strand .trait-pill.is-checked {
            background: rgba(16, 185, 129, 0.12) !important;
            border-color: rgba(16, 185, 129, 0.5) !important;
            color: #a7f3d0 !important;
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.12) !important;
        }
        .trait-group-card.strand .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.strand .trait-pill.is-checked .trait-pill-check {
            background: #10b981 !important;
            border-color: #10b981 !important;
            color: #0f172a !important;
            transform: scale(1.05);
        }
        .trait-group-card.strand .trait-pill:has(input:checked) .trait-pill-icon,
        .trait-group-card.strand .trait-pill.is-checked .trait-pill-icon {
            color: #34d399 !important;
        }

        /* ── Modal Footer ───────────────────────────── */
        #addClusterModal .modal-footer,
        #editClusterModal .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.07) !important;
            padding-top: 1.25rem !important;
            margin-top: 1.5rem !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 1rem !important;
        }

        #addClusterModal .btn-secondary,
        #editClusterModal .btn-secondary {
            background-color: transparent !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #cbd5e1 !important;
            padding: 0.65rem 1.4rem !important;
            border-radius: 9px !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
        }
        #addClusterModal .btn-secondary:hover,
        #editClusterModal .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.06) !important;
            color: #ffffff !important;
            border-color: rgba(255, 255, 255, 0.2) !important;
        }

        #addClusterModal .btn-primary,
        #editClusterModal .btn-primary {
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            border: none !important;
            color: #000000 !important;
            padding: 0.65rem 1.6rem !important;
            border-radius: 9px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3) !important;
            transition: all 0.2s !important;
        }
        #addClusterModal .btn-primary:hover,
        #editClusterModal .btn-primary:hover {
            background: linear-gradient(135deg, #f59e0b, #d97706) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.4) !important;
        }

        /* ── View Modal Trait Badges ─────────────────── */
        .cluster-traits-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0.85rem 0 1.25rem;
        }
        .view-trait-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(8px);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: default;
        }
        .view-trait-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }
        .view-trait-badge.holland {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.05));
            color: #fde68a;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .view-trait-badge.holland i { color: #fbbf24; }
        
        .view-trait-badge.bigfive {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.15), rgba(126, 34, 206, 0.05));
            color: #e9d5ff;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }
        .view-trait-badge.bigfive i { color: #c084fc; }
        
        .view-trait-badge.strand {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(4, 120, 87, 0.05));
            color: #a7f3d0;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .view-trait-badge.strand i { color: #34d399; }
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
                        <a href="manage_clusters.php" class="nav-subitem active">
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
                    <h1>Manage Career Clusters</h1>
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

            <!-- Clusters Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-actions">
                        <div class="search-filter">
                            <div class="search-box-wrapper">
                                <div class="search-box">
                                    <i class="fa-solid fa-search"></i>
                                    <input type="text" id="searchInput" placeholder="Search clusters...">
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
                                <i class="fa-solid fa-briefcase"></i>
                                <span id="totalClusters"><?php echo count($clusters); ?></span> Clusters
                            </span>
                        </div>
                        <button class="btn-primary" id="addClusterBtn">
                            <i class="fa-solid fa-plus"></i>
                            Add Cluster
                        </button>
                    </div>
                </div>

                <!-- Clusters Grid -->
                <div class="clusters-grid">
                    <?php if (empty($clusters)): ?>
                    <div class="no-clusters">
                        <i class="fa-solid fa-folder-open"></i>
                        <h3>No Career Clusters Found</h3>
                        <p>Click "Add Cluster" to create your first career cluster.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($clusters as $cluster): ?>
                        <?php
                        // Get course count for this cluster
                        $courseCount = 0;
                        $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM courses WHERE cluster_id = ?");
                        $countStmt->bind_param('i', $cluster['id']);
                        $countStmt->execute();
                        $courseCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                        $countStmt->close();
                        
                        $icon = getClusterIconClass($cluster['name']);
                        ?>
                        <div class="cluster-card" data-id="<?php echo $cluster['id']; ?>">
                            <div class="cluster-card-header">
                                <div class="cluster-icon">
                                    <i class="fa-solid <?php echo $icon; ?>"></i>
                                </div>
                                <div class="cluster-stats">
                                    <span class="course-count">
                                        <i class="fa-solid fa-graduation-cap"></i> <?php echo $courseCount; ?> Courses
                                    </span>
                                </div>
                            </div>
                            
                            <div class="cluster-card-body">
                                <h3 class="cluster-name"><?php echo htmlspecialchars($cluster['name']); ?></h3>
                                <p class="cluster-description"><?php echo htmlspecialchars($cluster['description'] ?? 'No description available'); ?></p>
                            </div>

                            <div class="cluster-actions">
                                <button class="btn-action view" data-id="<?php echo $cluster['id']; ?>" title="View Cluster Details">
                                    <i class="fa-solid fa-eye"></i> View
                                </button>
                                <button class="btn-action edit" data-id="<?php echo $cluster['id']; ?>" title="Edit Cluster">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                                <button class="btn-action delete" data-id="<?php echo $cluster['id']; ?>" data-name="<?php echo htmlspecialchars($cluster['name'] ?? ''); ?>" data-course-count="<?php echo (int)($cluster['course_count'] ?? 0); ?>" data-job-count="<?php echo (int)($cluster['job_count'] ?? 0); ?>" title="Delete Cluster">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- Add Cluster Modal -->
    <div class="modal" id="addClusterModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-plus-circle"></i> Add New Cluster</h2>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="addClusterForm" class="cluster-form">
                    <div class="form-group">
                        <label for="clusterName">Cluster Name <span class="required">*</span></label>
                        <input type="text" id="clusterName" name="name" required placeholder="Enter cluster name">
                    </div>
                    <div class="form-group">
                        <label for="clusterDescription">Description</label>
                        <textarea id="clusterDescription" name="description" rows="3" placeholder="Enter cluster description..."></textarea>
                    </div>

                    <!-- Courses Assigned Section -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Assigned Programs &amp; Courses</span>
                                    <span class="co-header-subtitle">Search programs to assign or link them to this career cluster</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="addClusterCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="addClusterClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="addClusterSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="addClusterSearchInput" placeholder="Search courses by name (e.g. BS Information Technology)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="addClusterSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="addClusterDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="addClusterChips">
                            <div class="co-empty-state" id="addClusterNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No courses assigned yet. Type in the box above to search and assign courses.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Traits & Alignments Card -->
                    <div class="modal-section-card" style="margin-top:1.5rem;">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge">
                                    <i class="fa-solid fa-shapes"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Trait &amp; Strand Alignments</h4>
                                    <p class="section-subtitle-text">Select matching traits and senior high strands mapped to this career cluster for scoring.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="traits-redesign-grid">
                            <!-- Holland RIASEC Traits -->
                            <div class="trait-group-card holland">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-compass"></i></span>
                                        <span>Holland Traits</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('add', 'holland_traits')">Select All</button>
                                        <span class="trait-category-tag holland">RIASEC</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($hollandTraits as $trait): 
                                        $tIcon = $hollandIcons[$trait] ?? 'fa-circle-dot';
                                    ?>
                                    <label class="trait-pill" for="add_ht_<?php echo $trait; ?>">
                                        <input type="checkbox" name="holland_traits[]" id="add_ht_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Big Five Personality Traits -->
                            <div class="trait-group-card bigfive">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-brain"></i></span>
                                        <span>Big Five Traits</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('add', 'bigfive_traits')">Select All</button>
                                        <span class="trait-category-tag bigfive">OCEAN</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($bigFiveTraits as $trait): 
                                        $tIcon = $bigFiveIcons[$trait] ?? 'fa-circle-dot';
                                    ?>
                                    <label class="trait-pill" for="add_bf_<?php echo $trait; ?>">
                                        <input type="checkbox" name="bigfive_traits[]" id="add_bf_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- SHS Strands -->
                            <div class="trait-group-card strand">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-graduation-cap"></i></span>
                                        <span>SHS Strands</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('add', 'shs_strands')">Select All</button>
                                        <span class="trait-category-tag strand">Track</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($strands as $strand): 
                                        $sCode = $strand['code'] ?? '';
                                        $sName = $strand['name'] ?? $sCode;
                                        $tIcon = $strandIcons[$sCode] ?? 'fa-graduation-cap';
                                    ?>
                                    <label class="trait-pill" for="add_st_<?php echo htmlspecialchars($sCode); ?>" title="<?php echo htmlspecialchars($sName); ?>">
                                        <input type="checkbox" name="shs_strands[]" id="add_st_<?php echo htmlspecialchars($sCode); ?>" value="<?php echo htmlspecialchars($sCode); ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo htmlspecialchars($sCode); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAdd">Cancel</button>
                <button type="submit" class="btn-primary" form="addClusterForm">Add Cluster</button>
            </div>
        </div>
    </div>

    <!-- Edit Cluster Modal -->
    <div class="modal" id="editClusterModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Cluster</h2>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editClusterForm" class="cluster-form">
                    <input type="hidden" id="editClusterId" name="id">
                    <div class="form-group">
                        <label for="editClusterName">Cluster Name <span class="required">*</span></label>
                        <input type="text" id="editClusterName" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="editClusterDescription">Description</label>
                        <textarea id="editClusterDescription" name="description" rows="3"></textarea>
                    </div>

                    <!-- Courses Assigned Section -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Assigned Programs &amp; Courses</span>
                                    <span class="co-header-subtitle">Search programs to assign or link them to this career cluster</span>
                                </div>
                            </div>
                            <div class="co-header-right">
                                <span class="co-count-badge" id="editClusterCountBadge"><i class="fa-solid fa-layer-group"></i> 0 Selected</span>
                                <button type="button" class="co-btn-clear-all" id="editClusterClearAllBtn" style="display: none;"><i class="fa-solid fa-rotate-left"></i> Clear All</button>
                            </div>
                        </div>
                        <div class="co-search-box" id="editClusterSearchBox" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass co-search-icon"></i>
                            <input type="text" class="co-search-input" id="editClusterSearchInput" placeholder="Search courses by name (e.g. BS Information Technology)..." autocomplete="off" style="padding-right: 35px;">
                            <button type="button" class="co-search-clear" id="editClusterSearchClear" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding: 5px;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="editClusterDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="editClusterChips">
                            <div class="co-empty-state" id="editClusterNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No courses assigned yet. Type in the box above to search and assign courses.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Traits & Alignments Card -->
                    <div class="modal-section-card" style="margin-top:1.5rem;">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge">
                                    <i class="fa-solid fa-shapes"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Trait &amp; Strand Alignments</h4>
                                    <p class="section-subtitle-text">Select matching traits and senior high strands mapped to this career cluster for scoring.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="traits-redesign-grid">
                            <!-- Holland RIASEC Traits -->
                            <div class="trait-group-card holland">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-compass"></i></span>
                                        <span>Holland Traits</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('edit', 'holland_traits')">Select All</button>
                                        <span class="trait-category-tag holland">RIASEC</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($hollandTraits as $trait): 
                                        $tIcon = $hollandIcons[$trait] ?? 'fa-circle-dot';
                                    ?>
                                    <label class="trait-pill" for="edit_ht_<?php echo $trait; ?>">
                                        <input type="checkbox" name="holland_traits[]" id="edit_ht_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Big Five Personality Traits -->
                            <div class="trait-group-card bigfive">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-brain"></i></span>
                                        <span>Big Five Traits</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('edit', 'bigfive_traits')">Select All</button>
                                        <span class="trait-category-tag bigfive">OCEAN</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($bigFiveTraits as $trait): 
                                        $tIcon = $bigFiveIcons[$trait] ?? 'fa-circle-dot';
                                    ?>
                                    <label class="trait-pill" for="edit_bf_<?php echo $trait; ?>">
                                        <input type="checkbox" name="bigfive_traits[]" id="edit_bf_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- SHS Strands -->
                            <div class="trait-group-card strand">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-graduation-cap"></i></span>
                                        <span>SHS Strands</span>
                                    </div>
                                    <div class="trait-group-actions">
                                        <button type="button" class="trait-toggle-btn" onclick="toggleTraitGroup('edit', 'shs_strands')">Select All</button>
                                        <span class="trait-category-tag strand">Track</span>
                                    </div>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($strands as $strand): 
                                        $sCode = $strand['code'] ?? '';
                                        $sName = $strand['name'] ?? $sCode;
                                        $tIcon = $strandIcons[$sCode] ?? 'fa-graduation-cap';
                                    ?>
                                    <label class="trait-pill" for="edit_st_<?php echo htmlspecialchars($sCode); ?>" title="<?php echo htmlspecialchars($sName); ?>">
                                        <input type="checkbox" name="shs_strands[]" id="edit_st_<?php echo htmlspecialchars($sCode); ?>" value="<?php echo htmlspecialchars($sCode); ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-icon"><i class="fa-solid <?php echo $tIcon; ?>"></i></span>
                                        <span class="trait-pill-text"><?php echo htmlspecialchars($sCode); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEdit">Cancel</button>
                <button type="submit" class="btn-primary" form="editClusterForm">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- View Cluster Modal -->
    <div class="modal" id="viewClusterModal">
        <div class="modal-overlay" id="viewModalOverlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header" id="viewHeaderIconBox">
                        <i class="fa-solid fa-shapes" id="viewHeaderIcon"></i>
                    </div>
                    <div class="header-titles">
                        <h2 id="viewModalHeading">Career Cluster Details</h2>
                        <p>Academic programs, associated traits, and partner universities offering degrees</p>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="view-modal-body">
                <!-- Hero Overview Card -->
                <div class="cluster-hero-overview">
                    <div class="hero-top-row">
                        <div class="cluster-avatar-badge" id="viewClusterAvatar">
                            <i class="fa-solid fa-laptop-code" id="viewClusterIcon"></i>
                        </div>
                        <div class="hero-info-main">
                            <span class="cluster-category-tag"><i class="fa-solid fa-layer-group"></i> Career Pathway</span>
                            <h3 class="cluster-hero-title" id="viewClusterName">Loading...</h3>
                            <p class="cluster-hero-desc" id="viewClusterDescription">Loading description...</p>
                        </div>
                    </div>

                    <!-- Alignment Tags (Holland, Big 5, Strands) -->
                    <div class="cluster-traits-wrapper" id="viewClusterTraits">
                        <!-- Populated by JS -->
                    </div>

                    <!-- Stat Cards Row -->
                    <div class="cluster-stat-grid">
                        <div class="c-stat-card">
                            <div class="c-stat-icon" style="color: #60a5fa; background: rgba(96, 165, 250, 0.12);"><i class="fa-solid fa-graduation-cap"></i></div>
                            <div class="c-stat-info">
                                <span class="c-stat-val" id="viewCoursesLinked">0</span>
                                <span class="c-stat-lbl">Programs & Courses</span>
                            </div>
                        </div>
                        <div class="c-stat-card">
                            <div class="c-stat-icon" style="color: #34d399; background: rgba(52, 211, 153, 0.12);"><i class="fa-solid fa-school"></i></div>
                            <div class="c-stat-info">
                                <span class="c-stat-val" id="viewSchoolsOffering">0</span>
                                <span class="c-stat-lbl">Partner Schools Offering</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Courses & Offering Schools Section -->
                <div class="cluster-courses-card">
                    <div class="courses-card-header">
                        <div class="c-card-title">
                            <i class="fa-solid fa-book-open-reader" style="color: #fbbf24;"></i>
                            <h4>Courses & Offering Institutions</h4>
                        </div>
                        <span class="courses-count-pill" id="viewClusterCoursesCountBadge">0 Courses</span>
                    </div>

                    <div class="courses-detailed-container" id="viewCoursesList">
                        <div class="courses-loading-state">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <p>Loading course offerings and school links...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="view-modal-footer">
                <button type="button" class="btn-close-view-modal" id="closeView">Close</button>
                <button type="button" class="btn-edit-cluster-modal" id="viewEditBtn">
                    <i class="fa-solid fa-pen"></i> Edit Cluster
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-exclamation-triangle"></i> <span id="deleteClusterModalTitle">Confirm Delete</span></h2>
                <button class="modal-close" id="closeDeleteModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm">
                    <div class="delete-icon">
                        <i class="fa-solid fa-trash-alt"></i>
                    </div>
                    <p class="delete-message" id="deleteClusterMessage">Are you sure you want to delete this cluster?</p>
                    <p class="delete-warning" id="deleteClusterWarning">This action cannot be undone.</p>
                    <div class="delete-cluster-info">
                        <span class="cluster-name" id="deleteClusterName">Information Technology</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete">Delete</button>
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
        // Manage Clusters JavaScript
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
            // Modal Elements
            const addModal = document.getElementById('addClusterModal');
            const editModal = document.getElementById('editClusterModal');
            const viewModal = document.getElementById('viewClusterModal');
            const deleteModal = document.getElementById('deleteModal');

            // -- All Available Courses for Cluster Assignment --
            const allAvailableCourses = <?php echo json_encode($allCourses); ?>;

            // Selected courses state for Add and Edit modals
            let addClusterSelectedCourses = {};
            let editClusterSelectedCourses = {};

            function escHtmlGlobal(str) {
                if (!str) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function setupClusterCourseManager(prefix) {
                const searchInput = document.getElementById(`${prefix}ClusterSearchInput`);
                const dropdown = document.getElementById(`${prefix}ClusterDropdown`);
                const chipsContainer = document.getElementById(`${prefix}ClusterChips`);
                const noneHint = document.getElementById(`${prefix}ClusterNoneHint`);
                const countBadge = document.getElementById(`${prefix}ClusterCountBadge`);
                const clearAllBtn = document.getElementById(`${prefix}ClusterClearAllBtn`);

                function getSelectedMap() {
                    return prefix === 'add' ? addClusterSelectedCourses : editClusterSelectedCourses;
                }

                function renderChips() {
                    const selectedMap = getSelectedMap();
                    Array.from(chipsContainer.children).forEach(el => {
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
                            const name = selectedMap[id].name;
                            const chip = document.createElement('div');
                            chip.className = 'co-chip';
                            chip.innerHTML = `
                                <i class="fa-solid fa-graduation-cap co-chip-icon"></i>
                                <span class="co-chip-name" title="${escHtmlGlobal(name)}">${escHtmlGlobal(name)}</span>
                                <button type="button" class="co-chip-remove" data-id="${id}" title="Remove course from cluster">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            `;

                            chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                                e.stopPropagation();
                                delete selectedMap[id];
                                renderChips();
                            });

                            chipsContainer.appendChild(chip);
                        });
                    }
                }

                function renderDropdown(filterText = '') {
                    const selectedMap = getSelectedMap();
                    dropdown.innerHTML = '';
                    filterText = filterText.toLowerCase().trim();

                    const matches = allAvailableCourses.filter(c => {
                        if (!filterText) return true;
                        return c.course_name.toLowerCase().includes(filterText);
                    });

                    if (matches.length === 0) {
                        dropdown.innerHTML = '<div class="co-dropdown-empty">No matching courses found</div>';
                    } else {
                        matches.forEach(c => {
                            const isSelected = selectedMap.hasOwnProperty(c.id);
                            const item = document.createElement('div');
                            item.className = 'co-dropdown-item' + (isSelected ? ' already-selected' : '');
                            item.innerHTML = `
                                <div class="item-text-box">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                    <span title="${escHtmlGlobal(c.course_name)}">${escHtmlGlobal(c.course_name)}</span>
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
                             
                            // Remove button handler
                            const removeBtn = item.querySelector('.item-remove-btn');
                            if (removeBtn) {
                                removeBtn.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    delete selectedMap[c.id];
                                    renderChips();
                                    renderDropdown(searchInput ? searchInput.value : '');
                                });
                            }

                            item.addEventListener('mousedown', function(e) {
                                if (e.target.closest('.item-add-btn') || e.target.closest('.item-remove-btn')) return;
                                e.preventDefault();
                                if (isSelected) return;
                                selectedMap[c.id] = { name: c.course_name };
                                renderChips();
                                renderDropdown(searchInput ? searchInput.value : '');
                            });
                            dropdown.appendChild(item);
                        });
                    }
                    dropdown.classList.add('open');
                }

                if (dropdown) {
                    dropdown.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                    });
                }

                const clearSearchBtn = document.getElementById(`${prefix}ClusterSearchClear`);

                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('focus', function() {
                        renderDropdown(this.value);
                        if (clearSearchBtn) clearSearchBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                    });
                    searchInput.addEventListener('blur', function() {
                        setTimeout(() => dropdown.classList.remove('open'), 200);
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
                    clearAllBtn.addEventListener('click', function() {
                        if (prefix === 'add') {
                            addClusterSelectedCourses = {};
                        } else {
                            editClusterSelectedCourses = {};
                        }
                        if (searchInput) searchInput.value = '';
                        if (clearSearchBtn) clearSearchBtn.style.display = 'none';
                        if (dropdown) dropdown.classList.remove('open');
                        renderChips();
                    });
                }

                return { renderChips };
            }
              function syncTraitPills(container) {
                if (!container) return;
                container.querySelectorAll('.trait-pill').forEach(pill => {
                    const input = pill.querySelector('input[type="checkbox"]');
                    if (input) {
                        pill.classList.toggle('is-checked', input.checked);
                    }
                });
            }

            // Sync trait pills dynamically on checkbox change
            document.addEventListener('change', function(e) {
                if (e.target.matches('.trait-pill input[type="checkbox"]')) {
                    const pill = e.target.closest('.trait-pill');
                    if (pill) {
                        pill.classList.toggle('is-checked', e.target.checked);
                    }
                }
            });

            // Toggle All / Clear All per category group
            window.toggleTraitGroup = function(prefix, fieldName) {
                const form = document.getElementById(`${prefix}ClusterForm`);
                if (!form) return;
                const cbs = form.querySelectorAll(`input[name="${fieldName}[]"]`);
                if (!cbs.length) return;
                const anyUnchecked = Array.from(cbs).some(cb => !cb.checked);
                cbs.forEach(cb => {
                    cb.checked = anyUnchecked;
                    const pill = cb.closest('.trait-pill');
                    if (pill) pill.classList.toggle('is-checked', anyUnchecked);
                });
            };

            const addCourseManager = setupClusterCourseManager('add');
            const editCourseManager = setupClusterCourseManager('edit');

            // Open Add Modal
            document.getElementById('addClusterBtn').addEventListener('click', () => {
                const form = document.getElementById('addClusterForm');
                form.reset();
                syncTraitPills(form);
                addClusterSelectedCourses = {};
                addCourseManager.renderChips();
                addModal.classList.add('active');
            });

            // Close Modals
            document.getElementById('closeAddModal').addEventListener('click', () => {
                addModal.classList.remove('active');
            });
            document.getElementById('cancelAdd').addEventListener('click', () => {
                addModal.classList.remove('active');
            });

            document.getElementById('closeEditModal').addEventListener('click', () => {
                editModal.classList.remove('active');
            });
            document.getElementById('cancelEdit').addEventListener('click', () => {
                editModal.classList.remove('active');
            });

            document.getElementById('closeViewModal').addEventListener('click', () => {
                viewModal.classList.remove('active');
            });
            document.getElementById('closeView').addEventListener('click', () => {
                viewModal.classList.remove('active');
            });

            document.getElementById('closeDeleteModal').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });
            document.getElementById('cancelDelete').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });

            let currentViewClusterId = null;

            // View Button Handlers
            document.querySelectorAll('.btn-action.view').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const card = btn.closest('.cluster-card');
                    currentViewClusterId = btn.getAttribute('data-id');
                    const clusterName = card.querySelector('.cluster-name').textContent.trim();
                    const description = card.querySelector('.cluster-description').textContent.trim();
                    
                    // Match the icon dynamically
                    const cardIconEl = card.querySelector('.cluster-icon i');
                    const cardIconClass = cardIconEl ? cardIconEl.className : 'fa-solid fa-shapes';
                    document.getElementById('viewClusterIcon').className = cardIconClass;
                    document.getElementById('viewHeaderIcon').className = cardIconClass;

                    document.getElementById('viewClusterName').textContent = clusterName;
                    document.getElementById('viewClusterDescription').textContent = description;
                    
                    // Clear traits wrapper and set loading
                    const traitsWrapper = document.getElementById('viewClusterTraits');
                    if (traitsWrapper) traitsWrapper.innerHTML = '';

                    // Initial loading state
                    document.getElementById('viewCoursesLinked').textContent = '...';
                    document.getElementById('viewSchoolsOffering').textContent = '...';
                    document.getElementById('viewClusterCoursesCountBadge').textContent = 'Loading...';
                    document.getElementById('viewCoursesList').innerHTML = `
                        <div class="courses-loading-state">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <p>Loading course offerings and school links...</p>
                        </div>
                    `;
                    viewModal.classList.add('active');

                    function escHtml(str) {
                        if (!str) return '';
                        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    }

                    // Fetch live stats & courses
                    const fd = new FormData();
                    fd.append('action', 'get_cluster');
                    fd.append('id', currentViewClusterId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.cluster) {
                            const c = data.cluster;
                            document.getElementById('viewCoursesLinked').textContent = c.courses_linked || 0;
                            document.getElementById('viewSchoolsOffering').textContent = c.schools_offering || 0;

                            // Populate Trait Badges in View Modal
                            if (traitsWrapper) {
                                let badgesHtml = '';
                                if (c.holland_traits) {
                                    c.holland_traits.split(',').map(s => s.trim()).filter(Boolean).forEach(t => {
                                        badgesHtml += `<span class="view-trait-badge holland"><i class="fa-solid fa-compass"></i> ${escHtml(t)}</span>`;
                                    });
                                }
                                if (c.bigfive_traits) {
                                    c.bigfive_traits.split(',').map(s => s.trim()).filter(Boolean).forEach(t => {
                                        badgesHtml += `<span class="view-trait-badge bigfive"><i class="fa-solid fa-brain"></i> ${escHtml(t)}</span>`;
                                    });
                                }
                                if (c.shs_strands) {
                                    c.shs_strands.split(',').map(s => s.trim()).filter(Boolean).forEach(t => {
                                        badgesHtml += `<span class="view-trait-badge strand"><i class="fa-solid fa-graduation-cap"></i> ${escHtml(t)}</span>`;
                                    });
                                }
                                if (!badgesHtml) {
                                    badgesHtml = '<span style="font-size: 0.8rem; color: #64748b; font-style: italic;"><i class="fa-solid fa-circle-info"></i> No traits or strands assigned to this cluster yet.</span>';
                                }
                                traitsWrapper.innerHTML = badgesHtml;
                            }

                            // Render Courses with Offering Schools
                            const listCont = document.getElementById('viewCoursesList');
                            const countBadge = document.getElementById('viewClusterCoursesCountBadge');
                            const coursesData = c.courses_data || [];
                            
                            if (countBadge) {
                                countBadge.textContent = `${coursesData.length} Course${coursesData.length !== 1 ? 's' : ''}`;
                            }

                            if (coursesData.length > 0) {
                                let coursesHtml = '';
                                coursesData.forEach(course => {
                                    const schools = course.schools || [];
                                    const schoolsCount = schools.length;
                                    
                                    let schoolsHtml = '';
                                    if (schoolsCount > 0) {
                                        schools.forEach(sch => {
                                            const isSpec = parseInt(sch.is_specialization) === 1;
                                            const locText = [sch.city, sch.province].filter(Boolean).join(', ');
                                            schoolsHtml += `
                                                <div class="offering-school-chip ${isSpec ? 'is-specialized' : ''}" title="${escHtml(sch.school_name)}${locText ? ' (' + escHtml(locText) + ')' : ''}">
                                                    <i class="fa-solid fa-school"></i>
                                                    <span class="school-name-lbl">${escHtml(sch.school_name)}</span>
                                                    ${isSpec ? '<span class="chip-spec-star-badge"><i class="fa-solid fa-star"></i> Best Offered</span>' : ''}
                                                    ${locText ? '<span style="color: #94a3b8; font-size: 0.72rem; margin-left: 0.2rem;">• ' + escHtml(locText) + '</span>' : ''}
                                                </div>
                                            `;
                                        });
                                    } else {
                                        schoolsHtml = '<span class="no-school-pill"><i class="fa-solid fa-circle-info"></i> No partner schools offering this course yet</span>';
                                    }

                                    coursesHtml += `
                                        <div class="course-offering-box">
                                            <div class="course-box-title-row">
                                                <span class="course-title-lbl">
                                                    <i class="fa-solid fa-graduation-cap"></i>
                                                    ${escHtml(course.course_name)}
                                                </span>
                                                <span style="font-size: 0.75rem; color: #94a3b8; font-weight: 600;">
                                                    ${schoolsCount} ${schoolsCount === 1 ? 'School' : 'Schools'}
                                                </span>
                                            </div>
                                            <div class="course-schools-tag-row">
                                                ${schoolsHtml}
                                            </div>
                                        </div>
                                    `;
                                });
                                listCont.innerHTML = coursesHtml;
                            } else {
                                listCont.innerHTML = `
                                    <div class="courses-empty-state">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                        <p>No academic courses are currently linked to this cluster.</p>
                                    </div>
                                `;
                            }
                        } else {
                            document.getElementById('viewCoursesList').innerHTML = `
                                <div class="courses-empty-state">
                                    <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                                    <p>${data.message || 'Failed to load cluster details.'}</p>
                                </div>
                            `;
                        }
                    } catch (err) {
                        document.getElementById('viewCoursesList').innerHTML = `
                            <div class="courses-empty-state">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                                <p>Error loading cluster information.</p>
                            </div>
                        `;
                    }
                });
            });

            // View to Edit
            document.getElementById('viewEditBtn').addEventListener('click', () => {
                viewModal.classList.remove('active');
                if (currentViewClusterId) {
                    const editBtn = document.querySelector(`.btn-action.edit[data-id="${currentViewClusterId}"]`);
                    if (editBtn) {
                        editBtn.click();
                    }
                }
            });

            // Click overlay to close view modal
            const viewOverlay = document.getElementById('viewModalOverlay');
            if (viewOverlay) {
                viewOverlay.addEventListener('click', () => {
                    viewModal.classList.remove('active');
                });
            }

            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('manage_clusters.php', { method: 'POST', body: formData });
                return res.json();
            }

            // Edit Button Handlers
            document.querySelectorAll('.btn-action.edit').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const clusterId = btn.dataset.id;
                    
                    // Fetch cluster data
                    const fd = new FormData();
                    fd.append('action', 'get_cluster');
                    fd.append('id', clusterId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.cluster) {
                            const cluster = data.cluster;
                            document.getElementById('editClusterId').value = cluster.id;
                            document.getElementById('editClusterName').value = cluster.name || '';
                            document.getElementById('editClusterDescription').value = cluster.description || '';
                            
                            editClusterSelectedCourses = {};
                            if (cluster.courses_data && Array.isArray(cluster.courses_data)) {
                                cluster.courses_data.forEach(c => {
                                    editClusterSelectedCourses[c.id] = { name: c.course_name };
                                });
                            }
                            editCourseManager.renderChips();
                            
                            // Reset and populate traits
                            const editForm = document.getElementById('editClusterForm');
                            editForm.reset();
                            document.getElementById('editClusterId').value = cluster.id;
                            document.getElementById('editClusterName').value = cluster.name || '';
                            document.getElementById('editClusterDescription').value = cluster.description || '';
                            
                            ['holland_traits', 'bigfive_traits', 'shs_strands'].forEach(field => {
                                if (cluster[field]) {
                                    const vals = cluster[field].split(',').map(s => s.trim());
                                    vals.forEach(v => {
                                        const cb = editForm.querySelector(`input[name="${field}[]"][value="${v}"]`);
                                        if (cb) cb.checked = true;
                                    });
                                }
                            });
                            syncTraitPills(editForm);
                            
                            editModal.classList.add('active');
                        } else {
                            alert(data.message || 'Failed to load cluster data');
                        }
                    } catch (e) {
                        alert('Error loading cluster data');
                    }
                });
            });

            // Delete Button Handlers
            let deleteClusterId = null;
            let unlinkClusterMode = false;

            document.querySelectorAll('.btn-action.delete').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const card = btn.closest('.cluster-card');
                    const clusterName = btn.getAttribute('data-name') || card.querySelector('.cluster-name').textContent.trim();
                    deleteClusterId = btn.dataset.id;
                    unlinkClusterMode = false;

                    const titleEl = document.getElementById('deleteClusterModalTitle');
                    const msgEl = document.getElementById('deleteClusterMessage');
                    const nameEl = document.getElementById('deleteClusterName');
                    const warningEl = document.getElementById('deleteClusterWarning');
                    const confirmBtn = document.getElementById('confirmDelete');

                    if (nameEl) nameEl.textContent = clusterName;

                    let courseCount = parseInt(btn.getAttribute('data-course-count') || '0', 10);
                    let jobCount = parseInt(btn.getAttribute('data-job-count') || '0', 10);
                    let totalCount = courseCount + jobCount;

                    try {
                        const fd = new FormData();
                        fd.append('action', 'check_cluster_associations');
                        fd.append('id', deleteClusterId);
                        const res = await apiPost(fd);
                        if (res && res.success && typeof res.count !== 'undefined') {
                            totalCount = res.count;
                        }
                    } catch (e) {}

                    if (totalCount > 0) {
                        unlinkClusterMode = true;
                        if (titleEl) titleEl.textContent = 'Delete Cluster?';
                        if (msgEl) msgEl.textContent = 'This cluster is currently associated with one or more courses or jobs. Deleting this cluster will remove its associations. The courses and jobs themselves will not be deleted.';
                        if (warningEl) warningEl.textContent = `(${totalCount} association${totalCount > 1 ? 's' : ''} will be unlinked)`;
                        if (confirmBtn) confirmBtn.textContent = 'Unlink & Delete';
                    } else {
                        unlinkClusterMode = false;
                        if (titleEl) titleEl.textContent = 'Confirm Delete';
                        if (msgEl) msgEl.textContent = 'Are you sure you want to delete this cluster?';
                        if (warningEl) warningEl.textContent = 'This action cannot be undone.';
                        if (confirmBtn) confirmBtn.textContent = 'Delete';
                    }

                    deleteModal.classList.add('active');
                });
            });

            // Form Validation - Add Cluster
            document.getElementById('addClusterForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_cluster');
                
                // Append selected course IDs
                const ids = Object.keys(addClusterSelectedCourses);

                // Validation: Ensure at least one course or trait is selected
                const hasTraits = this.querySelectorAll('input[type="checkbox"]:checked').length > 0;
                if (ids.length === 0 && !hasTraits) {
                    showStatusModal('Warning', 'Please assign at least one course or select at least one trait/strand alignment before saving.', false);
                    return;
                }

                ids.forEach(id => formData.append('course_ids[]', id));
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Cluster added successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add cluster', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error adding cluster', false);
                }
            });

            // Form Validation - Edit Cluster
            document.getElementById('editClusterForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'edit_cluster');
                
                // Append selected course IDs
                const ids = Object.keys(editClusterSelectedCourses);

                // Validation: Ensure at least one course or trait is selected
                const hasTraits = this.querySelectorAll('input[type="checkbox"]:checked').length > 0;
                if (ids.length === 0 && !hasTraits) {
                    showStatusModal('Warning', 'Please assign at least one course or select at least one trait/strand alignment before saving.', false);
                    return;
                }

                ids.forEach(id => formData.append('course_ids[]', id));
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Cluster updated successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update cluster', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error updating cluster', false);
                }
            });

            // Confirm Delete
            document.getElementById('confirmDelete').addEventListener('click', async () => {
                if (!deleteClusterId) return;
                
                const fd = new FormData();
                fd.append('action', 'delete_cluster');
                fd.append('id', deleteClusterId);
                if (unlinkClusterMode) {
                    fd.append('unlink_associations', '1');
                }
                
                deleteModal.classList.remove('active');
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        showStatusModal('Success', 'Cluster deleted successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to delete cluster', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error deleting cluster', false);
                }
            });

            // Search functionality for clusters
            const searchInput = document.getElementById('searchInput');
            const clearBtn = document.getElementById('clearFilter');

            if (searchInput && clearBtn) {
                searchInput.addEventListener('input', () => {
                    const searchTerm = searchInput.value.toLowerCase().trim();
                    const cards = document.querySelectorAll('.cluster-card');
                    let visibleCount = 0;

                    cards.forEach(card => {
                        const clusterName = card.querySelector('.cluster-name')?.textContent.toLowerCase() || '';
                        const description = card.querySelector('.cluster-description')?.textContent.toLowerCase() || '';
                        
                        if (clusterName.includes(searchTerm) || description.includes(searchTerm)) {
                            card.style.display = '';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

                    const totalSpan = document.getElementById('totalClusters');
                    if (totalSpan) totalSpan.textContent = visibleCount;
                });

                clearBtn.addEventListener('click', () => {
                    searchInput.value = '';
                    document.querySelectorAll('.cluster-card').forEach(card => {
                        card.style.display = '';
                    });
                    const totalSpan = document.getElementById('totalClusters');
                    if (totalSpan) totalSpan.textContent = document.querySelectorAll('.cluster-card').length;
                });
            }

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
