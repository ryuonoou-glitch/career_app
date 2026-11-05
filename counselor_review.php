<?php
// Counselor & Admin Review Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'system_config.php';
require_once 'config.php';
require_once 'includes/auth_rbac.php';
require_once 'includes/assessment_answers.php';
require_once 'includes/notify.php';

// Check if user is logged in as staff (Admin or Counselor)
if (!is_staff_logged_in()) {
    header('Location: login.php');
    exit();
}

$staffRole = get_staff_role() ?? 'counselor';
$staffId = get_staff_id();
$userName = get_staff_name();
$roleTitle = ($staffRole === 'counselor') ? 'Guidance Counselor' : 'Administrator';
$avatarIcon = ($staffRole === 'counselor') ? 'fa-user-tie' : 'fa-user-shield';
$avatarClass = ($staffRole === 'counselor') ? 'counselor-avatar' : 'admin-avatar';

$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header("Location: assessment_verifications.php");
    exit;
}

// Fetch assessment and student details
$stmt = $mysqli->prepare("
    SELECT sa.*, s.id as student_id, s.first_name, s.last_name, s.student_id as lrn, s.grade_level, st.name as strand_name 
    FROM student_assessments sa
    LEFT JOIN students s ON sa.student_id = s.id
    LEFT JOIN strands st ON s.strand_id = st.id
    WHERE sa.id = ?
");
$stmt->bind_param('i', $assessmentId);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assessment) {
    header("Location: assessment_verifications.php");
    exit;
}

// Fetch all courses for the dropdown
$coursesStmt = $mysqli->prepare("SELECT id, course_name FROM courses ORDER BY course_name ASC");
$coursesStmt->execute();
$allCourses = $coursesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$coursesStmt->close();

// Recommendations (System-Generated)
$recStmt = $mysqli->prepare("
    SELECT r.course_id, r.rank, r.match_percentage, c.course_name, cl.name as cluster_name
    FROM recommendations r
    LEFT JOIN courses c ON r.course_id = c.id
    LEFT JOIN clusters cl ON c.cluster_id = cl.id
    WHERE r.assessment_id = ?
    ORDER BY r.rank ASC
");
$recStmt->bind_param('i', $assessmentId);
$recStmt->execute();
$recommendations = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recStmt->close();

// Handle POST actions
$postError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $notes = trim($_POST['counselor_notes'] ?? '');
    
    if ($action === 'verify') {
        $evals = $_POST['evals'] ?? [];
        $finalRecs = $_POST['final_recs'] ?? [];
        $adjustmentReason = trim($_POST['adjustment_reason'] ?? '');
        
        $originalCourses = array_column(array_slice($recommendations, 0, 5), 'course_id');
        $missingEval = false;
        foreach($originalCourses as $cid) {
            if (!isset($evals[$cid])) {
                $missingEval = true;
                break;
            }
        }
        
        // Check if there's any modifications
        $isModified = false;
        $finalRecsCount = count($finalRecs);
        if ($finalRecsCount !== count($originalCourses)) {
            $isModified = true;
        } else {
            foreach ($finalRecs as $idx => $cid) {
                if ($cid != $originalCourses[$idx]) {
                    $isModified = true;
                    break;
                }
            }
        }
        
        if ($missingEval) {
            $postError = "Please evaluate all system-generated recommendations for alignment.";
        } elseif (empty($finalRecs)) {
            $postError = "Please provide at least one verified recommendation.";
        } elseif ($isModified && empty($adjustmentReason)) {
            $postError = "Because you adjusted the final recommendations, please provide an Adjustment Reason.";
        } else {
            // Save evaluations
            $mysqli->query("DELETE FROM recommendation_evaluations WHERE assessment_id = $assessmentId");
            $evalStmt = $mysqli->prepare("INSERT INTO recommendation_evaluations (assessment_id, course_id, is_aligned, evaluated_by, evaluation_date) VALUES (?, ?, ?, ?, NOW())");
            foreach($originalCourses as $cid) {
                $isAligned = (int)($evals[$cid] === '1');
                $evalStmt->bind_param('iiii', $assessmentId, $cid, $isAligned, $staffId);
                $evalStmt->execute();
            }
            $evalStmt->close();
            
            // Save verified recommendations
            $mysqli->query("DELETE FROM verified_recommendations WHERE assessment_id = $assessmentId");
            $vrStmt = $mysqli->prepare("INSERT INTO verified_recommendations (assessment_id, course_id, rank) VALUES (?, ?, ?)");
            foreach($finalRecs as $idx => $cid) {
                $rank = $idx + 1;
                $vrStmt->bind_param('iii', $assessmentId, $cid, $rank);
                $vrStmt->execute();
            }
            $vrStmt->close();

            $validation = 'appropriate'; // Keep validation logic internally
            $stmt = $mysqli->prepare("UPDATE student_assessments SET verification_status = 'verified', verified_by = ?, verified_by_role = ?, verified_at = NOW(), counselor_notes = ?, recommendation_validation = ?, revision_details = NULL, adjustment_reason = ? WHERE id = ?");
            $stmt->bind_param('issssi', $staffId, $staffRole, $notes, $validation, $adjustmentReason, $assessmentId);
            $stmt->execute();
            $stmt->close();

            if (!empty($assessment['student_id'])) {
                notify_student(
                    (int)$assessment['student_id'],
                    'Assessment Verified',
                    'Your assessment results have been verified by your ' . $roleTitle . ' and are now released!',
                    'success',
                    'assessment_results.php?id=' . $assessmentId
                );
            }

            header("Location: assessment_verifications.php?filter=verified");
            exit;
        }
    } elseif ($action === 'return') {
        $flaggedItems = [];
        $hasSpecificFlags = false;
        
        if (isset($_POST['flagged']) && is_array($_POST['flagged'])) {
            foreach ($_POST['flagged'] as $key => $flagVal) {
                if ($flagVal === '1' || $flagVal === 'on' || $flagVal === 1) {
                    $itemNote = trim($_POST['flag_notes'][$key] ?? '');
                    $flaggedItems[$key] = [
                        'flagged' => true,
                        'note' => $itemNote
                    ];
                    $hasSpecificFlags = true;
                }
            }
        }
        
        $revisionData = [
            'has_specific_flags' => $hasSpecificFlags,
            'flags' => $flaggedItems,
            'general_notes' => $notes,
            'returned_by_id' => $staffId,
            'returned_by_name' => $userName,
            'returned_by_role' => $staffRole,
            'returned_at' => date('Y-m-d H:i:s')
        ];
        $revJson = json_encode($revisionData);
        $validation = 'needs_review';
        
        $stmt = $mysqli->prepare("UPDATE student_assessments SET verification_status = 'returned', verified_by = ?, verified_by_role = ?, verified_at = NOW(), counselor_notes = ?, recommendation_validation = ?, revision_details = ? WHERE id = ?");
        $stmt->bind_param('issssi', $staffId, $staffRole, $notes, $validation, $revJson, $assessmentId);
        $stmt->execute();
        $stmt->close();

        if (!empty($assessment['student_id'])) {
            $count = count($flaggedItems);
            $msg = $count > 0 
                ? "Your assessment requires revision on {$count} specific question(s). Please review your answers."
                : "Your assessment has been returned with revision instructions from your {$roleTitle}. Please update your answers.";
            notify_student(
                (int)$assessment['student_id'],
                'Assessment Requires Revision',
                $msg,
                'warning',
                'assessment_results.php?id=' . $assessmentId
            );
        }

        header("Location: assessment_verifications.php?filter=returned");
        exit;
    }
}

// Fetch existing evaluations
$evalStmt = $mysqli->prepare("SELECT course_id, is_aligned FROM recommendation_evaluations WHERE assessment_id = ?");
$evalStmt->bind_param('i', $assessmentId);
$evalStmt->execute();
$existingEvals = [];
$evalRes = $evalStmt->get_result();
while ($row = $evalRes->fetch_assoc()) {
    $existingEvals[$row['course_id']] = $row['is_aligned'];
}
$evalStmt->close();

// Fetch verified recommendations
$verRecStmt = $mysqli->prepare("
    SELECT vr.course_id, vr.rank, c.course_name 
    FROM verified_recommendations vr
    JOIN courses c ON vr.course_id = c.id
    WHERE vr.assessment_id = ? 
    ORDER BY vr.rank ASC
");
$verRecStmt->bind_param('i', $assessmentId);
$verRecStmt->execute();
$verifiedRecs = $verRecStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$verRecStmt->close();

// Existing Recommendation Validation
$existingValidation = $assessment['recommendation_validation'] ?? '';

// Decode existing revision details if present
$existingRevDetails = !empty($assessment['revision_details']) ? json_decode($assessment['revision_details'], true) : [];
$existingFlags = $existingRevDetails['flags'] ?? [];

// Medical Declaration
$medicalDeclaration = !empty($assessment['medical_declaration']) ? json_decode($assessment['medical_declaration'], true) : [];

// Category Scores
$categoryScores = [];
$catStmt = $mysqli->prepare("SELECT category, score, percentage FROM category_scores WHERE assessment_id = ? ORDER BY FIELD(category, 'career', 'personality', 'skills', 'strand')");
if ($catStmt) {
    $catStmt->bind_param('i', $assessmentId);
    $catStmt->execute();
    $catRes = $catStmt->get_result();
    while ($cRow = $catRes->fetch_assoc()) {
        $categoryScores[$cRow['category']] = $cRow;
    }
    $catStmt->close();
}



// Open-ended Answers
$ansStmt = $mysqli->prepare("
    SELECT saq.question_type, saq.open_answer, saq.question_id, saq.question_text
    FROM student_answers saq
    WHERE saq.assessment_id = ? AND saq.open_answer IS NOT NULL AND saq.open_answer != ''
");
$ansStmt->bind_param('i', $assessmentId);
$ansStmt->execute();
$ansRes = $ansStmt->get_result();

$openAnswers = [];
while ($row = $ansRes->fetch_assoc()) {
    $qText = getOpenEndedQuestionText($mysqli, $row['question_type'], $row['question_id'], $row['question_text'] ?? '');
    $key = strtolower($row['question_type']) . '_' . $row['question_id'];
    $isFlagged = !empty($existingFlags[$key]['flagged']);
    $itemNote = $existingFlags[$key]['note'] ?? '';

    $openAnswers[] = [
        'key' => $key,
        'raw_type' => strtolower($row['question_type']),
        'type' => ucfirst($row['question_type']),
        'question_id' => $row['question_id'],
        'question' => $qText,
        'answer' => $row['open_answer'],
        'is_flagged' => $isFlagged,
        'existing_note' => $itemNote
    ];
}
$ansStmt->close();

// Fetch all answers for printable view
$allAnswersData = fetchStudentAssessmentAnswers($mysqli, $assessment['student_id'], 'all', $assessmentId);
$allPrintableAnswers = $allAnswersData['answers'] ?? [];
$groupedPrintAnswers = [];
foreach ($allPrintableAnswers as $pAns) {
    $pCat = ucfirst($pAns['question_type']);
    $groupedPrintAnswers[$pCat][] = $pAns;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Assessment - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
    <style>
        .review-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            max-width: 950px;
            margin: 0 auto;
        }
        .review-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }
        .review-header {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 0.75rem;
        }
        .review-header i { color: #fbbf24; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .info-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #94a3b8;
            font-weight: 600;
        }
        .info-value {
            font-size: 0.95rem;
            color: #f8fafc;
            font-weight: 500;
        }

        .rec-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Interactive Open-Ended Answer Cards */
        .ans-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            transition: all 0.25s ease;
            position: relative;
        }
        .ans-item.is-flagged {
            border-color: rgba(239, 68, 68, 0.55);
            background: rgba(239, 68, 68, 0.04);
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.12);
        }
        .ans-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.65rem;
        }
        .ans-category-badge {
            font-size: 0.75rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.06);
            padding: 3px 8px;
            border-radius: 4px;
        }
        .flag-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.08);
            color: #f87171;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }
        .flag-btn:hover {
            background: rgba(239, 68, 68, 0.18);
            border-color: #ef4444;
            color: #fca5a5;
        }
        .flag-btn.active {
            background: #ef4444;
            border-color: #ef4444;
            color: #ffffff;
            box-shadow: 0 2px 10px rgba(239, 68, 68, 0.4);
        }
        .ans-q {
            color: #f8fafc;
            font-weight: 600;
            font-size: 0.98rem;
            line-height: 1.45;
            margin-bottom: 0.75rem;
        }
        .ans-a {
            color: #fbbf24;
            font-size: 0.95rem;
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.18);
            padding: 0.85rem 1rem;
            border-radius: 8px;
            line-height: 1.5;
        }
        .revision-input-wrap {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed rgba(239, 68, 68, 0.3);
            animation: fadeIn 0.25s ease;
        }
        .revision-input-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #fca5a5;
            margin-bottom: 0.45rem;
        }
        .revision-textarea {
            width: 100%;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #f8fafc;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            min-height: 70px;
            font-size: 0.9rem;
            font-family: inherit;
            resize: vertical;
            outline: none;
            transition: border-color 0.2s;
        }
        .revision-textarea:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }
        
        .actions-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }
        .actions-form textarea {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            min-height: 90px;
            resize: vertical;
            font-family: inherit;
            outline: none;
        }
        .actions-form textarea:focus {
            border-color: #3b82f6;
        }
        .actions-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .btn-verify {
            background: #10b981;
            color: white;
            border: none;
            padding: 0.85rem 1.6rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-verify:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        .btn-return {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid #ef4444;
            padding: 0.85rem 1.6rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-return:hover {
            background: #ef4444;
            color: white;
            transform: translateY(-1px);
        }
        .admin-avatar {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        .counselor-avatar {
            background: linear-gradient(135deg, #10b981, #047857);
            color: white;
        }
        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-status.pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-status.verified { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-status.returned { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        .validation-choice-label {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.85rem 1.4rem;
            border-radius: 10px;
            color: #e2e8f0;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            user-select: none;
        }
        .validation-choice-label:hover {
            border-color: rgba(255, 255, 255, 0.25);
            background: rgba(255, 255, 255, 0.04);
        }
        .validation-choice-label.selected-appropriate {
            background: rgba(16, 185, 129, 0.12);
            border-color: #10b981;
            color: #6ee7b7;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }
        .validation-choice-label.selected-needs-review {
            background: rgba(245, 158, 11, 0.12);
            border-color: #f59e0b;
            color: #fde68a;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
        }

        @media print {
            .sidebar, .dashboard-header, .top-bar, .btn-print-summary, .actions-buttons, .flag-btn, .revision-input-wrap, #returnSummaryNotice, .app-footer, .back-link-wrap, .btn-official-pdf, .hide-on-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                display: block !important;
                height: auto !important;
                overflow: visible !important;
            }
            body, html {
                background: #ffffff !important;
                color: #0f172a !important;
                height: auto !important;
                overflow: visible !important;
            }
            .dashboard-container, .dashboard-content, .review-container {
                height: auto !important;
                overflow: visible !important;
                display: block !important;
            }
            .review-card {
                background: #ffffff !important;
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                color: #0f172a !important;
                page-break-inside: avoid;
                margin-bottom: 1rem !important;
            }
            .review-header, .info-value, .ans-q, strong {
                color: #0f172a !important;
            }
            .ans-a {
                color: #1e293b !important;
                background: #f8fafc !important;
                border: 1px solid #cbd5e1 !important;
            }
            .info-label {
                color: #64748b !important;
            }
            .rec-item {
                background: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
            }
            .rec-item strong {
                color: #0f172a !important;
            }
            .print-answers-section {
                display: block !important;
            }
        }

        .print-answers-section {
            display: none; /* Hidden by default in web view, optionally we can show it or just show it when printing */
        }
        
        /* Optional toggle for web view */
        .all-answers-toggle {
            cursor: pointer;
            color: #60a5fa;
            font-size: 0.85rem;
            text-decoration: underline;
            background: none;
            border: none;
            padding: 0;
            margin-top: 10px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>
        <main class="main-content">
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fa-solid fa-bars"></i></button>
                <div class="page-title">
                    <h1>Review Assessment</h1>
                </div>
                <div class="top-bar-actions">
                    <div class="user-profile">
                        <div class="user-avatar <?php echo $avatarClass; ?>"><i class="fa-solid <?php echo $avatarIcon; ?>"></i></div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                            <span style="font-size: 0.72rem; color: #94a3b8; display: block; text-transform: uppercase;"><?php echo htmlspecialchars($roleTitle); ?></span>
                        </div>
                    </div>
                </div>
            </header>
            
            <div class="dashboard-content">
                <div class="review-container">
                    
                    <!-- Back Link & Print Actions -->
                    <div class="back-link-wrap" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:-0.5rem; flex-wrap:wrap; gap:10px;">
                        <a href="assessment_verifications.php" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Verification Queue
                        </a>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="button" onclick="window.print()" class="btn-print-summary" style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); color: #e2e8f0; padding: 6px 14px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-print"></i> Print Review Summary
                            </button>
                            <?php if ($assessment['verification_status'] === 'verified'): ?>
                            <a href="download_results_pdf.php?id=<?php echo $assessmentId; ?>" target="_blank" class="btn-official-pdf" style="background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.35); color: #60a5fa; padding: 6px 14px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-file-pdf"></i> Official Results PDF
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($postError)): ?>
                    <div style="background: rgba(239, 68, 68, 0.15); border-left: 4px solid #ef4444; border-radius: 8px; padding: 1rem 1.25rem; color: #fca5a5; margin-bottom: 1rem; font-size: 0.95rem;">
                        <i class="fa-solid fa-circle-exclamation"></i> <strong>Validation Required:</strong> <?php echo htmlspecialchars($postError); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Student Info Card -->
                    <div class="review-card">
                        <div class="review-header"><i class="fa-solid fa-user"></i> Student Information</div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Student Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($assessment['first_name'] . ' ' . $assessment['last_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">LRN / ID</span>
                                <span class="info-value"><?php echo htmlspecialchars($assessment['lrn']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Grade & Strand</span>
                                <span class="info-value"><?php echo htmlspecialchars($assessment['grade_level']); ?> - <?php echo htmlspecialchars($assessment['strand_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Verification Status</span>
                                <span class="info-value">
                                    <span class="badge-status <?php echo htmlspecialchars($assessment['verification_status']); ?>">
                                        <?php echo ucfirst($assessment['verification_status']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Recommendation Validation</span>
                                <span class="info-value">
                                    <?php if (!empty($assessment['recommendation_validation'])): ?>
                                        <span class="badge-status <?php echo ($assessment['recommendation_validation'] === 'appropriate') ? 'verified' : 'returned'; ?>">
                                            <?php echo ($assessment['recommendation_validation'] === 'appropriate') ? 'Appropriate' : 'Needs Review'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:0.85rem;">Pending Validation</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Declaration -->
                    <?php if (!empty($medicalDeclaration)): ?>
                    <div class="review-card">
                        <div class="review-header"><i class="fa-solid fa-notes-medical"></i> Medical Declaration</div>
                        <ul style="color:#e2e8f0; margin:0; padding-left:1.5rem;">
                            <?php foreach ($medicalDeclaration as $med): ?>
                                <li><?php echo htmlspecialchars(is_array($med) ? json_encode($med) : $med); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Assessment Dimension Scores -->
                    <?php if (!empty($categoryScores)): ?>
                    <div class="review-card">
                        <div class="review-header"><i class="fa-solid fa-chart-simple"></i> Assessment Dimension Scores</div>
                        <div class="info-grid">
                            <?php 
                            $catLabels = ['career' => 'Career Interest', 'personality' => 'Personality', 'skills' => 'Skills Assessment', 'strand' => 'Strand Alignment'];
                            foreach (['career', 'personality', 'skills', 'strand'] as $ckey):
                                $cData = $categoryScores[$ckey] ?? null;
                                $cPct = $cData ? round((float)$cData['percentage'], 1) : 0;
                            ?>
                            <div class="info-item" style="background: rgba(15,23,42,0.5); padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06);">
                                <span class="info-label"><?php echo $catLabels[$ckey] ?? ucfirst($ckey); ?></span>
                                <span class="info-value" style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; display: flex; align-items: center; justify-content: space-between;">
                                    <span><?php echo $cPct; ?>%</span>
                                    <span style="font-size: 0.75rem; font-weight: 600; color: #94a3b8;"><?php echo ($cData && isset($cData['score'])) ? 'Score: ' . $cData['score'] : ''; ?></span>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Student Open-Ended Answers (With Individual Return/Flagging) -->
                    <div class="review-card hide-on-print" style="border: 1px solid rgba(52, 211, 153, 0.3); box-shadow: 0 4px 15px rgba(52, 211, 153, 0.1);">
                        <div class="review-header" style="justify-content:space-between; border-bottom: 1px solid rgba(52, 211, 153, 0.2);">
                            <div style="color: #34d399;">
                                <i class="fa-solid fa-bullseye"></i> Student's Career Goal / Open-Ended Response
                            </div>
                            <span style="font-size:0.8rem; font-weight:500; color:#94a3b8;">
                                Flag individual answers that need revision before releasing
                            </span>
                        </div>

                        <?php if(empty($openAnswers)): ?>
                            <p style="color:#94a3b8;">No open-ended answers provided.</p>
                        <?php else: ?>
                            <?php foreach($openAnswers as $ans): 
                                $key = $ans['key'];
                                $isFlagged = $ans['is_flagged'];
                                $existingNote = $ans['existing_note'];
                            ?>
                            <div class="ans-item <?php echo $isFlagged ? 'is-flagged' : ''; ?>" id="card_<?php echo $key; ?>" data-key="<?php echo $key; ?>">
                                <div class="ans-header-bar">
                                    <span class="ans-category-badge"><?php echo htmlspecialchars($ans['type']); ?></span>
                                    
                                    <!-- Flag Button Toggle -->
                                    <button type="button" class="flag-btn <?php echo $isFlagged ? 'active' : ''; ?>" id="flagBtn_<?php echo $key; ?>" onclick="toggleFlag('<?php echo $key; ?>')">
                                        <i class="fa-solid <?php echo $isFlagged ? 'fa-rotate-left' : 'fa-rotate-left'; ?>"></i>
                                        <span id="flagText_<?php echo $key; ?>"><?php echo $isFlagged ? 'Flagged for Revision' : 'Flag for Revision'; ?></span>
                                    </button>

                                    <!-- Hidden Checkbox connected to the form below via form attribute -->
                                    <input type="checkbox" name="flagged[<?php echo $key; ?>]" id="chk_<?php echo $key; ?>" value="1" class="flag-hidden-input" <?php echo $isFlagged ? 'checked' : ''; ?> style="display:none;" form="verificationForm">
                                </div>

                                <div class="ans-q"><?php echo htmlspecialchars($ans['question']); ?></div>
                                <div class="ans-a"><?php echo nl2br(htmlspecialchars($ans['answer'])); ?></div>

                                <!-- Specific Feedback Input (Visible when flagged) -->
                                <div class="revision-input-wrap" id="feedback_wrap_<?php echo $key; ?>" style="<?php echo $isFlagged ? 'display:block;' : 'display:none;'; ?>">
                                    <div class="revision-input-label">
                                        <i class="fa-solid fa-pen"></i> Revision Instruction for this <?php echo htmlspecialchars($ans['type']); ?> answer:
                                    </div>
                                    <textarea name="flag_notes[<?php echo $key; ?>]" id="note_<?php echo $key; ?>" class="revision-textarea" placeholder="e.g. Please provide a more serious explanation without jokes or references..." form="verificationForm"><?php echo htmlspecialchars($existingNote); ?></textarea>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Printable All Answers Section (Hidden on screen, visible on print) -->
                    <div class="print-answers-section" id="allAnswersCard">
                        <div class="review-header" style="border-bottom: 2px solid #000; padding-bottom: 0.5rem; margin-bottom: 1rem; margin-top: 2rem; color: #000;">
                            <div><i class="fa-solid fa-list-check"></i> All Student Answers</div>
                        </div>
                        <div id="printAnswersWrap">
                            <?php if (empty($groupedPrintAnswers)): ?>
                                <p style="color:#000;">No answers recorded.</p>
                            <?php else: ?>
                                <?php foreach (['Career', 'Personality', 'Skills', 'Strand'] as $cat): ?>
                                    <?php if (!empty($groupedPrintAnswers[$cat])): ?>
                                        <div style="margin-top: 1.5rem; page-break-inside: avoid;">
                                            <h4 style="color:#000; border-bottom: 1px solid #ccc; padding-bottom: 0.5rem; margin-bottom: 0.75rem;"><?php echo $cat; ?> Answers</h4>
                                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                            <?php foreach ($groupedPrintAnswers[$cat] as $ansRow): ?>
                                                <div style="padding: 0.5rem; border-bottom: 1px dashed #eee;">
                                                    <div style="color:#000; font-size:0.9rem; font-weight:600; margin-bottom:0.25rem;">
                                                        <?php 
                                                        $dispQ = $ansRow['question_text'];
                                                        if (empty($dispQ)) {
                                                            if (!empty($ansRow['open_answer'])) {
                                                                $dispQ = getOpenEndedQuestionText($mysqli, $ansRow['question_type'], $ansRow['question_id']);
                                                            } else {
                                                                $dispQ = getQuestionTextByType($mysqli, $ansRow['question_type'], $ansRow['question_id']);
                                                            }
                                                        }
                                                        echo htmlspecialchars($dispQ ?: 'Question #' . $ansRow['question_id']); 
                                                        ?>
                                                    </div>
                                                    <div style="color:#333; font-size:0.85rem;">
                                                        <?php 
                                                        if (!empty($ansRow['open_answer'])) {
                                                            echo '<span style="font-style: italic;">' . nl2br(htmlspecialchars($ansRow['open_answer'])) . '</span>';
                                                        } elseif (!empty($ansRow['option_label'])) {
                                                            echo htmlspecialchars($ansRow['option_label'] . '. ' . $ansRow['option_text']);
                                                        } else {
                                                            $score = (int)$ansRow['likert_value'];
                                                            $scoreText = "Score: $score";
                                                            if ($score === 5) $scoreText = "Strongly Agree (5)";
                                                            elseif ($score === 4) $scoreText = "Agree (4)";
                                                            elseif ($score === 3) $scoreText = "Neutral (3)";
                                                            elseif ($score === 2) $scoreText = "Disagree (2)";
                                                            elseif ($score === 1) $scoreText = "Strongly Disagree (1)";
                                                            echo htmlspecialchars($scoreText);
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recommendation Validation Section -->
                    <div class="review-card" id="recommendationValidationSection">
                        <div class="review-header" style="justify-content: space-between;">
                            <div>
                                <i class="fa-solid fa-check-double" style="color: #60a5fa;"></i> Recommendation Accuracy / Career Goal Alignment
                            </div>
                        </div>

                        <!-- Existing System-Generated Top 5 Recommendations & Evaluation -->
                        <div style="margin-bottom: 1.25rem;">
                            <div style="font-size: 0.85rem; font-weight: 600; color: #94a3b8; margin-bottom: 0.6rem; letter-spacing: 0.5px;">
                                System-Generated Top 5 Course Recommendations:
                            </div>
                            <?php if(empty($recommendations)): ?>
                                <p style="color:#94a3b8; font-size: 0.9rem;">No recommendations generated yet.</p>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap:0.75rem;">
                                    <?php foreach(array_slice($recommendations, 0, 5) as $rec): 
                                        $cid = $rec['course_id'];
                                        $eval = $existingEvals[$cid] ?? null;
                                    ?>
                                    <div class="rec-item" style="margin: 0; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <strong style="color:#f8fafc; font-size:1rem;">#<?php echo $rec['rank']; ?> <?php echo htmlspecialchars($rec['course_name']); ?></strong>
                                                <div style="font-size:0.8rem; color:#94a3b8;"><?php echo htmlspecialchars($rec['cluster_name']); ?></div>
                                            </div>
                                            <div style="color:#34d399; font-weight:700; font-size:1.1rem;"><?php echo round($rec['match_percentage'], 1); ?>%</div>
                                        </div>
                                        <div style="background: rgba(15, 23, 42, 0.8); border-radius: 8px; padding: 0.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                            <span style="color: #e2e8f0; font-size: 0.85rem; font-weight: 600;">Alignment with Career Goal:</span>
                                            <div style="display: flex; gap: 1rem;">
                                                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: #34d399;">
                                                    <input type="radio" name="evals[<?php echo $cid; ?>]" value="1" required form="verificationForm" <?php echo ($eval === 1 || $eval === '1') ? 'checked' : ''; ?>>
                                                    Aligned
                                                </label>
                                                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: #f87171;">
                                                    <input type="radio" name="evals[<?php echo $cid; ?>]" value="0" required form="verificationForm" <?php echo ($eval === 0 || $eval === '0') ? 'checked' : ''; ?>>
                                                    Not Aligned
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Verified Recommendations Section -->
                    <div class="review-card">
                        <div class="review-header">
                            <i class="fa-solid fa-list-ol" style="color: #fbbf24;"></i> Counselor-Verified Final Recommendations
                        </div>
                        <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 1rem;">Adjust the final recommendations to be released to the student. You can remove non-aligned courses, add other appropriate courses, and reorder them.</p>
                        
                        <div id="finalRecsContainer" style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1rem;">
                            <?php 
                            $finalList = !empty($verifiedRecs) ? array_column($verifiedRecs, 'course_id') : array_column(array_slice($recommendations, 0, 5), 'course_id');
                            foreach ($finalList as $idx => $fcid): 
                            ?>
                            <div class="final-rec-row" style="display: flex; gap: 10px; align-items: center;">
                                <span style="color: #e2e8f0; font-weight: 600; width: 20px;"><?php echo $idx + 1; ?>.</span>
                                <select name="final_recs[]" form="verificationForm" style="flex: 1; padding: 0.6rem; border-radius: 6px; background: rgba(15, 23, 42, 0.8); color: #f8fafc; border: 1px solid rgba(255,255,255,0.1);">
                                    <?php foreach ($allCourses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $fcid) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['course_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn-remove-rec" onclick="this.parentElement.remove(); updateFinalRanks();" style="background: rgba(239, 68, 68, 0.2); border: none; color: #fca5a5; padding: 0.5rem 0.8rem; border-radius: 6px; cursor: pointer;"><i class="fa-solid fa-times"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addFinalRecRow()" style="background: rgba(59, 130, 246, 0.2); border: 1px dashed #60a5fa; color: #93c5fd; padding: 0.6rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-plus"></i> Add Course
                        </button>
                        
                        <div style="margin-top: 1.25rem;">
                            <label style="color:#94a3b8; font-size:0.85rem; font-weight:600; display:block; margin-bottom: 0.4rem;">
                                Adjustment Reason (Required if modified):
                            </label>
                            <textarea name="adjustment_reason" id="adjustmentReason" form="verificationForm" placeholder="e.g. The student's stated career goal is software development, so the recommendations were adjusted toward IT..." style="width: 100%; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.1); color: #f8fafc; padding: 0.75rem; border-radius: 8px; min-height: 70px; font-family: inherit; resize: vertical; outline: none;"></textarea>
                            <script>
                                document.getElementById('adjustmentReason').value = <?php echo json_encode($assessment['adjustment_reason'] ?? ''); ?>;
                                
                                function addFinalRecRow() {
                                    const container = document.getElementById('finalRecsContainer');
                                    const allOptions = <?php echo json_encode(array_map(function($c){ return ['id'=>$c['id'], 'name'=>$c['course_name']]; }, $allCourses)); ?>;
                                    let optionsHtml = '';
                                    allOptions.forEach(c => {
                                        optionsHtml += `<option value="${c.id}">${c.name}</option>`;
                                    });
                                    const row = document.createElement('div');
                                    row.className = 'final-rec-row';
                                    row.style.display = 'flex';
                                    row.style.gap = '10px';
                                    row.style.alignItems = 'center';
                                    row.innerHTML = `
                                        <span class="rank-num" style="color: #e2e8f0; font-weight: 600; width: 20px;"></span>
                                        <select name="final_recs[]" form="verificationForm" style="flex: 1; padding: 0.6rem; border-radius: 6px; background: rgba(15, 23, 42, 0.8); color: #f8fafc; border: 1px solid rgba(255,255,255,0.1);">
                                            ${optionsHtml}
                                        </select>
                                        <button type="button" class="btn-remove-rec" onclick="this.parentElement.remove(); updateFinalRanks();" style="background: rgba(239, 68, 68, 0.2); border: none; color: #fca5a5; padding: 0.5rem 0.8rem; border-radius: 6px; cursor: pointer;"><i class="fa-solid fa-times"></i></button>
                                    `;
                                    container.appendChild(row);
                                    updateFinalRanks();
                                }
                                function updateFinalRanks() {
                                    const rows = document.querySelectorAll('.final-rec-row');
                                    rows.forEach((r, idx) => {
                                        r.querySelector('span').innerText = (idx + 1) + '.';
                                    });
                                }
                            </script>
                        </div>
                    </div>
                    
                    <!-- Verification Action Form (Accessible to both Counselor and Admin) -->
                    <div class="review-card">
                        <div class="review-header">
                            <i class="fa-solid fa-clipboard-check"></i> Verification Action
                        </div>

                        <?php if ($assessment['verification_status'] === 'verified'): ?>
                            <div style="background:rgba(16,185,129,0.1); border-left:4px solid #10b981; padding:0.85rem 1.25rem; border-radius:4px; margin-bottom:1.25rem; color:#6ee7b7;">
                                <i class="fa-solid fa-circle-check"></i> <strong>This assessment is currently Verified & Released.</strong>
                                <?php if (!empty($assessment['counselor_notes'])): ?>
                                    <div style="margin-top:0.4rem; color:#e2e8f0; font-size:0.9rem;">
                                        <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($assessment['counselor_notes'])); ?>
                                    </div>
                                <?php endif; ?>
                                <p style="margin:0.4rem 0 0 0; color:#94a3b8; font-size:0.85rem;">You can re-open and return it for revision if further changes are needed.</p>
                            </div>
                        <?php elseif ($assessment['verification_status'] === 'returned'): ?>
                            <div style="background:rgba(239,68,68,0.1); border-left:4px solid #ef4444; padding:0.85rem 1.25rem; border-radius:4px; margin-bottom:1.25rem; color:#fca5a5;">
                                <i class="fa-solid fa-rotate-left"></i> <strong>This assessment is currently Returned for Revision.</strong>
                                <?php if (!empty($assessment['counselor_notes'])): ?>
                                    <div style="margin-top:0.4rem; color:#e2e8f0; font-size:0.9rem;">
                                        <strong>Previous Instruction:</strong> <?php echo nl2br(htmlspecialchars($assessment['counselor_notes'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="verificationForm" class="actions-form">
                            <input type="hidden" name="action" id="formAction" value="verify">

                            <label style="color:#94a3b8; font-size:0.85rem; font-weight:600;" id="counselorNoteLabel">
                                Overall Note / Summary Instructions for Student (Optional for Verify, Required for General Return or "Needs Review"):
                            </label>
                            <textarea name="counselor_notes" id="counselorNotes" placeholder="e.g. Please review the flagged questions and provide detailed answers before your results can be released..."><?php echo htmlspecialchars($assessment['counselor_notes'] ?? ''); ?></textarea>
                            
                            <!-- Dynamic Flag Summary Banner -->
                            <div id="returnSummaryNotice" style="display:none; background:rgba(239,68,68,0.12); border-left:4px solid #ef4444; padding:0.85rem 1.25rem; border-radius:4px; color:#fca5a5; font-size:0.9rem;">
                                <i class="fa-solid fa-triangle-exclamation"></i> <strong id="flagCountText">0 answers flagged</strong> for revision. When you click Return, the student will be required to revise these specific questions.
                            </div>

                            <div class="actions-buttons" style="margin-top:0.5rem;">
                                <button type="button" id="btnVerify" class="btn-verify" onclick="submitVerification('verify')">
                                    <i class="fa-solid fa-check"></i> Verify & Release Results
                                </button>
                                <button type="button" id="btnReturn" class="btn-return" onclick="submitVerification('return')">
                                    <i class="fa-solid fa-rotate-left"></i> <span id="returnBtnLabel">Return for Revision</span>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script>
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        function toggleFlag(key) {
            const card = document.getElementById('card_' + key);
            const hiddenCheckbox = document.getElementById('chk_' + key);
            const btn = document.getElementById('flagBtn_' + key);
            const btnText = document.getElementById('flagText_' + key);
            const inputWrap = document.getElementById('feedback_wrap_' + key);
            const textarea = document.getElementById('note_' + key);

            const isFlagged = !hiddenCheckbox.checked;
            hiddenCheckbox.checked = isFlagged;

            if (isFlagged) {
                card.classList.add('is-flagged');
                btn.classList.add('active');
                btnText.textContent = 'Flagged for Revision';
                inputWrap.style.display = 'block';
                textarea.focus();
            } else {
                card.classList.remove('is-flagged');
                btn.classList.remove('active');
                btnText.textContent = 'Flag for Revision';
                inputWrap.style.display = 'none';
            }

            updateReturnSummary();
        }

        function updateReturnSummary() {
            const allChecked = document.querySelectorAll('.flag-hidden-input:checked');
            const count = allChecked.length;
            const notice = document.getElementById('returnSummaryNotice');
            const countText = document.getElementById('flagCountText');
            const returnBtnLabel = document.getElementById('returnBtnLabel');
            const btnReturn = document.getElementById('btnReturn');

            if (count > 0) {
                if (notice) notice.style.display = 'block';
                if (countText) countText.textContent = count + ' answer' + (count > 1 ? 's' : '') + ' flagged';
                if (returnBtnLabel) returnBtnLabel.textContent = 'Return ' + count + ' Flagged Answer' + (count > 1 ? 's' : '') + ' for Revision';
                if (btnReturn) {
                    btnReturn.style.background = '#ef4444';
                    btnReturn.style.color = '#ffffff';
                }
            } else {
                if (notice) notice.style.display = 'none';
                if (returnBtnLabel) returnBtnLabel.textContent = 'Return for Revision';
                if (btnReturn) {
                    btnReturn.style.background = '';
                    btnReturn.style.color = '';
                }
            }
        }

        function onValidationChange(val) {
            const labelAppropriate = document.getElementById('labelValAppropriate');
            const labelNeedsReview = document.getElementById('labelValNeedsReview');
            const notice = document.getElementById('needsReviewNotice');
            const notes = document.getElementById('counselorNotes');
            const noteLabel = document.getElementById('counselorNoteLabel');

            if (val === 'appropriate') {
                if (labelAppropriate) labelAppropriate.classList.add('selected-appropriate');
                if (labelNeedsReview) labelNeedsReview.classList.remove('selected-needs-review');
                if (notice) notice.style.display = 'none';
                if (noteLabel) {
                    noteLabel.textContent = 'Overall Note / Summary Instructions for Student (Optional for Verify, Required for General Return):';
                }
            } else if (val === 'needs_review') {
                if (labelNeedsReview) labelNeedsReview.classList.add('selected-needs-review');
                if (labelAppropriate) labelAppropriate.classList.remove('selected-appropriate');
                if (notice) notice.style.display = 'block';
                if (noteLabel) {
                    noteLabel.innerHTML = '<span style="color:#fbbf24;"><i class="fa-solid fa-triangle-exclamation"></i> Counselor Note / Concern Explanation (REQUIRED for "Needs Review"):</span>';
                }
                if (notes && (!notes.value || notes.value.trim().length === 0)) {
                    notes.placeholder = 'Please explain your concern (e.g. The recommended course does not sufficiently align with the student\'s expressed career interest)...';
                }
            }
        }

        function submitVerification(action) {
            const form = document.getElementById('verificationForm');
            const formAction = document.getElementById('formAction');
            const notes = document.getElementById('counselorNotes');
            const allChecked = document.querySelectorAll('.flag-hidden-input:checked');

            formAction.value = action;

            if (action === 'return') {
                if (allChecked.length === 0 && (!notes.value || notes.value.trim().length === 0)) {
                    alert('Please either flag at least one question for revision using the "Flag for Revision" buttons, or provide overall revision instructions in the note box.');
                    notes.focus();
                    return;
                }
                const promptMsg = allChecked.length > 0 
                    ? 'Return this assessment to the student with ' + allChecked.length + ' answer(s) flagged for revision?'
                    : 'Return this entire assessment to the student for revision?';
                if (!confirm(promptMsg)) {
                    return;
                }
            } else if (action === 'verify') {
                const valAppropriate = document.getElementById('val_appropriate');
                const valNeedsReview = document.getElementById('val_needs_review');
                
                let selectedVal = null;
                if (valAppropriate && valAppropriate.checked) selectedVal = 'appropriate';
                if (valNeedsReview && valNeedsReview.checked) selectedVal = 'needs_review';

                if (!selectedVal) {
                    alert('Please complete the Recommendation Validation section by selecting either "Appropriate" or "Needs Review" before verifying.');
                    const valSection = document.getElementById('recommendationValidationSection');
                    if (valSection) valSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                if (selectedVal === 'needs_review') {
                    if (!notes.value || notes.value.trim().length === 0) {
                        alert('Because you selected "Needs Review", please provide an explanation in the Counselor Note field explaining your concern (e.g. course mismatch with expressed interest).');
                        notes.focus();
                        return;
                    }
                }

                if (allChecked.length > 0) {
                    if (!confirm('You currently have ' + allChecked.length + ' answer(s) flagged for revision. Clicking Verify will APPROVE the assessment and release the results to the student. Do you want to proceed?')) {
                        return;
                    }
                } else {
                    if (!confirm('Verify and release this assessment results to the student?')) {
                        return;
                    }
                }
            }

            form.submit();
        }
    
        // Initialize flag count and validation UI on load
        document.addEventListener('DOMContentLoaded', function() {
            updateReturnSummary();
            const checkedVal = document.querySelector('input[name="recommendation_validation"]:checked');
            if (checkedVal) {
                onValidationChange(checkedVal.value);
            }
        });
    </script>
</body>
</html>
