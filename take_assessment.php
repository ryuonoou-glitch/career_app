<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

$student = getCurrentStudent();

$studentName = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);
$studentId = $student['id'] ?? 0;

// Read-only guard: deactivated Grade 12 students cannot take new assessments
if (!empty($student['is_readonly'])) {
    header('Location: dashboard.php?readonly=1');
    exit;
}

// Assessment Limit: Maximum 2 assessments allowed
$MAX_ASSESSMENTS = 2;

// Auto-expire abandoned assessments (24 hours timeout)
$expireStmt = $mysqli->prepare("
    UPDATE student_assessments 
    SET status = 'abandoned' 
    WHERE status = 'in_progress' 
      AND (
          (expires_at IS NOT NULL AND expires_at < NOW())
          OR 
          (expires_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
      )
");
if ($expireStmt) {
    $expireStmt->execute();
    $expireStmt->close();
}


// Get current school year safely
$yearResult = $mysqli->query("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
$yearRow = $yearResult ? $yearResult->fetch_assoc() : null;
$schoolYearId = $yearRow ? (int)$yearRow['id'] : 1;

// Fetch saved preferred region and medical declaration for the current school year
$savedPreferredRegion = null;
$savedMedicalDeclaration = null;
if ($studentId > 0) {
    $stmt = $mysqli->prepare("SELECT preferred_region, medical_declaration FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status = 'in_progress' AND preferred_region IS NOT NULL LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $studentId, $schoolYearId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $savedPreferredRegion = $res['preferred_region'] ?? null;
        if (!empty($res['medical_declaration'])) {
            $savedMedicalDeclaration = json_decode($res['medical_declaration'], true);
        }
        $stmt->close();
    }
}


// Check how many assessments student has completed for the current school year
$completedCount = 0;
if ($studentId > 0) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status = 'completed'");
    $stmt->bind_param("ii", $studentId, $schoolYearId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $completedCount = $result['count'] ?? 0;
    $stmt->close();
}

$remainingAssessments = $MAX_ASSESSMENTS - $completedCount;
$hasReachedLimit = $remainingAssessments <= 0;

// Fetch question counts from database
function getQuestionCount($mysqli, $table) {
    $result = $mysqli->query("SELECT COUNT(*) as count FROM {$table} WHERE is_active = 1");
    return $result->fetch_assoc()['count'] ?? 0;
}

$careerCount = getQuestionCount($mysqli, 'questions_career');
$personalityCount = getQuestionCount($mysqli, 'questions_personality');
$skillsCount = getQuestionCount($mysqli, 'questions_skills');

// Strand count depends on Grade Level
$gradeLevelStr = $student['grade_level'] ?? 'Grade 12';
$gradeLevelNum = $gradeLevelStr === 'Grade 11' ? 11 : 12;

$stmtCount = $mysqli->prepare("
    SELECT COUNT(*) as count 
    FROM questions_strand qs
    LEFT JOIN strands s ON qs.strand_id = s.id
    WHERE qs.is_active = 1 AND (s.grade_level = ? OR qs.strand_id IS NULL)
");
$stmtCount->bind_param("i", $gradeLevelNum);
$stmtCount->execute();
$strandCountRes = $stmtCount->get_result()->fetch_assoc();
$strandCount = $strandCountRes['count'] ?? 0;
$stmtCount->close();

// Fetch questions from each category for the assessment.
// Open-ended questions are always placed last and the total per category is capped at 20.
function fetchQuestions($mysqli, $table, $limit = 20, $seed = 1) {
    $limit = (int) $limit;
    $limit = max(1, min(20, $limit));
    $openEnded = [];
    $nonOpenEnded = [];

    // Open-ended question: always the same for all students (fixed order by id, not randomized)
    $openResult = $mysqli->query("SELECT * FROM {$table} WHERE is_active = 1 AND question_type = 'open-ended' ORDER BY id ASC LIMIT 1");
    if ($openResult) {
        while ($row = $openResult->fetch_assoc()) {
            $openEnded[] = $row;
        }
    }

    // We always reserve 1 slot for the open-ended question. Do not substitute if missing.
    $nonOpenLimit = max(0, $limit - 1);
    if ($nonOpenLimit > 0) {
        $result = $mysqli->query("SELECT * FROM {$table} WHERE is_active = 1 AND question_type <> 'open-ended' ORDER BY RAND({$seed}) LIMIT {$nonOpenLimit}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $nonOpenEnded[] = $row;
            }
        }
    }

    return array_merge($nonOpenEnded, $openEnded);
}

function mapQuestionTypeForClient($questionType) {
    switch ($questionType) {
        case 'objective':
            return 'radio';
        case 'open-ended':
            return 'text';
        default:
            return $questionType ?: 'likert';
    }
}

// Calculate deterministic seed based on student ID and completed attempts.
// This ensures Attempt 2 shuffles differently, while preventing page refreshes from breaking the in-progress attempt order.
$questionSeed = ($studentId * 10) + $completedCount;

// Fetch questions for each category (capped at 20 questions per category)
$careerQuestions = fetchQuestions($mysqli, 'questions_career', 20, $questionSeed);

// Fetch exactly 19 Likert questions and 1 Open-ended question for Personality
$personalityLikert = [];
$personalityOpenEnded = [];
$traits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];

// 1. Fetch 1 Open-ended question deterministically
$stmtOpen = $mysqli->prepare("
    SELECT * FROM questions_personality 
    WHERE is_active = 1 AND question_type = 'open-ended' 
    ORDER BY RAND(?) LIMIT 1
");
if ($stmtOpen) {
    $stmtOpen->bind_param('i', $questionSeed);
    $stmtOpen->execute();
    if ($resOpen = $stmtOpen->get_result()) {
        if ($row = $resOpen->fetch_assoc()) {
            $personalityOpenEnded[] = $row;
        }
    }
    $stmtOpen->close();
}

// 2. Fetch Likert questions. To get exactly 19, one trait gets 3, others get 4.
$traitWithThree = $questionSeed % 5;

foreach ($traits as $index => $trait) {
    $limit = ($index === $traitWithThree) ? 3 : 4;
    $traitSeed = $questionSeed + $index;
    $stmtTrait = $mysqli->prepare("
        SELECT * FROM questions_personality 
        WHERE is_active = 1 AND question_type = 'likert' AND trait = ? 
        ORDER BY RAND(?) LIMIT ?
    ");
    if ($stmtTrait) {
        $stmtTrait->bind_param('sii', $trait, $traitSeed, $limit);
        $stmtTrait->execute();
        $resTrait = $stmtTrait->get_result();
        while ($row = $resTrait->fetch_assoc()) {
            $personalityLikert[] = $row;
        }
        $stmtTrait->close();
    }
}
// Shuffle Likert questions so they aren't grouped by trait, keeping open-ended at the end
// Use the same seed to ensure deterministic shuffling
mt_srand($questionSeed);
shuffle($personalityLikert);
mt_srand(); // Reset to avoid affecting other rand calls

// Always place open-ended question last
$personalityQuestions = array_merge($personalityLikert, $personalityOpenEnded);

$skillsQuestions = fetchQuestions($mysqli, 'questions_skills', 20, $questionSeed);

// Strand questions depend on Grade Level
$strandQuestions = [];
$strandLimit = 20;
$openEndedStrand = [];
$nonOpenEndedStrand = [];

$stmtOpen = $mysqli->prepare("
    SELECT qs.* 
    FROM questions_strand qs
    LEFT JOIN strands s ON qs.strand_id = s.id
    WHERE qs.is_active = 1 AND qs.question_type = 'open-ended' AND (s.grade_level = ? OR qs.strand_id IS NULL)
    ORDER BY RAND(?) LIMIT 1
");
$stmtOpen->bind_param("ii", $gradeLevelNum, $questionSeed);
$stmtOpen->execute();
$resOpen = $stmtOpen->get_result();
while ($row = $resOpen->fetch_assoc()) {
    $openEndedStrand[] = $row;
}
$stmtOpen->close();

// We always reserve 1 slot for the open-ended question. Do not substitute if missing.
$nonOpenLimitStrand = max(0, $strandLimit - 1);
if ($nonOpenLimitStrand > 0) {
    $stmtNonOpen = $mysqli->prepare("
        SELECT qs.* 
        FROM questions_strand qs
        JOIN strands s ON qs.strand_id = s.id
        WHERE qs.is_active = 1 AND qs.question_type <> 'open-ended' AND s.grade_level = ?
        ORDER BY RAND(?) LIMIT ?
    ");
    $stmtNonOpen->bind_param("iii", $gradeLevelNum, $questionSeed, $nonOpenLimitStrand);
    $stmtNonOpen->execute();
    $resNonOpen = $stmtNonOpen->get_result();
    while ($row = $resNonOpen->fetch_assoc()) {
        $nonOpenEndedStrand[] = $row;
    }
    $stmtNonOpen->close();
}
$strandQuestions = array_merge($nonOpenEndedStrand, $openEndedStrand);

$skillsQuestionIds = array_map(static fn($q) => (int) $q['id'], $skillsQuestions);
$strandQuestionIds = array_map(static fn($q) => (int) $q['id'], $strandQuestions);
$skillsOptionsMap = fetchQuestionOptionsMap($mysqli, 'skills', $skillsQuestionIds);
$strandOptionsMap = fetchQuestionOptionsMap($mysqli, 'strand', $strandQuestionIds);

// Build questions array for JavaScript
function buildQuestionArray($questions, $category, $optionsMap = []) {
    $result = [];
    foreach ($questions as $q) {
        $questionId = (int) $q['id'];
        $item = [
            'id' => $questionId,
            'question' => $q['question_text'],
            'type' => mapQuestionTypeForClient($q['question_type'] ?? 'likert'),
        ];

        if ($category === 'personality' && isset($q['trait'])) {
            $item['trait'] = $q['trait'];
        }
        if (($category === 'career' || $category === 'strand') && !empty($q['strand_id'])) {
            $item['strand_id'] = (int) $q['strand_id'];
        }

        if ($category === 'skills' && !empty($q['competency_id'])) {
            $item['competency_id'] = (int) $q['competency_id'];
        }

        if (in_array($category, ['skills', 'strand'], true) && isset($optionsMap[$questionId])) {
            $item['options'] = array_map(static function ($option) {
                return $option['option_text'];
            }, $optionsMap[$questionId]);
            $item['option_map'] = array_map(static function ($option) {
                return [
                    'id' => (int) $option['id'],
                    'label' => $option['option_label'],
                    'text' => $option['option_text'],
                    'is_correct' => (int) $option['is_correct'],
                ];
            }, $optionsMap[$questionId]);
        }

        $result[] = $item;
    }
    return $result;
}

$jsCareerQuestions = buildQuestionArray($careerQuestions, 'career');
$jsPersonalityQuestions = buildQuestionArray($personalityQuestions, 'personality');
$jsSkillsQuestions = buildQuestionArray($skillsQuestions, 'skills', $skillsOptionsMap);
$jsStrandQuestions = buildQuestionArray($strandQuestions, 'strand', $strandOptionsMap);

// Retrieve active in-progress assessment from MySQL for cross-device/cross-browser seamless resumption
$activeAssessment = null;
$activeAssessmentAnswers = [];
$activeAssessmentData = null;

if ($studentId > 0 && !$hasReachedLimit) {
    $activeStmt = $mysqli->prepare("
        SELECT id, region, preferred_region, medical_declaration, created_at, started_at, expires_at 
        FROM student_assessments 
        WHERE student_id = ? 
          AND school_year_id = ? 
          AND status = 'in_progress' 
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY id DESC 
        LIMIT 1
    ");
    if ($activeStmt) {
        $activeStmt->bind_param("ii", $studentId, $schoolYearId);
        $activeStmt->execute();
        $activeAssessment = $activeStmt->get_result()->fetch_assoc();
        $activeStmt->close();
    }

    if ($activeAssessment) {
        $ansStmt = $mysqli->prepare("
            SELECT question_type, question_id, selected_option_id, likert_value, open_answer 
            FROM student_answers 
            WHERE assessment_id = ?
        ");
        if ($ansStmt) {
            $ansStmt->bind_param("i", $activeAssessment['id']);
            $ansStmt->execute();
            $ansRes = $ansStmt->get_result();
            while ($ansRow = $ansRes->fetch_assoc()) {
                $qType = $ansRow['question_type'];
                $qId = (int)$ansRow['question_id'];
                $val = null;

                if ($ansRow['likert_value'] !== null) {
                    $val = (string)$ansRow['likert_value'];
                } elseif ($ansRow['selected_option_id'] !== null) {
                    $optId = (int)$ansRow['selected_option_id'];
                    $optMap = ($qType === 'skills') ? $skillsOptionsMap : (($qType === 'strand') ? $strandOptionsMap : []);
                    if (isset($optMap[$qId])) {
                        foreach ($optMap[$qId] as $opt) {
                            if ((int)$opt['id'] === $optId) {
                                $val = $opt['option_text'];
                                break;
                            }
                        }
                    }
                    if ($val === null) {
                        $val = (string)$optId;
                    }
                } elseif ($ansRow['open_answer'] !== null && $ansRow['open_answer'] !== '') {
                    $decoded = json_decode($ansRow['open_answer'], true);
                    if (is_array($decoded) && isset($decoded['option_id'])) {
                        $optId = (int)$decoded['option_id'];
                        $optMap = ($qType === 'skills') ? $skillsOptionsMap : (($qType === 'strand') ? $strandOptionsMap : []);
                        if (isset($optMap[$qId])) {
                            foreach ($optMap[$qId] as $opt) {
                                if ((int)$opt['id'] === $optId) {
                                    $val = $opt['option_text'];
                                    break;
                                }
                            }
                        }
                        if ($val === null) {
                            $val = (string)$optId;
                        }
                    } else {
                        $val = $ansRow['open_answer'];
                    }
                }

                if ($val !== null) {
                    $activeAssessmentAnswers[$qType . '_' . $qId] = $val;
                }
            }
            $ansStmt->close();
        }

        // Determine which category and question to resume
        $assessmentSequence = ['career', 'personality', 'skills', 'strand'];
        $allQuestionsByCategory = [
            'career' => $jsCareerQuestions,
            'personality' => $jsPersonalityQuestions,
            'skills' => $jsSkillsQuestions,
            'strand' => $jsStrandQuestions
        ];

        $resumeCategoryIndex = 0;
        $resumeQuestionIndex = 0;
        $foundUnanswered = false;

        foreach ($assessmentSequence as $catIdx => $catName) {
            $qList = $allQuestionsByCategory[$catName] ?? [];
            foreach ($qList as $qIdx => $q) {
                $qKey = $catName . '_' . $q['id'];
                if (!isset($activeAssessmentAnswers[$qKey])) {
                    $resumeCategoryIndex = $catIdx;
                    $resumeQuestionIndex = $qIdx;
                    $foundUnanswered = true;
                    break 2;
                }
            }
        }

        if (!$foundUnanswered) {
            $resumeCategoryIndex = count($assessmentSequence) - 1;
            $lastCat = $assessmentSequence[$resumeCategoryIndex];
            $resumeQuestionIndex = max(0, count($allQuestionsByCategory[$lastCat] ?? []) - 1);
        }

        $activeAssessmentData = [
            'assessmentId' => (int)$activeAssessment['id'],
            'region' => $activeAssessment['region'] ?: ($activeAssessment['preferred_region'] ?: 'All'),
            'currentAssessmentIndex' => $resumeCategoryIndex,
            'type' => $assessmentSequence[$resumeCategoryIndex],
            'currentQuestion' => $resumeQuestionIndex,
            'answers' => (object)$activeAssessmentAnswers,
            'savedAt' => $activeAssessment['created_at'],
            'medicalDeclaration' => !empty($activeAssessment['medical_declaration']) ? json_decode($activeAssessment['medical_declaration'], true) : null,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Assessment - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css?v=<?php echo filemtime('user.css'); ?>">
    <style>
        /* Assessment Limit Styles */
        .assessment-limit-banner {
            background: #1a2440;
            color: #c5cbdb;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 0 2rem 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .assessment-limit-banner.limit-reached {
            background: #2d1f1f;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .assessment-limit-banner.limit-warning {
            background: #2a2015;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        .assessment-limit-banner .limit-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .assessment-limit-banner .limit-info i {
            font-size: 1.5rem;
            color: #f5a623; /* Accent yellow only on the icon */
        }
        .assessment-limit-banner .limit-text {
            font-weight: 600;
            font-size: 1rem;
        }
        .assessment-limit-banner .limit-count {
            background: rgba(245, 166, 35, 0.1);
            color: #f5a623;
            border: 1px solid rgba(245, 166, 35, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .assessment-type-card.disabled {
            opacity: 0.5;
            pointer-events: none;
            filter: grayscale(0.8);
        }
        .assessment-type-card .limit-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #f56565;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .assessment-type-card {
            position: relative;
        }
        .region-selector {
            margin: 1rem 0 1.25rem;
            padding: 1rem 1.1rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        }
        .region-selector label {
            display: block;
            margin-bottom: 0.45rem;
            font-weight: 700;
            color: #334155;
        }
        .region-selector select {
            width: 100%;
            padding: 0.8rem 0.95rem;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .region-selector select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }
        .modal-medical-option-label {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            color: #cbd5e1;
            font-size: 0.93rem;
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .modal-medical-option-label:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
        }
        .modal-medical-option-label input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 1.15rem;
            height: 1.15rem;
            cursor: pointer;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <!-- Assessment Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <?php echo getSystemLogo('logo-icon'); ?>
                    <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
                </div>
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Close sidebar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <i class="fa-solid fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="take_assessment.php" class="nav-item active">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>Take Assessment</span>
                </a>
                <a href="assessment_results.php" class="nav-item">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Assessment Results</span>
                </a>
                <a href="recommended_courses.php" class="nav-item">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Recommendations</span>
                </a>
                <a href="profile.php" class="nav-item">
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="logout.php?role=student" class="nav-item logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Mobile sidebar overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Take Assessment</h1>
                </div>
                <div class="top-bar-actions">
                    <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                    <div class="user-profile">
                        <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar">
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo $studentName; ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Assessment Content -->
            <div class="assessment-content">
                <!-- Assessment Limit Banner -->
                <div class="assessment-limit-banner <?php echo $hasReachedLimit ? 'limit-reached' : ($remainingAssessments == 1 ? 'limit-warning' : ''); ?>" id="assessmentLimitBanner">
                    <div class="limit-info">
                        <i class="fa-solid <?php echo $hasReachedLimit ? 'fa-circle-exclamation' : 'fa-info-circle'; ?>"></i>
                        <span class="limit-text">
                            <?php if ($hasReachedLimit): ?>
                                You have used all your assessments (2/2 completed)
                            <?php elseif ($remainingAssessments == 1): ?>
                                You have <strong>1 assessment remaining</strong> (1/2 completed)
                            <?php else: ?>
                                You have <strong><?php echo $remainingAssessments; ?> assessments remaining</strong> (0/2 completed)
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span class="limit-count"><?php echo $completedCount; ?>/<?php echo $MAX_ASSESSMENTS; ?></span>
                        <button id="dismissBannerBtn" aria-label="Dismiss" title="Dismiss" style="background: none; border: none; color: #94a3b8; font-size: 1.25rem; cursor: pointer; transition: color 0.2s; padding: 0.25rem; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.color='#f1f5f9'" onmouseout="this.style.color='#94a3b8'">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Assessment Type Selection -->
                <div class="assessment-type-selection" id="assessmentTypeSelection" <?php echo !empty($activeAssessmentData) ? 'style="display: none;"' : ''; ?>>
                    <div class="selection-header">
                        <h2><?php echo $hasReachedLimit ? 'Assessment Limit Reached' : 'Career Assessment'; ?></h2>
                        <p><?php echo $hasReachedLimit ? 'You have completed the maximum allowed assessments. View your results below.' : 'Complete the following four assessment categories'; ?></p>
                    </div>
                    <div class="assessment-types">
                        <div class="assessment-type-card <?php echo ($hasReachedLimit || $careerCount === 0) ? 'disabled' : ''; ?>">
                            <?php if ($hasReachedLimit): ?><span class="limit-badge">Limit Reached</span><?php endif; ?>
                            <div class="type-icon">
                                <i class="fa-solid fa-briefcase"></i>
                            </div>
                            <div class="type-info">
                                <div class="type-title-row">
                                    <h3>Career Interest Assessment</h3>
                                    <span class="question-count">20 questions</span>
                                </div>
                                <p>Based on the Holland RIASEC model</p>
                            </div>
                        </div>
                        <div class="assessment-type-card <?php echo ($hasReachedLimit || $personalityCount === 0) ? 'disabled' : ''; ?>">
                            <?php if ($hasReachedLimit): ?><span class="limit-badge">Limit Reached</span><?php endif; ?>
                            <div class="type-icon">
                                <i class="fa-solid fa-user-circle"></i>
                            </div>
                            <div class="type-info">
                                <div class="type-title-row">
                                    <h3>Personality Assessment</h3>
                                    <span class="question-count">20 questions</span>
                                </div>
                                <p>Measures personality traits</p>
                            </div>
                        </div>
                        <div class="assessment-type-card <?php echo ($hasReachedLimit || $skillsCount === 0) ? 'disabled' : ''; ?>">
                            <?php if ($hasReachedLimit): ?><span class="limit-badge">Limit Reached</span><?php endif; ?>
                            <div class="type-icon">
                                <i class="fa-solid fa-tools"></i>
                            </div>
                            <div class="type-info">
                                <div class="type-title-row">
                                    <h3>Skills Assessment</h3>
                                    <span class="question-count">20 questions</span>
                                </div>
                                <p>Assesses skills and abilities</p>
                            </div>
                        </div>
                        <div class="assessment-type-card <?php echo ($hasReachedLimit || $strandCount === 0) ? 'disabled' : ''; ?>">
                            <?php if ($hasReachedLimit): ?><span class="limit-badge">Limit Reached</span><?php endif; ?>
                            <div class="type-icon">
                                <i class="fa-solid fa-school"></i>
                            </div>
                            <div class="type-info">
                                <div class="type-title-row">
                                    <h3>Strand Assessment</h3>
                                    <span class="question-count">20 questions</span>
                                </div>
                                <p>Assesses strand-related aptitude</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="assessment-notice" style="text-align: center; margin-top: 1.25rem; color: #94a3b8; font-size: 0.88rem; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.18); border-radius: 10px; padding: 0.65rem 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i class="fa-solid fa-circle-info" style="color: #60a5fa; font-size: 0.95rem; flex-shrink: 0;"></i>
                        <span>All four assessment categories are required to generate your complete assessment results and course recommendations.</span>
                    </div>

                    <div id="regionSelectionContainer" style="margin-top: 1.5rem;"></div>
                </div>

                <!-- Assessment Questions -->
                <div class="assessment-questions" id="assessmentQuestions" <?php echo !empty($activeAssessmentData) ? 'style="display: block;"' : 'style="display: none;"'; ?>>


                    <!-- Question Card -->
                    <div class="question-card" id="questionCard">
                        <div class="question-header">
                            <span class="assessment-category-info" id="assessmentCategoryInfo">Career Assessment &middot; 1 of 4</span>
                            <span class="question-progress-counter" id="questionProgressCounter">Loading questions...</span>
                        </div>
                        <div class="question-text" id="questionText">
                            Loading question...
                        </div>
                        <div class="question-options" id="questionOptions">
                            <!-- Options will be dynamically loaded -->
                        </div>
                        <div class="question-actions">
                            <button class="btn-back" id="btnBack">
                                <i class="fa-solid fa-arrow-left"></i>
                                Back
                            </button>
                            <button class="btn-next" id="btnNext">
                                Next
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                            <button class="btn-submit" id="btnSubmit" style="display: none;">
                                <i class="fa-solid fa-check"></i>
                                Submit Assessment
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Assessment Results Preview -->
                <div class="results-preview" id="resultsPreview" style="display: none;">
                    <div class="preview-header">
                        <div class="success-icon">
                            <i class="fa-solid fa-check-circle"></i>
                        </div>
                        <h2>Assessment Completed!</h2>
                        <p>Your assessment has been submitted successfully. Your results are being analyzed.</p>
                    </div>
                    <div class="preview-actions">
                        <a href="assessment_results.php" class="btn-view-results" id="btnViewResults">
                            <i class="fa-solid fa-chart-line"></i>
                            View Results
                        </a>
                        <button class="btn-take-another" id="btnTakeAnother">
                            <i class="fa-solid fa-plus-circle"></i>
                            Take Another Assessment
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pre-Assessment Medical & Physical Considerations Modal -->
            <div class="modal" id="medicalDeclarationModal" tabindex="-1" role="dialog" aria-labelledby="medModalTitle" aria-hidden="true">
                <div class="modal-overlay" id="medicalModalOverlay"></div>
                <div class="modal-content" style="max-width: 620px; border: 1px solid rgba(245, 158, 11, 0.25); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
                    <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="background: rgba(239, 68, 68, 0.12); color: #ef4444; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                                <i class="fa-solid fa-notes-medical"></i>
                            </div>
                            <div>
                                <h3 id="medModalTitle" style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 0; line-height: 1.3;">Medical &amp; Physical Considerations</h3>
                                <p style="font-size: 0.8rem; color: #94a3b8; margin: 0.2rem 0 0 0;">Pre-assessment declaration for career guidance</p>
                            </div>
                        </div>
                        <button type="button" class="modal-close" id="closeMedicalModalBtn" aria-label="Close modal" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer; padding: 0.25rem 0.5rem; line-height: 1;">&times;</button>
                    </div>
                    
                    <div class="modal-body" style="padding: 1.5rem; overflow-y: auto; max-height: calc(85vh - 140px);">
                        <p style="color: #94a3b8; font-size: 0.92rem; line-height: 1.6; margin-bottom: 1.25rem;">
                            This section is for career-guidance purposes only. It does not determine medical fitness or diagnose any condition. Some courses and careers may have specific medical, vision, hearing, or physical requirements.
                        </p>

                        <div style="background: rgba(30, 41, 59, 0.55); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem;">
                            <p style="font-weight: 600; color: #f1f5f9; margin-bottom: 1rem; font-size: 0.95rem;">
                                Which of the following health or physical considerations may apply to you?
                            </p>
                            <div class="modal-medical-options" style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <label class="modal-medical-option-label">
                                    <input type="checkbox" name="modal_med_consideration" value="color_perception">
                                    <span>Difficulty distinguishing certain colors</span>
                                </label>
                                <label class="modal-medical-option-label">
                                    <input type="checkbox" name="modal_med_consideration" value="vision">
                                    <span>Difficulty seeing clearly</span>
                                </label>
                                <label class="modal-medical-option-label">
                                    <input type="checkbox" name="modal_med_consideration" value="hearing">
                                    <span>Difficulty hearing</span>
                                </label>
                                <label class="modal-medical-option-label">
                                    <input type="checkbox" name="modal_med_consideration" value="physical_limitation">
                                    <span>Physical condition that limits strenuous activity</span>
                                </label>
                                <label class="modal-medical-option-label">
                                    <input type="checkbox" name="modal_med_consideration" value="other" id="modalMedOtherCheckbox">
                                    <span>Other health or physical consideration</span>
                                </label>

                                <div id="modalMedOtherDescContainer" style="display: none; margin-top: 0.25rem; margin-left: 2rem;">
                                    <label for="modalMedOtherDesc" style="display: block; color: #94a3b8; font-size: 0.82rem; margin-bottom: 0.35rem;">Please briefly describe the consideration:</label>
                                    <input type="text" id="modalMedOtherDesc" maxlength="255" placeholder="e.g., Asthma, mobility assistance, etc." style="width: 100%; padding: 0.65rem 0.85rem; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 8px; color: #f1f5f9; font-size: 0.9rem;">
                                </div>

                                <label class="modal-medical-option-label" style="margin-top: 0.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 0.85rem;">
                                    <input type="checkbox" name="modal_med_consideration" value="none" id="modalMedNoneCheckbox">
                                    <span>None of the above</span>
                                </label>
                            </div>
                        </div>

                        <div style="background: rgba(245, 158, 11, 0.06); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 12px; padding: 1.15rem;">
                            <label style="display: flex; gap: 0.85rem; cursor: pointer; align-items: flex-start;">
                                <input type="checkbox" id="modalMedAckCheckbox" style="margin-top: 0.2rem; accent-color: #f59e0b; width: 1.15rem; height: 1.15rem; flex-shrink: 0; cursor: pointer;">
                                <span style="font-size: 0.85rem; color: #cbd5e1; line-height: 1.55;">
                                    I understand that my answers are for career-guidance purposes only and do not determine whether I am medically qualified for any course, training program, license, or career. I understand that I may need to consult my Guidance Counselor and an appropriate medical professional regarding specific requirements.
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer" style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding: 1rem 1.5rem; display: flex; justify-content: flex-end; gap: 0.75rem; background: rgba(15, 23, 42, 0.4);">
                        <button type="button" id="cancelMedicalModalBtn" style="background: rgba(255, 255, 255, 0.06); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.12); padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">Cancel</button>
                        <button type="button" id="confirmMedicalModalBtn" disabled style="background: #f59e0b; color: #0f172a; border: none; padding: 0.65rem 1.4rem; border-radius: 8px; font-weight: 700; cursor: not-allowed; opacity: 0.5; transition: all 0.2s;">Save &amp; Continue</button>
                    </div>
                </div>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script>
        // Student ID for API calls
        window.studentId = <?php echo (int)$studentId; ?>;
        window.savedPreferredRegion = <?php echo json_encode($savedPreferredRegion); ?>;
        window.savedMedicalDeclaration = <?php echo json_encode($savedMedicalDeclaration); ?>;
        window.serverActiveAssessment = <?php echo json_encode($activeAssessmentData); ?>;
        
        // Dynamic assessment questions from database (20 questions per category, up to 80 total)
        window.assessmentQuestionsFromDB = {
            career: <?php echo json_encode($jsCareerQuestions); ?>,
            personality: <?php echo json_encode($jsPersonalityQuestions); ?>,
            skills: <?php echo json_encode($jsSkillsQuestions); ?>,
            strand: <?php echo json_encode($jsStrandQuestions); ?>
        };
        
        // Assessment Limit Configuration
        window.assessmentLimit = {
            maxAssessments: <?php echo $MAX_ASSESSMENTS; ?>,
            completedCount: <?php echo $completedCount; ?>,
            remainingCount: <?php echo $remainingAssessments; ?>,
            hasReachedLimit: <?php echo $hasReachedLimit ? 'true' : 'false'; ?>
        };
        
        // Check limit before starting assessment
        window.checkAssessmentLimit = function() {
            if (window.assessmentLimit.hasReachedLimit) {
                alert('You have reached the maximum limit of ' + window.assessmentLimit.maxAssessments + ' assessments.');
                return false;
            }
            return true;
        };

        // Banner dismiss logic
        document.addEventListener('DOMContentLoaded', function() {
            const banner = document.getElementById('assessmentLimitBanner');
            const dismissBtn = document.getElementById('dismissBannerBtn');
            
            if (banner && dismissBtn) {
                if (sessionStorage.getItem('assessmentBannerDismissed') === 'true') {
                    banner.style.display = 'none';
                }
                
                dismissBtn.addEventListener('click', function() {
                    banner.style.display = 'none';
                    sessionStorage.setItem('assessmentBannerDismissed', 'true');
                });
            }
        });
        
        // Start assessment on server
        window.startAssessmentOnServer = async function(type, region, medicalDeclaration) {
            if (!window.studentId) {
                alert('You must be logged in to start an assessment');
                return null;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'create');
                formData.append('student_id', window.studentId);
                formData.append('type', type);
                formData.append('region', region || '');
                formData.append('preferred_region', region || '');
                
                if (medicalDeclaration) {
                    formData.append('medical_declaration', JSON.stringify(medicalDeclaration));
                }
                
                const response = await fetch('api/assessment.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    return data;
                } else {
                    alert(data.message || 'Failed to start assessment');
                    return null;
                }
            } catch (error) {
                console.error('Error starting assessment:', error);
                alert('Error starting assessment. Please try again.');
                return null;
            }
        };

    </script>
    <script src="script.js?v=<?php echo (int)@filemtime(__DIR__ . '/script.js'); ?>"></script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
