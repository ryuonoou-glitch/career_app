<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}



$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        handleAddQuestion($mysqli, $response);
        break;
    case 'edit':
        handleEditQuestion($mysqli, $response);
        break;
    case 'delete':
        handleDeleteQuestion($mysqli, $response);
        break;
    case 'get':
        handleGetQuestion($mysqli, $response);
        break;
    default:
        $response['message'] = 'Invalid action';
}

echo json_encode($response);

function handleAddQuestion($mysqli, &$response) {
    $category = $_POST['category'] ?? '';
    $type = $_POST['type'] ?? '';
    $text = trim($_POST['text'] ?? '');
    $difficulty = $_POST['difficulty'] ?? 'medium';
    $classificationValue = trim($_POST['classification_value'] ?? '');

    if (empty($category) || empty($type) || empty($text)) {
        $response['message'] = 'Please fill in all required fields';
        return;
    }

    if (empty($classificationValue)) {
        $response['message'] = 'Please select a classification';
        return;
    }

    if (!in_array($type, ['likert', 'objective', 'open-ended'], true)) {
        $response['message'] = 'Invalid question type';
        return;
    }

    if (in_array($category, ['career', 'personality'], true) && !in_array($type, ['likert', 'open-ended'], true)) {
        $response['message'] = 'Invalid question type for this category';
        return;
    }

    if (in_array($category, ['skills', 'strand'], true) && !in_array($type, ['objective', 'open-ended'], true)) {
        $response['message'] = 'Invalid question type for this category';
        return;
    }

    $table = '';
    $fields = [];
    $types = '';
    $params = [];
    $strandIdForOptions = null;
    $competencyIdForOptions = null;

    switch ($category) {
        case 'career':
            $table = 'questions_career';
            $strandId = (int) ($_POST['strand_id'] ?? 0);
            if ($strandId <= 0) {
                $strandId = getDefaultStrandId($mysqli);
            }
            $hollandType = trim($_POST['holland_type'] ?? 'Investigative');
            if (!isValidHollandType($hollandType)) {
                $hollandType = 'Investigative';
            }
            $fields = ['question_text', 'strand_id', 'holland_type', 'competency', 'question_type', 'difficulty'];
            $types = 'sissss';
            $params = [$text, $strandId, $hollandType, $classificationValue, $type, $difficulty];
            $strandIdForOptions = $strandId;
            break;

        case 'personality':
            $table = 'questions_personality';
            $fields = ['question_text', 'trait', 'question_type', 'difficulty'];
            $types = 'ssss';
            $params = [$text, $classificationValue, $type, $difficulty];
            break;

        case 'skills':
            $table = 'questions_skills';
            $competencyId = mapSkillCategoryToCompetencyId($mysqli, $classificationValue);
            $strandId = (int) ($_POST['strand_id'] ?? 0);
            $strandId = $strandId > 0 ? $strandId : null;
            $fields = ['question_text', 'competency_id', 'strand_id', 'skill_category', 'question_type', 'difficulty'];
            $types = 'siisss';
            $params = [$text, $competencyId, $strandId, $classificationValue, $type, $difficulty];
            $strandIdForOptions = $strandId;
            $competencyIdForOptions = $competencyId;
            break;

        case 'strand':
            $table = 'questions_strand';
            $competencyId = resolveCompetencyId($mysqli, $classificationValue);
            $strandId = resolveStrandId($mysqli, $classificationValue);
            $fields = ['question_text', 'competency_id', 'strand_id', 'strand', 'question_type', 'difficulty'];
            $types = 'siisss';
            $params = [$text, $competencyId, $strandId, $classificationValue, $type, $difficulty];
            $strandIdForOptions = $strandId;
            $competencyIdForOptions = $competencyId;
            break;

        default:
            $response['message'] = 'Invalid category';
            return;
    }

    $fieldStr = implode(', ', $fields);
    $placeholderStr = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO {$table} ({$fieldStr}, is_active, created_at) VALUES ({$placeholderStr}, 1, NOW())";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Database error: ' . $mysqli->error;
        return;
    }

    try {
        if (!$stmt->bind_param($types, ...$params)) {
            $response['message'] = 'Bind error: ' . $stmt->error;
            $stmt->close();
            return;
        }

        if ($stmt->execute()) {
            $questionId = $mysqli->insert_id;

            if ($type === 'objective' && in_array($category, ['skills', 'strand'], true)) {
                insertQuestionOptions($mysqli, $category, $questionId, $strandIdForOptions, $competencyIdForOptions);
            }

            // Audit logging
            $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
            $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
            $userTitle = ($userType === 'counselor') ? 'Counselor' : 'Admin';
            
            $description = '';
            switch ($category) {
                case 'career':
                    $description = "{$userTitle} added a new Career Interest question (Holland Type: {$hollandType})";
                    break;
                case 'personality':
                    $description = "{$userTitle} added a new Personality question (Big Five trait: {$classificationValue})";
                    break;
                case 'skills':
                    $description = "{$userTitle} added a new Skills question (Competency: {$classificationValue})";
                    break;
                case 'strand':
                    $description = "{$userTitle} added a new Strand question (Strand Relevance: {$classificationValue})";
                    break;
            }
            log_activity($userId, $userType, 'Added Question', $table, $questionId, $description, null, $text);

            $response['success'] = true;
            $response['message'] = 'Question added successfully';
            $response['question_id'] = $questionId;
        } else {
            $response['message'] = 'Error adding question: ' . $stmt->error;
        }
    } catch (\Throwable $e) {
        $response['message'] = 'System Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    } finally {
        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
    }
}

function handleEditQuestion($mysqli, &$response) {
    $id = intval($_POST['id'] ?? 0);
    $category = $_POST['category'] ?? '';
    $oldCategory = $_POST['old_category'] ?? $category;

    if ($id <= 0 || empty($category)) {
        $response['message'] = 'Invalid question ID or category';
        return;
    }

    $text = trim($_POST['text'] ?? '');
    $difficulty = $_POST['difficulty'] ?? 'medium';
    $classificationValue = trim($_POST['classification_value'] ?? '');

    if (empty($classificationValue)) {
        $response['message'] = 'Please select a classification';
        return;
    }

    $oldTable = '';
    switch ($oldCategory) {
        case 'career': $oldTable = 'questions_career'; break;
        case 'personality': $oldTable = 'questions_personality'; break;
        case 'skills': $oldTable = 'questions_skills'; break;
        case 'strand': $oldTable = 'questions_strand'; break;
        default:
            $response['message'] = 'Invalid old category';
            return;
    }

    $table = '';
    switch ($category) {
        case 'career': $table = 'questions_career'; break;
        case 'personality': $table = 'questions_personality'; break;
        case 'skills': $table = 'questions_skills'; break;
        case 'strand': $table = 'questions_strand'; break;
        default:
            $response['message'] = 'Invalid category';
            return;
    }

    // 1. Fetch old question text before updating (for audit logging)
    $oldText = '';
    $oldQuery = $mysqli->prepare("SELECT question_text FROM {$oldTable} WHERE id = ?");
    if ($oldQuery) {
        $oldQuery->bind_param('i', $id);
        $oldQuery->execute();
        $oldResult = $oldQuery->get_result()->fetch_assoc();
        $oldText = $oldResult['question_text'] ?? '';
        $oldQuery->close();
    }

    // 2. Set the old question row to inactive (soft-delete)
    $deactivateStmt = $mysqli->prepare("UPDATE {$oldTable} SET is_active = 0 WHERE id = ?");
    $deactivateStmt->bind_param('i', $id);
    if (!$deactivateStmt->execute()) {
        $response['message'] = 'Failed to deactivate old question version';
        $deactivateStmt->close();
        return;
    }
    $deactivateStmt->close();

    // 3. Insert the new active question version row
    $fields = [];
    $types = '';
    $params = [];
    $strandIdForOptions = null;
    $competencyIdForOptions = null;

    switch ($category) {
        case 'career':
            $strandId = (int) ($_POST['strand_id'] ?? 0);
            if ($strandId <= 0) {
                $strandId = getDefaultStrandId($mysqli);
            }
            $hollandType = trim($_POST['holland_type'] ?? 'Investigative');
            if (!isValidHollandType($hollandType)) {
                $hollandType = 'Investigative';
            }
            $fields = ['question_text', 'strand_id', 'holland_type', 'competency', 'question_type', 'difficulty'];
            $types = 'sissss';
            $params = [$text, $strandId, $hollandType, $classificationValue, $_POST['type'] ?? 'likert', $difficulty];
            $strandIdForOptions = $strandId;
            break;

        case 'personality':
            $fields = ['question_text', 'trait', 'question_type', 'difficulty'];
            $types = 'ssss';
            $params = [$text, $classificationValue, $_POST['type'] ?? 'likert', $difficulty];
            break;

        case 'skills':
            $competencyId = mapSkillCategoryToCompetencyId($mysqli, $classificationValue);
            $strandId = (int) ($_POST['strand_id'] ?? 0);
            $strandId = $strandId > 0 ? $strandId : null;
            $fields = ['question_text', 'competency_id', 'strand_id', 'skill_category', 'question_type', 'difficulty'];
            $types = 'siisss';
            $params = [$text, $competencyId, $strandId, $classificationValue, $_POST['type'] ?? 'objective', $difficulty];
            $strandIdForOptions = $strandId;
            $competencyIdForOptions = $competencyId;
            break;

        case 'strand':
            $competencyId = resolveCompetencyId($mysqli, $classificationValue);
            $strandId = resolveStrandId($mysqli, $classificationValue);
            $fields = ['question_text', 'competency_id', 'strand_id', 'strand', 'question_type', 'difficulty'];
            $types = 'siisss';
            $params = [$text, $competencyId, $strandId, $classificationValue, $_POST['type'] ?? 'objective', $difficulty];
            $strandIdForOptions = $strandId;
            $competencyIdForOptions = $competencyId;
            break;
    }

    $fieldStr = implode(', ', $fields);
    $placeholderStr = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO {$table} ({$fieldStr}, is_active, created_at) VALUES ({$placeholderStr}, 1, NOW())";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Database error creating new version: ' . $mysqli->error;
        return;
    }

    try {
        if (!$stmt->bind_param($types, ...$params)) {
            $response['message'] = 'Bind error: ' . $stmt->error;
            $stmt->close();
            return;
        }

        if ($stmt->execute()) {
            $newQuestionId = $mysqli->insert_id;

            // 4. If objective, insert new options associated with the new question ID (leaving old options intact)
            $type = $_POST['type'] ?? 'likert';
            if ($type === 'objective' && in_array($category, ['skills', 'strand'], true)) {
                insertQuestionOptions($mysqli, $category, $newQuestionId, $strandIdForOptions, $competencyIdForOptions);
            }

            // 5. Audit logging
            $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
            $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
            $userTitle = ($userType === 'counselor') ? 'Counselor' : 'Admin';
            $categoryName = ucfirst($category);
            $description = "{$userTitle} edited question #{$id} by creating a new version #{$newQuestionId} in {$categoryName} category";
            log_activity($userId, $userType, 'Edited Question', $table, $newQuestionId, $description, $oldText, $text);

            $response['success'] = true;
            $response['message'] = 'Question updated successfully';
            $response['new_question_id'] = $newQuestionId;
        } else {
            $response['message'] = 'Error inserting new question version: ' . $stmt->error;
        }
    } catch (\Throwable $e) {
        $response['message'] = 'System Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    } finally {
        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
    }
}

function handleDeleteQuestion($mysqli, &$response) {
    $id = intval($_POST['id'] ?? 0);
    $category = $_POST['category'] ?? '';

    if ($id <= 0 || empty($category)) {
        $response['message'] = 'Invalid question ID or category';
        return;
    }

    $table = '';
    switch ($category) {
        case 'career': $table = 'questions_career'; break;
        case 'personality': $table = 'questions_personality'; break;
        case 'skills': $table = 'questions_skills'; break;
        case 'strand': $table = 'questions_strand'; break;
        default:
            $response['message'] = 'Invalid category';
            return;
    }

    // Fetch old question text before deleting
    $oldText = '';
    $oldQuery = $mysqli->prepare("SELECT question_text FROM {$table} WHERE id = ?");
    if ($oldQuery) {
        $oldQuery->bind_param('i', $id);
        $oldQuery->execute();
        $oldResult = $oldQuery->get_result()->fetch_assoc();
        $oldText = $oldResult['question_text'] ?? '';
        $oldQuery->close();
    }

    $sql = "UPDATE {$table} SET is_active = 0 WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        // Audit logging
        $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
        $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
        $userTitle = ($userType === 'counselor') ? 'Counselor' : 'Admin';
        $categoryName = ucfirst($category);
        $description = "{$userTitle} deleted question #{$id} from {$categoryName} category";
        log_activity($userId, $userType, 'Deleted Question', $table, $id, $description, $oldText, null);

        $response['success'] = true;
        $response['message'] = 'Question deleted successfully';
    } else {
        $response['message'] = 'Error deleting question: ' . $mysqli->error;
    }

    $stmt->close();
}

function handleGetQuestion($mysqli, &$response) {
    $id = intval($_POST['id'] ?? 0);
    $category = $_POST['category'] ?? '';

    if ($id <= 0 || empty($category)) {
        $response['message'] = 'Invalid question ID or category';
        return;
    }

    switch ($category) {
        case 'career':
            $sql = "
                SELECT qc.*, COALESCE(qc.holland_type, '') AS classification_value, st.name AS strand_name
                FROM questions_career qc
                LEFT JOIN strands st ON qc.strand_id = st.id
                WHERE qc.id = ? AND qc.is_active = 1
            ";
            break;
        case 'personality':
            $sql = "
                SELECT *, trait AS classification_value
                FROM questions_personality
                WHERE id = ? AND is_active = 1
            ";
            break;
        case 'skills':
            $sql = "
                SELECT qs.*,
                       COALESCE(qs.skill_category, c.name, '') AS classification_value,
                       c.name AS competency_name
                FROM questions_skills qs
                LEFT JOIN competencies c ON qs.competency_id = c.id
                WHERE qs.id = ? AND qs.is_active = 1
            ";
            break;
        case 'strand':
            $sql = "
                SELECT qs.*,
                       COALESCE(st.code, qs.strand, st.name, '') AS classification_value,
                       st.name AS strand_name
                FROM questions_strand qs
                LEFT JOIN strands st ON qs.strand_id = st.id
                WHERE qs.id = ? AND qs.is_active = 1
            ";
            break;
        default:
            $response['message'] = 'Invalid category';
            return;
    }

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($question = $result->fetch_assoc()) {
        if (in_array($category, ['skills', 'strand'], true)) {
            $optStmt = $mysqli->prepare('SELECT * FROM question_options WHERE question_type = ? AND question_id = ? ORDER BY option_label');
            $optStmt->bind_param('si', $category, $id);
            $optStmt->execute();
            $question['options'] = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $optStmt->close();
        }

        $response['success'] = true;
        $response['question'] = $question;
    } else {
        $response['message'] = 'Question not found';
    }

    $stmt->close();
}
