<?php
// Manage Jobs

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// Admin auth check
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// -------------------------------------------------------------
// AJAX handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $action   = $_POST['action'];

    switch ($action) {

        // -- Add Job ------------------------------------------
        case 'add_job':
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $clusterId = isset($_POST['cluster_id']) && $_POST['cluster_id'] !== '' ? (int)$_POST['cluster_id'] : null;

            if (empty($title)) {
                $response['message'] = 'Job title is required.';
                echo json_encode($response); exit;
            }

            // Duplicate check
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? LIMIT 1");
            $chk->bind_param('s', $title);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $response['message'] = 'A job with this title already exists.';
                $chk->close();
                echo json_encode($response); exit;
            }
            $chk->close();

            $stmt = $mysqli->prepare("INSERT INTO jobs (job_title, description, cluster_id, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param('ssi', $title, $desc, $clusterId);
            if ($stmt->execute()) {
                $insertedId = $mysqli->insert_id;
                
                // Link courses if provided
                if (isset($_POST['offered_course_ids']) && is_array($_POST['offered_course_ids'])) {
                    $newCourses = array_map('intval', $_POST['offered_course_ids']);
                    if (!empty($newCourses)) {
                        $addStmt = $mysqli->prepare("INSERT IGNORE INTO course_jobs (job_id, course_id) VALUES (?, ?)");
                        if ($addStmt) {
                            foreach ($newCourses as $cid) {
                                if ($cid > 0) {
                                    $addStmt->bind_param('ii', $insertedId, $cid);
                                    $addStmt->execute();
                                }
                            }
                            $addStmt->close();
                        }
                    }
                }

                $response['success'] = true;
                $response['message'] = 'Job added successfully.';
                $response['id']      = $insertedId;
                $response['job']     = ['id' => $insertedId, 'job_title' => $title, 'description' => $desc, 'cluster_id' => $clusterId];

                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                log_activity($userId, $userType, 'Added Job', 'jobs', $insertedId, "Admin added job: {$title}", null, json_encode($response['job']));
            } else {
                $response['message'] = 'Failed to add job: ' . $stmt->error;
            }
            $stmt->close();
            echo json_encode($response); exit;

        // -- Get Job ------------------------------------------
        case 'get_job':
            $jobId = (int)($_POST['id'] ?? 0);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($job) {
                $response['success'] = true;
                
                // Fetch linked courses
                $job['courses'] = [];
                $cStmt = $mysqli->prepare("SELECT c.id AS course_id, c.course_name FROM course_jobs cj JOIN courses c ON cj.course_id = c.id WHERE cj.job_id = ? ORDER BY c.course_name");
                if ($cStmt) {
                    $cStmt->bind_param('i', $jobId);
                    $cStmt->execute();
                    $res = $cStmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $job['courses'][] = $row;
                    }
                    $cStmt->close();
                }
                
                // Fetch linked competencies
                $job['competencies'] = [];
                $compStmt = $mysqli->prepare("SELECT c.id AS competency_id, c.name FROM job_competencies jc JOIN competencies c ON jc.competency_id = c.id WHERE jc.job_id = ? ORDER BY c.name");
                if ($compStmt) {
                    $compStmt->bind_param('i', $jobId);
                    $compStmt->execute();
                    $res = $compStmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $job['competencies'][] = $row;
                    }
                    $compStmt->close();
                }

                $response['job']     = $job;
            } else {
                $response['message'] = 'Job not found.';
            }
            echo json_encode($response); exit;

        // -- Edit Job -----------------------------------------
        case 'edit_job':
            $jobId = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $clusterId = isset($_POST['cluster_id']) && $_POST['cluster_id'] !== '' ? (int)$_POST['cluster_id'] : null;

            if ($jobId <= 0 || empty($title)) {
                $response['message'] = 'Invalid data provided.';
                echo json_encode($response); exit;
            }

            // Duplicate check (exclude self)
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? AND id != ? LIMIT 1");
            $chk->bind_param('si', $title, $jobId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $response['message'] = 'Another job with this title already exists.';
                $chk->close();
                echo json_encode($response); exit;
            }
            $chk->close();

            // Fetch old value for audit logging
            $oldJob = null;
            $oldStmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ?");
            if ($oldStmt) {
                $oldStmt->bind_param('i', $jobId);
                $oldStmt->execute();
                $oldJob = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();
            }

            $stmt = $mysqli->prepare("UPDATE jobs SET job_title = ?, description = ?, cluster_id = ? WHERE id = ?");
            $stmt->bind_param('ssii', $title, $desc, $clusterId, $jobId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Job updated successfully.';
                
                // Sync courses
                if (isset($_POST['offered_course_ids']) && is_array($_POST['offered_course_ids'])) {
                    $newCourses = array_map('intval', $_POST['offered_course_ids']);
                    $currentCourses = [];
                    $crs = $mysqli->query("SELECT course_id FROM course_jobs WHERE job_id = $jobId");
                    while ($r = $crs->fetch_assoc()) {
                        $currentCourses[] = (int)$r['course_id'];
                    }
                    
                    $toAdd = array_diff($newCourses, $currentCourses);
                    $toRemove = array_diff($currentCourses, $newCourses);
                    
                    if (!empty($toRemove)) {
                        $remList = implode(',', $toRemove);
                        $mysqli->query("DELETE FROM course_jobs WHERE job_id = $jobId AND course_id IN ($remList)");
                    }
                    if (!empty($toAdd)) {
                        $addStmt = $mysqli->prepare("INSERT IGNORE INTO course_jobs (job_id, course_id) VALUES (?, ?)");
                        foreach ($toAdd as $cid) {
                            $addStmt->bind_param('ii', $jobId, $cid);
                            $addStmt->execute();
                        }
                        $addStmt->close();
                    }
                } else {
                    $mysqli->query("DELETE FROM course_jobs WHERE job_id = $jobId");
                }

                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                $newJob = ['id' => $jobId, 'job_title' => $title, 'description' => $desc, 'cluster_id' => $clusterId];
                
                $oldChanges = $oldJob;
                $newChanges = $newJob;
                log_activity(
                    $userId,
                    $userType,
                    'Edited Job',
                    'jobs',
                    $jobId,
                    "Admin edited job #{$jobId} ({$title})",
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );
            } else {
                $response['message'] = 'Failed to update job.';
            }
            $stmt->close();
            echo json_encode($response); exit;

        case 'check_job_associations':
            $jobId = (int)($_POST['id'] ?? 0);
            $check = $mysqli->prepare("SELECT COUNT(*) as count FROM course_jobs WHERE job_id = ?");
            $check->bind_param('i', $jobId);
            $check->execute();
            $count = (int)($check->get_result()->fetch_assoc()['count'] ?? 0);
            $check->close();
            echo json_encode(['success' => true, 'count' => $count]);
            exit;

        case 'delete_job':
            $jobId = (int)($_POST['id'] ?? 0);
            $unlinkCourses = !empty($_POST['unlink_courses']);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }

            // Check if job is associated with any courses
            $check = $mysqli->prepare("SELECT COUNT(*) as count FROM course_jobs WHERE job_id = ?");
            $check->bind_param('i', $jobId);
            $check->execute();
            $count = (int)($check->get_result()->fetch_assoc()['count'] ?? 0);
            $check->close();

            if ($count > 0 && !$unlinkCourses) {
                $response['has_associations'] = true;
                $response['count'] = $count;
                $response['message'] = 'This job is currently associated with one or more courses. Please confirm unlinking courses.';
                echo json_encode($response); exit;
            }

            // Fetch old value for audit logging
            $oldJob = null;
            $oldStmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ?");
            if ($oldStmt) {
                $oldStmt->bind_param('i', $jobId);
                $oldStmt->execute();
                $oldJob = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();
            }

            // Database transaction: unlink course_jobs (and job_competencies), then delete job
            $mysqli->begin_transaction();
            try {
                if ($count > 0) {
                    $delCJ = $mysqli->prepare("DELETE FROM course_jobs WHERE job_id = ?");
                    $delCJ->bind_param('i', $jobId);
                    if (!$delCJ->execute()) {
                        throw new Exception('Failed to remove course associations: ' . $delCJ->error);
                    }
                    $delCJ->close();
                }

                $delJC = $mysqli->prepare("DELETE FROM job_competencies WHERE job_id = ?");
                $delJC->bind_param('i', $jobId);
                $delJC->execute();
                $delJC->close();

                $stmt = $mysqli->prepare("DELETE FROM jobs WHERE id = ?");
                $stmt->bind_param('i', $jobId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete job: ' . $stmt->error);
                }
                $stmt->close();

                $mysqli->commit();

                $response['success'] = true;
                $response['message'] = 'Job deleted successfully.';

                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                $jobTitle = $oldJob['job_title'] ?? "ID {$jobId}";
                log_activity($userId, $userType, 'Deleted Job', 'jobs', $jobId, "Admin deleted job: {$jobTitle}" . ($count > 0 ? " (unlinked {$count} course association(s))" : ""), json_encode($oldJob), null);
            } catch (Exception $ex) {
                $mysqli->rollback();
                $response['success'] = false;
                $response['message'] = $ex->getMessage();
            }
            echo json_encode($response); exit;

        // -- Get all jobs (for course modal inline picker) ----
        case 'get_all_jobs':
            $result = $mysqli->query("SELECT id, job_title FROM jobs ORDER BY job_title");
            $jobs   = [];
            while ($row = $result->fetch_assoc()) {
                $jobs[] = $row;
            }
            $response['success'] = true;
            $response['jobs']    = $jobs;
            echo json_encode($response); exit;

        case 'get_job_courses':
            $jobId = (int)($_POST['id'] ?? 0);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT c.course_name FROM course_jobs cj JOIN courses c ON cj.course_id = c.id WHERE cj.job_id = ? ORDER BY c.course_name");
            if ($stmt) {
                $stmt->bind_param('i', $jobId);
                $stmt->execute();
                $result = $stmt->get_result();
                $courses = [];
                while ($row = $result->fetch_assoc()) {
                    $courses[] = $row['course_name'];
                }
                $stmt->close();
                $response['success'] = true;
                $response['courses'] = $courses;
            } else {
                $response['message'] = 'Database error: failed to prepare statement.';
            }
            echo json_encode($response); exit;
    }
}

// -------------------------------------------------------------
// Helper Functions for Badges & Avatars
// -------------------------------------------------------------
function getJobInitials($name) {
    $name = trim($name);
    $words = preg_split('/\s+/', $name);
    $meaningfulWords = [];
    foreach ($words as $w) {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
        if (empty($clean)) continue;
        if (!in_array(strtolower($clean), ['of', 'and', 'the', 'in', 'at', 'for', 'to', 'a', 'an'])) {
            $meaningfulWords[] = $clean;
        }
    }
    if (count($meaningfulWords) >= 2) {
        return strtoupper(substr($meaningfulWords[0], 0, 1) . substr($meaningfulWords[1], 0, 1));
    } elseif (count($meaningfulWords) === 1) {
        return strtoupper(substr($meaningfulWords[0], 0, min(2, strlen($meaningfulWords[0]))));
    }
    return strtoupper(substr($name, 0, 2));
}

function getJobAvatarStyle($name) {
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

// -------------------------------------------------------------
// Page data
// -------------------------------------------------------------
$jobs = [];
$result = $mysqli->query("
    SELECT j.*, c.name AS cluster_name, c.color AS cluster_color, COUNT(cj.course_id) AS course_count 
    FROM jobs j 
    LEFT JOIN clusters c ON j.cluster_id = c.id 
    LEFT JOIN course_jobs cj ON j.id = cj.job_id 
    GROUP BY j.id 
    ORDER BY j.job_title
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
}

// Fetch all courses for the multi-select
$allCourses = [];
$cRes = $mysqli->query("SELECT id, course_name FROM courses ORDER BY course_name");
if ($cRes) {
    while ($row = $cRes->fetch_assoc()) $allCourses[] = $row;
}

// Fetch all clusters for the dropdown
$allClusters = [];
$clRes = $mysqli->query("SELECT id, name, color FROM clusters ORDER BY name");
if ($clRes) {
    while ($row = $clRes->fetch_assoc()) $allClusters[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Jobs - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css?v=<?php echo filemtime('admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* -- Standardized Add & Edit Job Modal Styling (Matches Manage Schools) -- */
        #addJobModal .modal-content,
        #editJobModal .modal-content {
            max-width: 820px !important;
            width: 95% !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
            text-align: left !important;
            border-radius: 16px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.75) !important;
        }
        #addJobModal .modal-body,
        #editJobModal .modal-body {
            text-align: left !important;
            padding: 1.5rem !important;
        }

        /* Form elements */
        .job-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        @media (max-width: 640px) {
            .job-form .form-row {
                grid-template-columns: 1fr;
            }
        }
        .job-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            text-align: left;
        }
        .job-form .form-group label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .job-form .form-group label .required {
            color: #f87171;
        }
        .job-form .form-group input,
        .job-form .form-group select,
        .job-form .form-group textarea {
            width: 100%;
            padding: 0.7rem 0.9rem;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: #f8fafc;
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .job-form .form-group input:focus,
        .job-form .form-group select:focus,
        .job-form .form-group textarea:focus {
            outline: none;
            border-color: #fbbf24;
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .job-form .form-group select option {
            background: #0f172a;
            color: #f8fafc;
        }

        /* -- Related Courses Offered Section -- */
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
            margin: 0;
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
        .co-search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 5px;
            display: none;
            transition: color 0.2s;
        }
        .co-search-clear:hover {
            color: #f8fafc;
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
        .co-dropdown-item .item-add-btn {
            font-size: 0.72rem;
            font-weight: 700;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
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
        .co-dropdown-item .item-remove-btn {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 0.22rem 0.55rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .co-dropdown-item .item-remove-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            color: #fecaca;
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
        .co-chip-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-left: auto;
            flex-shrink: 0;
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

        /* -- Modern Manage Jobs Single-Row Toolbar -- */
        .schools-filter-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border-radius: 14px;
            padding: 0.65rem 0.85rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .schools-count-badge {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            padding: 0 0.75rem;
            height: 38px;
            border-radius: 9px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            white-space: nowrap;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .filter-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .filter-input-wrap.search-input-wrap {
            flex: 1 1 200px;
            min-width: 140px;
            max-width: 320px;
        }

        .filter-input-wrap.select-wrap {
            flex: 0 1 180px;
            min-width: 130px;
            max-width: 220px;
        }

        .filter-input-wrap i {
            position: absolute;
            left: 0.65rem;
            color: #64748b;
            font-size: 0.78rem;
            pointer-events: none;
            z-index: 1;
        }

        .filter-input-wrap input,
        .filter-input-wrap select {
            width: 100%;
            height: 38px;
            padding: 0 0.65rem 0 1.85rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 9px;
            color: #f1f5f9;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s;
            outline: none;
            box-sizing: border-box;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .filter-input-wrap select {
            appearance: none;
            -webkit-appearance: none;
            padding-right: 1.45rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.45rem center;
            cursor: pointer;
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

        .btn-filter-search {
            height: 38px;
            box-sizing: border-box;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0 0.85rem;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-filter-search:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            transform: translateY(-1px);
        }

        .btn-filter-clear {
            height: 38px;
            box-sizing: border-box;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #e2e8f0;
            padding: 0 0.75rem;
            border-radius: 9px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-filter-clear:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }

        .btn-add-school-main {
            height: 38px;
            box-sizing: border-box;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0 1rem;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
            transition: all 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
            margin-left: auto;
        }

        .btn-add-school-main:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
            transform: translateY(-1px);
        }

        @media (max-width: 900px) {
            .schools-filter-card {
                flex-wrap: wrap;
            }
            .btn-add-school-main {
                margin-left: 0;
            }
        }

        /* -- Modern Jobs Grid (3 Columns) -- */
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

        /* -- Modern Job Card -- */
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
            min-height: 250px;
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
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .school-badges-stack {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.4rem;
        }
        .card-badge-district {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
            white-space: nowrap;
        }
        .card-badge-type {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
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
        .school-phone-text {
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
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

        /* -- View Job Modal Custom Styles -- */
        #viewJobModal .modal-content {
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
        #viewJobModal .modal-header-custom {
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
        #viewJobModal .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        #viewJobModal .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        #viewJobModal .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        #viewJobModal .header-titles h2 {
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
        #viewJobModal .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        #viewJobModal .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }
        #viewJobModal .view-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        #viewJobModal .job-hero-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }
        #viewJobModal .job-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        #viewJobModal .job-hero-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.3;
        }
        #viewJobModal .badge-job-cluster {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        #viewJobModal .job-description-box {
            color: #cbd5e1;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
            white-space: pre-line;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding-top: 0.85rem;
            margin-top: 0.75rem;
        }
        #viewJobModal .courses-offered-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
        }
        #viewJobModal .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        #viewJobModal .card-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        #viewJobModal .courses-count-badge {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        #viewJobModal .view-courses-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            padding-right: 0.25rem;
        }
        #viewJobModal .view-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
        }
        #viewJobModal .btn-edit-job-modal {
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
        #viewJobModal .btn-edit-job-modal:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
            transform: translateY(-1px);
        }
        #viewJobModal .btn-close-view-modal {
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
        #viewJobModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">

        <!-- --- Sidebar ------------------------------------------- -->
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
                        <a href="manage_schools.php" class="nav-subitem">
                            <i class="fa-solid fa-school"></i>
                            Manage Schools
                        </a>
                        <a href="manage_jobs.php" class="nav-subitem active">
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

        <!-- --- Main Content -------------------------------------- -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Manage Jobs</h1>
                </div>
                <?php
                $userName       = $_SESSION['admin_name'] ?? 'Admin User';
                $notifications  = [];
                $unreadCount    = 0;
                $adminId        = $_SESSION['admin_id'] ?? null;
                $adminProfilePic = null;

                if ($adminId) {
                    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                    $profileStmt->bind_param('i', $adminId);
                    $profileStmt->execute();
                    $adminData       = $profileStmt->get_result()->fetch_assoc();
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
                    while ($row = $result->fetch_assoc()) { $notifications[] = $row; }
                    $notifStmt->close();
                }
                ?>
                <div class="top-bar-actions">
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <i class="fa-solid fa-bell"></i>
                            <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown" style="display:none;">
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

                <!-- Modern Search, Filter & Action Toolbar (Single Row) -->
                <div class="schools-filter-card">
                    <span class="schools-count-badge" id="jobsTotalCount">
                        <i class="fa-solid fa-briefcase"></i>
                        <span id="jobsCountText"><?php echo count($jobs); ?> Jobs</span>
                    </span>
                    
                    <div class="filter-input-wrap search-input-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchJobTitle" placeholder="Search by job title or keyword...">
                    </div>

                    <div class="filter-input-wrap select-wrap">
                        <i class="fa-solid fa-layer-group"></i>
                        <select id="searchCluster">
                            <option value="">All Career Clusters</option>
                            <?php foreach ($allClusters as $cl): ?>
                            <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" class="btn-filter-search" id="searchBtn">
                        <i class="fa-solid fa-filter"></i> Search
                    </button>
                    <button type="button" class="btn-filter-clear" id="clearBtn">
                        <i class="fa-solid fa-rotate-left"></i> Clear
                    </button>

                    <button class="btn-add-school-main" id="addJobBtn">
                        <i class="fa-solid fa-plus"></i>
                        <span>Add Job</span>
                    </button>
                </div>

                <!-- Jobs Grid (Matches Manage Schools Layout) -->
                <div class="schools-grid" id="jobsGrid">
                    <?php if (empty($jobs)): ?>
                    <div class="no-schools-found-box">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Jobs Registered</h3>
                        <p>Click "Add Job" above to register your first career position.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($jobs as $job): 
                            $courseCount = (int)($job['course_count'] ?? 0);
                            $clusterName = $job['cluster_name'] ?? 'General Career';
                        ?>
                        <div class="school-card job-card" 
                             data-id="<?php echo $job['id']; ?>" 
                             data-cluster="<?php echo htmlspecialchars($job['cluster_id'] ?? ''); ?>"
                             data-cluster-name="<?php echo htmlspecialchars($clusterName); ?>"
                             data-title="<?php echo htmlspecialchars($job['job_title']); ?>"
                             data-desc="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                            <div>
                                <div class="school-card-top">
                                    <div class="school-avatar-box" style="<?php echo getJobAvatarStyle($job['job_title']); ?>">
                                        <?php echo htmlspecialchars(getJobInitials($job['job_title'])); ?>
                                    </div>
                                    <div class="school-badges-stack">
                                        <span class="card-badge-district">
                                            <i class="fa-solid fa-layer-group" style="font-size: 0.65rem;"></i>
                                            <?php echo htmlspecialchars($clusterName); ?>
                                        </span>
                                        <span class="card-badge-type">
                                            <i class="fa-solid fa-graduation-cap" style="font-size: 0.65rem;"></i>
                                            <?php echo $courseCount; ?> Course<?php echo $courseCount !== 1 ? 's' : ''; ?>
                                        </span>
                                    </div>
                                </div>

                                <h3 class="school-name" title="<?php echo htmlspecialchars($job['job_title']); ?>">
                                    <?php echo htmlspecialchars($job['job_title']); ?>
                                </h3>

                                <div class="school-location">
                                    <i class="fa-solid fa-briefcase"></i>
                                    <span><?php echo htmlspecialchars($clusterName); ?></span>
                                </div>

                                <p class="school-address" title="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(!empty($job['description']) ? $job['description'] : 'No description provided for this job position.'); ?>
                                </p>

                                <div class="school-meta-links">
                                    <span class="school-phone-text">
                                        <i class="fa-regular fa-calendar" style="font-size: 0.75rem;"></i>
                                        <span>Added <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                                    </span>
                                </div>
                            </div>

                            <div class="school-card-actions">
                                <button type="button" class="btn-card-action btn-action view" data-id="<?php echo $job['id']; ?>" title="View Job Details">
                                    <i class="fa-solid fa-eye"></i>
                                    <span>View</span>
                                </button>
                                <button type="button" class="btn-card-action btn-action edit" data-id="<?php echo $job['id']; ?>" title="Edit Job">
                                    <i class="fa-solid fa-pen"></i>
                                    <span>Edit</span>
                                </button>
                                <button type="button" class="btn-card-action btn-action delete" data-id="<?php echo $job['id']; ?>" data-name="<?php echo htmlspecialchars($job['job_title'] ?? ''); ?>" data-course-count="<?php echo (int)($job['course_count'] ?? 0); ?>" title="Delete Job">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="no-schools-found-box" id="noResultsMessage" style="display: none;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <h3>No Matching Jobs Found</h3>
                        <p>Try adjusting your search keywords or resetting the career cluster filter.</p>
                        <button type="button" class="btn-filter-clear" onclick="document.getElementById('clearBtn').click();">
                            <i class="fa-solid fa-rotate-left"></i> Reset Filters
                        </button>
                    </div>
                </div>

            </div><!-- /dashboard-content -->
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- =================================================================== -->
    <!-- Add Job Modal (Standardized to Match Manage Schools)                 -->
    <!-- =================================================================== -->
    <div class="modal" id="addJobModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-plus-circle"></i> Add Job</h2>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="addJobForm" class="job-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="addJobTitle">Job Title <span class="required">*</span></label>
                            <input type="text" id="addJobTitle" name="job_title" placeholder="e.g. Software Engineer" required>
                        </div>
                        <div class="form-group">
                            <label for="addJobCluster">Career Cluster</label>
                            <select id="addJobCluster" name="cluster_id">
                                <option value="">Select Career Cluster (Optional)</option>
                                <?php foreach ($allClusters as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="addJobDescription">Job Description</label>
                        <textarea id="addJobDescription" name="description" rows="3" placeholder="Brief overview and responsibilities of this career/job position..."></textarea>
                    </div>

                    <!-- Related Courses Section (Add Job) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Related Courses</span>
                                    <span class="co-header-subtitle">Search programs &amp; link academic courses that lead to this career</span>
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
                            <button type="button" class="co-search-clear" id="addCoSearchClear" style="display:none;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="addCoDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="addCoChips">
                            <div class="co-empty-state" id="addCoNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No courses linked yet. Type in the box above to search and add courses.</span>
                            </div>
                        </div>
                    </div>

                    <div id="addJobCoursesInputsContainer"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAdd">Cancel</button>
                <button type="submit" form="addJobForm" class="btn-primary">Add Job</button>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Edit Job Modal (Standardized to Match Manage Schools)                -->
    <!-- =================================================================== -->
    <div class="modal" id="editJobModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Job</h2>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editJobForm" class="job-form">
                    <input type="hidden" id="editJobId" name="id">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editJobTitle">Job Title <span class="required">*</span></label>
                            <input type="text" id="editJobTitle" name="job_title" placeholder="e.g. Software Engineer" required>
                        </div>
                        <div class="form-group">
                            <label for="editJobCluster">Career Cluster</label>
                            <select id="editJobCluster" name="cluster_id">
                                <option value="">Select Career Cluster (Optional)</option>
                                <?php foreach ($allClusters as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="editJobDescription">Job Description</label>
                        <textarea id="editJobDescription" name="description" rows="3" placeholder="Brief overview and responsibilities of this career/job position..."></textarea>
                    </div>

                    <!-- Related Courses Section (Edit Job) -->
                    <div class="courses-offered-section">
                        <div class="co-header-bar">
                            <div class="co-header-left">
                                <div class="co-header-icon">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="co-header-titles">
                                    <span class="co-header-title">Related Courses</span>
                                    <span class="co-header-subtitle">Search programs &amp; link academic courses that lead to this career</span>
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
                            <button type="button" class="co-search-clear" id="coSearchClear" style="display:none;" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                            <div class="co-dropdown" id="coDropdown"></div>
                        </div>
                        <div class="co-chips-container" id="coChips">
                            <div class="co-empty-state" id="coNoneHint">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>No courses linked yet. Type in the box above to search and add courses.</span>
                            </div>
                        </div>
                    </div>

                    <div id="jobCoursesInputsContainer"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEdit">Cancel</button>
                <button type="submit" form="editJobForm" class="btn-primary">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- View Job Modal (Matches Manage Schools View Modal)                  -->
    <!-- =================================================================== -->
    <div class="modal" id="viewJobModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Job Details</h2>
                        <p>Career profile overview and affiliated academic programs</p>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="view-modal-body">
                <!-- Job Hero Card -->
                <div class="job-hero-card">
                    <div class="job-hero-top">
                        <h3 class="job-hero-title" id="viewJobTitle">-</h3>
                        <span class="badge-job-cluster" id="viewJobClusterBadge">
                            <i class="fa-solid fa-layer-group"></i>
                            <span id="viewJobCluster">-</span>
                        </span>
                    </div>
                    <p class="job-description-box" id="viewJobDescription">No description provided for this job.</p>
                </div>

                <!-- Linked Courses Section -->
                <div class="courses-offered-card">
                    <div class="card-header-row">
                        <h4 class="card-header-title">
                            <i class="fa-solid fa-graduation-cap" style="color: #fbbf24;"></i>
                            Linked Academic Programs & Courses
                        </h4>
                        <span class="courses-count-badge" id="viewJobCoursesCount">0 Courses</span>
                    </div>
                    <div class="view-courses-list" id="viewJobCoursesList">
                        <!-- Courses rendered dynamically -->
                    </div>
                </div>
            </div>

            <div class="view-modal-footer">
                <button type="button" class="btn-close-view-modal" id="closeView">Close</button>
                <button type="button" class="btn-edit-job-modal" id="viewEditBtn">
                    <i class="fa-solid fa-pen"></i> Edit Job
                </button>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Delete Confirmation Modal (Matches Manage Schools)                  -->
    <!-- =================================================================== -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-trash-can"></i> <span id="deleteJobModalTitle">Delete Job</span></h2>
                <button class="modal-close" id="closeDeleteModal"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-message" style="text-align: center; padding: 1.5rem 1rem;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #f59e0b; margin-bottom: 1rem; display: block;"></i>
                    <p id="deleteJobMessage" style="font-size: 1.1rem; font-weight: 600; color: #f8fafc; margin-bottom: 0.5rem;">Are you sure you want to delete this job?</p>
                    <p class="delete-job-name" id="deleteJobName" style="color: #fbbf24; font-weight: 700; font-size: 1.05rem; margin-bottom: 0.5rem;"></p>
                    <small id="deleteJobWarning" style="color: #94a3b8;">This action cannot be undone.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer;">Delete</button>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Remove Course Confirmation Modal                                    -->
    <!-- =================================================================== -->
    <div class="modal" id="removeCourseModal" style="z-index: 10050;">
        <div class="modal-overlay" id="removeCourseOverlay"></div>
        <div class="modal-content" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
            <div class="modal-body" style="padding: 0.75rem 0.5rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;">Remove Course?</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;">Do you want to unlink this course from this career position?</p>
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

    <!-- =================================================================== -->
    <!-- Status Feedback Modal                                               -->
    <!-- =================================================================== -->
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
    document.addEventListener('DOMContentLoaded', function () {
        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

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

        // -- Modal Elements ----------------------------------------
        const addModal          = document.getElementById('addJobModal');
        const editModal         = document.getElementById('editJobModal');
        const viewModal         = document.getElementById('viewJobModal');
        const deleteModal       = document.getElementById('deleteModal');
        const removeCourseModal = document.getElementById('removeCourseModal');

        // -- AJAX helper -------------------------------------------
        async function apiPost(formData) {
            const res = await fetch('manage_jobs.php', { method: 'POST', body: formData });
            return res.json();
        }

        // -- Open Add Job Modal ------------------------------------
        document.getElementById('addJobBtn').addEventListener('click', () => {
            document.getElementById('addJobForm').reset();
            addSelectedCourses = {};
            addCoursesManager.renderChips();
            addModal.classList.add('active');
        });

        // -- Modal Close Handlers ----------------------------------
        ['closeAddModal','cancelAdd'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', () => addModal.classList.remove('active'));
        });
        ['closeEditModal','cancelEdit'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', () => editModal.classList.remove('active'));
        });
        ['closeViewModal','closeView'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', () => viewModal.classList.remove('active'));
        });
        ['closeDeleteModal','cancelDelete'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', () => deleteModal.classList.remove('active'));
        });

        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', () => o.closest('.modal').classList.remove('active'));
        });

        // =========================================================
        // Courses Offered / Related Courses Widget (Add & Edit)
        // Matches Manage Schools implementation exactly
        // =========================================================
        const allCourses = <?php echo json_encode(array_values($allCourses)); ?>;
        let addSelectedCourses = {};  // id => name
        let editSelectedCourses = {}; // id => name
        let pendingRemove = null;     // { prefix, id, name }

        function setupJobCoursesManager(prefix) {
            const searchInput = document.getElementById(prefix === 'add' ? 'addCoSearchInput' : 'coSearchInput');
            const clearSearchBtn = document.getElementById(prefix === 'add' ? 'addCoSearchClear' : 'coSearchClear');
            const dropdown    = document.getElementById(prefix === 'add' ? 'addCoDropdown' : 'coDropdown');
            const chips       = document.getElementById(prefix === 'add' ? 'addCoChips' : 'coChips');
            const noneHint    = document.getElementById(prefix === 'add' ? 'addCoNoneHint' : 'coNoneHint');
            const countBadge  = document.getElementById(prefix === 'add' ? 'addCoCountBadge' : 'coCountBadge');
            const clearAllBtn = document.getElementById(prefix === 'add' ? 'addCoClearAllBtn' : 'coClearAllBtn');
            const inputsContainer = document.getElementById(prefix === 'add' ? 'addJobCoursesInputsContainer' : 'jobCoursesInputsContainer');

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
                        const item = document.createElement('div');
                        item.className = 'co-dropdown-item' + (isSelected ? ' already-selected' : '');
                        item.innerHTML = `
                            <div class="item-text-box">
                                <i class="fa-solid fa-book-open"></i>
                                <span title="${escHtml(c.course_name)}">${escHtml(c.course_name)}</span>
                            </div>
                            <div class="item-actions-box">
                                ${isSelected ? `
                                    <span class="item-add-btn" style="cursor:default; margin-right:4px;"><i class="fa-solid fa-check"></i> Added</span>
                                    <button type="button" class="item-remove-btn"><i class="fa-solid fa-xmark"></i> Remove</button>
                                ` : `
                                    <span class="item-add-btn" style="cursor:pointer;"><i class="fa-solid fa-plus"></i> Add</span>
                                `}
                            </div>
                        `;

                        const addBtn = item.querySelector('.item-add-btn');
                        if (addBtn && !isSelected) {
                            addBtn.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                addCourse(c.id, c.course_name);
                                renderDropdown(searchInput ? searchInput.value : '');
                            });
                        }

                        const removeBtn = item.querySelector('.item-remove-btn');
                        if (removeBtn) {
                            removeBtn.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                promptRemoveCourse(prefix, c.id, c.course_name);
                            });
                        }

                        // Clicking item row adds it if not selected
                        item.addEventListener('mousedown', function(e) {
                            if (e.target.closest('.item-add-btn') || e.target.closest('.item-remove-btn')) return;
                            e.preventDefault();
                            if (selectedMap.hasOwnProperty(c.id)) return;
                            addCourse(c.id, c.course_name);
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
                        const name = selectedMap[id];
                        const chip = document.createElement('div');
                        chip.className = 'co-chip';
                        chip.innerHTML = `
                            <i class="fa-solid fa-graduation-cap co-chip-icon"></i>
                            <span class="co-chip-name" title="${escHtml(name)}">${escHtml(name)}</span>
                            <div class="co-chip-actions">
                                <button type="button" class="co-chip-remove" data-id="${id}" title="Remove course">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        `;

                        chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                            e.stopPropagation();
                            promptRemoveCourse(prefix, parseInt(id), name);
                        });

                        chips.appendChild(chip);
                    });
                }

                // Sync hidden inputs
                if (inputsContainer) {
                    inputsContainer.innerHTML = '';
                    ids.forEach(id => {
                        const inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'offered_course_ids[]';
                        inp.value = id;
                        inputsContainer.appendChild(inp);
                    });
                }
            }

            function addCourse(id, name) {
                id = parseInt(id);
                const selectedMap = getSelectedMap();
                if (!selectedMap.hasOwnProperty(id)) {
                    selectedMap[id] = name;
                    renderChips();
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

            // Search input events
            if (searchInput) {
                searchInput.addEventListener('focus', function() {
                    renderDropdown(this.value);
                    if (clearSearchBtn) {
                        clearSearchBtn.style.display = this.value.trim() ? 'block' : 'none';
                    }
                });

                searchInput.addEventListener('input', function() {
                    renderDropdown(this.value);
                    if (clearSearchBtn) {
                        clearSearchBtn.style.display = this.value.trim() ? 'block' : 'none';
                    }
                });

                searchInput.addEventListener('blur', function() {
                    setTimeout(() => {
                        if (dropdown) dropdown.classList.remove('open');
                    }, 250);
                });
            }

            // Clear search button (X)
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (searchInput) {
                        searchInput.value = '';
                        this.style.display = 'none';
                        searchInput.focus();
                        renderDropdown('');
                    }
                });
            }

            // Clear All Button
            if (clearAllBtn) {
                clearAllBtn.addEventListener('click', function() {
                    if (prefix === 'add') {
                        addSelectedCourses = {};
                    } else {
                        editSelectedCourses = {};
                    }
                    renderChips();
                    if (searchInput && dropdown && dropdown.classList.contains('open')) {
                        renderDropdown(searchInput.value);
                    }
                });
            }

            return { renderChips, addCourse, removeCourse };
        }

        const addCoursesManager  = setupJobCoursesManager('add');
        const editCoursesManager = setupJobCoursesManager('edit');

        // Course remove confirmation prompt
        function promptRemoveCourse(prefix, id, name) {
            pendingRemove = { prefix, id, name };
            document.getElementById('removeCourseTargetName').textContent = name;
            removeCourseModal.classList.add('active');
        }

        document.getElementById('cancelRemoveCourseBtn').addEventListener('click', () => {
            removeCourseModal.classList.remove('active');
            pendingRemove = null;
        });
        document.getElementById('removeCourseOverlay').addEventListener('click', () => {
            removeCourseModal.classList.remove('active');
            pendingRemove = null;
        });
        document.getElementById('confirmRemoveCourseBtn').addEventListener('click', () => {
            if (pendingRemove) {
                if (pendingRemove.prefix === 'add') {
                    addCoursesManager.removeCourse(pendingRemove.id);
                } else {
                    editCoursesManager.removeCourse(pendingRemove.id);
                }
            }
            removeCourseModal.classList.remove('active');
            pendingRemove = null;
        });

        // =========================================================
        // View Job Modal Details
        // =========================================================
        document.querySelectorAll('.btn-card-action.view').forEach(btn => {
            btn.addEventListener('click', async function() {
                const jobId = this.getAttribute('data-id');
                const card = this.closest('.job-card');
                const jobTitle = card.dataset.title || '-';
                const jobDesc = card.dataset.desc || 'No description provided for this job.';
                const clusterName = card.dataset.clusterName || 'General Career';

                document.getElementById('viewJobTitle').textContent = jobTitle;
                document.getElementById('viewJobCluster').textContent = clusterName;
                document.getElementById('viewJobDescription').textContent = jobDesc;
                document.getElementById('viewEditBtn').setAttribute('data-id', jobId);

                const coursesList = document.getElementById('viewJobCoursesList');
                const countBadge  = document.getElementById('viewJobCoursesCount');
                coursesList.innerHTML = '<span style="font-size: 0.85rem; color: #94a3b8; padding: 0.5rem 0;">Loading linked courses...</span>';
                if (countBadge) countBadge.textContent = '...';

                viewModal.classList.add('active');

                // Fetch linked courses
                const fd = new FormData();
                fd.append('action', 'get_job_courses');
                fd.append('id', jobId);
                try {
                    const data = await apiPost(fd);
                    if (data.success && data.courses) {
                        coursesList.innerHTML = '';
                        if (data.courses.length > 0) {
                            if (countBadge) countBadge.textContent = `${data.courses.length} Course${data.courses.length > 1 ? 's' : ''}`;
                            data.courses.forEach(courseName => {
                                const item = document.createElement('div');
                                item.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.55rem 0.75rem; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; color: #e2e8f0; font-size: 0.88rem;';
                                item.innerHTML = `
                                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                                        <i class="fa-solid fa-graduation-cap" style="color: #fbbf24; font-size: 0.85rem;"></i>
                                        <span>${escHtml(courseName)}</span>
                                    </div>
                                    <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 0.75rem;"></i>
                                `;
                                coursesList.appendChild(item);
                            });
                        } else {
                            if (countBadge) countBadge.textContent = '0 Courses';
                            coursesList.innerHTML = '<p style="color: #64748b; font-style: italic; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">No academic courses linked yet.</p>';
                        }
                    } else {
                        if (countBadge) countBadge.textContent = '0 Courses';
                        coursesList.innerHTML = '<p style="color: #ef4444; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">Failed to load courses.</p>';
                    }
                } catch (e) {
                    if (countBadge) countBadge.textContent = '0 Courses';
                    coursesList.innerHTML = '<p style="color: #ef4444; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">Error loading courses.</p>';
                }
            });
        });

        // Edit button inside View Modal
        document.getElementById('viewEditBtn').addEventListener('click', function() {
            const jobId = this.getAttribute('data-id');
            viewModal.classList.remove('active');
            const editBtn = document.querySelector(`.school-card-actions .btn-card-action.edit[data-id="${jobId}"]`);
            if (editBtn) {
                editBtn.click();
            }
        });

        // =========================================================
        // Edit Job Action
        // =========================================================
        document.querySelectorAll('.btn-card-action.edit').forEach(btn => {
            btn.addEventListener('click', async function() {
                const jobId = this.getAttribute('data-id');
                const fd = new FormData();
                fd.append('action', 'get_job');
                fd.append('id', jobId);
                try {
                    const data = await apiPost(fd);
                    if (data.success && data.job) {
                        document.getElementById('editJobId').value          = data.job.id;
                        document.getElementById('editJobTitle').value       = data.job.job_title || '';
                        document.getElementById('editJobCluster').value     = data.job.cluster_id || '';
                        document.getElementById('editJobDescription').value = data.job.description || '';

                        editSelectedCourses = {};
                        if (data.job.courses) {
                            data.job.courses.forEach(c => {
                                editSelectedCourses[c.course_id] = c.course_name;
                            });
                        }
                        editCoursesManager.renderChips();
                        editModal.classList.add('active');
                    } else {
                        showStatusModal('Error', data.message || 'Failed to load job data.', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error loading job data.', false);
                }
            });
        });

        // =========================================================
        // Delete Job Action
        // =========================================================
        let deleteJobId = null;
        let unlinkJobCoursesMode = false;

        document.querySelectorAll('.btn-card-action.delete').forEach(btn => {
            btn.addEventListener('click', async function() {
                const card = this.closest('.job-card');
                deleteJobId = this.getAttribute('data-id');
                unlinkJobCoursesMode = false;

                const jobTitle = this.getAttribute('data-name') || (card ? (card.dataset.title || card.querySelector('.school-name')?.textContent?.trim() || '') : '');
                document.getElementById('deleteJobName').textContent = jobTitle;

                const titleEl = document.getElementById('deleteJobModalTitle');
                const msgEl = document.getElementById('deleteJobMessage');
                const warningEl = document.getElementById('deleteJobWarning');
                const confirmBtn = document.getElementById('confirmDelete');

                let courseCount = parseInt(this.getAttribute('data-course-count') || '0', 10);
                try {
                    const fd = new FormData();
                    fd.append('action', 'check_job_associations');
                    fd.append('id', deleteJobId);
                    const res = await apiPost(fd);
                    if (res && res.success && typeof res.count !== 'undefined') {
                        courseCount = res.count;
                    }
                } catch (e) {}

                if (courseCount > 0) {
                    unlinkJobCoursesMode = true;
                    if (titleEl) titleEl.textContent = 'Delete Job?';
                    if (msgEl) msgEl.textContent = 'This job is currently associated with one or more courses. Deleting this job will remove its course associations. The courses themselves will not be deleted.';
                    if (warningEl) warningEl.textContent = `(${courseCount} course association${courseCount > 1 ? 's' : ''} will be unlinked)`;
                    if (confirmBtn) confirmBtn.textContent = 'Unlink Courses & Delete';
                } else {
                    unlinkJobCoursesMode = false;
                    if (titleEl) titleEl.textContent = 'Delete Job';
                    if (msgEl) msgEl.textContent = 'Are you sure you want to delete this job?';
                    if (warningEl) warningEl.textContent = 'This action cannot be undone.';
                    if (confirmBtn) confirmBtn.textContent = 'Delete';
                }

                deleteModal.classList.add('active');
            });
        });

        document.getElementById('confirmDelete').addEventListener('click', async () => {
            if (!deleteJobId) return;
            const fd = new FormData();
            fd.append('action', 'delete_job');
            fd.append('id', deleteJobId);
            if (unlinkJobCoursesMode) {
                fd.append('unlink_courses', '1');
            }

            deleteModal.classList.remove('active');
            try {
                const data = await apiPost(fd);
                if (data.success) {
                    showStatusModal('Success', 'Job deleted successfully.', true, () => location.reload());
                } else {
                    showStatusModal('Error', data.message || 'Failed to delete job.', false);
                }
            } catch {
                showStatusModal('Error', 'Error deleting job.', false);
            }
        });

        // =========================================================
        // Form Submissions (Add & Edit)
        // =========================================================
        document.getElementById('addJobForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'add_job');
            try {
                const data = await apiPost(fd);
                if (data.success) {
                    showStatusModal('Success', 'Job added successfully!', true, () => location.reload());
                } else {
                    showStatusModal('Error', data.message || 'Failed to add job.', false);
                }
            } catch {
                showStatusModal('Error', 'Error adding job.', false);
            }
        });

        document.getElementById('editJobForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'edit_job');
            try {
                const data = await apiPost(fd);
                if (data.success) {
                    showStatusModal('Success', 'Job updated successfully!', true, () => location.reload());
                } else {
                    showStatusModal('Error', data.message || 'Failed to update job.', false);
                }
            } catch {
                showStatusModal('Error', 'Error updating job.', false);
            }
        });

        // =========================================================
        // Search & Filter Toolbar Logic (Matches Manage Schools)
        // =========================================================
        const searchTitleInput = document.getElementById('searchJobTitle');
        const searchClusterSelect = document.getElementById('searchCluster');
        const clearFilterBtn = document.getElementById('clearBtn');
        const searchSubmitBtn = document.getElementById('searchBtn');

        function filterJobs() {
            const query = (searchTitleInput.value || '').toLowerCase().trim();
            const clusterVal = searchClusterSelect.value;
            const cards = document.querySelectorAll('#jobsGrid .job-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const title = (card.dataset.title || '').toLowerCase();
                const desc  = (card.dataset.desc || '').toLowerCase();
                const cardCluster = card.dataset.cluster || '';

                const matchesQuery = !query || title.includes(query) || desc.includes(query);
                const matchesCluster = !clusterVal || cardCluster === clusterVal;

                if (matchesQuery && matchesCluster) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const noResults = document.getElementById('noResultsMessage');
            if (noResults) {
                noResults.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
            }

            const countText = document.getElementById('jobsCountText');
            if (countText) {
                countText.textContent = `${visibleCount} Job${visibleCount !== 1 ? 's' : ''}`;
            }
        }

        searchTitleInput.addEventListener('input', filterJobs);
        searchClusterSelect.addEventListener('change', filterJobs);
        searchSubmitBtn.addEventListener('click', filterJobs);

        clearFilterBtn.addEventListener('click', () => {
            searchTitleInput.value = '';
            searchClusterSelect.value = '';
            filterJobs();
        });
    });

    // -- Top Bar Notification Dropdown Logic --
    document.getElementById('notificationBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        const d = document.getElementById('notificationDropdown');
        d.style.display = d.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function () {
        document.getElementById('notificationDropdown').style.display = 'none';
    });
    document.getElementById('notificationDropdown').addEventListener('click', e => e.stopPropagation());

    function markAllRead(e) {
        e.preventDefault(); e.stopPropagation();
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read&caller_type=admin'
        }).then(r => r.json()).then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(i => i.classList.remove('unread'));
                const badge = document.getElementById('notificationBadge'); 
                if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
                document.querySelector('.mark-all-read')?.remove();
            }
        });
    }

    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function () {
            if (!this.classList.contains('unread')) return;
            const id = this.dataset.id;
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + id + '&caller_type=admin'
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    this.classList.remove('unread');
                    const badge = document.getElementById('notificationBadge');
                    const cnt   = parseInt(badge.textContent);
                    if (cnt > 0) badge.textContent = cnt - 1;
                }
            });
        });
    });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
