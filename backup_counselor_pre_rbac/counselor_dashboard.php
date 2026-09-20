<?php
// Counselor Dashboard - With Backend

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

// Bypass prevention: if counselor logged in with temp password and must_change_password = 1,
// redirect to set_password.php even if they navigate directly to this URL.
if (isset($_SESSION['counselor_id']) && !isset($_SESSION['admin_id'])) {
    $mcpBypassCheck = $mysqli->prepare("SELECT must_change_password FROM counselors WHERE id = ? LIMIT 1");
    if ($mcpBypassCheck) {
        $mcpBypassCheck->bind_param('i', $_SESSION['counselor_id']);
        $mcpBypassCheck->execute();
        $mcpBypassRow = $mcpBypassCheck->get_result()->fetch_assoc();
        $mcpBypassCheck->close();
        if (!empty($mcpBypassRow['must_change_password'])) {
            header('Location: set_password.php');
            exit();
        }
    }
}

// School-wide Guidance Counselor — no per-strand scoping

// Get real statistics from database — school-wide
$filterClause = ' WHERE s.status = \'active\'';

// Total Students
$totalStudentsResult = $mysqli->query("SELECT COUNT(*) as count FROM students s" . $filterClause);
$totalStudents = $totalStudentsResult->fetch_assoc()['count'] ?? 0;

// Total Assessments Completed
$assessmentFilter = " WHERE sa.student_id IN (SELECT id FROM students s" . $filterClause . ")";
$totalAssessmentsResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments sa" . $assessmentFilter . " AND sa.status = 'completed'");
$totalAssessments = $totalAssessmentsResult->fetch_assoc()['count'] ?? 0;

// Pending Assessments
$pendingAssessmentsResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments sa" . $assessmentFilter . " AND sa.status = 'in_progress'");
$pendingAssessments = $pendingAssessmentsResult->fetch_assoc()['count'] ?? 0;

// Recent Students
$recentStudents = [];
$recentFilter = $filterClause;
$recentResult = $mysqli->query("
    SELECT s.id, s.first_name, s.middle_name, s.last_name, s.suffix, s.student_id, s.grade_level, s.created_at,
           st.name as strand_name, st.code as strand_code,
           (SELECT COUNT(*) FROM student_assessments sa WHERE sa.student_id = s.id) as assessment_count,
           (SELECT status FROM student_assessments sa WHERE sa.student_id = s.id ORDER BY COALESCE(sa.completed_at, sa.started_at, sa.created_at) DESC LIMIT 1) as latest_assessment_status
    FROM students s
    LEFT JOIN strands st ON s.strand_id = st.id
    " . $recentFilter . "
    ORDER BY s.created_at DESC
    LIMIT 5
");
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentStudents[] = $row;
    }
}

// Recent Assessment Results — school-wide
$recentResults = [];
$resultsFilter = '';
$resultsQuery = $mysqli->query("
    SELECT sa.id, sa.student_id, sa.completed_at, sa.total_score,
           s.first_name, s.middle_name, s.last_name, s.suffix, s.student_id as school_id
    FROM student_assessments sa
    LEFT JOIN students s ON sa.student_id = s.id
    " . $resultsFilter . " AND sa.status = 'completed'
    ORDER BY sa.completed_at DESC
    LIMIT 5
");
if ($resultsQuery) {
    while ($row = $resultsQuery->fetch_assoc()) {
        $recentResults[] = $row;
    }
}

// Get counselor/admin name
$userName = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_name'] : $_SESSION['admin_name'] ?? 'Counselor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guidance Counselor Dashboard - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
</head>
<body data-user-logged-in="true">
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
                    <h1>Dashboard</h1>
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
                <!-- Header Section -->
                <div class="dashboard-header">
                    <h2>Dashboard</h2>
                    <p class="subtitle">School-wide overview of student progress</p>
                </div>

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
                            <p class="card-number"><?php echo $totalAssessments; ?></p>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="card-icon assessments pending">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div class="card-info">
                            <h3>Pending Assessments</h3>
                            <p class="card-number"><?php echo $pendingAssessments; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-section">
                    <h2>Quick Actions</h2>
                    <div class="quick-actions-buttons">
                        <a href="counselor_students.php" class="action-btn primary">
                            <i class="fa-solid fa-users"></i>
                            <span>View Students</span>
                        </a>
                        <a href="counselor_results.php" class="action-btn secondary">
                            <i class="fa-solid fa-file-alt"></i>
                            <span>View Results</span>
                        </a>
                    </div>
                </div>

                <!-- Student Overview Section -->
                <div class="student-overview-section">
                    <div class="table-header">
                        <h2>Student Overview</h2>
                        <a href="counselor_students.php" class="view-all-link">View All</a>
                    </div>
                    
                    <!-- Desktop Table Layout -->
                    <div class="table-container desktop-table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Grade Level</th>
                                    <th>Strand</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentStudents) > 0): ?>
                                    <?php foreach ($recentStudents as $student): 
                                        $fullName = getStudentDisplayName($student);
                                        $strandCode = strtolower($student['strand_code'] ?? 'none');
                                        $strandName = $student['strand_code'] ?? 'N/A';

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
                                    ?>
                                    <tr>
                                        <td><?php echo $fullName; ?></td>
                                        <td><?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?></td>
                                        <td><span class="strand-badge <?php echo $strandCode; ?>"><?php echo htmlspecialchars($strandName); ?></span></td>
                                        <td><span class="status <?php echo $assessmentStatusClass; ?>"><?php echo $assessmentStatusText; ?></span></td>
                                        <td><a href="counselor_answers.php?student_id=<?php echo $student['id']; ?>" class="action-link">View</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem;">No students found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card Layout -->
                    <div class="student-cards mobile-cards">
                        <?php if (count($recentStudents) > 0): ?>
                            <?php foreach ($recentStudents as $student): 
                                        $fullName = getStudentDisplayName($student);
                                        $strandName = $student['strand_code'] ?? 'N/A';
                                        
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
                                    ?>
                            <div class="student-card">
                                <div class="student-card-header">
                                    <h3><?php echo $fullName; ?></h3>
                                    <span class="status <?php echo $assessmentStatusClass; ?>"><?php echo $assessmentStatusText; ?></span>
                                </div>
                                <div class="student-card-details">
                                    <span class="grade-strand"><?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?> • <?php echo htmlspecialchars($strandName); ?></span>
                                </div>
                                <a href="counselor_answers.php?student_id=<?php echo $student['id']; ?>" class="card-action-link">View Details</a>
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

    <script src="counselor.js"></script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
