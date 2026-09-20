<?php
/**
 * Shared helpers for loading student assessment answers (admin + counselor view).
 */

function getQuestionTextByType($mysqli, $questionType, $questionId) {
    $tables = [
        'career' => 'questions_career',
        'personality' => 'questions_personality',
        'skills' => 'questions_skills',
        'strand' => 'questions_strand',
    ];

    if (!isset($tables[$questionType]) || $questionId <= 0) {
        return null;
    }

    $table = $tables[$questionType];
    $stmt = $mysqli->prepare("SELECT question_text FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $questionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row['question_text'] ?? null;
}

function enrichAnswersWithQuestionText($mysqli, array $answers) {
    foreach ($answers as &$answer) {
        if (empty($answer['question_text'])) {
            $answer['question_text'] = getQuestionTextByType(
                $mysqli,
                $answer['question_type'] ?? '',
                (int)($answer['question_id'] ?? 0)
            );
        }
    }
    unset($answer);

    return $answers;
}

function fetchStudentAssessmentAnswers($mysqli, $studentId, $assessmentType = '', $targetAssessmentId = 0) {
    $studentId = (int)$studentId;
    $targetAssessmentId = (int)$targetAssessmentId;
    if ($studentId <= 0) {
        return ['student' => null, 'answers' => []];
    }

    $stmt = $mysqli->prepare("
        SELECT s.*, st.name as strand_name, st.code as strand_code
        FROM students s
        LEFT JOIN strands st ON s.strand_id = st.id
        WHERE s.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        return ['student' => null, 'answers' => []];
    }

    // ── Determine assessment ID for this student ──────────
    $latestAssessmentId = null;

    if ($targetAssessmentId > 0) {
        // Use specifically requested assessment attempt
        $latestAssessmentId = $targetAssessmentId;
    } else if (!empty($assessmentType) && $assessmentType !== 'all') {
        // Find the most recent assessment that has at least one answer of the given type
        $latestStmt = $mysqli->prepare("
            SELECT sa.id
            FROM student_assessments sa
            INNER JOIN student_answers saq ON sa.id = saq.assessment_id
            WHERE sa.student_id = ? AND saq.question_type = ?
            ORDER BY sa.created_at DESC
            LIMIT 1
        ");
        $latestStmt->bind_param('is', $studentId, $assessmentType);
        $latestStmt->execute();
        $latestRow = $latestStmt->get_result()->fetch_assoc();
        $latestStmt->close();
        $latestAssessmentId = $latestRow ? (int)$latestRow['id'] : null;
    } else {
        // No type filter — just get the most recent assessment for the student
        $latestStmt = $mysqli->prepare("
            SELECT id FROM student_assessments
            WHERE student_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $latestStmt->bind_param('i', $studentId);
        $latestStmt->execute();
        $latestRow = $latestStmt->get_result()->fetch_assoc();
        $latestStmt->close();
        $latestAssessmentId = $latestRow ? (int)$latestRow['id'] : null;
    }

    if (!$latestAssessmentId) {
        return ['student' => $student, 'answers' => []];
    }

    // ── Build WHERE using the single resolved assessment ID ───────────────────
    $whereClause = "sa.id = ?";
    $params = [$latestAssessmentId];
    $types = 'i';

    if (!empty($assessmentType) && $assessmentType !== 'all') {
        $whereClause .= " AND saq.question_type = ?";
        $params[] = $assessmentType;
        $types .= 's';
    }

    $query = "
        SELECT sa.id as assessment_id, sa.created_at, sa.status, sa.started_at, sa.completed_at, sa.total_score,
               saq.id as answer_id, saq.question_type, saq.question_id,
               saq.selected_option_id, saq.likert_value, saq.open_answer, saq.score,
               qo.option_label, qo.option_text, qo.is_correct,
               qo_correct.option_label AS correct_option_label,
               qo_correct.option_text AS correct_option_text
        FROM student_assessments sa
        LEFT JOIN student_answers saq ON sa.id = saq.assessment_id
        LEFT JOIN question_options qo 
            ON saq.selected_option_id = qo.id 
            AND qo.question_id = saq.question_id 
            AND qo.question_type = saq.question_type
        LEFT JOIN question_options qo_correct 
            ON qo_correct.question_id = saq.question_id 
            AND qo_correct.question_type = saq.question_type 
            AND qo_correct.is_correct = 1
        WHERE {$whereClause}
        ORDER BY saq.question_type, saq.question_id
    ";

    $stmt = $mysqli->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $answers = [];
    while ($row = $result->fetch_assoc()) {
        $answers[] = $row;
    }
    $stmt->close();

    $answers = enrichAnswersWithQuestionText($mysqli, $answers);

    if ($student && !empty($answers)) {
        $student['started_at'] = $answers[0]['started_at'] ?? null;
        $student['completed_at'] = $answers[0]['completed_at'] ?? null;
    }

    return ['student' => $student, 'answers' => $answers];
}

function counselorCanAccessStudent($mysqli, $counselorId, $studentId) {
    // School-wide Guidance Counselor: access any active student
    $stmt = $mysqli->prepare("SELECT id FROM students WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $allowed = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}
