<?php


function resolveCompetencyId($mysqli, $nameOrCode, $defaultId = 1) {
    $nameOrCode = trim((string) $nameOrCode);
    if ($nameOrCode === '') {
        return (int) $defaultId;
    }

    $stmt = $mysqli->prepare('SELECT id FROM competencies WHERE name = ? OR code = ? LIMIT 1');
    if (!$stmt) {
        return (int) $defaultId;
    }
    $stmt->bind_param('ss', $nameOrCode, $nameOrCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? $defaultId);
}

function resolveSkillCategoryId($mysqli, $name, $defaultId = null) {
    $name = trim((string) $name);
    if ($name === '') {
        return $defaultId;
    }

    $stmt = $mysqli->prepare('SELECT id FROM skill_categories WHERE name = ? LIMIT 1');
    if (!$stmt) {
        return $defaultId;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : $defaultId;
}

function resolveStrandId($mysqli, $codeOrName, $defaultId = null) {
    $codeOrName = trim((string) $codeOrName);
    if ($codeOrName === '') {
        return $defaultId;
    }

    $stmt = $mysqli->prepare('SELECT id FROM strands WHERE code = ? OR name = ? LIMIT 1');
    if (!$stmt) {
        return $defaultId;
    }
    $stmt->bind_param('ss', $codeOrName, $codeOrName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : $defaultId;
}

function mapSkillCategoryToCompetencyId($mysqli, $skillCategory) {
    $map = [
        'Mathematics' => 'MATH',
        'Science' => 'RESEARCH',
        'Technology' => 'TECH',
        'Communication' => 'COMM',
        'Business' => 'BUSINESS',
        'Problem Solving' => 'LOGICAL',
    ];

    $code = $map[trim((string) $skillCategory)] ?? null;
    if ($code) {
        return resolveCompetencyId($mysqli, $code);
    }

    return resolveCompetencyId($mysqli, $skillCategory);
}

function getDefaultStrandId($mysqli) {
    $result = $mysqli->query('SELECT id FROM strands ORDER BY grade_level, id LIMIT 1');
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) $row['id'];
    }
    return 1;
}

function isValidHollandType($value) {
    static $types = ['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'];
    return in_array($value, $types, true);
}

function insertQuestionOptions($mysqli, $category, $questionId, $strandId = null, $competencyId = null) {
    $correctAnswer = $_POST['correctAnswer'] ?? '';
    $options = ['A', 'B', 'C', 'D'];

    foreach ($options as $opt) {
        $optText = trim($_POST["option{$opt}"] ?? '');
        if ($optText === '') {
            continue;
        }

        $isCorrect = ($correctAnswer === $opt) ? 1 : 0;
        $optStmt = $mysqli->prepare('
            INSERT INTO question_options (question_type, question_id, option_label, option_text, is_correct, strand_id, competency_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        if (!$optStmt) {
            continue;
        }
        $optStmt->bind_param('sissiii', $category, $questionId, $opt, $optText, $isCorrect, $strandId, $competencyId);
        $optStmt->execute();
        $optStmt->close();
    }
}

function fetchQuestionOptionsMap($mysqli, $questionType, array $questionIds) {
    $map = [];
    if (empty($questionIds)) {
        return $map;
    }

    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $types = 's' . str_repeat('i', count($questionIds));
    $params = array_merge([$questionType], $questionIds);

    $sql = "SELECT id, question_id, option_label, option_text, is_correct
            FROM question_options
            WHERE question_type = ? AND question_id IN ({$placeholders})
            ORDER BY option_label";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return $map;
    }

    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $qid = (int) $row['question_id'];
        if (!isset($map[$qid])) {
            $map[$qid] = [];
        }
        $map[$qid][] = $row;
    }
    $stmt->close();

    return $map;
}

function saveCategoryScores($mysqli, $assessmentId) {
    $categories = ['career', 'personality', 'skills', 'strand'];

    foreach ($categories as $category) {
        $stmt = $mysqli->prepare('
            SELECT likert_value, score, selected_option_id, question_id
            FROM student_answers
            WHERE assessment_id = ? AND question_type = ?
        ');
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('is', $assessmentId, $category);
        $stmt->execute();
        $result = $stmt->get_result();

        $totalLikert = 0.0;
        $answerCount = 0;

        while ($row = $result->fetch_assoc()) {
            $isCorrect = null;
            if (!empty($row['selected_option_id'])) {
                $optStmt = $mysqli->prepare('SELECT is_correct FROM question_options WHERE id = ? AND question_id = ? AND question_type = ? LIMIT 1');
                if ($optStmt) {
                    $optionId = (int) $row['selected_option_id'];
                    $optStmt->bind_param('iis', $optionId, $row['question_id'], $category);
                    $optStmt->execute();
                    $optRow = $optStmt->get_result()->fetch_assoc();
                    $optStmt->close();
                    if ($optRow) {
                        $isCorrect = (bool) $optRow['is_correct'];
                    }
                }
            }

            $likert = normalizeAnswerToLikertScale($row['likert_value'], $row['score'], $isCorrect);
            if ($likert === null) {
                continue;
            }

            $totalLikert += $likert;
            $answerCount++;
        }
        $stmt->close();

        if ($answerCount === 0) {
            continue;
        }

        $avgScore = round($totalLikert / $answerCount, 2);
        $percentage = likertToPercentage($avgScore);

        $upsert = $mysqli->prepare('
            INSERT INTO category_scores (assessment_id, category, score, percentage)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), percentage = VALUES(percentage)
        ');
        if (!$upsert) {
            continue;
        }
        $upsert->bind_param('isdd', $assessmentId, $category, $avgScore, $percentage);
        $upsert->execute();
        $upsert->close();
    }
}
