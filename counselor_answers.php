<?php
// Counselor View Answers Page - With Backend

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration and database
require_once 'system_config.php';
require_once 'config.php';
require_once 'includes/assessment_answers.php';

// Check if user is logged in (admin or counselor)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }
    header('Location: login.php');
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'search_students':
            $search = trim($_POST['search'] ?? '');
            $statusFilter = trim($_POST['status_filter'] ?? 'active');
            $students = [];
            
            $statusClause = "s.status IN ('active', 'graduated')";
            if ($statusFilter === 'active') {
                $statusClause = "s.status = 'active'";
            } elseif ($statusFilter === 'graduated') {
                $statusClause = "s.status = 'graduated'";
            }

            if (strlen($search) >= 2) {
                $query = "
                    SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.status,
                           st.name as strand_name, st.code as strand_code
                    FROM students s
                    LEFT JOIN strands st ON s.strand_id = st.id
                    WHERE $statusClause AND (
                        s.student_id LIKE ? OR 
                        s.first_name LIKE ? OR 
                        s.last_name LIKE ? OR
                        CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                    )
                    ORDER BY s.first_name, s.last_name LIMIT 15";

                $stmt = $mysqli->prepare($query);
                $searchParam = "%$search%";
                $stmt->bind_param('ssss', $searchParam, $searchParam, $searchParam, $searchParam);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $students[] = [
                        'id' => $row['id'],
                        'student_id' => $row['student_id'],
                        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                        'strand' => $row['strand_name'] ?? 'N/A',
                        'strand_code' => $row['strand_code'] ?? '',
                        'grade' => $row['grade_level'],
                        'status' => $row['status'] ?? 'active'
                    ];
                }
                $stmt->close();
            }
            
            $response['success'] = true;
            $response['students'] = $students;
            echo json_encode($response);
            exit;

        case 'get_student_assessments':
            $studentId = (int)($_POST['student_id'] ?? 0);
            $assessmentType = $_POST['assessment_type'] ?? '';
            $targetAssessmentId = (int)($_POST['target_assessment_id'] ?? 0);

            if ($studentId <= 0) {
                $response['message'] = 'Invalid student ID';
                echo json_encode($response);
                exit;
            }

            if (isset($_SESSION['counselor_id'])) {
                // School-wide: verify student exists and is active or graduated
                $chkStmt = $mysqli->prepare("SELECT id FROM students WHERE id = ? AND status IN ('active', 'graduated') LIMIT 1");
                if ($chkStmt) {
                    $chkStmt->bind_param('i', $studentId);
                    $chkStmt->execute();
                    $allowed = (bool)$chkStmt->get_result()->fetch_assoc();
                    $chkStmt->close();
                    if (!$allowed) {
                        $response['message'] = 'Student not found.';
                        echo json_encode($response);
                        exit;
                    }
                }
            }

            $data = fetchStudentAssessmentAnswers($mysqli, $studentId, $assessmentType, $targetAssessmentId);
            if (!$data['student']) {
                $response['message'] = 'Student not found';
                echo json_encode($response);
                exit;
            }

            // Extract assessment info from the first answer
            $assessment = null;
            if (!empty($data['answers'])) {
                $assessment = [
                    'id' => $data['answers'][0]['assessment_id'] ?? null,
                    'status' => $data['answers'][0]['status'] ?? null,
                    'created_at' => $data['answers'][0]['created_at'] ?? null,
                    'completed_at' => $data['answers'][0]['completed_at'] ?? null,
                    'total_score' => $data['answers'][0]['total_score'] ?? null
                ];
            }

            // Query total active questions count for this category
            $totalActiveQuestions = 0;
            $tableMap = [
                'career' => 'questions_career',
                'personality' => 'questions_personality',
                'skills' => 'questions_skills',
                'strand' => 'questions_strand'
            ];
            $tableName = $tableMap[$assessmentType] ?? '';
            if (!empty($tableName)) {
                $qCountResult = $mysqli->query("SELECT COUNT(*) as count FROM {$tableName} WHERE is_active = 1");
                $totalActiveQuestions = $qCountResult ? ($qCountResult->fetch_assoc()['count'] ?? 0) : 0;
                $totalActiveQuestions = min(20, $totalActiveQuestions);
            }

            // Fetch the category score percentage
            $categoryScorePercentage = null;
            if ($assessment && !empty($assessment['id']) && !empty($assessmentType)) {
                $csStmt = $mysqli->prepare("SELECT percentage FROM category_scores WHERE assessment_id = ? AND category = ? LIMIT 1");
                if ($csStmt) {
                    $csStmt->bind_param('is', $assessment['id'], $assessmentType);
                    $csStmt->execute();
                    $csRow = $csStmt->get_result()->fetch_assoc();
                    $categoryScorePercentage = $csRow ? (float)$csRow['percentage'] : null;
                    $csStmt->close();
                }
            }

            // Attempt enrichment (attempt_num, total_attempts, is_latest)
            if ($assessment && !empty($assessment['id'])) {
                $attemptStmt = $mysqli->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN id <= ? THEN 1 ELSE 0 END) as attempt_num FROM student_assessments WHERE student_id = ?");
                if ($attemptStmt) {
                    $attemptStmt->bind_param("ii", $assessment['id'], $studentId);
                    $attemptStmt->execute();
                    $attRow = $attemptStmt->get_result()->fetch_assoc();
                    $assessment['attempt_num']    = (int)($attRow['attempt_num'] ?? 1);
                    $assessment['total_attempts'] = (int)($attRow['total'] ?? 1);
                    $attemptStmt->close();

                    $latestStmt = $mysqli->prepare("SELECT id FROM student_assessments WHERE student_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
                    $latestStmt->bind_param("i", $studentId);
                    $latestStmt->execute();
                    $latestRow = $latestStmt->get_result()->fetch_assoc();
                    $assessment['is_latest'] = ($latestRow && $latestRow['id'] == $assessment['id']);
                    $latestStmt->close();
                }
            }

            // Check if currently loaded assessment has archived snapshot
            if ($assessment && !empty($assessment['id'])) {
                $archStmt = $mysqli->prepare("SELECT grade_level, archived_at FROM archived_assessments WHERE assessment_id = ? OR (student_id = ? AND assessment_id = ?) LIMIT 1");
                if ($archStmt) {
                    $archStmt->bind_param('iii', $assessment['id'], $studentId, $assessment['id']);
                    $archStmt->execute();
                    $archRow = $archStmt->get_result()->fetch_assoc();
                    if ($archRow) {
                        $assessment['is_archived'] = true;
                        $assessment['archive_grade_level'] = $archRow['grade_level'] ?? 'Grade 11';
                        $assessment['archived_at'] = $archRow['archived_at'] ?? null;
                    } else {
                        $assessment['is_archived'] = false;
                    }
                    $archStmt->close();
                }
            }

            // All attempts list (for attempt history pills) — includes archived snapshot metadata
            $allAttempts = [];
            $attemptsStmt = $mysqli->prepare("
                SELECT sa.id, sa.status, sa.created_at, sa.completed_at, sy.year_label,
                       aa.id AS archive_id, aa.grade_level AS archive_grade_level, aa.archived_at
                FROM student_assessments sa
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id
                LEFT JOIN archived_assessments aa ON (aa.assessment_id = sa.id OR (aa.student_id = sa.student_id AND aa.assessment_id = sa.id))
                WHERE sa.student_id = ?
                ORDER BY sa.created_at ASC
            ");
            if ($attemptsStmt) {
                $attemptsStmt->bind_param("i", $studentId);
                $attemptsStmt->execute();
                $attRes = $attemptsStmt->get_result();
                $attNum = 1;
                while ($attRow = $attRes->fetch_assoc()) {
                    $dateStr   = $attRow['completed_at'] ? date('M d, Y', strtotime($attRow['completed_at'])) : date('M d, Y', strtotime($attRow['created_at']));
                    $syStr     = !empty($attRow['year_label']) ? "SY {$attRow['year_label']} - " : "";
                    $isArch    = !empty($attRow['archive_id']) || !empty($attRow['archive_grade_level']);
                    $archGrade = !empty($attRow['archive_grade_level']) ? $attRow['archive_grade_level'] : 'Grade 11';
                    
                    if ($isArch) {
                        $label = "🎓 {$archGrade} (Archived) - Attempt #{$attNum} ({$syStr}{$dateStr})";
                        $shortLabel = "🎓 {$archGrade} (Archived)";
                    } else {
                        $currGrade = $data['student']['grade_level'] ?? 'Current';
                        $label = "{$currGrade} Attempt #{$attNum} ({$syStr}{$dateStr})";
                        $shortLabel = "Attempt #{$attNum}";
                    }

                    $allAttempts[] = [
                        'id'                  => (int)$attRow['id'],
                        'attempt_num'         => $attNum,
                        'label'               => $label,
                        'short_label'         => $shortLabel,
                        'status'              => $attRow['status'],
                        'is_archived'         => $isArch,
                        'archive_grade_level' => $isArch ? $archGrade : null,
                        'archived_at'         => $attRow['archived_at'] ?? null,
                        'school_year'         => $attRow['year_label'] ?? null
                    ];
                    $attNum++;
                }
                $attemptsStmt->close();
            }

            // Top recommendation
            $topRecommendation     = null;
            $allRecommendations    = [];
            if ($assessment && !empty($assessment['id'])) {
                $recStmt = $mysqli->prepare("
                    SELECT r.rank, r.match_percentage, c.course_name, cl.name as cluster_name
                    FROM recommendations r
                    JOIN courses c  ON r.course_id  = c.id
                    LEFT JOIN clusters cl ON c.cluster_id = cl.id
                    WHERE r.assessment_id = ?
                    ORDER BY r.match_percentage DESC, r.rank ASC
                ");
                if ($recStmt) {
                    $recStmt->bind_param('i', $assessment['id']);
                    $recStmt->execute();
                    $recRes = $recStmt->get_result();
                    while ($recRow = $recRes->fetch_assoc()) {
                        $allRecommendations[] = $recRow;
                        if (!$topRecommendation) {
                            $topRecommendation = $recRow;
                        }
                    }
                    $recStmt->close();
                }
            }

            $response['success']                  = true;
            $response['student']                  = $data['student'];
            $response['assessment']               = $assessment;
            $response['answers']                  = $data['answers'];
            $response['total_active_questions']   = $totalActiveQuestions;
            $response['category_score_percentage'] = $categoryScorePercentage;
            $response['top_recommendation']       = $topRecommendation;
            $response['all_recommendations']      = $allRecommendations;
            $response['all_attempts']             = $allAttempts;
            echo json_encode($response);
            exit;

    }

    $response['message'] = 'Invalid action';
    echo json_encode($response);
    exit;
}

// School-wide Guidance Counselor — no per-strand scoping

// Get all students for dropdown — school-wide
$allStudents = [];
$studentQuery = "
    SELECT s.id, s.first_name, s.last_name, s.student_id, s.grade_level, s.status,
           st.name as strand_name, st.code as strand_code
    FROM students s
    LEFT JOIN strands st ON s.strand_id = st.id
    WHERE s.status IN ('active', 'graduated')
    ORDER BY s.last_name, s.first_name";

$studentsQuery = $mysqli->query($studentQuery);
if ($studentsQuery) {
    while ($row = $studentsQuery->fetch_assoc()) {
        $allStudents[] = [
            'id' => $row['id'],
            'student_id' => $row['student_id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'strand' => $row['strand_name'] ?? 'N/A',
            'strand_code' => $row['strand_code'] ?? '',
            'grade' => $row['grade_level'],
            'status' => $row['status'] ?? 'active'
        ];
    }
}

$initialStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$initialStudentName = '';

if ($initialStudentId) {
    foreach ($allStudents as $student) {
        if ($student['id'] == $initialStudentId) {
            $initialStudentName = $student['name'];
            break;
        }
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
    <title>View Answers - Guidance Counselor - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <link rel="stylesheet" href="counselor.css">
    <style>
        .attempt-pill.archived {
            background: rgba(245, 158, 11, 0.1) !important;
            border-color: rgba(245, 158, 11, 0.35) !important;
            color: #fbbf24 !important;
        }
        .attempt-pill.archived:hover {
            background: rgba(245, 158, 11, 0.2) !important;
            border-color: #fbbf24 !important;
        }
        .attempt-pill.archived.active {
            background: linear-gradient(135deg, #f59e0b, #d97706) !important;
            color: #0f172a !important;
            border-color: #f59e0b !important;
            font-weight: 700 !important;
        }
    </style>
</head>
<body>
    <!-- Dashboard Container -->
    <div class="dashboard-container">
        
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>
        
        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>View Answers</h1>
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

            <!-- Assessment Content -->
            <div class="dashboard-content">
                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group search-student-group">
                            <label for="searchStudent"><i class="fa-solid fa-user"></i> Search Student</label>
                            <div class="search-input-wrapper">
                                <i class="fa-solid fa-search search-icon"></i>
                                <input type="text" id="searchStudent" class="filter-search" placeholder="Search by name or ID..." autocomplete="off" value="<?php echo htmlspecialchars($initialStudentName); ?>">
                                <div class="student-suggestions" id="studentSuggestions" style="display: none;">
                                    <?php foreach ($allStudents as $student): ?>
                                    <div class="suggestion-item" data-id="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['name']); ?>" data-strand="<?php echo htmlspecialchars($student['strand']); ?>" data-grade="<?php echo htmlspecialchars($student['grade']); ?>" data-status="<?php echo htmlspecialchars($student['status'] ?? 'active'); ?>" style="<?php echo ($student['status'] ?? 'active') === 'graduated' ? 'display: none;' : ''; ?>">
                                        <div class="suggestion-item-main" style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                            <span class="suggestion-name"><?php echo htmlspecialchars($student['name']); ?></span>
                                            <?php if (($student['status'] ?? '') === 'graduated'): ?>
                                                <span class="suggestion-status-badge graduated"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>
                                            <?php else: ?>
                                                <span class="suggestion-status-badge active">Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="suggestion-meta">ID: <?php echo htmlspecialchars($student['student_id']); ?> | <?php echo htmlspecialchars($student['strand']); ?> | <?php echo htmlspecialchars($student['grade']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" id="selectedStudentId" value="<?php echo $initialStudentId ? $initialStudentId : ''; ?>">
                        </div>
                        <div class="filter-group">
                            <label for="statusFilter"><i class="fa-solid fa-user-graduate"></i> Student Status</label>
                            <select id="statusFilter" class="filter-select">
                                <option value="active" selected>Active Enrolled</option>
                                <option value="graduated">Graduated Alumni</option>
                                <option value="all">All Students</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="selectAssessment"><i class="fa-solid fa-clipboard-list"></i> Assessment Type</label>
                            <select id="selectAssessment" class="filter-select">
                                <option value="">Select type...</option>
                                <option value="career" selected>Career Interest</option>
                                <option value="personality">Personality</option>
                                <option value="skills">Skills Assessment</option>
                                <option value="strand">Strand-Based</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button class="btn-primary" id="searchBtn">
                                <i class="fa-solid fa-search"></i> Search
                            </button>
                            <button class="btn-secondary" id="clearBtn">
                                <i class="fa-solid fa-eraser"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="status-row" id="filterStatusRow" style="display: none; margin-top: 1rem; align-items: center; gap: 1rem;">
                        <span style="font-size: 0.85rem; color: #94a3b8; font-weight: 500;">Status</span>
                        <span id="filterStatusBadge" class="assessment-status-badge"></span>
                    </div>
                </div>

                <!-- Archived Student Notice Banner -->
                <div class="archived-student-banner" id="archivedStudentBanner" style="display: none;">
                    <div class="asb-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="asb-text">
                        <strong>Archived Alumnus Record:</strong> This assessment belongs to a graduated student. Records and scores are preserved in read-only mode for historical guidance and tracer reference.
                    </div>
                </div>

                <!-- Student Info Card -->
                <div class="student-info-card" id="studentInfoCard" style="display: none;">
                    <div class="sic-top-row">
                        <!-- Left: Student Profile -->
                        <div class="sic-student-profile">
                            <div class="student-avatar-large" id="studentAvatar">--</div>
                            <div class="sic-student-details">
                                <div class="sic-name-row">
                                    <h2 id="studentName">Select a student</h2>
                                    <div class="sic-badges" id="sicBadges">
                                        <span class="badge-lifecycle graduated" id="badgeGraduated" style="display:none;"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>
                                        <span class="badge-lifecycle archived-snapshot" id="badgeArchivedSnapshot" style="display:none; background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); padding: 3px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;"><i class="fa-solid fa-box-archive"></i> <span id="badgeArchivedText">Grade 11 Archived Record</span></span>
                                        <span class="badge-attempt" id="badgeAttempt" style="display:none;"></span>
                                        <span class="badge-latest" id="badgeLatest" style="display:none;">Latest</span>
                                        <span class="badge-status status completed" id="badgeStatus" style="display:none;">Completed</span>
                                    </div>
                                </div>
                                <div class="student-meta sic-meta-row">
                                    <div class="meta-item">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                        <div class="meta-text">
                                            <span class="meta-label">Grade Level</span>
                                            <strong id="studentGrade">--</strong>
                                        </div>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fa-solid fa-book"></i>
                                        <div class="meta-text">
                                            <span class="meta-label">Strand</span>
                                            <strong id="studentStrand">--</strong>
                                        </div>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fa-solid fa-id-card"></i>
                                        <div class="meta-text">
                                            <span class="meta-label">Student ID</span>
                                            <strong id="studentId">--</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Top Recommendation Box -->
                        <div class="sic-recommendation-box" id="sicRecommendationBox" style="display: none;">
                            <div class="sic-rec-header">Top Recommendation</div>
                            <div class="sic-rec-content">
                                <div class="sic-rec-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                <div class="sic-rec-details">
                                    <h3 id="sicRecCourse">--</h3>
                                    <div class="sic-rec-score">Career Fit: <span id="sicRecScore">--</span></div>
                                </div>
                            </div>
                            <button class="sic-rec-btn" id="viewRecBtn">View Recommendation</button>
                        </div>
                    </div>

                    <div class="sic-divider"></div>

                    <div class="sic-bottom-row">
                        <!-- Left: Dates -->
                        <div class="assessment-dates sic-dates-row">
                            <div class="date-item">
                                <span class="date-icon"><i class="fa-regular fa-calendar"></i></span>
                                <div class="date-text">
                                    <span class="date-label">Started</span>
                                    <strong id="startDate">--</strong>
                                </div>
                            </div>
                            <div class="date-item">
                                <span class="date-icon"><i class="fa-regular fa-calendar-check"></i></span>
                                <div class="date-text">
                                    <span class="date-label">Completed</span>
                                    <strong id="completeDate">--</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Actions -->
                        <div class="sic-actions" id="sicActions">
                            <!-- Injected via JS -->
                        </div>
                    </div>

                    <!-- Archived Attempt Notice (for promoted Grade 11 -> 12 students viewing prior snapshot) -->
                    <div class="archived-attempt-notice" id="archivedAttemptNotice" style="display: none; margin-top: 1rem; padding: 0.75rem 1rem; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 8px; color: #fde68a; font-size: 0.85rem; align-items: center; gap: 0.6rem;">
                        <i class="fa-solid fa-box-archive" style="color: #fbbf24; font-size: 1.1rem; flex-shrink: 0;"></i>
                        <div><strong>Prior-Year Archived Snapshot (<span id="archivedNoticeGrade">Grade 11</span>):</strong> This assessment was completed in a prior school year and snapshotted during the school year transition. Viewing historical responses and recommendations in read-only mode.</div>
                    </div>

                    <!-- Attempt History Pills -->
                    <div class="attempt-history-bar" id="attemptHistoryBar" style="display: none; margin-top: 1.2rem; padding-top: 1.2rem; border-top: 1px solid rgba(255,255,255,0.08);">
                        <div style="font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-history" style="color: #f59e0b;"></i> Assessment Attempts / History:
                        </div>
                        <div class="attempt-pills-container" id="attemptPillsContainer" style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
                            <!-- Dynamically populated -->
                        </div>
                    </div>
                </div>


                <!-- Summary Section -->
                <div class="summary-section">
                    <div class="summary-cards">
                        <div class="summary-card">
                            <div class="summary-icon">
                                <i class="fa-solid fa-list-ol"></i>
                            </div>
                            <div class="summary-info">
                                <span class="summary-label">Total Questions</span>
                                <span class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-icon answered">
                                <i class="fa-solid fa-check-double"></i>
                            </div>
                            <div class="summary-info">
                                <span class="summary-label">Answered</span>
                                <span class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-icon score">
                                <i class="fa-solid fa-percentage"></i>
                            </div>
                            <div class="summary-info">
                                <span class="summary-label">Score</span>
                                <span class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-icon" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">
                                <i class="fa-solid fa-star"></i>
                            </div>
                            <div class="summary-info">
                                <span class="summary-label">Total Score</span>
                                <span class="summary-value">--</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-section">
                    <div class="search-box">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" id="searchQuestions" placeholder="Search question text...">
                    </div>
                    <button class="btn-clear" id="clearSearch">
                        <i class="fa-solid fa-times"></i> Clear
                    </button>
                </div>

                <!-- Answers Section -->
                <div class="answers-section">
                    <h3 class="section-title">
                        <i class="fa-solid fa-clipboard-question"></i> Question Responses
                    </h3>

                    <!-- Question Cards Container -->
                    <div class="question-cards" id="questionCards">
                        <!-- Empty state - will be populated by JavaScript when student is selected -->
                        <div class="no-answers-state" id="noAnswersState" style="text-align: center; padding: 40px 20px; border: 2px dashed rgba(251, 191, 36, 0.3); border-radius: 12px; background: rgba(15, 23, 42, 0.4); margin: 20px 0;">
                            <p style="font-size: 15px; color: #94a3b8; font-weight: 500; margin: 0;">Search for a student to view their assessment responses.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Counselor View Answers JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            function escapeHtml(text) {
                if (text === null || text === undefined) return '';
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return text.toString().replace(/[&<>"']/g, m => map[m]);
            }

            const searchStudent = document.getElementById('searchStudent');
            const studentSuggestions = document.getElementById('studentSuggestions');
            const selectedStudentId = document.getElementById('selectedStudentId');
            const studentInfoCard = document.getElementById('studentInfoCard');
            
            const statusFilter = document.getElementById('statusFilter');

            // Search students on input
            searchStudent.addEventListener('input', async function() {
                const query = this.value.trim();
                const statusVal = statusFilter ? statusFilter.value : 'active';
                
                if (query.length < 2) {
                    studentSuggestions.style.display = 'none';
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'search_students');
                    formData.append('search', query);
                    formData.append('status_filter', statusVal);
                    
                    const response = await fetch('counselor_answers.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.students.length > 0) {
                        let html = '';
                        data.students.forEach(student => {
                            const isGrad = (student.status === 'graduated');
                            const statusBadge = isGrad 
                                ? '<span class="suggestion-status-badge graduated"><i class="fa-solid fa-graduation-cap"></i> Graduated</span>'
                                : '<span class="suggestion-status-badge active">Active</span>';
                            html += `
                                <div class="suggestion-item" data-id="${student.id}" data-name="${student.name}" data-strand="${student.strand}" data-grade="${student.grade}" data-status="${student.status || 'active'}">
                                    <div class="suggestion-item-main" style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                        <span class="suggestion-name">${student.name}</span>
                                        ${statusBadge}
                                    </div>
                                    <span class="suggestion-meta">ID: ${student.student_id} | ${student.strand} | ${student.grade}</span>
                                </div>
                            `;
                        });
                        studentSuggestions.innerHTML = html;
                        studentSuggestions.style.display = 'block';
                    } else {
                        studentSuggestions.style.display = 'none';
                    }
                } catch (error) {
                    console.error('Error searching students:', error);
                    studentSuggestions.style.display = 'none';
                }
            });

            // Filter suggestions when status dropdown changes
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    if (searchStudent.value.trim().length >= 2) {
                        searchStudent.dispatchEvent(new Event('input'));
                    } else {
                        const filterVal = this.value;
                        const items = studentSuggestions.querySelectorAll('.suggestion-item');
                        let visibleCount = 0;
                        items.forEach(item => {
                            const itemStatus = item.dataset.status || 'active';
                            if (filterVal === 'all' || itemStatus === filterVal) {
                                item.style.display = '';
                                visibleCount++;
                            } else {
                                item.style.display = 'none';
                            }
                        });
                        if (visibleCount > 0 && searchStudent === document.activeElement) {
                            studentSuggestions.style.display = 'block';
                        }
                    }
                });
            }
            
            // Handle student selection
            studentSuggestions.addEventListener('click', function(e) {
                const item = e.target.closest('.suggestion-item');
                if (item) {
                    const id = item.dataset.id;
                    const name = item.dataset.name;
                    
                    searchStudent.value = name;
                    selectedStudentId.value = id;
                    studentSuggestions.style.display = 'none';

                    if (document.getElementById('selectAssessment').value) {
                        loadStudentAssessmentData(0, true);
                    }
                }
            });
            
            // Close suggestions when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-student-group')) {
                    studentSuggestions.style.display = 'none';
                }
            });
            
            // Update student info card (admin-style)
            function updateStudentInfoCardWithRealData(student, assessment = null, topRecommendation = null) {
                const name = `${student.first_name} ${student.last_name}`;
                const avatar = name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

                document.getElementById('studentAvatar').textContent = avatar;
                document.getElementById('studentName').textContent = name;
                document.getElementById('studentGrade').textContent = student.grade_level;
                const strandEl = document.getElementById('studentStrand');
                const strandCode = (student.strand_code || '').trim();
                const strandName = (student.strand_name || '').trim();
                strandEl.textContent = strandCode ? strandCode.toUpperCase() : (strandName || 'N/A');
                if (strandName) {
                    strandEl.title = strandName;
                }
                document.getElementById('studentId').textContent = student.student_id;

                // Lifecycle & Graduated Badge / Banner
                const badgeGrad = document.getElementById('badgeGraduated');
                const archBanner = document.getElementById('archivedStudentBanner');
                const isGraduated = (student.status === 'graduated');

                if (badgeGrad) {
                    badgeGrad.style.display = isGraduated ? 'inline-flex' : 'none';
                }
                if (archBanner) {
                    archBanner.style.display = isGraduated ? 'flex' : 'none';
                }

                // Archived Assessment Snapshot Badge & Notice (for Grade 11 -> 12 moved up students)
                const badgeArchived = document.getElementById('badgeArchivedSnapshot');
                const badgeArchivedText = document.getElementById('badgeArchivedText');
                const archNotice = document.getElementById('archivedAttemptNotice');
                const archNoticeGrade = document.getElementById('archivedNoticeGrade');
                const isArchived = Boolean(assessment && assessment.is_archived);
                const archGrade = (assessment && assessment.archive_grade_level) ? assessment.archive_grade_level : 'Grade 11';

                if (badgeArchived) {
                    badgeArchived.style.display = isArchived ? 'inline-flex' : 'none';
                    if (badgeArchivedText) badgeArchivedText.textContent = `${archGrade} Archived Record`;
                }
                if (archNotice) {
                    archNotice.style.display = isArchived ? 'flex' : 'none';
                    if (archNoticeGrade) archNoticeGrade.textContent = archGrade;
                }

                // Badges
                const badgeAttempt = document.getElementById('badgeAttempt');
                const badgeLatest  = document.getElementById('badgeLatest');
                const badgeStatus  = document.getElementById('badgeStatus');

                if (assessment && assessment.attempt_num) {
                    badgeAttempt.textContent = `Attempt ${assessment.attempt_num}/${assessment.total_attempts || assessment.attempt_num}`;
                    badgeAttempt.style.display = 'inline-block';
                } else {
                    badgeAttempt.style.display = 'none';
                }

                if (assessment && assessment.is_latest) {
                    badgeLatest.style.display = 'inline-block';
                } else {
                    badgeLatest.style.display = 'none';
                }

                if (assessment && assessment.status) {
                    badgeStatus.style.display = 'inline-block';
                    badgeStatus.textContent = assessment.status === 'completed' ? 'Completed' : (assessment.status === 'in_progress' ? 'In Progress' : 'Abandoned');
                    badgeStatus.className = `badge-status status ${assessment.status === 'completed' ? 'completed' : (assessment.status === 'in_progress' ? 'in-progress' : 'abandoned')}`;
                } else {
                    badgeStatus.style.display = 'none';
                }

                // Dates
                const startDateEl    = document.getElementById('startDate');
                const completeDateEl = document.getElementById('completeDate');
                const startDate = assessment?.created_at || student.started_at;
                const endDate   = assessment?.completed_at || student.completed_at;

                if (startDate) {
                    const s = new Date(startDate);
                    startDateEl.innerHTML = s.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        + '<br><span style="font-weight:400; font-size:0.85em;">' + s.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + '</span>';
                } else {
                    startDateEl.textContent = '--';
                }

                if (endDate) {
                    const e = new Date(endDate);
                    completeDateEl.innerHTML = e.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        + '<br><span style="font-weight:400; font-size:0.85em;">' + e.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + '</span>';
                } else if (assessment?.status === 'in_progress') {
                    completeDateEl.textContent = 'In Progress...';
                } else if (assessment?.status === 'abandoned') {
                    completeDateEl.textContent = 'Abandoned';
                } else {
                    completeDateEl.textContent = 'Not completed';
                }

                // Top Recommendation Box
                const recBox = document.getElementById('sicRecommendationBox');
                const viewRecBtn = document.getElementById('viewRecBtn');
                if (topRecommendation && assessment?.status === 'completed') {
                    recBox.style.display = 'flex';
                    document.getElementById('sicRecCourse').textContent = topRecommendation.course_name || 'N/A';
                    document.getElementById('sicRecScore').textContent = topRecommendation.match_percentage
                        ? Number(topRecommendation.match_percentage).toFixed(2) + '%' : 'N/A';
                    if (viewRecBtn) {
                        viewRecBtn.onclick = function() {
                            // Open recommendations modal
                            const modal = document.getElementById('recommendationsModal');
                            const list  = document.getElementById('recModalList');
                            if (!modal || !list) return;
                            const recs = window.currentAllRecommendations || [];
                            if (recs.length === 0) {
                                list.innerHTML = '<p style="color:#94a3b8; font-style:italic; margin:0;">No recommendations recorded for this attempt.</p>';
                            } else {
                                let html = '';
                                recs.forEach((r, idx) => {
                                    const badgeStyle = idx === 0
                                        ? 'background:linear-gradient(135deg,#f59e0b,#d97706);color:#0f172a;'
                                        : idx === 1
                                            ? 'background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;'
                                            : 'background:rgba(255,255,255,0.1);color:#cbd5e1;';
                                    html += `
                                        <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:0.85rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                                            <div style="display:flex;align-items:center;gap:0.8rem;">
                                                <span style="${badgeStyle}width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">#${r.rank || (idx+1)}</span>
                                                <div>
                                                    <strong style="color:#ffffff;font-size:0.95rem;display:block;line-height:1.3;">${r.course_name}</strong>
                                                    <span style="color:#94a3b8;font-size:0.8rem;">${r.cluster_name || ''}</span>
                                                </div>
                                            </div>
                                            <div style="text-align:right;flex-shrink:0;">
                                                <span style="font-size:0.75rem;color:#94a3b8;display:block;text-transform:uppercase;">Match</span>
                                                <strong style="color:#4ade80;font-size:1rem;">${Number(r.match_percentage).toFixed(2)}%</strong>
                                            </div>
                                        </div>`;
                                });
                                list.innerHTML = html;
                            }
                            modal.classList.add('show');
                        };
                    }
                } else {
                    recBox.style.display = 'none';
                }

                // Actions panel — "View Full Answers" scroll button
                const sicActions = document.getElementById('sicActions');
                if (sicActions) {
                    sicActions.innerHTML = '';
                    if (assessment && assessment.status === 'completed') {
                        const scrollBtn = document.createElement('button');
                        scrollBtn.className = 'btn-outline-primary';
                        scrollBtn.style.cssText = 'font-size:0.85rem; padding:8px 16px; border-radius:6px; font-weight:600; width:100%; cursor:pointer; text-align:center; border:1px solid rgba(255,255,255,0.2); background:transparent; color:#fff;';
                        scrollBtn.innerHTML = '<i class="fa-solid fa-eye"></i> View Full Answers';
                        scrollBtn.onclick = () => document.querySelector('.answers-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
                        sicActions.appendChild(scrollBtn);
                    }
                }

                // Attempt History Pills
                const historyBar    = document.getElementById('attemptHistoryBar');
                const pillsContainer = document.getElementById('attemptPillsContainer');
                const allAttempts   = window.currentAllAttempts || [];

                if (historyBar && pillsContainer && allAttempts.length > 1) {
                    historyBar.style.display = 'block';
                    pillsContainer.innerHTML = '';
                    allAttempts.forEach(attempt => {
                        const pill = document.createElement('button');
                        pill.className = 'attempt-pill' + (assessment && attempt.id === assessment.id ? ' active' : '');
                        pill.textContent = attempt.label;
                        pill.dataset.attemptId = attempt.id;
                        pillsContainer.appendChild(pill);
                    });
                } else if (historyBar) {
                    historyBar.style.display = 'none';
                }

                // Filter status badge
                const badgeContainer = document.getElementById('filterStatusRow');
                const badgeEl        = document.getElementById('filterStatusBadge');
                if (badgeContainer && badgeEl) {
                    if (assessment && assessment.status) {
                        badgeContainer.style.display = 'flex';
                        badgeEl.className = 'assessment-status-badge';
                        let statusText = '';
                        if (assessment.status === 'completed') {
                            badgeEl.classList.add('badge-completed'); statusText = 'Completed';
                        } else if (assessment.status === 'in_progress') {
                            badgeEl.classList.add('badge-in-progress'); statusText = 'In progress';
                        } else if (assessment.status === 'abandoned') {
                            badgeEl.classList.add('badge-abandoned'); statusText = 'Abandoned';
                        } else {
                            badgeEl.classList.add('badge-not-started');
                            statusText = assessment.status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
                        }
                        badgeEl.textContent = statusText;
                    } else {
                        badgeContainer.style.display = 'none';
                    }
                }
            }


            // Display assessment answers
            function displayAssessmentAnswers(answers) {
                const answersContainer = document.querySelector('.question-cards');
                if (!answersContainer) return;
                
                if (!answers || answers.length === 0) {
                    answersContainer.innerHTML = '<div class="no-results"><p><i class="fa-solid fa-inbox"></i> No answers recorded yet. Assessment may be in progress or not started.</p></div>';
                    return;
                }

                const validAnswers = answers.filter(answer => answer.answer_id !== null);
                
                if (validAnswers.length === 0) {
                    answersContainer.innerHTML = '<div class="no-results"><p><i class="fa-solid fa-inbox"></i> No answers recorded yet. Assessment may be in progress or not started.</p></div>';
                    return;
                }
                
                let html = '';
                const totalAnswers = validAnswers.length;
                let questionNumber = 0;
                validAnswers.forEach((answer) => {
                    questionNumber++;
                    const questionType = answer.question_type || 'unknown';
                    let questionTypeAttr = '';
                    let answerHtml = '';
                    
                    if (answer.open_answer !== null) {
                        questionTypeAttr = 'open-ended';
                        answerHtml = `<div class="open-answer">${answer.open_answer}</div>`;
                    } else if (['career', 'personality'].includes(questionType) && (answer.likert_value !== null || answer.score !== null)) {
                        questionTypeAttr = 'likert';
                        const selectedVal = Number(answer.likert_value ?? answer.score);
                        let circlesHtml = '';
                        for (let i = 1; i <= 5; i++) {
                            const isSelected = i === selectedVal ? 'selected' : '';
                            circlesHtml += `<div class="likert-circle ${isSelected}">${i}</div>`;
                        }
                        
                        html += `
                            <div class="likert-question-card" data-type="${questionType}" data-question-type="likert">
                                <div class="likert-card-header">
                                    <span class="likert-question-label">QUESTION</span>
                                    <span class="likert-question-counter">Question ${questionNumber} of ${totalAnswers}</span>
                                </div>
                                <div class="likert-card-body">
                                    <div class="likert-question-text">${answer.question_text || 'Question not available'}</div>
                                    <div class="likert-scale-wrapper">
                                        <div class="likert-label-left">Strongly<br>Disagree</div>
                                        <div class="likert-options-row">
                                            ${circlesHtml}
                                        </div>
                                        <div class="likert-label-right">Strongly<br>Agree</div>
                                    </div>
                                </div>
                            </div>
                        `;
                        return;
                    } else if (['skills', 'strand'].includes(questionType) && answer.selected_option_id !== null) {
                        questionTypeAttr = 'objective';
                        const isCorrect = answer.is_correct ? 'correct' : 'incorrect';
                        const scoreLabel = answer.is_correct ? '1/1' : '0/1';
                        const badgeBg = answer.is_correct ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                        const badgeColor = answer.is_correct ? '#10b981' : '#ef4444';
                        const badgeBorder = answer.is_correct ? '1px solid rgba(16, 185, 129, 0.2)' : '1px solid rgba(239, 68, 68, 0.2)';
                        
                        const selectedText = `Selected: ${answer.option_label ? answer.option_label + '. ' : ''}${answer.option_text || 'Option text not available'}`;
                        const correctText = answer.correct_option_label ? `Correct: ${answer.correct_option_label}. ${answer.correct_option_text}` : 'Correct answer not found';

                        answerHtml = `
                            <div class="option-answer ${isCorrect}" style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>${selectedText}</span>
                                    <span class="question-score-badge" style="font-weight: 600; padding: 2px 8px; border-radius: 6px; font-size: 11px; background: ${badgeBg}; color: ${badgeColor}; border: ${badgeBorder}; margin-left: 10px; white-space: nowrap;">
                                        Score: ${scoreLabel}
                                    </span>
                                </div>
                                ${!answer.is_correct ? `<div style="font-size: 12px; color: #10b981; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 6px;"><i class="fa-solid fa-check-circle"></i> ${correctText}</div>` : ''}
                            </div>`;
                    }
                    
                    html += `
                        <div class="question-card" data-type="${questionType}" data-question-type="${questionTypeAttr}">
                            <div class="question-header">
                                <span class="question-type-badge">${questionType}</span>
                                <span class="question-id">Question ${questionNumber} of ${totalAnswers}</span>
                            </div>
                            <div class="question-text">${answer.question_text || 'Question not available'}</div>
                            <div class="student-answer">
                                <h4>Student's Answer:</h4>
                                ${answerHtml}
                            </div>
                        </div>
                    `;
                });
                
                answersContainer.innerHTML = html;
                updateSummaryCounts();
            }

            // Search Functionality for Questions
            const searchInput = document.getElementById('searchQuestions');
            const clearQuestionsBtn = document.getElementById('clearSearch');

            function performSearch() {
                const searchTerm = (searchInput?.value || '').toLowerCase().trim();
                const questionCards = document.querySelectorAll('.question-card, .likert-question-card');

                questionCards.forEach(card => {
                    const questionTextEl = card.querySelector('.question-text, .likert-question-text');
                    if (!questionTextEl) return;
                    const questionText = questionTextEl.textContent.toLowerCase();
                    if (questionText.includes(searchTerm)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });

                const visibleCards = document.querySelectorAll('.question-card:not([style*="display: none"]), .likert-question-card:not([style*="display: none"])');
                const answersSection = document.querySelector('.question-cards');
                if (!answersSection) return;
                
                let noResultsMsg = answersSection.querySelector('.no-results');
                
                if (visibleCards.length === 0 && searchTerm !== '') {
                    if (!noResultsMsg) {
                        noResultsMsg = document.createElement('div');
                        noResultsMsg.className = 'no-results';
                        noResultsMsg.innerHTML = '<p><i class="fa-solid fa-search"></i> No questions found matching your search.</p>';
                        answersSection.appendChild(noResultsMsg);
                    }
                    noResultsMsg.style.display = 'block';
                } else if (noResultsMsg) {
                    noResultsMsg.style.display = 'none';
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }

            if (clearQuestionsBtn) {
                clearQuestionsBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    performSearch();
                });
            }

            // Buttons
            const clearStudentBtn = document.getElementById('clearBtn');
            const searchBtn = document.getElementById('searchBtn');

            clearStudentBtn.addEventListener('click', () => {
                searchStudent.value = '';
                selectedStudentId.value = '';
                studentSuggestions.style.display = 'none';
                studentInfoCard.style.display = 'none';
                window.totalActiveQuestions = 0;
                window.assessmentTotalScore = null;
                
                document.querySelector('.summary-card:nth-child(1) .summary-value').textContent = '--';
                document.querySelector('.summary-card:nth-child(2) .summary-value').textContent = '--';
                document.querySelector('.summary-card:nth-child(3) .summary-value').textContent = '--';
                const overallCard = document.querySelector('.summary-card:nth-child(4) .summary-value');
                if (overallCard) overallCard.textContent = '--';
                
                document.querySelector('.question-cards').innerHTML = `
                    <div class="no-answers-state" id="noAnswersState" style="text-align: center; padding: 40px 20px; border: 2px dashed rgba(251, 191, 36, 0.3); border-radius: 12px; background: rgba(15, 23, 42, 0.4); margin: 20px 0;">
                        <p style="font-size: 15px; color: #94a3b8; font-weight: 500; margin: 0;">Search for a student to view their assessment responses.</p>
                    </div>
                `;
                
                const badgeContainer = document.getElementById('filterStatusRow');
                if (badgeContainer) badgeContainer.style.display = 'none';
            });

            searchBtn.addEventListener('click', async () => {
                const query = searchStudent.value.trim();
                
                if (selectedStudentId.value) {
                    await loadStudentAssessmentData(0, true);
                    return;
                }

                if (query.length < 2) {
                    alert('Please enter at least 2 characters to search');
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'search_students');
                    formData.append('search', query);
                    const statusVal = statusFilter ? statusFilter.value : 'all';
                    formData.append('status_filter', statusVal);
                    
                    const response = await fetch('counselor_answers.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.students.length === 1) {
                        const student = data.students[0];
                        
                        selectedStudentId.value = student.id;
                        searchStudent.value = student.name;
                        
                        document.getElementById('studentInfoCard').style.display = 'flex';
                        document.getElementById('studentSuggestions').style.display = 'none';
                        
                        await loadStudentAssessmentData(0, true);
                    } else if (data.success && data.students.length > 1) {
                        alert('Multiple students found. Please select the correct student from the dropdown list.');
                        searchStudent.dispatchEvent(new Event('input'));
                    } else {
                        alert('No students found matching your search.');
                        searchStudent.dispatchEvent(new Event('input'));
                    }
                } catch (error) {
                    console.error('Search error:', error);
                    searchStudent.dispatchEvent(new Event('input'));
                }
            });

            // Core loader — used by search, suggestion click, and attempt pills
            async function loadStudentAssessmentData(targetAssessmentId = 0, scrollToAnswers = true) {
                const studentId = selectedStudentId.value;
                const assessmentType = document.getElementById('selectAssessment').value;

                if (!studentId || !assessmentType) {
                    alert('Please select a student and assessment type');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'get_student_assessments');
                    formData.append('student_id', studentId);
                    formData.append('assessment_type', assessmentType);
                    if (targetAssessmentId > 0) {
                        formData.append('target_assessment_id', targetAssessmentId);
                    }

                    const response = await fetch('counselor_answers.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.totalActiveQuestions       = data.total_active_questions || 0;
                        window.assessmentTotalScore       = data.category_score_percentage;
                        window.overallAssessmentScore     = data.assessment ? data.assessment.total_score : null;
                        window.assessmentStatus           = data.assessment ? data.assessment.status : null;
                        window.currentAllRecommendations  = data.all_recommendations || [];

                        updateStudentInfoCardWithRealData(data.student, data.assessment, data.top_recommendation || null);

                        // Rebuild attempt pills with click handlers
                        const attemptBar     = document.getElementById('attemptHistoryBar');
                        const pillsContainer = document.getElementById('attemptPillsContainer');
                        const allAttempts    = data.all_attempts || [];
                        const currentId      = targetAssessmentId > 0 ? targetAssessmentId : (data.assessment ? data.assessment.id : 0);

                        if (attemptBar && pillsContainer && (allAttempts.length > 1 || allAttempts.some(a => a.is_archived))) {
                            attemptBar.style.display = 'block';
                            pillsContainer.innerHTML = '';
                            allAttempts.forEach(att => {
                                const btn      = document.createElement('button');
                                btn.type       = 'button';
                                const isActive = (att.id == currentId);
                                const isArch   = Boolean(att.is_archived);
                                btn.className  = `attempt-pill ${isArch ? 'archived' : ''} ${isActive ? 'active' : ''}`;
                                const iconClass= isArch ? 'fa-box-archive' : (isActive ? 'fa-clock-rotate-left' : 'fa-history');
                                btn.innerHTML  = `<i class="fa-solid ${iconClass}"></i> ${escapeHtml(att.label)}`;
                                btn.onclick = () => {
                                    if (att.id != currentId) {
                                        loadStudentAssessmentData(att.id, false);
                                    }
                                };
                                pillsContainer.appendChild(btn);
                            });
                        } else if (attemptBar) {
                            attemptBar.style.display = 'none';
                        }

                        displayAssessmentAnswers(data.answers);
                        studentInfoCard.style.display = 'block';

                        if (scrollToAnswers) {
                            document.querySelector('.answers-section').scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    } else {
                        alert(data.message || 'Failed to load assessment data');
                    }
                } catch (error) {
                    console.error('Error loading assessments:', error);
                    alert('Error loading assessment data');
                }
            }

            // Expose globally for bottom script
            window.loadStudentAssessmentData = loadStudentAssessmentData;

            // Assessment Selection Change
            const assessmentSelect = document.getElementById('selectAssessment');

            assessmentSelect.addEventListener('change', () => {
                const selectedType = assessmentSelect.value;
                if (selectedStudentId.value && selectedType) {
                    loadStudentAssessmentData(0, false);
                } else if (!selectedType) {
                    studentInfoCard.style.display = 'none';
                }
            });
            
            function updateSummaryCounts() {
                const visibleCards = document.querySelectorAll('.question-card:not([style*="display: none"]), .likert-question-card:not([style*="display: none"])');
                const hasCards = visibleCards.length > 0;
                const totalQuestions = (hasCards && window.totalActiveQuestions) ? window.totalActiveQuestions : visibleCards.length;
                const answeredQuestionsCount = visibleCards.length;
                
                if (hasCards && totalQuestions > 0) {
                    document.querySelector('.summary-card:nth-child(1) .summary-value').textContent = totalQuestions;
                    document.querySelector('.summary-card:nth-child(2) .summary-value').textContent = `${answeredQuestionsCount} / ${totalQuestions}`;
                } else {
                    document.querySelector('.summary-card:nth-child(1) .summary-value').textContent = '--';
                    document.querySelector('.summary-card:nth-child(2) .summary-value').textContent = '--';
                }
                
                let score = '--';
                if (window.assessmentStatus === 'abandoned') {
                    score = 'Not Scored';
                } else if (hasCards && window.assessmentTotalScore !== null && window.assessmentTotalScore !== undefined) {
                    score = Math.round(Number(window.assessmentTotalScore));
                } else if (hasCards) {
                    let totalScore = 0;
                    let scoredCount = 0;
                    
                    visibleCards.forEach(card => {
                        if (card.dataset.questionType === 'objective') {
                            scoredCount++;
                            const isCorrect = card.querySelector('.option-answer.correct');
                            if (isCorrect) {
                                totalScore += 100;
                            }
                        } else if (card.dataset.questionType === 'likert') {
                            scoredCount++;
                            const selectedCircle = card.querySelector('.likert-circle.selected');
                            if (selectedCircle) {
                                const ratingValue = parseInt(selectedCircle.textContent);
                                if (!isNaN(ratingValue)) {
                                    totalScore += Math.max(0, (ratingValue - 1) * 25);
                                }
                            }
                        }
                    });
                    
                    if (scoredCount > 0) {
                        score = Math.round(totalScore / scoredCount);
                    }
                }
                
                document.querySelector('.summary-card:nth-child(3) .summary-value').textContent =
                    (score === '--' || score === 'Not Scored') ? score : `${score}%`;
                    
                let overallScoreDisplay = '--';
                if (hasCards && window.overallAssessmentScore !== null && window.overallAssessmentScore !== undefined) {
                    overallScoreDisplay = Number(window.overallAssessmentScore).toFixed(2) + '%';
                }
                const overallCard = document.querySelector('.summary-card:nth-child(4) .summary-value');
                if (overallCard) {
                    overallCard.textContent = overallScoreDisplay;
                }
            }

            // Question Card Toggle
            document.querySelector('.question-cards').addEventListener('click', (e) => {
                const card = e.target.closest('.question-card, .likert-question-card');
                if (!card) return;

                if (e.target.closest('.likert-scale') || e.target.closest('.likert-scale-wrapper') || e.target.closest('.option-item')) {
                    return;
                }
                card.classList.toggle('expanded');
            });


        });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
    <?php if ($initialStudentId): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                if (typeof window.loadStudentAssessmentData === 'function') {
                    window.loadStudentAssessmentData(0, true);
                } else {
                    const searchBtn = document.getElementById('searchBtn');
                    if (searchBtn) searchBtn.click();
                }
            }, 300);
        });
    </script>
    <?php endif; ?>
    <!-- Recommendations Modal -->
    <div class="logout-modal-overlay" id="recommendationsModal">
        <div class="logout-modal">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,0.1);padding-bottom:1rem;margin-bottom:1rem;">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <div style="width:40px;height:40px;border-radius:10px;background:rgba(251,191,36,0.15);color:#fbbf24;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <h3 style="margin:0;color:#ffffff;font-size:1.15rem;font-weight:700;text-align:left;">Recommended Courses</h3>
                        <span style="font-size:0.8rem;color:#94a3b8;text-align:left;display:block;">Top recommendations for this assessment</span>
                    </div>
                </div>
                <button type="button" id="closeRecModalBtn" style="background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0 0.5rem;">&times;</button>
            </div>
            <div id="recModalList" style="display:flex;flex-direction:column;gap:0.65rem;max-height:360px;overflow-y:auto;padding-right:0.3rem;">
                <!-- Populated dynamically -->
            </div>
            <div style="margin-top:1.25rem;text-align:right;">
                <button type="button" class="btn-logout-cancel" id="btnOkRecModal" style="padding:0.5rem 1.25rem;">Close</button>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const recModal = document.getElementById('recommendationsModal');
            const closeBtn = document.getElementById('closeRecModalBtn');
            const okBtn    = document.getElementById('btnOkRecModal');
            const closeRec = () => recModal && recModal.classList.remove('show');
            if (closeBtn) closeBtn.addEventListener('click', closeRec);
            if (okBtn)    okBtn.addEventListener('click', closeRec);
            if (recModal) recModal.addEventListener('click', e => { if (e.target === recModal) closeRec(); });
        });
    </script>
</body>
</html>
