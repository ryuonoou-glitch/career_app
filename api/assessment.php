<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/recommendation_scoring.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id']) && !isset($_SESSION['counselor_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

function checkAssessmentOwnership($mysqli, $assessmentId) {
    if (isset($_SESSION['admin_id']) || isset($_SESSION['counselor_id'])) {
        return true;
    }
    if (!isset($_SESSION['student_db_id'])) {
        return false;
    }
    $stmt = $mysqli->prepare("SELECT student_id FROM student_assessments WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && (int)$row['student_id'] === (int)$_SESSION['student_db_id']);
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

// Production: Auto-abandon any in_progress assessment older than 24 hours.
$expiredStmt = $mysqli->prepare("
    SELECT id, student_id 
    FROM student_assessments 
    WHERE status = 'in_progress' 
    AND COALESCE(started_at, created_at) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
if ($expiredStmt) {
    $expiredStmt->execute();
    $expiredResult = $expiredStmt->get_result();
    $expiredIds = [];
    while ($row = $expiredResult->fetch_assoc()) {
        $expiredIds[] = $row['id'];
        notify_student(
            (int) $row['student_id'],
            'Assessment Expired',
            'Your in-progress assessment has expired after 24 hours of inactivity.',
            'warning',
            'assessment_history.php'
        );
    }
    $expiredStmt->close();

    if (!empty($expiredIds)) {
        $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
        $types = str_repeat('i', count($expiredIds));
        $updateStmt = $mysqli->prepare("UPDATE student_assessments SET status = 'abandoned' WHERE id IN ($placeholders)");
        if ($updateStmt) {
            $updateStmt->bind_param($types, ...$expiredIds);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'create':
        handleCreateAssessment($mysqli, $response);
        break;
    case 'save':
    case 'save_answer':
        handleSaveAnswer($mysqli, $response);
        break;
    case 'complete':
        handleCompleteAssessment($mysqli, $response);
        break;
    case 'get_results':
        handleGetResults($mysqli, $response);
        break;
    case 'get_history':
        handleGetHistory($mysqli, $response);
        break;
    case 'validate_assessment':
        handleValidateAssessment($mysqli, $response);
        break;
    default:
        $response['message'] = 'Invalid action';
}

echo json_encode($response);

function ensureStudentAnswerUniqueConstraint($mysqli) {
    $check = $mysqli->query("SHOW INDEX FROM student_answers WHERE Key_name = 'uk_student_question'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    $mysqli->query("DELETE a FROM student_answers a JOIN student_answers b ON a.assessment_id = b.assessment_id AND a.question_type = b.question_type AND a.question_id = b.question_id AND a.id < b.id");
    $mysqli->query("ALTER TABLE student_answers ADD UNIQUE KEY uk_student_question (assessment_id, question_type, question_id)");
}

function ensureAssessmentRegionColumn($mysqli) {
    $check = $mysqli->query("SHOW COLUMNS FROM student_assessments LIKE 'region'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    $mysqli->query("ALTER TABLE student_assessments ADD COLUMN region VARCHAR(100) NULL DEFAULT NULL");
}

function handleCreateAssessment($mysqli, &$response) {
    $studentId = intval($_POST['student_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $preferredRegion = trim($_POST['preferred_region'] ?? '');
    $MAX_ASSESSMENTS = 2; // Maximum allowed assessments per student
    
    if ($studentId <= 0 || empty($type)) {
        $response['message'] = 'Invalid student ID or assessment type';
        return;
    }
    
    if (isset($_SESSION['student_db_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
        if ($studentId !== (int)$_SESSION['student_db_id']) {
            $response['message'] = 'Unauthorized student access.';
            return;
        }
    }
    
    // Get current school year safely
    $yearResult = $mysqli->query("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
    $yearRow = $yearResult ? $yearResult->fetch_assoc() : null;
    $schoolYearId = $yearRow ? (int)$yearRow['id'] : 1;
    
    // Check if student has reached assessment limit for THIS school year
    $checkStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND status = 'completed'");
    $checkStmt->bind_param('ii', $studentId, $schoolYearId);
    $checkStmt->execute();
    $completedCount = $checkStmt->get_result()->fetch_assoc()['count'] ?? 0;
    $checkStmt->close();
    
    if ($completedCount >= $MAX_ASSESSMENTS) {
        $response['message'] = "You have reached the maximum limit of {$MAX_ASSESSMENTS} assessments for this school year.";
        $response['limit_reached'] = true;
        return;
    }
    
    ensureAssessmentRegionColumn($mysqli);
    
    // Look up preferred_region from previous attempts in this school year if empty
    if (empty($preferredRegion)) {
        $prevStmt = $mysqli->prepare("SELECT preferred_region FROM student_assessments WHERE student_id = ? AND school_year_id = ? AND preferred_region IS NOT NULL LIMIT 1");
        if ($prevStmt) {
            $prevStmt->bind_param("ii", $studentId, $schoolYearId);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $preferredRegion = $prevRow['preferred_region'] ?? '';
            $prevStmt->close();
        }
    }
    
    // Mark any existing in_progress assessments as abandoned before starting a new one
    $abandonStmt = $mysqli->prepare("
        UPDATE student_assessments
        SET status = 'abandoned'
        WHERE student_id = ?
          AND school_year_id = ?
          AND status = 'in_progress'
    ");
    if ($abandonStmt) {
        $abandonStmt->bind_param('ii', $studentId, $schoolYearId);
        $abandonStmt->execute();
        $abandonStmt->close();
    }

    $stmt = $mysqli->prepare("
        INSERT INTO student_assessments (student_id, school_year_id, status, region, preferred_region) 
        VALUES (?, ?, 'in_progress', ?, ?)
    ");
    $stmt->bind_param('iiss', $studentId, $schoolYearId, $preferredRegion, $preferredRegion);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['assessment_id'] = $mysqli->insert_id;
        $response['message'] = 'Assessment created successfully';
        $response['remaining_assessments'] = $MAX_ASSESSMENTS - ($completedCount + 1);
    } else {
        $response['message'] = 'Error creating assessment: ' . $mysqli->error;
    }
    
    $stmt->close();
}

function handleSaveAnswer($mysqli, &$response) {
    $assessmentId = intval($_POST['assessment_id'] ?? 0);
    $questionType = $_POST['question_type'] ?? '';
    $questionId = intval($_POST['question_id'] ?? 0);
    $answer = $_POST['answer'] ?? '';
    $score = floatval($_POST['score'] ?? 0);
    
    if ($assessmentId <= 0 || empty($questionType) || $questionId <= 0) {
        $response['message'] = 'Invalid assessment or question data';
        return;
    }

    // Verify assessment exists and is in_progress
    $checkAssess = $mysqli->prepare("SELECT status FROM student_assessments WHERE id = ? LIMIT 1");
    if ($checkAssess) {
        $checkAssess->bind_param('i', $assessmentId);
        $checkAssess->execute();
        $assessRow = $checkAssess->get_result()->fetch_assoc();
        $checkAssess->close();
        
        if (!$assessRow) {
            $response['success'] = false;
            $response['message'] = 'Assessment not found (possibly deleted)';
            $response['invalid_assessment'] = true;
            return;
        }
        if ($assessRow['status'] !== 'in_progress') {
            $response['success'] = false;
            $response['message'] = 'Assessment is not in progress';
            $response['invalid_assessment'] = true;
            return;
        }
    }
    
    // Decode answer if it's JSON
    $answerData = json_decode($answer, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $answer = $answerData;
    }
    
    // Detect question type from the database question table
    $isTextQuestion = false;
    $isObjectiveQuestion = false;
    
    $tableMap = [
        'career' => 'questions_career',
        'personality' => 'questions_personality',
        'skills' => 'questions_skills',
        'strand' => 'questions_strand'
    ];
    $qTable = $tableMap[$questionType] ?? '';
    
    if (!empty($qTable)) {
        $qStmt = $mysqli->prepare("SELECT question_type FROM {$qTable} WHERE id = ? LIMIT 1");
        if ($qStmt) {
            $qStmt->bind_param('i', $questionId);
            $qStmt->execute();
            $qRow = $qStmt->get_result()->fetch_assoc();
            $qStmt->close();
            
            $dbQuestionType = $qRow['question_type'] ?? '';
            if ($dbQuestionType === 'open-ended') {
                $isTextQuestion = true;
            } elseif ($dbQuestionType === 'objective') {
                $isObjectiveQuestion = true;
            }
        }
    }
    
    $selectedOptionId = null;
    $likertValue = null;
    $openAnswer = null;
    
    // Determine answer type based on mapped question type and answer format
    if ($isTextQuestion) {
        if (is_array($answer)) {
            $openAnswer = $answer['text'] ?? json_encode($answer);
        } else {
            $openAnswer = $answer;
        }
        
        $openAnswerTrimmed = trim((string)$openAnswer);
        if (strlen($openAnswerTrimmed) < 10 || 
            preg_match('/^(.)\1+$/', $openAnswerTrimmed) || 
            preg_match('/^[^aeiou\s]{10,}$/i', $openAnswerTrimmed) ||
            preg_match('/(asdf|qwerty|zxcv|asdasd|ashdasdasd)/i', $openAnswerTrimmed) ||
            preg_match('/^(asd|qwe|zxc|fgh|jkl)+$/i', $openAnswerTrimmed)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a meaningful response to continue.']);
            exit;
        }
    } elseif ($isObjectiveQuestion) {
        if (is_array($answer)) {
            if (isset($answer['option_id'])) {
                $selectedOptionId = intval($answer['option_id']);
            } else {
                $openAnswer = json_encode($answer);
            }
        } else {
            $openAnswer = $answer;
        }
    } else {
        // Likert question
        if (is_array($answer)) {
            $likertValue = isset($answer['likert']) ? intval($answer['likert']) : (isset($answer['option_id']) ? intval($answer['option_id']) : null);
        } else {
            if (is_string($answer) && preg_match('/^[A-E]$/i', $answer)) {
                $map = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
                $likertValue = $map[strtoupper($answer)];
            } else {
                $likertValue = intval($answer);
            }
        }
    }
    
    ensureStudentAnswerUniqueConstraint($mysqli);

    // Check if an answer already exists for this question in this assessment
    $checkStmt = $mysqli->prepare("
        SELECT id FROM student_answers 
        WHERE assessment_id = ? AND question_type = ? AND question_id = ? 
        LIMIT 1
    ");
    
    if ($checkStmt) {
        $checkStmt->bind_param('isi', $assessmentId, $questionType, $questionId);
        $checkStmt->execute();
        $existingRow = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($existingRow) {
            // Update existing answer
            $updateSql = "
                UPDATE student_answers 
                SET selected_option_id = ?, likert_value = ?, open_answer = ?, score = ?, created_at = NOW()
                WHERE id = ?
            ";
            $stmt = $mysqli->prepare($updateSql);
            $stmt->bind_param('iisdi', $selectedOptionId, $likertValue, $openAnswer, $score, $existingRow['id']);
        } else {
            // Insert new answer
            $insertSql = "
                INSERT INTO student_answers 
                (assessment_id, question_type, question_id, selected_option_id, likert_value, open_answer, score, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            $stmt = $mysqli->prepare($insertSql);
            $stmt->bind_param('isiiisd', $assessmentId, $questionType, $questionId, $selectedOptionId, $likertValue, $openAnswer, $score);
        }
        
        if ($stmt) {
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Answer saved successfully';
            } else {
                $response['message'] = 'Error executing statement: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['message'] = 'Error preparing statement: ' . $mysqli->error;
        }
    } else {
        $response['message'] = 'Error checking existing answer: ' . $mysqli->error;
    }
}

function handleCompleteAssessment($mysqli, &$response) {
    $assessmentId = intval($_POST['assessment_id'] ?? 0);
    
    if ($assessmentId <= 0) {
        $response['message'] = 'Invalid assessment ID';
        return;
    }
    
    if (!checkAssessmentOwnership($mysqli, $assessmentId)) {
        $response['message'] = 'Unauthorized assessment completion.';
        return;
    }
    
    // 1. Calculate category scores first so we can average their percentages
    saveCategoryScores($mysqli, $assessmentId);

    // 2. Calculate total score as the average of the 4 category percentages
    $scoreStmt = $mysqli->prepare("
        SELECT AVG(percentage) as avg_score FROM category_scores WHERE assessment_id = ?
    ");
    $scoreStmt->bind_param('i', $assessmentId);
    $scoreStmt->execute();
    $scoreResult = $scoreStmt->get_result()->fetch_assoc();
    $totalScore = $scoreResult['avg_score'] ?? 0;
    $scoreStmt->close();
    
    // 3. Update assessment status
    $stmt = $mysqli->prepare("
        UPDATE student_assessments 
        SET status = 'completed', completed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE), total_score = ?
        WHERE id = ?
    ");
    $stmt->bind_param('di', $totalScore, $assessmentId);
    
    if ($stmt->execute()) {
        saveCompetencyScores($mysqli, $assessmentId);
        generateRecommendations($mysqli, $assessmentId);

        // ── Notify student: assessment submitted ───────────────────────────
        $studentRow = $mysqli->prepare('SELECT student_id FROM student_assessments WHERE id = ? LIMIT 1');
        $studentRow->bind_param('i', $assessmentId);
        $studentRow->execute();
        $sRow = $studentRow->get_result()->fetch_assoc();
        $studentRow->close();
        if ($sRow) {
            notify_student(
                (int) $sRow['student_id'],
                'Assessment Submitted',
                'Assessment Submitted — Your responses have been recorded.',
                'success',
                'assessment_history.php'
            );
            maybeNotifyWeeklyAssessmentSummary();

            $nameStmt = $mysqli->prepare(
                'SELECT CONCAT(s.first_name, " ", s.last_name) AS full_name
                 FROM students s WHERE s.id = ? LIMIT 1'
            );
            $nameStmt->bind_param('i', $sRow['student_id']);
            $nameStmt->execute();
            $nameRow = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();
            $studentName = trim($nameRow['full_name'] ?? 'A student');
            notify_all_active_counselors(
                'Assessment Completed',
                $studentName . ' has completed their assessment — view their results now.',
                'success',
                'counselor_results.php'
            );
        }

        $response['success'] = true;
        $response['message'] = 'Assessment completed successfully';
        $response['total_score'] = round($totalScore, 2);
    } else {
        $response['message'] = 'Error completing assessment: ' . $mysqli->error;
    }

    $stmt->close();
}

function handleGetResults($mysqli, &$response) {
    $assessmentId = intval($_POST['assessment_id'] ?? 0);
    $selectedRegion = $_POST['region'] ?? $_POST['filter_region'] ?? null;
    
    if ($assessmentId <= 0) {
        $response['message'] = 'Invalid assessment ID';
        return;
    }
    
    if (!checkAssessmentOwnership($mysqli, $assessmentId)) {
        $response['message'] = 'Unauthorized access to assessment results.';
        return;
    }
    
    // Get assessment data
    $stmt = $mysqli->prepare("
        SELECT sa.*, 
               COUNT(sa2.id) as total_questions,
               AVG(sa2.score) as avg_score
        FROM student_assessments sa
        LEFT JOIN student_answers sa2 ON sa.id = sa2.assessment_id
        WHERE sa.id = ?
        GROUP BY sa.id
    ");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$assessment) {
        $response['message'] = 'Assessment not found';
        return;
    }
    
    // Get recommendations
    $recStmt = $mysqli->prepare("
        SELECT r.*, c.course_name, c.description, cl.name as cluster_name
        FROM recommendations r
        JOIN courses c ON r.course_id = c.id
        LEFT JOIN clusters cl ON c.cluster_id = cl.id
        WHERE r.assessment_id = ?
        ORDER BY r.rank ASC
    ");
    $recStmt->bind_param('i', $assessmentId);
    $recStmt->execute();
    $recommendations = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recStmt->close();
    
    // Check recommendation confidence dynamically
    require_once __DIR__ . '/../includes/recommendation_scoring.php';
    
    $strandStmt = $mysqli->prepare("
        SELECT s.strand_id, st.code AS strand_code
        FROM student_assessments sa
        JOIN students s ON sa.student_id = s.id
        LEFT JOIN strands st ON s.strand_id = st.id
        WHERE sa.id = ?
    ");
    $strandStmt->bind_param('i', $assessmentId);
    $strandStmt->execute();
    $strandResult = $strandStmt->get_result()->fetch_assoc();
    $strandStmt->close();
    
    $studentStrandId = (int) ($strandResult['strand_id'] ?? 0);
    $strandCode = $strandResult['strand_code'] ?? null;
    
    $clusterAlignments = calculateClusterAlignmentScores($mysqli, $assessmentId, $studentStrandId, $strandCode);
    $confidenceData = checkRecommendationConfidence($clusterAlignments);
    $response['confidence'] = $confidenceData;
    
    // Fallback to student's preferred_region if no override is provided
    if ($selectedRegion === null || $selectedRegion === '') {
        $selectedRegion = $assessment['preferred_region'] ?? null;
    }
    
    // STEP 6: Job Recommendations
    $recommendations = getJobRecommendationsForCourses($mysqli, $recommendations);
    
    // STEP 7: School Recommendations with Specialization + Region Ranking
    $recommendations = getSchoolRecommendationsForCourses($mysqli, $recommendations, $selectedRegion, $assessmentId);
    
    $response['success'] = true;
    $response['assessment'] = $assessment;
    $response['recommendations'] = $recommendations;
    $response['selected_region'] = $selectedRegion;
}

function handleGetHistory($mysqli, &$response) {
    $studentId = intval($_POST['student_id'] ?? 0);
    
    if ($studentId <= 0) {
        $response['message'] = 'Invalid student ID';
        return;
    }
    
    if (isset($_SESSION['student_db_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
        if ($studentId !== (int)$_SESSION['student_db_id']) {
            $response['message'] = 'Unauthorized access to student history';
            return;
        }
    }
    
    $stmt = $mysqli->prepare("
        SELECT sa.*, 
               COUNT(sa2.id) as total_questions,
               AVG(sa2.score) as avg_score
        FROM student_assessments sa
        LEFT JOIN student_answers sa2 ON sa.id = sa2.assessment_id
        WHERE sa.student_id = ?
        GROUP BY sa.id
        ORDER BY sa.created_at DESC
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $response['success'] = true;
    $response['history'] = $history;
}

function handleValidateAssessment($mysqli, &$response) {
    $assessmentId = intval($_POST['assessment_id'] ?? 0);
    
    if ($assessmentId <= 0) {
        $response['message'] = 'Invalid assessment ID';
        return;
    }
    
    if (!checkAssessmentOwnership($mysqli, $assessmentId)) {
        $response['message'] = 'Unauthorized access to validate assessment.';
        return;
    }
    
    $stmt = $mysqli->prepare("SELECT id, status FROM student_assessments WHERE id = ? LIMIT 1");
    if (!$stmt) {
        $response['message'] = 'Database error';
        return;
    }
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$assessment) {
        $response['message'] = 'Assessment not found';
        return;
    }
    
    if ($assessment['status'] !== 'in_progress') {
        $response['message'] = 'Assessment is not resumable';
        return;
    }
    
    $response['success'] = true;
    $response['assessment_id'] = $assessmentId;
    $response['status'] = $assessment['status'];
}
