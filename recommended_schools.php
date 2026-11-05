<?php
require_once 'config.php';
require_once 'system_config.php';

// Check if logged in as student OR admin/counselor
$isStudent = isLoggedIn();
$isAdminOrCounselor = isset($_SESSION['admin_id']) || isset($_SESSION['counselor_id']);

if (!$isStudent && !$isAdminOrCounselor) {
    redirect('index.php');
}

// Bypass strictly student-only activity logs here if not a student
if ($isStudent) {
    // any student-specific activity logging can go here if needed
}

$student = null;
if ($isAdminOrCounselor && isset($_GET['student_id'])) {
    $stmt = $mysqli->prepare("
        SELECT s.*, st.name AS strand_name, st.code AS strand_code, sy.year_label AS school_year
        FROM students s
        LEFT JOIN strands st ON s.strand_id = st.id
        LEFT JOIN school_years sy ON s.school_year_id = sy.id
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $_GET['student_id']);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
} else {
    $student = getCurrentStudent();
}

$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);

$recommendedSchools  = [];
$recommendedCourses  = [];   // all 5 recommended courses for the switcher
$activeCourse        = null; // the course we're currently showing schools for

if ($student) {
    // 1. Get all completed assessments for this student
    $allAssessments = [];
    $MAX_ASSESSMENTS = 2;
    $histStmt = $mysqli->prepare("
        SELECT id, completed_at, status
        FROM student_assessments
        WHERE student_id = ? AND status = 'completed'
        ORDER BY completed_at ASC
    ");
    if ($histStmt) {
        $histStmt->bind_param("i", $student['id']);
        $histStmt->execute();
        $histResult = $histStmt->get_result();
        while ($row = $histResult->fetch_assoc()) {
            $allAssessments[] = $row;
        }
        $histStmt->close();
    }

    $latestAssessment = !empty($allAssessments) ? end($allAssessments) : null;
    $latestAssessmentId = $latestAssessment ? $latestAssessment['id'] : 0;

    $viewingAttemptId = $latestAssessmentId; // default: latest
    $isViewingOldAttempt = false;
    $viewingAttemptNum = $totalAttempts = count($allAssessments);

    $requestedAttemptId = (int)($_GET['attempt_id'] ?? 0);
    if ($requestedAttemptId > 0) {
        foreach ($allAssessments as $idx => $att) {
            if ($att['id'] === $requestedAttemptId) {
                $viewingAttemptId = $requestedAttemptId;
                $viewingAttemptNum = $idx + 1;
                $isViewingOldAttempt = ($requestedAttemptId !== $latestAssessmentId);
                break;
            }
        }
    }

    if ($viewingAttemptId > 0) {
        $courseStmt = $mysqli->prepare("
            SELECT c.id, c.course_name, r.match_percentage, r.rank
            FROM recommendations r
            JOIN courses c ON r.course_id = c.id
            WHERE r.assessment_id = ?
            ORDER BY r.rank ASC
            LIMIT 5
        ");
        $courseStmt->bind_param("i", $viewingAttemptId);
        $courseStmt->execute();
        $courseResult = $courseStmt->get_result();
        while ($row = $courseResult->fetch_assoc()) {
            $recommendedCourses[] = $row;
        }
        $courseStmt->close();
    }

    // 2. Determine which course to show schools for
    //    - Honour ?course_id= if it belongs to the student's recommendations
    //    - Default to rank-1 course
    $requestedCourseId = (int)($_GET['course_id'] ?? 0);
    $activeCourse = $recommendedCourses[0] ?? null; // default: rank 1

    if ($requestedCourseId > 0) {
        foreach ($recommendedCourses as $rc) {
            if ((int)$rc['id'] === $requestedCourseId) {
                $activeCourse = $rc;
                break;
            }
        }
    }

    // 3. Fetch schools ONLY for the active course
    if ($activeCourse) {
        $schoolStmt = $mysqli->prepare("
            SELECT s.*, d.name AS district_name,
                   ? AS course_name,
                   ? AS match_percentage,
                   MAX(cs.is_specialization) AS is_specialization
            FROM course_schools cs
            JOIN schools s ON cs.school_id = s.id
            LEFT JOIN districts d ON s.district_id = d.id
            WHERE cs.course_id = ? AND s.status = 'active'
            GROUP BY s.id, d.name
            ORDER BY MAX(cs.is_specialization) DESC, s.name ASC
        ");
        $courseName   = $activeCourse['course_name'];
        $matchPct     = $activeCourse['match_percentage'];
        $activeCourseId = (int)$activeCourse['id'];
        $schoolStmt->bind_param("sdi", $courseName, $matchPct, $activeCourseId);
        $schoolStmt->execute();
        $schoolResult = $schoolStmt->get_result();
        while ($row = $schoolResult->fetch_assoc()) {
            $recommendedSchools[] = $row;
        }
        $schoolStmt->close();
    }
}

// Stats
$totalSchools = count($recommendedSchools);
$highestMatch = !empty($recommendedSchools) ? (float)$recommendedSchools[0]['match_percentage'] : 0;

// Build unique districts from the filtered school list
$uniqueDistricts = [];
foreach ($recommendedSchools as $s) {
    $d = $s['district_name'] ?? '';
    if ($d && !in_array($d, $uniqueDistricts)) $uniqueDistricts[] = $d;
}
sort($uniqueDistricts);

// Helper: school initials from name
function schoolInitials($name) {
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

// Helper: consistent color from string with rich gradients
function avatarColor($str) {
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
    $idx = abs(crc32($str)) % count($palettes);
    $p = $palettes[$idx];
    return "background: {$p['bg']}; box-shadow: 0 4px 14px {$p['shadow']};";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recommended Schools - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css">
    <style>
        /* ── Page header ─────────────────────────────── */
        .rs-page-header { text-align:center; padding:1.75rem 0 1.5rem; }
        .rs-page-header-icon { font-size:2rem; color:#f59e0b; margin-bottom:0.5rem; display:block; }
        .rs-page-header h2 { font-size:1.75rem; font-weight:700; color:#f8fafc; margin:0 0 6px; }
        .rs-page-header p  { font-size:0.95rem; color:#94a3b8; margin:0; }

        /* ── Info cards row ──────────────────────────── */
        .rc-info-row { display:grid; grid-template-columns:1fr; gap:1rem; margin-bottom:1.5rem; }
        .rc-info-card {
            background:#111827;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:1.25rem 1.5rem;
        }
        .rc-info-card-title { font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; margin:0 0 0.6rem; }
        .rc-info-card-status-row { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
        .rc-status-dot { width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .rc-status-dot.green { background:#16a34a; }
        .rc-status-dot.amber { background:#d97706; }
        .rc-status-dot i { font-size:0.6rem; color:#fff; }
        .rc-attempt-label { font-size:1rem; font-weight:600; color:#22c55e; }
        .rc-attempt-label.amber { color:#f59e0b; }
        .rc-attempt-date { font-size:0.8rem; color:#64748b; margin:0 0 0.75rem; }
        .rc-attempt-note { font-size:0.8rem; color:#64748b; margin:0; }
        .rc-compare-btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 14px; background:transparent;
            border:1px solid rgba(255,255,255,0.15); border-radius:8px;
            color:#cbd5e1; font-size:0.8rem; cursor:pointer; text-decoration:none;
            transition:background 0.2s;
        }
        .rc-compare-btn:hover { background:rgba(255,255,255,0.06); }

        /* ── Filter bar ─────────────────────────────── */
        .rs-filter-bar {
            display:flex; align-items:center; gap:10px; flex-wrap:nowrap;
            margin-bottom:1rem; overflow-x:auto;
        }
        .rs-search-wrap {
            position:relative; flex:1; min-width:120px;
        }
        .rs-search-wrap i {
            position:absolute; left:0.9rem; top:50%; transform:translateY(-50%);
            color:#64748b; font-size:0.85rem; pointer-events:none;
        }
        .rs-search-wrap input {
            width:100%; padding:0.6rem 1rem 0.6rem 2.5rem;
            background:#0f172a; border:1px solid rgba(255,255,255,0.1);
            border-radius:9px; color:#f1f5f9; font-size:0.875rem;
            outline:none; font-family:inherit; box-sizing:border-box;
            transition:border-color 0.2s;
        }
        .rs-search-wrap input:focus { border-color:rgba(99,102,241,0.5); }
        .rs-search-wrap input::placeholder { color:#475569; }

        .rs-filter-group { display:flex; align-items:center; gap:8px; }
        .rs-filter-label {
            font-size:0.75rem; font-weight:700; text-transform:uppercase;
            letter-spacing:0.06em; color:#64748b; white-space:nowrap;
        }
        .rs-filter-select {
            padding:0.55rem 2rem 0.55rem 0.85rem;
            background:#0f172a url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='%2394a3b8'%3E%3Cpath d='M6 8L0 0h12z'/%3E%3C/svg%3E") no-repeat right 0.7rem center;
            border:1px solid rgba(255,255,255,0.1); border-radius:9px;
            color:#f1f5f9; font-size:0.875rem; cursor:pointer;
            outline:none; font-family:inherit; appearance:none; -webkit-appearance:none;
            transition:border-color 0.2s; white-space:nowrap;
        }
        .rs-filter-select:focus { border-color:rgba(99,102,241,0.5); }

        /* ── Stats bar ───────────────────────────────── */
        .rs-stats-bar {
            display:flex; align-items:stretch; gap:0;
            background:#111827; border:1px solid rgba(255,255,255,0.08);
            border-radius:14px; margin-bottom:1.5rem; overflow:hidden;
        }
        .rs-stat {
            flex:1; padding:1.1rem 1.5rem; display:flex; align-items:center; gap:1rem;
            border-right:1px solid rgba(255,255,255,0.06);
        }
        .rs-stat:last-child { border-right:none; }
        .rs-stat-icon {
            width:44px; height:44px; border-radius:12px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center; font-size:1.1rem;
        }
        .rs-stat-icon.blue   { background:rgba(29,78,216,0.15); color:#60a5fa; }
        .rs-stat-icon.green  { background:rgba(5,150,105,0.15);  color:#34d399; }
        .rs-stat-icon.purple { background:rgba(124,58,237,0.15); color:#a78bfa; }
        .rs-stat-text { flex:1; }
        .rs-stat-label { font-size:0.72rem; color:#64748b; margin:0 0 2px; }
        .rs-stat-value { font-size:1.4rem; font-weight:700; color:#f8fafc; margin:0; line-height:1.1; }
        .rs-stat-sub { font-size:0.72rem; color:#64748b; margin:0; }
        @media(max-width:640px){ .rs-stats-bar { flex-direction:column; } .rs-stat { border-right:none; border-bottom:1px solid rgba(255,255,255,0.06); } .rs-stat:last-child { border-bottom:none; } }

        /* ── Schools grid ────────────────────────────── */
        .rs-grid {
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:1.25rem; margin-bottom:1.5rem;
        }
        @media(max-width:1100px){ .rs-grid { grid-template-columns:repeat(2, 1fr); } }
        @media(max-width:680px){ .rs-grid { grid-template-columns:1fr; } }

        /* ── School card ─────────────────────────────── */
        .rs-card {
            background:linear-gradient(145deg, rgba(30, 41, 59, 0.65), rgba(15, 23, 42, 0.85));
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px; padding:1.25rem; position:relative;
            display:flex; flex-direction:column; justify-content:space-between;
            transition:border-color 0.25s, transform 0.25s, box-shadow 0.25s;
            min-height:280px;
        }
        .rs-card:hover {
            border-color:rgba(245,158,11,0.35);
            transform:translateY(-3px);
            box-shadow:0 12px 30px rgba(0,0,0,0.45);
        }
        .rs-card.best-match { border-color:rgba(245,158,11,0.4); }

        /* Best match badge */
        .rs-best-badge {
            position:absolute; top:-1px; right:14px;
            background:linear-gradient(135deg,#f59e0b,#d97706);
            color:#0f172a; font-size:0.65rem; font-weight:800;
            text-transform:uppercase; letter-spacing:0.06em;
            padding:3px 10px; border-radius:0 0 8px 8px;
            box-shadow:0 2px 8px rgba(245,158,11,0.3);
            z-index:2;
        }

        /* Avatar + name row */
        .rs-card-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin:0 0 0.85rem; }
        .rs-avatar {
            width:48px; height:48px; border-radius:13px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
            font-size:1.15rem; font-weight:800; color:#fff;
            letter-spacing:-0.01em;
            border:1px solid rgba(255,255,255,0.12);
            overflow:hidden;
        }
        .rs-avatar img { width:100%; height:100%; object-fit:cover; border-radius:12px; }
        .rs-badges-group { display:flex; flex-wrap:wrap; gap:4px; align-items:center; justify-content:flex-end; }
        .rs-badge-type {
            font-size:0.72rem; font-weight:600; padding:0.2rem 0.55rem;
            border-radius:999px; background:rgba(59,130,246,0.15);
            color:#93c5fd; border:1px solid rgba(59,130,246,0.3); white-space:nowrap;
        }
        .rs-badge-district {
            font-size:0.72rem; font-weight:600; padding:0.2rem 0.55rem;
            border-radius:999px; background:rgba(168,85,247,0.15);
            color:#d8b4fe; border:1px solid rgba(168,85,247,0.3); white-space:nowrap;
        }
        .rs-card-name {
            font-size:1.05rem; font-weight:700; color:#f8fafc; line-height:1.35;
            margin:0 0 0.5rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden; min-height:2.7rem;
        }

        /* Career fit pill */
        .rs-fit-pill {
            display:inline-flex; align-items:center; gap:5px;
            padding:3px 10px; border-radius:20px;
            background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.3);
            color:#fbbf24; font-size:0.75rem; font-weight:700; margin-bottom:0.6rem;
            width:fit-content;
        }
        .rs-fit-pill i { font-size:0.65rem; }

        /* Location */
        .rs-location {
            display:flex; align-items:flex-start; gap:6px;
            font-size:0.8rem; color:#94a3b8; line-height:1.4; margin-bottom:0.75rem;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden; min-height:2.2rem;
        }
        .rs-location i { color:#f87171; font-size:0.8rem; margin-top:2px; flex-shrink:0; }

        /* Recommended course */
        .rs-course-box {
            background:rgba(15,23,42,0.6); border:1px solid rgba(255,255,255,0.06);
            border-radius:8px; padding:0.5rem 0.75rem; margin-bottom:0.85rem;
        }
        .rs-course-label { font-size:0.68rem; text-transform:uppercase; letter-spacing:0.06em; color:#64748b; margin:0 0 2px; }
        .rs-course-name { font-size:0.85rem; font-weight:600; color:#e2e8f0; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* View button */
        .rs-view-btn {
            display:flex; align-items:center; justify-content:center; gap:6px;
            width:100%; padding:0.6rem; margin-top:auto;
            background:linear-gradient(135deg,#f59e0b,#d97706); border:none; border-radius:8px;
            color:#0f172a; font-size:0.85rem; font-weight:700;
            text-decoration:none; cursor:pointer;
            box-shadow:0 4px 12px rgba(245,158,11,0.25);
            transition:all 0.2s;
        }
        .rs-view-btn:hover { background:linear-gradient(135deg,#fbbf24,#f59e0b); transform:translateY(-1px); }
        .rs-view-btn i { font-size:0.75rem; }

        /* ── No-match message ────────────────────────── */
        .rs-no-match {
            display:none; grid-column:1/-1; text-align:center;
            padding:3rem 1rem; color:#64748b;
        }
        .rs-no-match i { font-size:2.5rem; margin-bottom:0.75rem; display:block; }
        .rs-no-match h3 { color:#94a3b8; margin:0 0 6px; }

        /* ── Empty state ─────────────────────────────── */
        .rs-empty { text-align:center; padding:4rem 1rem; color:#64748b; }
        .rs-empty i { font-size:3rem; margin-bottom:1rem; display:block; }
        .rs-empty h3 { color:#94a3b8; margin:0 0 8px; }

        /* ── Footer note ─────────────────────────────── */
        .rs-footer-note {
            display:flex; align-items:center; gap:8px;
            padding:0 0 1.5rem;
        }
        .rs-footer-note i { color:#475569; font-size:0.8rem; flex-shrink:0; }
        .rs-footer-note span { font-size:0.78rem; color:#475569; }

        /* visible count chip */
        #rsCountChip { font-size:0.78rem; color:#94a3b8; white-space:nowrap; }
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
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Close sidebar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-home"></i><span>Dashboard</span></a>
            <a href="take_assessment.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i><span>Take Assessment</span></a>
            <a href="assessment_results.php" class="nav-item"><i class="fa-solid fa-chart-line"></i><span>Assessment Results</span></a>
            <a href="recommended_courses.php" class="nav-item active"><i class="fa-solid fa-graduation-cap"></i><span>Recommendations</span></a>
            <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
            <a href="logout.php?role=student" class="nav-item logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <header class="top-bar">
            <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fa-solid fa-bars"></i></button>
            <div class="page-title"><h1>Recommended Schools</h1></div>
            <div class="top-bar-actions">
                <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                <div class="user-profile">
                    <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar">
                    <div class="user-dropdown"><span class="user-name"><?php echo $studentName; ?></span></div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="dashboard-content">

            <div class="rs-page-header">
                <i class="fa-solid fa-school rs-page-header-icon"></i>
                <h2>Schools Offering Your Recommended Courses</h2>
                <p>Explore educational institutions that can help you achieve your career goals.</p>
            </div>

            <?php if ($viewingAttemptId > 0): ?>
            <!-- Info cards row -->
            <div class="rc-info-row">
                <!-- Assessment Status -->
                <div class="rc-info-card">
                    <p class="rc-info-card-title">Assessment Status</p>
                    <div class="rc-info-card-status-row">
                        <div class="rc-status-dot green"><i class="fa-solid fa-check"></i></div>
                        <span class="rc-attempt-label <?php echo $isViewingOldAttempt ? 'amber' : ''; ?>">
                            Attempt <?php echo $viewingAttemptNum; ?> of <?php echo $MAX_ASSESSMENTS; ?>
                            <?php echo $isViewingOldAttempt ? '(Viewing)' : '(Latest)'; ?>
                        </span>
                    </div>
                    <p class="rc-attempt-date">
                        Completed on: <?php
                            $viewedAssessment = $allAssessments[$viewingAttemptNum - 1] ?? $latestAssessment;
                            echo date('F j, Y \a\t g:i A', strtotime($viewedAssessment['completed_at']));
                        ?>
                    </p>
                    <p class="rc-attempt-date" style="margin-top:4px;">
                        <?php
                            // Fetch preferred_region for the viewed attempt
                            $rsRawDistrict = '';
                            $rsRegionStmt = $mysqli->prepare("SELECT preferred_region FROM student_assessments WHERE id = ? LIMIT 1");
                            if ($rsRegionStmt) {
                                $rsRegionStmt->bind_param("i", $viewingAttemptId);
                                $rsRegionStmt->execute();
                                $rsRegionRow = $rsRegionStmt->get_result()->fetch_assoc();
                                $rsRawDistrict = $rsRegionRow['preferred_region'] ?? '';
                                $rsRegionStmt->close();
                            }
                            if (empty($rsRawDistrict)) {
                                $rsDistrictDisplay = 'Not Selected';
                            } elseif (strtolower($rsRawDistrict) === 'all') {
                                $rsDistrictDisplay = 'All Districts';
                            } else {
                                $rsParts = array_map('trim', explode(',', $rsRawDistrict));
                                $rsDistrictDisplay = implode(', ', array_map(function($id) {
                                    if ($id === 'All') return 'All Districts';
                                    $suffixes = ['1'=>'st','2'=>'nd','3'=>'rd'];
                                    $s = $suffixes[$id] ?? 'th';
                                    return $id . $s . ' District';
                                }, $rsParts));
                            }
                        ?>
                        <span style="color:#94a3b8;">Preferred District(s):</span>
                        <span style="color:#c7d2fe; font-weight:500;"><?php echo htmlspecialchars($rsDistrictDisplay); ?></span>
                    </p>
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                        <p class="rc-attempt-note">
                            <?php echo $isViewingOldAttempt
                                ? 'You are viewing an older set of recommendations.'
                                : 'These recommendations are based on your latest assessment.'; ?>
                        </p>
                        <?php if ($totalAttempts > 1 && !$isViewingOldAttempt): ?>
                        <a href="recommended_schools.php?attempt_id=<?php echo $allAssessments[0]['id']; ?>" class="rc-compare-btn">
                            <i class="fa-solid fa-right-left"></i> Compare Attempt 1
                        </a>
                        <?php elseif ($isViewingOldAttempt): ?>
                        <a href="recommended_schools.php" class="rc-compare-btn" style="border-color:rgba(99,102,241,0.4); color:#a5b4fc;">
                            <i class="fa-solid fa-arrow-right"></i> View Latest
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($recommendedSchools)): ?>
            <div class="rs-empty">
                <i class="fa-solid fa-school"></i>
                <h3>No Schools Available</h3>
                <p>Take an assessment to get personalized school recommendations.</p>
                <a href="take_assessment.php" class="btn btn-primary" style="margin-top:1rem;">Take Assessment</a>
            </div>
            <?php else: ?>

            <!-- Filter bar -->
            <div class="rs-filter-bar">
                <div class="rs-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchSchools" placeholder="Search school name or address...">
                </div>
                <div class="rs-filter-group">
                    <span class="rs-filter-label">Course:</span>
                    <select id="filterCourse" class="rs-filter-select" onchange="window.location.href='recommended_schools.php?course_id=' + this.value + '<?php echo $viewingAttemptId > 0 && $isViewingOldAttempt ? '&attempt_id=' . $viewingAttemptId : ''; ?>'">
                        <?php foreach ($recommendedCourses as $rc): ?>
                        <option value="<?php echo (int)$rc['id']; ?>"
                            <?php echo ($activeCourse && (int)$rc['id'] === (int)$activeCourse['id']) ? 'selected' : ''; ?>>
                            #<?php echo (int)$rc['rank']; ?> <?php echo htmlspecialchars($rc['course_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rs-filter-group">
                    <span class="rs-filter-label">Type:</span>
                    <select id="filterType" class="rs-filter-select">
                        <option value="all">All Types</option>
                        <option value="public">Public</option>
                        <option value="private">Private</option>
                    </select>
                </div>
                <div class="rs-filter-group">
                    <span class="rs-filter-label">District:</span>
                    <select id="filterRegion" class="rs-filter-select">
                        <option value="all">All Districts</option>
                        <?php foreach ($uniqueDistricts as $d): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($d)); ?>"><?php echo htmlspecialchars($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span id="rsCountChip"></span>
            </div>

            <!-- Stats bar -->
            <div class="rs-stats-bar">
                <div class="rs-stat">
                    <div class="rs-stat-icon blue"><i class="fa-solid fa-school"></i></div>
                    <div class="rs-stat-text">
                        <p class="rs-stat-label">Schools Found</p>
                        <p class="rs-stat-value" id="rsSchoolCount"><?php echo $totalSchools; ?></p>
                        <p class="rs-stat-sub">schools match your results</p>
                    </div>
                </div>
                <div class="rs-stat">
                    <div class="rs-stat-icon green"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="rs-stat-text">
                        <p class="rs-stat-label">Highest Match</p>
                        <p class="rs-stat-value"><?php echo number_format($highestMatch, 1); ?>%</p>
                        <p class="rs-stat-sub">career fit score</p>
                    </div>
                </div>
                <div class="rs-stat">
                    <div class="rs-stat-icon purple"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="rs-stat-text">
                        <p class="rs-stat-label">Showing schools for</p>
                        <p class="rs-stat-value" id="rsDistrictLabel" style="font-size:0.95rem; line-height:1.3;"><?php echo htmlspecialchars($activeCourse['course_name'] ?? 'Top Course'); ?></p>
                        <p class="rs-stat-sub">switch course above to explore more</p>
                    </div>
                </div>
            </div>

            <!-- Schools grid -->
            <div class="rs-grid" id="rsGrid">
                <!-- No match message (hidden by default) -->
                <div class="rs-no-match" id="noSchoolsMatch">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <h3>No matching schools found</h3>
                    <p>Try adjusting your search terms or filters.</p>
                </div>

                <?php foreach ($recommendedSchools as $idx => $school):
                    $isBest = ($idx === 0);
                    $initials = schoolInitials($school['name'] ?? 'S');
                    $avatarStyle = avatarColor($school['name'] ?? '');
                    $districtName = $school['district_name'] ?? '';
                    
                    $street = trim($school['address'] ?? '');
                    $city = trim($school['city'] ?? '');
                    $province = trim($school['province'] ?? '');
                    if (strcasecmp($province, 'PANGASINAN') === 0) $province = 'Pangasinan';
                    if ($province && str_ends_with(strtolower($street), strtolower($province))) {
                        $street = trim(rtrim(substr($street, 0, -strlen($province)), ','));
                    }
                    if ($city && str_ends_with(strtolower($street), strtolower($city))) {
                        $street = trim(rtrim(substr($street, 0, -strlen($city)), ','));
                    }
                    $fullAddressStr = implode(', ', array_filter([$street, $city, $province]));
                ?>
                <div class="rs-card <?php echo $isBest ? 'best-match' : ''; ?>"
                     data-name="<?php echo htmlspecialchars(strtolower($school['name'] ?? '')); ?>"
                     data-address="<?php echo htmlspecialchars(strtolower($school['address'] ?? '')); ?>"
                     data-type="<?php echo htmlspecialchars(strtolower($school['type'] ?? '')); ?>"
                     data-region="<?php echo htmlspecialchars(strtolower($districtName)); ?>"
                     data-course="<?php echo htmlspecialchars(strtolower($school['course_name'] ?? '')); ?>">

                    <?php if ($isBest): ?>
                    <div class="rs-best-badge"><i class="fa-solid fa-crown" style="font-size: 0.6rem;"></i> Best Match</div>
                    <?php endif; ?>

                    <div>
                        <!-- Header with Avatar and Badges -->
                        <div class="rs-card-header">
                            <div class="rs-avatar" style="<?php echo $avatarStyle; ?>">
                                <?php if (!empty($school['logo']) && file_exists($school['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($school['logo']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($initials); ?>
                                <?php endif; ?>
                            </div>
                            <div class="rs-badges-group">
                                <?php if (!empty($school['type'])): ?>
                                <span class="rs-badge-type"><?php echo htmlspecialchars($school['type']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($districtName)): ?>
                                <span class="rs-badge-district"><i class="fa-solid fa-map-pin" style="font-size: 0.65rem;"></i> <?php echo htmlspecialchars($districtName); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- School Name -->
                        <h3 class="rs-card-name" title="<?php echo htmlspecialchars($school['name']); ?>">
                            <?php echo htmlspecialchars($school['name']); ?>
                        </h3>

                        <!-- Career Fit Match Score -->
                        <?php if (isset($school['match_percentage'])): ?>
                        <div class="rs-fit-pill">
                            <i class="fa-solid fa-circle-check"></i>
                            <?php echo number_format($school['match_percentage'], 1); ?>% Career Fit
                        </div>
                        <?php endif; ?>

                        <!-- Location -->
                        <?php if ($fullAddressStr): ?>
                        <div class="rs-location" title="<?php echo htmlspecialchars($fullAddressStr); ?>">
                            <i class="fa-solid fa-location-dot"></i>
                            <span><?php echo htmlspecialchars($fullAddressStr); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Recommended Course Offering -->
                        <?php if (!empty($school['course_name'])): ?>
                        <div class="rs-course-box">
                            <p class="rs-course-label"><i class="fa-solid fa-graduation-cap"></i> Recommended Program</p>
                            <p class="rs-course-name" title="<?php echo htmlspecialchars($school['course_name']); ?>">
                                <?php echo htmlspecialchars($school['course_name']); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- View button -->
                    <?php if (!empty($school['website'])): ?>
                    <a href="<?php echo htmlspecialchars($school['website']); ?>" target="_blank" rel="noopener" class="rs-view-btn">
                        <span>Visit School</span>
                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem;"></i>
                    </a>
                    <?php else: ?>
                    <span class="rs-view-btn" style="opacity: 0.4; cursor: default;">
                        <span>No Website Listed</span>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>

            <!-- Footer note -->
            <div class="rs-footer-note">
                <i class="fa-solid fa-circle-info"></i>
                <span>Career Fit Scores represent how well each school's available course aligns with your interests and assessment results.</span>
            </div>

        </div>
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<script src="script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput  = document.getElementById('searchSchools');
    const typeSelect   = document.getElementById('filterType');
    const regionSelect = document.getElementById('filterRegion');
    const cards        = document.querySelectorAll('.rs-card');
    const noMatch      = document.getElementById('noSchoolsMatch');
    const countEl      = document.getElementById('rsSchoolCount');
    const distLabel    = document.getElementById('rsDistrictLabel');
    const countChip    = document.getElementById('rsCountChip');

    if (!searchInput) return;

    function updateDistrictLabel() {
        const v = regionSelect.value;
        const txt = v === 'all'
            ? 'All Districts'
            : regionSelect.options[regionSelect.selectedIndex].text;
        if (distLabel) distLabel.textContent = txt;
    }

    function filterSchools() {
        const query = searchInput.value.toLowerCase().trim();
        const type  = typeSelect.value;
        const region = regionSelect.value;
        let visible = 0;

        cards.forEach(card => {
            const name    = card.dataset.name    || '';
            const address = card.dataset.address || '';
            const cType   = card.dataset.type    || '';
            const cRegion = card.dataset.region  || '';
            const course  = card.dataset.course  || '';

            const matchSearch = !query || name.includes(query) || address.includes(query) || course.includes(query);
            const matchType   = type === 'all' || cType === type;
            const matchRegion = region === 'all' || cRegion === region;

            const show = matchSearch && matchType && matchRegion;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (noMatch)  noMatch.style.display  = visible === 0 ? 'block' : 'none';
        if (countEl)  countEl.textContent     = visible;
        if (countChip) countChip.textContent  = visible + ' result' + (visible !== 1 ? 's' : '');
        updateDistrictLabel();
    }

    searchInput.addEventListener('input',  filterSchools);
    typeSelect.addEventListener('change',  filterSchools);
    regionSelect.addEventListener('change', filterSchools);
    filterSchools(); // init
});
</script>
<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
