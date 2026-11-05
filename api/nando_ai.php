<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/recommendation_scoring.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Ã¢â€â‚¬Ã¢â€â‚¬ 1. Authentication & Security Check Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput) && !empty($GLOBALS['TEST_MOCK_INPUT'])) {
        $rawInput = $GLOBALS['TEST_MOCK_INPUT'];
    }
    $input = json_decode($rawInput, true);

    $isMobileApi = isset($input['student_id']) && $input['student_id'] > 0;
    
    if (!$isMobileApi && !isset($_SESSION['student_id']) && !isset($_SESSION['student_db_id'])) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $studentId = $isMobileApi ? (int)$input['student_id'] : (int)($_SESSION['student_db_id'] ?? 0);
    if ($studentId <= 0 && !empty($_SESSION['student_id']) && isset($mysqli)) {
        $stmt = $mysqli->prepare("SELECT id FROM students WHERE student_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $_SESSION['student_id']);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res) {
                $studentId = (int)$res['id'];
                $_SESSION['student_db_id'] = $studentId;
            }
            $stmt->close();
        }
    }

    if ($studentId <= 0) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬ 2. Input Reading Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput) && !empty($GLOBALS['TEST_MOCK_INPUT'])) {
        $rawInput = $GLOBALS['TEST_MOCK_INPUT'];
    }
    $input = json_decode($rawInput, true);
    $message = trim($input['message'] ?? '');
    $history = is_array($input['history'] ?? null) ? $input['history'] : [];

    if (empty($message)) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'Empty message']);
        exit;
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬ 3. Intent & Entity Extraction Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $lowerMsg = strtolower($message);
    $cleanMsg = preg_replace('/[^\w\s]/', ' ', $lowerMsg);
    $cleanMsg = preg_replace('/\s+/', ' ', $cleanMsg);

    // Intent flags
    $assessmentKeywords = [
        'assessment', 'result', 'results', 'score', 'scores', 'test', 'exam', 'evaluat',
        'strength', 'strengths', 'weakness', 'weaknesses', 'aptitude', 'fit',
        'riasec', 'holland', 'personality', 'trait', 'traits', 'big five', 'big 5',
        'skill', 'skills', 'competenc', 'strand', 'readiness',
        'recommend', 'recommended', 'recommendation', 'recommendations', 'rank', 'ranking',
        'why was', 'why is', 'my top', 'top course', 'top courses',
        'wasn t in my', 'wasnt in my', 'not in my', 'didn t appear', 'didnt appear',
        'didn t show', 'didnt show', 'didn t get', 'didnt get',
        'can i still consider', 'can i still take', 'is it still something i can consider',
        'how was my score', 'how is my score', 'how do i compare'
    ];

    $isAssessmentQuery = false;
    foreach ($assessmentKeywords as $kw) {
        if (strpos($cleanMsg, $kw) !== false) {
            $isAssessmentQuery = true;
            break;
        }
    }

    $schoolKeywords = [
        'school', 'schools', 'college', 'colleges', 'universit', 'campus', 'campuses',
        'where can i study', 'where to enroll', 'who offers', 'what offers',
        'what school', 'which school', 'what college', 'which college',
        'what university', 'which university', 'where is', 'institu'
    ];

    $isSchoolQuery = false;
    foreach ($schoolKeywords as $kw) {
        if (strpos($cleanMsg, $kw) !== false) {
            $isSchoolQuery = true;
            break;
        }
    }

    $courseKeywords = [
        'course', 'courses', 'program', 'programs', 'degree', 'degrees',
        'major', 'majors', 'bachelor', 'curriculum', 'career path', 'careers'
    ];

    $isCourseQuery = false;
    foreach ($courseKeywords as $kw) {
        if (strpos($cleanMsg, $kw) !== false) {
            $isCourseQuery = true;
            break;
        }
    }

    // Course Acronym & Name Dictionary
    $courseDictionary = [
        'bsit' => ['BSIT', 'Information Technology'],
        'bscs' => ['BSCS', 'Computer Science'],
        'bscpe' => ['BSCpE', 'Computer Engineering'],
        'bsn' => ['BSN', 'Nursing'],
        'bsa' => ['BSA', 'Accountancy'],
        'bscrim' => ['BSCrim', 'Criminology'],
        'bsce' => ['BSCE', 'Civil Engineering'],
        'bsme' => ['BSME', 'Mechanical Engineering'],
        'bsee' => ['BSEE', 'Electrical Engineering'],
        'bsece' => ['BSECE', 'Electronics Engineering'],
        'bsba' => ['BSBA', 'Business Administration'],
        'bstm' => ['BSTM', 'Tourism Management'],
        'bshm' => ['BSHM', 'Hospitality Management'],
        'bshrm' => ['BSHRM', 'Hotel and Restaurant Management'],
        'beed' => ['BEEd', 'Elementary Education'],
        'bsed' => ['BSEd', 'Secondary Education'],
        'beced' => ['BECEd', 'Early Childhood Education'],
        'bsmls' => ['BSMLS', 'Medical Laboratory Science'],
        'bspt' => ['BSPT', 'Physical Therapy'],
        'bsrt' => ['BSRT', 'Radiologic Technology'],
        'bsmare' => ['BSMarE', 'Marine Engineering'],
        'bsmt' => ['BSMT', 'Marine Transportation'],
        'information technology' => ['Information Technology'],
        'computer science' => ['Computer Science'],
        'computer engineering' => ['Computer Engineering'],
        'nursing' => ['Nursing'],
        'accountancy' => ['Accountancy'],
        'accounting' => ['Accountancy', 'Accounting'],
        'criminology' => ['Criminology'],
        'civil engineering' => ['Civil Engineering'],
        'mechanical engineering' => ['Mechanical Engineering'],
        'electrical engineering' => ['Electrical Engineering'],
        'electronics engineering' => ['Electronics Engineering'],
        'business administration' => ['Business Administration'],
        'tourism management' => ['Tourism Management'],
        'tourism' => ['Tourism Management'],
        'hospitality management' => ['Hospitality Management'],
        'hospitality' => ['Hospitality Management', 'Hotel and Restaurant Management'],
        'hotel and restaurant' => ['Hotel and Restaurant Management'],
        'elementary education' => ['Elementary Education'],
        'secondary education' => ['Secondary Education'],
        'early childhood education' => ['Early Childhood Education'],
        'medical laboratory' => ['Medical Laboratory Science'],
        'medtech' => ['Medical Laboratory Science'],
        'physical therapy' => ['Physical Therapy'],
        'radiologic technology' => ['Radiologic Technology'],
        'radtech' => ['Radiologic Technology'],
        'pharmacy' => ['Pharmacy'],
        'psychology' => ['Psychology'],
        'architecture' => ['Architecture'],
        'agriculture' => ['Agriculture'],
        'social work' => ['Social Work'],
        'biology' => ['Biology'],
        'data science' => ['Data Science'],
        'engineering' => ['Engineering'],
        'engineer' => ['Engineering'],
        'education' => ['Education'],
        'teacher' => ['Education'],
        'marine' => ['Marine'],
        'medical' => ['Medical'],
        'doctor' => ['Medical'],
        'cybersecurity' => ['Cybersecurity'],
        'artificial intelligence' => ['Artificial Intelligence'],
        'aviation' => ['Aviation'],
        'avionics' => ['Avionics Technology'],
        'midwifery' => ['Midwifery'],
        'customs administration' => ['Customs Administration'],
        'public administration' => ['Public Administration'],
        'entrepreneurship' => ['Entrepreneurship'],
        'marketing' => ['Marketing'],
        'finance' => ['Finance']
    ];

    $matchedCourseKeywords = [];
    foreach ($courseDictionary as $term => $searchPhrases) {
        if (preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/i', $message)) {
            $isCourseQuery = true;
            foreach ($searchPhrases as $p) {
                $matchedCourseKeywords[] = $p;
            }
        }
    }
    // Match standalone short acronyms carefully with context
    if (preg_match('/\b(it|cs)\b/i', $message) && ($isCourseQuery || $isSchoolQuery)) {
        if (preg_match('/\bit\b/i', $message)) $matchedCourseKeywords[] = 'Information Technology';
        if (preg_match('/\bcs\b/i', $message)) $matchedCourseKeywords[] = 'Computer Science';
    }

    // Dynamically extract potential topic keywords from the message to search the DB
    $stopWords = ['school', 'schools', 'college', 'colleges', 'university', 'where', 'what', 'when', 'why', 'how', 'want', 'need', 'like', 'love', 'course', 'courses', 'program', 'programs', 'degree', 'major', 'career', 'job', 'jobs', 'work', 'working', 'good', 'best', 'top', 'recommend', 'suggest', 'please', 'help', 'could', 'would', 'should', 'can', 'will', 'there', 'their', 'they', 'them', 'this', 'that', 'these', 'those', 'which', 'who', 'whom', 'whose', 'about'];
    $words = str_word_count(strtolower($cleanMsg), 1);
    foreach ($words as $word) {
        if (strlen($word) >= 5 && !in_array($word, $stopWords)) {
            $isCourseQuery = true; // Assume they might be asking for a course based on this topic
            $matchedCourseKeywords[] = $word;
        }
    }
    
    $matchedCourseKeywords = array_unique($matchedCourseKeywords);

    // Dynamically fetch schools from the database
    $matchedSchoolKeywords = [];
    if (isset($mysqli)) {
        $schoolDictStmt = $mysqli->query("SELECT name FROM schools WHERE status = 'active'");
        if ($schoolDictStmt) {
            while ($sRow = $schoolDictStmt->fetch_assoc()) {
                $schoolName = $sRow['name'];
                if (preg_match('/(?<!\w)' . preg_quote($schoolName, '/') . '(?!\w)/i', $message)) {
                    $isSchoolQuery = true;
                    $matchedSchoolKeywords[] = $schoolName;
                }
            }
        }
    }
    $matchedSchoolKeywords = array_unique($matchedSchoolKeywords);

    // Location extraction
    $locationKeywords = [
        'alaminos', 'anda', 'asingan', 'bayambang', 'binalonan', 'binmaley',
        'calasiao', 'dagupan', 'infanta', 'lingayen', 'malasiqui', 'manaoag',
        'mangaldan', 'mangatarem', 'rosales', 'san carlos', 'sison',
        'sta. maria', 'santa maria', 'tayug', 'umingan', 'urdaneta', 'villasis',
        'pangasinan'
    ];
    $matchedLocations = [];
    foreach ($locationKeywords as $loc) {
        if (preg_match('/(?<!\w)' . preg_quote($loc, '/') . '(?!\w)/i', $message)) {
            $matchedLocations[] = $loc;
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬ 4. Targeted Relevant-Context Retrieval Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    $studentContextText = "";
    $dbDataText = "";
    $studentTopCourses = [];
    $studentHasAssessment = false;
    $latestAssessmentId = null;

    // Load student's academic profile (Strand & Grade Level)
    $studentStrandInfo = "Not specified";
    if (isset($mysqli)) {
        $pStmt = $mysqli->prepare("
            SELECT s.grade_level, st.name AS strand_name, st.code AS strand_code
            FROM students s
            LEFT JOIN strands st ON s.strand_id = st.id
            WHERE s.id = ? LIMIT 1
        ");
        if ($pStmt) {
            $pStmt->bind_param('i', $studentId);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            if ($pRow) {
                $code = $pRow['strand_code'] ?? '';
                $name = $pRow['strand_name'] ?? '';
                $grade = $pRow['grade_level'] ?? '';
                $studentStrandInfo = "{$grade} - {$code} ({$name})";
            }
            $pStmt->close();
        }

        // Check for completed assessments
        $assStmt = $mysqli->prepare("
            SELECT id, completed_at, total_score, preferred_region
            FROM student_assessments
            WHERE student_id = ? AND status = 'completed'
            ORDER BY completed_at DESC LIMIT 1
        ");
        if ($assStmt) {
            $assStmt->bind_param('i', $studentId);
            $assStmt->execute();
            $assRow = $assStmt->get_result()->fetch_assoc();
            if ($assRow) {
                $studentHasAssessment = true;
                $latestAssessmentId = (int)$assRow['id'];
                $studentPreferredRegion = $assRow['preferred_region'] ?? 'All';
            }
            $assStmt->close();
        }

        // Always fetch student's top recommendations so we can reference them when asked
        if ($studentHasAssessment && $latestAssessmentId > 0) {
            $recStmt = $mysqli->prepare("
                SELECT r.rank, c.id AS course_id, c.course_name, cl.name AS cluster_name, 
                       r.match_percentage, r.explanation
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE r.assessment_id = ?
                ORDER BY r.rank ASC LIMIT 5
            ");
            if ($recStmt) {
                $recStmt->bind_param('i', $latestAssessmentId);
                $recStmt->execute();
                $recRes = $recStmt->get_result();
                while ($row = $recRes->fetch_assoc()) {
                    $studentTopCourses[] = $row;
                }
                $recStmt->close();
            }
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬ Build Student Assessment Context (Only when relevant) Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $isAssessmentContextNeeded = $isAssessmentQuery 
        || (empty($matchedCourseKeywords) && empty($matchedSchoolKeywords) && !$isSchoolQuery && !$isCourseQuery)
        || stripos($lowerMsg, 'my course') !== false
        || stripos($lowerMsg, 'my result') !== false
        || stripos($lowerMsg, 'recommend') !== false;

    if ($isAssessmentContextNeeded) {
        if ($studentHasAssessment && $latestAssessmentId > 0 && isset($mysqli)) {
            $studentContextText .= "Student Enrolled Strand: {$studentStrandInfo}\n";
            $studentContextText .= "Assessment Status: Completed\n\n";

            // Category Scores (Career Interest, Personality, Skills, Strand Aptitude)
            $catStmt = $mysqli->prepare("
                SELECT category, score, percentage
                FROM category_scores
                WHERE assessment_id = ?
            ");
            if ($catStmt) {
                $catStmt->bind_param('i', $latestAssessmentId);
                $catStmt->execute();
                $catRes = $catStmt->get_result();
                $catScores = [];
                while ($cRow = $catRes->fetch_assoc()) {
                    $catScores[$cRow['category']] = [
                        'score' => (float)$cRow['score'],
                        'percentage' => (float)$cRow['percentage']
                    ];
                }
                $catStmt->close();

                $studentContextText .= "Overall Category Scores:\n";
                $studentContextText .= "- Career Interest: " . ($catScores['career']['percentage'] ?? 0) . "%\n";
                $studentContextText .= "- Personality: " . ($catScores['personality']['percentage'] ?? 0) . "%\n";
                $studentContextText .= "- Technical Skills: " . ($catScores['skills']['percentage'] ?? 0) . "%\n";
                $studentContextText .= "- Strand Aptitude: " . ($catScores['strand']['percentage'] ?? 0) . "%\n\n";
            }

            // Holland RIASEC Breakdown
            $hollandScores = getStudentHollandScores($mysqli, $latestAssessmentId);
            $studentContextText .= "Career Interest (Holland RIASEC) Breakdown:\n";
            foreach ($hollandScores as $hType => $hPct) {
                $studentContextText .= "- {$hType}: " . round($hPct, 1) . "%\n";
            }
            $studentContextText .= "\n";

            // Big Five Traits Breakdown
            $traitScores = getStudentTraitScores($mysqli, $latestAssessmentId);
            $studentContextText .= "Personality (Big Five) Breakdown:\n";
            foreach ($traitScores as $tName => $tPct) {
                $studentContextText .= "- {$tName}: " . round($tPct, 1) . "%\n";
            }
            $studentContextText .= "\n";

            // Individual Competency Scores (Sorted Descending for Strengths Identification)
            $compStmt = $mysqli->prepare("
                SELECT c.name, cs.percentage
                FROM competency_scores cs
                JOIN competencies c ON cs.competency_id = c.id
                WHERE cs.assessment_id = ?
                ORDER BY cs.percentage DESC
            ");
            if ($compStmt) {
                $compStmt->bind_param('i', $latestAssessmentId);
                $compStmt->execute();
                $compRes = $compStmt->get_result();
                $compScores = [];
                while ($compRow = $compRes->fetch_assoc()) {
                    $compScores[] = "- {$compRow['name']}: " . round($compRow['percentage'], 1) . "%";
                }
                $compStmt->close();

                if (!empty($compScores)) {
                    $studentContextText .= "Tested Competency Scores (Strengths & Growth Areas):\n";
                    $studentContextText .= implode("\n", $compScores) . "\n\n";
                }
            }

            // Official Course Recommendations
            if (!empty($studentTopCourses)) {
                $studentContextText .= "Official Top Course Recommendations:\n";
                foreach ($studentTopCourses as $topC) {
                    $studentContextText .= "Rank {$topC['rank']}: {$topC['course_name']} (Match: {$topC['match_percentage']}%, Cluster: {$topC['cluster_name']})\n";
                    $studentContextText .= "Official Explanation: {$topC['explanation']}\n\n";
                }
            }

            // Student's Open-Ended Answers (Removed to save token consumption)
            /*
            $openEndedStmt = $mysqli->prepare("
                SELECT 'Career' AS category, qc.question_text, sa.open_answer 
                FROM student_answers sa 
                JOIN questions_career qc ON sa.question_id = qc.id AND sa.question_type = 'career'
                WHERE sa.assessment_id = ? AND sa.open_answer IS NOT NULL AND sa.open_answer != ''
            ");
            if ($openEndedStmt) {
                $openEndedStmt->bind_param('i', $latestAssessmentId);
                $openEndedStmt->execute();
                $openEndedRes = $openEndedStmt->get_result();
                $hasOpenEnded = false;
                $openText = "Student's Open-Ended Self-Reflection Answers:\n";
                while ($oa = $openEndedRes->fetch_assoc()) {
                    $hasOpenEnded = true;
                    // Decode if it happens to be JSON
                    $answerText = $oa['open_answer'];
                    $decoded = @json_decode($answerText, true);
                    if (is_array($decoded) && isset($decoded['text'])) {
                        $answerText = $decoded['text'];
                    } elseif (is_array($decoded)) {
                        $answerText = json_encode($decoded);
                    }
                    $openText .= "- [{$oa['category']}] Q: \"{$oa['question_text']}\"\n  A: \"{$answerText}\"\n";
                }
                $openText .= "\n";
                $openEndedStmt->close();

                if ($hasOpenEnded) {
                    $studentContextText .= $openText;
                }
            }
            */
        } else {
            $studentContextText = "Student Enrolled Strand: {$studentStrandInfo}\nAssessment Status: No completed assessment found yet. Inform the student gently that they haven't completed the assessment yet, and encourage them to take it by clicking 'Take Assessment'.\n";
        }
    } else {
        // Minimal student context if the query is strictly about a school or course lookup
        $studentContextText = "Student Enrolled Strand: {$studentStrandInfo}\n";
        if ($studentHasAssessment) {
            $studentContextText .= "Assessment Status: Completed.\n";
            if (!empty($studentTopCourses)) {
                $topNames = array_map(function($c) { return "Rank {$c['rank']}: {$c['course_name']}"; }, $studentTopCourses);
                $studentContextText .= "Top Recommended Courses: " . implode("; ", $topNames) . "\n";
            }
        } else {
            $studentContextText .= "Assessment Status: Not completed yet.\n";
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬ Build Database Context: Courses & Schools Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $dbDataSections = [];

    // Query matching courses from database
    $dbMatchedCourses = [];
    if (!empty($matchedCourseKeywords) && isset($mysqli)) {
        foreach ($matchedCourseKeywords as $kw) {
            $like = "%{$kw}%";
            $cStmt = $mysqli->prepare("
                SELECT c.id, c.course_name, c.description, c.possible_careers, cl.name AS cluster_name
                FROM courses c
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE c.course_name LIKE ? OR c.description LIKE ? OR c.possible_careers LIKE ?
                LIMIT 3
            ");
            if ($cStmt) {
                $cStmt->bind_param('sss', $like, $like, $like);
                $cStmt->execute();
                $cRes = $cStmt->get_result();
                while ($cRow = $cRes->fetch_assoc()) {
                    $dbMatchedCourses[$cRow['id']] = $cRow;
                }
                $cStmt->close();
            }
        }
        
        // Fetch linked jobs for each matched course
        foreach ($dbMatchedCourses as $cId => &$mCourse) {
            $jStmt = $mysqli->prepare("
                SELECT j.job_title 
                FROM course_jobs cj
                JOIN jobs j ON cj.job_id = j.id
                WHERE cj.course_id = ?
            ");
            $linkedJobs = [];
            if ($jStmt) {
                $jStmt->bind_param('i', $cId);
                $jStmt->execute();
                $jRes = $jStmt->get_result();
                while ($jRow = $jRes->fetch_assoc()) {
                    $linkedJobs[] = $jRow['job_title'];
                }
                $jStmt->close();
            }
            $mCourse['linked_jobs'] = $linkedJobs;
            
            // Add general course information to the database context
            $courseSec = "Course Information from Database: {$mCourse['course_name']}\n";
            $courseSec .= "Description: {$mCourse['description']}\n";
            $courseSec .= "Cluster: " . ($mCourse['cluster_name'] ?? 'General') . "\n";
            if (!empty($mCourse['linked_jobs'])) {
                $courseSec .= "Possible Careers/Jobs: " . implode(', ', $mCourse['linked_jobs']) . "\n";
            }
            $dbDataSections[] = $courseSec;
        }
        unset($mCourse);
    }

    // Query matching jobs/careers from database
    $dbMatchedJobs = [];
    if (isset($mysqli)) {
        $allJobsStmt = $mysqli->query("SELECT id, job_title, description FROM jobs");
        if ($allJobsStmt) {
            while ($jRow = $allJobsStmt->fetch_assoc()) {
                $jTitle = strtolower($jRow['job_title']);
                if (strlen($jTitle) > 3 && preg_match('/(?<!\w)' . preg_quote($jTitle, '/') . '(?!\w)/i', $message)) {
                    $jcStmt = $mysqli->prepare("
                        SELECT c.course_name 
                        FROM course_jobs cj
                        JOIN courses c ON cj.course_id = c.id
                        WHERE cj.job_id = ?
                    ");
                    $linkedCourses = [];
                    if ($jcStmt) {
                        $jcStmt->bind_param('i', $jRow['id']);
                        $jcStmt->execute();
                        $jcRes = $jcStmt->get_result();
                        while ($jcRow = $jcRes->fetch_assoc()) {
                            $linkedCourses[] = $jcRow['course_name'];
                        }
                        $jcStmt->close();
                    }
                    $jRow['linked_courses'] = $linkedCourses;
                    $dbMatchedJobs[] = $jRow;
                }
            }
        }
    }
    
    if (!empty($dbMatchedJobs)) {
        foreach ($dbMatchedJobs as $j) {
            $sec = "Career/Job Information from Database: {$j['job_title']}\n";
            $sec .= "Description: {$j['description']}\n";
            if (!empty($j['linked_courses'])) {
                $sec .= "Courses mapped to this career: " . implode(", ", $j['linked_courses']) . "\n";
            } else {
                $sec .= "Courses mapped to this career: None specified in database.\n";
            }
            $dbDataSections[] = $sec;
        }
    }

    // Un-recommended Course Comparison Handler
    // If the student mentions a course not in their top 5, check its competency mappings and cluster
    if ($studentHasAssessment && !empty($dbMatchedCourses) && isset($mysqli)) {
        $topCourseIds = array_column($studentTopCourses, 'course_id');

        foreach ($dbMatchedCourses as $mCourseId => $mCourse) {
            if (!in_array($mCourseId, $topCourseIds)) {
                // Course is NOT in student's top 5
                $compCheckStmt = $mysqli->prepare("
                    SELECT comp.name AS comp_name, cc.weight, cs.percentage AS student_score
                    FROM course_competencies cc
                    JOIN competencies comp ON cc.competency_id = comp.id
                    LEFT JOIN competency_scores cs ON cs.competency_id = cc.competency_id AND cs.assessment_id = ?
                    WHERE cc.course_id = ?
                ");
                $courseCompRows = [];
                if ($compCheckStmt) {
                    $compCheckStmt->bind_param('ii', $latestAssessmentId, $mCourseId);
                    $compCheckStmt->execute();
                    $ccRes = $compCheckStmt->get_result();
                    while ($ccRow = $ccRes->fetch_assoc()) {
                        $courseCompRows[] = $ccRow;
                    }
                    $compCheckStmt->close();
                }

                $unrecText = "Un-recommended Course Analysis (Not in Student's Top 5 Recommendations):\n";
                $unrecText .= "Course: {$mCourse['course_name']}\n";
                
                if (!empty($courseCompRows)) {
                    $unrecText .= "Course Competency Mappings in Database:\n";
                    foreach ($courseCompRows as $ccr) {
                        $studentScore = $ccr['student_score'] !== null ? round($ccr['student_score'], 1) . "%" : "Not evaluated";
                        $unrecText .= "- Required: {$ccr['comp_name']} (Weight: {$ccr['weight']}) | Student Score: {$studentScore}\n";
                    }
                    $unrecText .= "Guidance Instruction: Explain the student's results compared to these competencies. The student can still consider this program as an alternative or area to develop, but remind them that official recommendations remain based on their assessment results.\n";
                } else {
                    // No competency mappings exist in the database!
                    $unrecText .= "Competency Mapping Status: No specific competency weights are mapped in the system database for this course.\n";
                    $unrecText .= "CRITICAL GUIDANCE RULE: Do NOT claim that the assessment specifically indicates why this course ranked lower based on competencies. Provide general course information and discuss it as an alternative they can explore, but advise consulting their counselor.\n";
                }
                $dbDataSections[] = $unrecText;
            }
        }
    }

    // Query schools offering identified courses OR top recommended course if student asks "what schools offer my course"
    $coursesForSchoolLookup = $dbMatchedCourses;
    if (empty($coursesForSchoolLookup) && ($isSchoolQuery || stripos($lowerMsg, 'what school') !== false || stripos($lowerMsg, 'which school') !== false)) {
        if (!empty($studentTopCourses)) {
            // Default to top 2 recommended courses for school lookup if no specific course was named
            foreach (array_slice($studentTopCourses, 0, 2) as $stc) {
                $coursesForSchoolLookup[$stc['course_id']] = [
                    'id' => $stc['course_id'],
                    'course_name' => $stc['course_name']
                ];
            }
        }
    }

    if (!empty($coursesForSchoolLookup) && isset($mysqli)) {
        foreach ($coursesForSchoolLookup as $cId => $cData) {
            $schStmt = $mysqli->prepare("
                SELECT s.id, s.name, s.city, s.province, s.type, cs.is_specialization, cs.notes, s.district_id
                FROM course_schools cs
                JOIN schools s ON cs.school_id = s.id
                WHERE cs.course_id = ? AND s.status = 'active'
                ORDER BY cs.is_specialization DESC, s.name ASC
            ");
            if ($schStmt) {
                $schStmt->bind_param('i', $cId);
                $schStmt->execute();
                $schRes = $schStmt->get_result();
                $schoolsList = [];
                while ($sRow = $schRes->fetch_assoc()) {
                    if (isset($studentPreferredRegion) && $studentPreferredRegion !== 'All' && $studentPreferredRegion !== '') {
                        $allowedDistricts = array_map('trim', explode(',', $studentPreferredRegion));
                        if (!in_array((string)$sRow['district_id'], $allowedDistricts)) {
                            continue; // Skip schools outside preferred district
                        }
                    }
                    $spec = ((int)$sRow['is_specialization'] === 1) ? " [Center of Specialization]" : "";
                    $schoolsList[] = "- {$sRow['name']} ({$sRow['city']}, {$sRow['province']}) - Type: {$sRow['type']}{$spec}";
                }
                $schStmt->close();

                $section = "Schools offering {$cData['course_name']} in the CareerPath Database:\n";
                if (!empty($schoolsList)) {
                    $section .= implode("\n", $schoolsList) . "\n";
                } else {
                    $section .= "No schools are currently listed as offering this course in the CareerPath database.\n";
                }
                $dbDataSections[] = $section;
            }
        }
    }

    // Query courses offered by identified school(s) (e.g. "What courses does PSU offer?")
    if (!empty($matchedSchoolKeywords) && isset($mysqli)) {
        foreach ($matchedSchoolKeywords as $sKeyword) {
            $sLike = "%{$sKeyword}%";
            $findSchoolStmt = $mysqli->prepare("
                SELECT id, name, city, province, type
                FROM schools
                WHERE status = 'active' AND name LIKE ?
                LIMIT 3
            ");
            if ($findSchoolStmt) {
                $findSchoolStmt->bind_param('s', $sLike);
                $findSchoolStmt->execute();
                $foundSchools = $findSchoolStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $findSchoolStmt->close();

                foreach ($foundSchools as $fSchool) {
                    $fId = (int)$fSchool['id'];
                    $offeredCoursesStmt = $mysqli->prepare("
                        SELECT c.id, c.course_name, cl.name AS cluster_name
                        FROM course_schools cs
                        JOIN courses c ON cs.course_id = c.id
                        LEFT JOIN clusters cl ON c.cluster_id = cl.id
                        WHERE cs.school_id = ?
                        ORDER BY c.course_name ASC
                    ");
                    if ($offeredCoursesStmt) {
                        $offeredCoursesStmt->bind_param('i', $fId);
                        $offeredCoursesStmt->execute();
                        $offeredRes = $offeredCoursesStmt->get_result();
                        $coursesList = [];
                        while ($oRow = $offeredRes->fetch_assoc()) {
                            $coursesList[] = "- {$oRow['course_name']}" . (!empty($oRow['cluster_name']) ? " ({$oRow['cluster_name']})" : "");
                        }
                        $offeredCoursesStmt->close();

                        $sec = "Courses offered by {$fSchool['name']} ({$fSchool['city']}, {$fSchool['province']}, {$fSchool['type']}) in the CareerPath Database:\n";
                        if (!empty($coursesList)) {
                            $sec .= implode("\n", $coursesList) . "\n";
                        } else {
                            $sec .= "No courses are currently listed for this school in the CareerPath database.\n";
                        }
                        $dbDataSections[] = $sec;
                    }
                }
            }
        }
    }

    // If student asked for a specific course or school that returned NO database results
    if (empty($dbMatchedCourses) && empty($matchedSchoolKeywords) && ($isCourseQuery || $isSchoolQuery)) {
        $dbDataSections[] = "Database Search Result: No matching course or school records were found for the query in the CareerPath database.\nRule: Inform the student clearly that the requested course or school is not currently available in the CareerPath database rather than inventing information.";
    }

    $dbDataText = !empty($dbDataSections) ? implode("\n---\n", $dbDataSections) : "No specific course or school database lookup required for this general query.";

    // Ã¢â€â‚¬Ã¢â€â‚¬ 5. System Instruction Construction Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $systemInstruction = "You are Nando, the personalized AI Career Guidance Assistant for CareerPath at Riverview High School.
You are helping a Senior High School student explore careers, college courses, schools, and their assessment results.

CORE ROLE & SYSTEM ARCHITECTURE:
- You are a GUIDANCE AND SUGGESTION TOOL, NOT the system's official recommendation engine.
- The official course recommendations are generated deterministically by the system's scoring algorithm and are fixed.
- You must NEVER attempt to change, recalculate, overwrite, or claim to alter the student's official scores, competency ratings, or official recommendations.
- You can explain results, discuss strengths, suggest alternatives to explore, and provide school and course information from the database.

BEHAVIORAL DIRECTIVES:
1. Career Assessment Guidance:
   - When answering questions about the student's assessment results, use ONLY the student assessment context provided below.
   - Explain what their scores mean in simple, encouraging terms (Career Interest / RIASEC, Personality / Big Five, Skills, Strand Aptitude, and individual competencies).
   - Point out strengths indicated by high competency scores and interest alignments.
   - Explain why a course was officially recommended based on its alignment with their strand, competencies, career interests, and personality fit.
   - Incorporate their Open-Ended Self-Reflection Answers into your advice. Use their own words or expressed interests to make your guidance more personalized and encouraging.

2. Course Suggestions & Un-recommended Courses:
   - When a student asks about a course that was NOT in their top recommendations (e.g., 'BSIT wasn't in my results. Is it still something I can consider?'):
     * If competency requirements are provided in the database context below, compare them honestly to the student's scores to explain why other courses ranked higher.
     * If the database context states that the course has NO competency mappings in the database, provide general course information and CLEARLY AVOID claiming that the assessment specifically indicates why the course ranked lower. Explain that specific competency mappings are not defined for it in the system.
     * Always encourage them that they can still consider and prepare for alternative courses they are passionate about, and recommend discussing their goals with their school counselor.

3. School and Course Information (Database Grounding):
   - Factual information regarding school offerings, locations, courses, and programs MUST be based STRICTLY on the DATABASE CONTEXT provided below.
   - Do NOT invent or hallucinate schools, campus branches, degrees, or program availability.
   - If the student asks what schools offer a course, list the schools provided in the database context for that course. If there are many schools (e.g. more than 5), suggest 3-5 of the most notable ones (like State Universities or Centers of Specialization) and mention that other schools are also available.
   - If the student asks what courses a school offers, list ONLY the courses provided in the database context for that school.
   - If the requested course, school, or location is not found in the database context, you MUST clearly state that the information is not currently available in the CareerPath database.

4. Data Privacy:
   - You only have access to this student's data and public school, course, and job records from the database.
   - Never reference or reveal other students' information, raw assessment questions/answers, admin data, or counselor data.
   - For school, course, and job information, only use information explicitly available in the database context below. Do NOT invent or hallucinate outside information.

5. Tone & Formatting (Chat Format):
   - Format like a natural, friendly chat message, NOT a markdown report.
   - Keep responses clear, concise, and appropriate for Senior High School students.
   - Use short paragraphs and conversational sentences.
   - DO NOT use markdown tables or large ### headings.
   - DO NOT use asterisks (*) for bold or italics. Use plain text only.
   - For lists, use simple dashes (-) rather than asterisks (*).
   - If small talk or greeting, respond warmly and briefly, then guide the student to career and course exploration.
   - If asked completely unrelated questions (e.g. math homework, coding scripts, gaming, politics), politely decline and redirect them back to career, education, and school exploration.
   - Always encourage consulting the school career guidance counselor for official guidance and final decisions.

--- STUDENT CONTEXT ---
{$studentContextText}

--- DATABASE CONTEXT ---
{$dbDataText}
";

    // Ã¢â€â‚¬Ã¢â€â‚¬ 6. Assemble Groq Messages Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $messages = [];
    $messages[] = ["role" => "system", "content" => $systemInstruction];

    // Append history (limit to last 4 messages to save tokens)
    $recentHistory = array_slice($history, -4);
    foreach ($recentHistory as $msg) {
        $r = ($msg['role'] === 'user') ? 'user' : 'assistant';
        $messages[] = ["role" => $r, "content" => $msg['content']];
    }
    $messages[] = ["role" => "user", "content" => $message];

    // Ã¢â€â‚¬Ã¢â€â‚¬ 7. Groq API Key Resolution Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    $apiKey = trim($_ENV['GROQ_API_KEY'] ?? $_SERVER['GROQ_API_KEY'] ?? (getenv('GROQ_API_KEY') ?: ''));
    if (empty($apiKey) && file_exists(__DIR__ . '/../.env')) {
        $envLines = @file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($envLines)) {
            foreach ($envLines as $line) {
                $line = trim($line);
                if (strpos($line, 'GROQ_API_KEY') === 0 && strpos($line, '=') !== false) {
                    list(, $val) = explode('=', $line, 2);
                    $apiKey = trim($val, " \t\n\r\0\x0B\"'");
                    break;
                }
            }
        }
    }

    if (empty($apiKey)) {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'error' => 'The AI assistant is temporarily unavailable because the GROQ_API_KEY is missing. Please ensure your .env file is uploaded to your hosting server and contains GROQ_API_KEY.'
        ]);
        exit;
    }

    $data = [
        "model" => "openai/gpt-oss-20b",
        "messages" => $messages,
        "temperature" => 0.7,
        "max_tokens" => 800
    ];

    $url = "https://api.groq.com/openai/v1/chat/completions";
    $result = false;
    $curlErr = '';

    // Prefer cURL for hosting environments
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $result = curl_exec($ch);
        if ($result === false) {
            $curlErr = curl_error($ch);
            if (stripos($curlErr, 'certificate') !== false || stripos($curlErr, 'SSL') !== false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $result = curl_exec($ch);
                if ($result !== false) {
                    $curlErr = '';
                } else {
                    $curlErr = curl_error($ch);
                }
            }
        }
        curl_close($ch);
    }

    // Fallback to stream context if cURL is unavailable
    if ($result === false && empty($curlErr)) {
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 25,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
    }

    if ($result === false) {
        if (ob_get_length()) ob_clean();
        $detail = !empty($curlErr) ? " ($curlErr)" : "";
        echo json_encode([
            'error' => 'The AI assistant took too long to respond or outbound connections are blocked by the host' . $detail . '. Please try again.'
        ]);
        exit;
    }

    $response = json_decode($result, true);
    if (!is_array($response)) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'The AI assistant returned an unreadable response. Please try again.']);
        exit;
    }

    if (isset($response['error'])) {
        if (ob_get_length()) ob_clean();
        $errCode = $response['error']['code'] ?? 'Unknown';
        $errMsg = $response['error']['message'] ?? '';
        if ($errCode === 'rate_limit_exceeded' || $errCode === 429 || $errCode === 503) {
            echo json_encode(['error' => 'The AI assistant is temporarily busy or rate-limited. Please try again in a moment.']);
        } else {
            echo json_encode(['error' => 'AI Service Error: ' . (!empty($errMsg) ? $errMsg : 'Please try again.')]);
        }
        exit;
    }

    $reply = $response['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';
    
    if (ob_get_length()) ob_clean();
    echo json_encode(['reply' => trim($reply)]);

} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

