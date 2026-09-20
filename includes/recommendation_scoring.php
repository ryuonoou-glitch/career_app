<?php
require_once __DIR__ . '/notify.php';

/**
 * Normalize a raw answer value to a 1–5 scale used throughout scoring.
 */
function normalizeAnswerToLikertScale($likertValue, $score, $isCorrect = null) {
    if ($likertValue !== null && $likertValue !== '') {
        return max(1, min(5, (float) $likertValue));
    }

    if ($isCorrect !== null) {
        return $isCorrect ? 5.0 : 0.0;
    }

    if ($score !== null && $score !== '') {
        return ($score > 0) ? 5.0 : 0.0;
    }

    return null;
}

/**
 * Convert a 1–5 scale value to a 0–100 percentage.
 */
function likertToPercentage($likertValue) {
    // Map 1-5 scale to 0-100 (1=0%, 2=25%, 3=50%, 4=75%, 5=100%)
    // If a raw score is 0 (e.g. incorrect answer), max(0) keeps it at 0%.
    return min(100, max(0, round(($likertValue - 1) * 25, 2)));
}

/**
 * Aggregate Holland-type scores from career assessment answers.
 *
 * @return array<string, float> Holland type => percentage (0–100)
 */
function getStudentHollandScores($mysqli, $assessmentId) {
    $hollandTypes = ['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'];
    $totals = array_fill_keys($hollandTypes, 0.0);
    $counts = array_fill_keys($hollandTypes, 0);

    $colCheck = $mysqli->query("SHOW COLUMNS FROM questions_career LIKE 'holland_type'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        return array_fill_keys($hollandTypes, 50.0);
    }

    $stmt = $mysqli->prepare("
        SELECT qc.holland_type, sa.likert_value, sa.score
        FROM student_answers sa
        INNER JOIN questions_career qc ON sa.question_id = qc.id
        WHERE sa.assessment_id = ? AND sa.question_type = 'career'
    ");
    if (!$stmt) {
        return array_fill_keys($hollandTypes, 50.0);
    }

    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $hollandType = $row['holland_type'] ?? '';
        if (!in_array($hollandType, $hollandTypes, true)) {
            continue;
        }

        $likert = normalizeAnswerToLikertScale($row['likert_value'], $row['score']);
        if ($likert === null) {
            continue;
        }

        $totals[$hollandType] += $likert;
        $counts[$hollandType]++;
    }
    $stmt->close();

    $scores = [];
    foreach ($hollandTypes as $type) {
        $scores[$type] = $counts[$type] > 0
            ? likertToPercentage($totals[$type] / $counts[$type])
            : 50.0;
    }

    return $scores;
}

/**
 * Checks if the student's final course scores indicate a clear top direction,
 * or if they are weak / ambiguous (low confidence).
 * 
 * @param array $scoredCourses Array of courses with their match_percentage
 * @return array ['is_low_confidence' => bool, 'message' => string|null]
 */
function checkRecommendationConfidence(array $scoredCourses): array {
    if (empty($scoredCourses)) {
        return ['is_low_confidence' => true, 'message' => 'Not enough data to form a confident recommendation.'];
    }

    $topScore = $scoredCourses[0]['match_percentage'] ?? 0;
    $secondScore = $scoredCourses[1]['match_percentage'] ?? 0;

    $isLowConfidence = false;

    if ($topScore < 50.0) {
        $isLowConfidence = true;
    } elseif (($topScore - $secondScore) < 5.0) {
        $isLowConfidence = true;
    }

    $message = null;
    if ($isLowConfidence) {
        $message = "Your results show a broad range of interests across several areas, rather than one dominant career direction. We recommend discussing these options with your school counselor to help narrow your focus.";
    }

    return [
        'is_low_confidence' => $isLowConfidence,
        'message' => $message
    ];
}

/**
 * Aggregate Big Five trait scores from personality assessment answers.
 *
 * @return array<string, float> Trait => percentage (0–100)
 */
function getStudentTraitScores($mysqli, $assessmentId) {
    $traits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];
    $totals = array_fill_keys($traits, 0.0);
    $counts = array_fill_keys($traits, 0);

    $stmt = $mysqli->prepare("
        SELECT qp.trait, sa.likert_value, sa.score
        FROM student_answers sa
        INNER JOIN questions_personality qp ON sa.question_id = qp.id
        WHERE sa.assessment_id = ? AND sa.question_type = 'personality'
    ");
    if (!$stmt) {
        return array_fill_keys($traits, 50.0);
    }

    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trait = $row['trait'] ?? '';
        if (!in_array($trait, $traits, true)) {
            continue;
        }

        $likert = normalizeAnswerToLikertScale($row['likert_value'], $row['score']);
        if ($likert === null) {
            continue;
        }

        $totals[$trait] += $likert;
        $counts[$trait]++;
    }
    $stmt->close();

    $scores = [];
    foreach ($traits as $trait) {
        $scores[$trait] = $counts[$trait] > 0
            ? likertToPercentage($totals[$trait] / $counts[$trait])
            : 50.0;
    }

    return $scores;
}

/**
 * Build per-competency scores from skills and strand answers (1–5 scale averages).
 *
 * @return array<int, float> competency_id => percentage (0–100)
 */
function computeCompetencyScoresFromAnswers($mysqli, $assessmentId) {
    $totals = [];
    $counts = [];

    $queries = [
        "
            SELECT qs.competency_id, sa.likert_value, sa.score, sa.selected_option_id, qo.is_correct
            FROM student_answers sa
            INNER JOIN questions_skills qs ON sa.question_id = qs.id
            LEFT JOIN question_options qo 
                ON sa.selected_option_id = qo.id 
                AND qo.question_id = sa.question_id 
                AND qo.question_type = 'skills'
            WHERE sa.assessment_id = ? AND sa.question_type = 'skills'
        ",
        "
            SELECT qs.competency_id, sa.likert_value, sa.score, sa.selected_option_id, qo.is_correct
            FROM student_answers sa
            INNER JOIN questions_strand qs ON sa.question_id = qs.id
            LEFT JOIN question_options qo 
                ON sa.selected_option_id = qo.id 
                AND qo.question_id = sa.question_id 
                AND qo.question_type = 'strand'
            WHERE sa.assessment_id = ? AND sa.question_type = 'strand'
        ",
    ];

    foreach ($queries as $sql) {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $competencyId = (int) ($row['competency_id'] ?? 0);
            if ($competencyId <= 0) {
                continue;
            }

            $likert = normalizeAnswerToLikertScale(
                $row['likert_value'],
                $row['score'],
                $row['is_correct'] !== null ? (bool) $row['is_correct'] : null
            );
            if ($likert === null) {
                continue;
            }

            if (!isset($totals[$competencyId])) {
                $totals[$competencyId] = 0.0;
                $counts[$competencyId] = 0;
            }

            $totals[$competencyId] += $likert;
            $counts[$competencyId]++;
        }
        $stmt->close();
    }

    $scores = [];
    foreach ($totals as $competencyId => $total) {
        $scores[(int) $competencyId] = likertToPercentage($total / $counts[$competencyId]);
    }

    return $scores;
}

/**
 * Persist competency_scores rows for an assessment.
 */
function saveCompetencyScores($mysqli, $assessmentId) {
    $scores = computeCompetencyScoresFromAnswers($mysqli, $assessmentId);

    foreach ($scores as $competencyId => $percentage) {
        $avgLikert = round($percentage / 20, 2);

        $stmt = $mysqli->prepare("
            INSERT INTO competency_scores (assessment_id, competency_id, score, percentage)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), percentage = VALUES(percentage)
        ");
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('iidd', $assessmentId, $competencyId, $avgLikert, $percentage);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Load category_scores keyed by category name.
 *
 * @return array<string, float>
 */
function loadCategoryScorePercentages($mysqli, $assessmentId) {
    $scores = [
        'career' => 50.0,
        'personality' => 50.0,
        'skills' => 50.0,
        'strand' => 50.0,
    ];

    $stmt = $mysqli->prepare("
        SELECT category, percentage
        FROM category_scores
        WHERE assessment_id = ?
    ");
    if (!$stmt) {
        return $scores;
    }

    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $category = $row['category'] ?? '';
        if (isset($scores[$category])) {
            $scores[$category] = (float) ($row['percentage'] ?? 50.0);
        }
    }
    $stmt->close();

    return $scores;
}

/**
 * Career (Holland) contribution to a single course (0–100).
 */
function calculateCareerCourseContribution(array $hollandScores, $course) {
    $assignedTraits = !empty($course['holland_traits']) ? array_filter(array_map('trim', explode(',', $course['holland_traits']))) : [];
    
    $weightedSum = 0.0;
    $totalWeight = 0.0;

    foreach ($hollandScores as $hollandType => $percentage) {
        if (!in_array($hollandType, $assignedTraits, true)) {
            continue;
        }

        $weightedSum += $percentage;
        $totalWeight += 1.0;
    }

    if ($totalWeight === 0.0) {
        return array_sum($hollandScores) / max(1, count($hollandScores));
    }

    return $weightedSum / $totalWeight;
}

/**
 * Personality (Big Five) contribution to a single course (0–100).
 */
function calculatePersonalityCourseContribution(array $traitScores, $course) {
    $assignedTraits = !empty($course['bigfive_traits']) ? array_filter(array_map('trim', explode(',', $course['bigfive_traits']))) : [];
    
    $weightedSum = 0.0;
    $totalWeight = 0.0;

    foreach ($traitScores as $trait => $percentage) {
        if (!in_array($trait, $assignedTraits, true)) {
            continue;
        }

        $weightedSum += $percentage;
        $totalWeight += 1.0;
    }

    if ($totalWeight === 0.0) {
        return array_sum($traitScores) / max(1, count($traitScores));
    }

    return $weightedSum / $totalWeight;
}



/**
 * Weighted competency match for a single course (0–100), or null if no weights defined.
 */
function calculateCourseCompetencyMatch($mysqli, $courseId, array $competencyScores) {
    $stmt = $mysqli->prepare("
        SELECT competency_id, weight
        FROM course_competencies
        WHERE course_id = ?
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) {
        return null;
    }

    $weightedSum = 0.0;
    $totalWeight = 0.0;

    foreach ($rows as $row) {
        $competencyId = (int) ($row['competency_id'] ?? 0);
        $weight = (float) ($row['weight'] ?? 0);
        if ($competencyId <= 0 || $weight <= 0) {
            continue;
        }

        $studentPct = $competencyScores[$competencyId] ?? 50.0;
        $weightedSum += $studentPct * $weight;
        $totalWeight += $weight;
    }

    if ($totalWeight === 0.0) {
        return null;
    }

    return $weightedSum / $totalWeight;
}

/**
 * Calculate final course scores directly using the two-stage model.
 * Stage 1: Cluster Alignment = (Career × 0.50) + (Personality × 0.25) + (Strand × 0.25)
 * Stage 2: Final Score = (Cluster Alignment × 0.35) + (Competency Match × 0.65)
 */
function calculateCourseScores($mysqli, $assessmentId) {
    $hollandScores = getStudentHollandScores($mysqli, $assessmentId);
    $traitScores = getStudentTraitScores($mysqli, $assessmentId);
    $competencyScores = computeCompetencyScoresFromAnswers($mysqli, $assessmentId);
    $categoryScores = loadCategoryScorePercentages($mysqli, $assessmentId);

    $courseStmt = $mysqli->query("
        SELECT c.id, c.course_name, c.description, c.cluster_id, 
               cl.name as cluster_name, cl.holland_traits, cl.bigfive_traits, cl.shs_strands 
        FROM courses c
        LEFT JOIN clusters cl ON c.cluster_id = cl.id
    ");
    
    if (!$courseStmt) {
        return [];
    }

    $scoredCourses = [];

    while ($course = $courseStmt->fetch_assoc()) {
        $courseId = (int) $course['id'];

        $careerPart = calculateCareerCourseContribution($hollandScores, $course);
        $personalityPart = calculatePersonalityCourseContribution($traitScores, $course);
        
        $strandPart = min(100, max(0, (float) ($categoryScores['strand'] ?? 0)));

        $clusterAlignment = (
            ($careerPart * 0.50) +
            ($personalityPart * 0.25) +
            ($strandPart * 0.25)
        );

        $skillsPart = calculateCourseCompetencyMatch($mysqli, $courseId, $competencyScores);
        if ($skillsPart === null) {
            $skillsPart = (float) ($categoryScores['skills'] ?? 50.0); 
        }

        $finalScore = (
            ($clusterAlignment * 0.35) +
            ($skillsPart * 0.65)
        );

        $scoredCourses[] = [
            'id' => $courseId,
            'course_id' => $courseId,
            'course_name' => $course['course_name'],
            'cluster_id' => $course['cluster_id'],
            'cluster_name' => $course['cluster_name'],
            'match_percentage' => round(min(100, max(0, $finalScore)), 2),
            'breakdown' => [
                'cluster_alignment' => round($clusterAlignment, 2),
                'skills_part' => round($skillsPart, 2),
                'career_part' => round($careerPart, 2),
                'personality_part' => round($personalityPart, 2),
                'strand_part' => round($strandPart, 2)
            ]
        ];
    }

    uasort($scoredCourses, function ($a, $b) {
        return $b['match_percentage'] <=> $a['match_percentage'];
    });

    // Re-index array so [0] is the top score, etc.
    return array_values($scoredCourses);
}

/**
 * Generate a plain-language fallback explanation using aggregate values.
 */
function generateTemplateExplanation($courseName, $clusterName, $clusterAlignment, $skillsScore, $finalScore) {
    return "Your overall cluster fit for {$clusterName} is " . round($clusterAlignment) . "%, which reflects your broad career interests, personality, and strand alignment. Your specific competency match for {$courseName} is " . round($skillsScore) . "%. Combining these, your final highly personalized recommendation score is " . round($finalScore) . "%.";
}

/**
 * Fetch AI explanations in batch for multiple courses.
 */
function fetchBatchAIExplanations(array $coursesBatch) {
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
    
    // Default fallback generator
    $generateFallbacks = function() use ($coursesBatch) {
        $fallbacks = [];
        foreach ($coursesBatch as $payload) {
            $fallbacks[$payload['courseId']] = generateTemplateExplanation(
                $payload['courseName'], $payload['clusterName'], 
                $payload['clusterAlignment'], $payload['skillsScore'],
                $payload['finalScore']
            );
        }
        return $fallbacks;
    };

    if (empty($apiKey) || empty($coursesBatch)) {
        return $generateFallbacks();
    }

    $systemPrompt = "You are an expert career counselor explaining course recommendations to a student. For each course, write a unique, personalized 2-3 sentence explanation of why this specific course is a good fit. Base your explanation strictly on the provided scores (Cluster Alignment and Competency Match) and the specific nature of the course. The system uses a two-stage model: it first checks broad field fit (Cluster Alignment), then specific course fit using tested skills (Competency Match). Highlight the strongest matching area, but ensure every explanation is distinct, engaging, and directly references the course's domain. Do not include exact percentages in the text. You must output the result strictly as a JSON object matching this schema:\n\n{\n  \"explanations\": [\n    {\n      \"course_id\": 123,\n      \"explanation\": \"...\"\n    }\n  ]\n}";
    
    $userPrompt = "Here are the top courses for the student:\n\n";
    foreach ($coursesBatch as $payload) {
        $userPrompt .= "Course ID: {$payload['courseId']}\nCourse: {$payload['courseName']}\nCluster: {$payload['clusterName']}\nCluster Alignment: {$payload['clusterAlignment']}\nCompetency Match: {$payload['skillsScore']}\nFinal Score: {$payload['finalScore']}\n\n";
    }

    $data = [
        "model" => "openai/gpt-oss-20b",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userPrompt]
        ],
        "temperature" => 0.7,
        "max_tokens" => 800,
        "response_format" => ["type" => "json_object"]
    ];

    $url = "https://api.groq.com/openai/v1/chat/completions";
    $result = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
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
            }
        }
        curl_close($ch);
    }

    if ($result === false) {
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 20,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
    }
    
    if ($result === false) {
        if (php_sapi_name() === 'cli') echo "\n⚠️ [WARNING] Groq API request completely failed (No response).\n";
        return $generateFallbacks();
    }

    $response = json_decode($result, true);

    if (isset($response['error'])) {
        $errCode = $response['error']['code'] ?? 'Unknown code';
        $errMsg = $response['error']['message'] ?? 'Unknown error';
        if (php_sapi_name() === 'cli') echo "\n⚠️ [WARNING] Groq API returned an error ($errCode): $errMsg\n";
        
        // Handle 429 retry
        if (($errCode === 'rate_limit_exceeded' || $errCode == 429) && php_sapi_name() === 'cli') {
            $retryDelay = 4; // default
            if (isset($response['error']['details'])) {
                foreach ($response['error']['details'] as $detail) {
                    if (isset($detail['retryDelay'])) {
                        $delayStr = $detail['retryDelay'];
                        $delayVal = (int)str_replace('s', '', $delayStr);
                        if ($delayVal > 0) $retryDelay = $delayVal;
                    }
                }
            }
            echo "Waiting $retryDelay seconds before proceeding with next batch...\n";
            sleep($retryDelay);
        }
        
        return $generateFallbacks();
    }

    $rawText = $response['choices'][0]['message']['content'] ?? '';
    
    $parsed = json_decode($rawText, true);
    if (!$parsed || !isset($parsed['explanations']) || !is_array($parsed['explanations'])) {
        return $generateFallbacks();
    }
    
    // Create mapping of course_id to explanation
    $explanations = [];
    foreach ($parsed['explanations'] as $item) {
        $courseId = $item['course_id'] ?? null;
        $explText = $item['explanation'] ?? '';
        if ($courseId && !empty(trim($explText))) {
            $explanations[$courseId] = trim($explText);
        }
    }
    
    // Fill in any missing courses with fallbacks
    $fallbacks = $generateFallbacks();
    foreach ($fallbacks as $courseId => $fallbackExpl) {
        if (!isset($explanations[$courseId])) {
            $explanations[$courseId] = $fallbackExpl;
        }
    }

    return $explanations;
}

/**
 * Generate a dynamic two-layer explanation for the frontend UI.
 */
function generateScoreExplanation($explanationText, $skillsPct, $careerPct, $personPct, $strandPct, $finalPct) {
    $sk = number_format((float)$skillsPct, 1);
    $c = number_format((float)$careerPct, 1);
    $p = number_format((float)$personPct, 1);
    $st = number_format((float)$strandPct, 1);
    $f = number_format((float)$finalPct, 1);

    $html = '<div class="score-explanation-layer1">';
    $html .= '<p style="margin:0 0 10px 0; line-height:1.6; font-size:0.875rem; color:#cbd5e1;">' . nl2br(htmlspecialchars($explanationText)) . '</p>';
    
    $html .= '<button type="button" onclick="const e = this.nextElementSibling; if(e.style.display===\'none\'){e.style.display=\'block\';}else{e.style.display=\'none\';}" style="background:none; border:none; color:#818cf8; font-size:0.8rem; cursor:pointer; padding:0; display:inline-flex; align-items:center; gap:4px; font-weight:600;">';
    $html .= '<i class="fa-solid fa-calculator"></i> Show the calculation';
    $html .= '</button>';
    
    $html .= '<div style="display:none; margin-top:10px; padding:12px; background:rgba(0,0,0,0.2); border-radius:8px; border:1px solid rgba(255,255,255,0.05); font-family:monospace; font-size:0.75rem; color:#94a3b8; line-height:1.7;">';
    $clusterVal = ($careerPct * 0.50) + ($personPct * 0.25) + ($strandPct * 0.25);
    $cluster = number_format($clusterVal, 1);
    
    $liveFinalPct = ($clusterVal * 0.35) + ($skillsPct * 0.65);
    $f_live = number_format($liveFinalPct, 1);
    
    $html .= '<strong style="color:#f8fafc;">Cluster Alignment (35%):</strong><br>';
    $html .= "(Career {$c}% &times; 0.50) + (Personality {$p}% &times; 0.25) + (Strand {$st}% &times; 0.25) = <strong style=\"color:#f59e0b;\">{$cluster}%</strong><br><br>";
    
    $html .= '<strong style="color:#f8fafc;">Final Course Score:</strong><br>';
    $html .= "(Cluster Alignment {$cluster}% &times; 0.35) + (Skills {$sk}% &times; 0.65) = <strong style=\"color:#f59e0b;\">{$f}%</strong>";
    
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * STEP 6: Job Recommendations
 * For each of the top 5 recommended courses, query course_jobs joined with jobs to get all job titles (and descriptions) linked to that course.
 * Attach this as a jobs array to each course's recommendation output.
 * If no jobs are linked, return an empty array.
 */
function getJobRecommendationsForCourses($mysqli, array $courses) {
    foreach ($courses as &$course) {
        $courseId = $course['course_id'] ?? $course['id'] ?? 0;
        $jobs = [];
        
        $stmt = $mysqli->prepare("
            SELECT j.id, j.job_title, j.description, j.created_at
            FROM course_jobs cj
            JOIN jobs j ON cj.job_id = j.id
            WHERE cj.course_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $jobs[] = [
                    'id' => (int) $row['id'],
                    'job_title' => $row['job_title'],
                    'description' => $row['description']
                ];
            }
            $stmt->close();
        }
        $course['jobs'] = $jobs;
    }
    return $courses;
}

/**
 * Get school data for a given course with specialized sorting, region prioritization,
 * custom counseling messages, and uncapped list options.
 */
function getRecommendedSchools($courseId, $selectedRegion) {
    global $mysqli;

    if (!$mysqli) {
        return [
            'status' => 'no_schools',
            'message' => "No schools are currently listed as offering this course in our system. \nWe recommend asking your school counselor directly about available options.",
            'top_schools' => [],
            'all_schools' => []
        ];
    }

    // Fetch all schools linked to this course via course_schools JOIN schools
    $stmt = $mysqli->prepare("
        SELECT s.id, s.name, s.logo, s.address, s.city_id, s.province_id, s.city, s.province, s.district_id, 
               s.contact, s.email, s.website, s.type, s.status, s.created_at,
               MAX(cs.is_specialization) AS is_specialization, MIN(cs.notes) AS notes,
               d.name AS district_name
        FROM course_schools cs
        JOIN schools s ON cs.school_id = s.id
        LEFT JOIN districts d ON s.district_id = d.id
        WHERE cs.course_id = ? AND s.status = 'active'
        GROUP BY s.id, d.name
    ");

    if (!$stmt) {
        return [
            'status' => 'no_schools',
            'message' => "No schools are currently listed as offering this course in our system. \nWe recommend asking your school counselor directly about available options.",
            'top_schools' => [],
            'all_schools' => []
        ];
    }

    $stmt->bind_param("i", $courseId);
    $stmt->execute();
    $result = $stmt->get_result();
    $schools = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // If NO schools are linked to this course at all, return 'no_schools' with message
    if (empty($schools)) {
        return [
            'status' => 'no_schools',
            'message' => "No schools are currently listed as offering this course in our system. \nWe recommend asking your school counselor directly about available options.",
            'top_schools' => [],
            'all_schools' => []
        ];
    }

    $hasRegion = ($selectedRegion !== null && $selectedRegion !== '' && strcasecmp((string)$selectedRegion, 'All Regions') !== 0 && strcasecmp((string)$selectedRegion, 'All') !== 0);
    $selectedDistricts = $hasRegion ? array_map('trim', explode(',', (string)$selectedRegion)) : [];

    $inDistrictSchools = [];
    $outOfDistrictSchools = [];

    foreach ($schools as $school) {
        $dist = (string)($school['district_id'] ?? '');
        if ($hasRegion && !in_array($dist, $selectedDistricts)) {
            $outOfDistrictSchools[] = $school;
        } else {
            $inDistrictSchools[] = $school;
        }
    }

    // Sort function: Specialized first, then alphabetical
    $sortFunction = function($a, $b) {
        $specA = (int)($a['is_specialization'] ?? 0);
        $specB = (int)($b['is_specialization'] ?? 0);
        
        if ($specA !== $specB) {
            return $specB <=> $specA;
        }
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    };

    usort($inDistrictSchools, $sortFunction);
    usort($outOfDistrictSchools, $sortFunction);

    return [
        'status' => 'ok',
        'message' => null,
        'in_district_schools' => $inDistrictSchools,
        'alternative_schools' => $outOfDistrictSchools,
        'has_region' => $hasRegion,
        'selected_region' => $selectedRegion
    ];
}

/**
 * STEP 7: School Recommendations with Specialization + Region Ranking
 * For each of the top 5 recommended courses, query course_schools joined with schools to get all schools offering that course.
 * Sort them using priority order based on region and specialization.
 * Attach this sorted schools array to each course's recommendation output.
 * If no schools are linked to a course, return an empty array.
 */
function getSchoolRecommendationsForCourses($mysqli, array $courses, $selectedRegion = null, $assessmentId = null) {
    // If $selectedRegion is null/empty and $assessmentId is provided, look up preferred_region
    if (($selectedRegion === null || $selectedRegion === '') && $assessmentId !== null) {
        $stmt = $mysqli->prepare("SELECT preferred_region FROM student_assessments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $assessmentId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res) {
                $selectedRegion = $res['preferred_region'];
            }
            $stmt->close();
        }
    }

    foreach ($courses as &$course) {
        $courseId = $course['course_id'] ?? $course['id'] ?? 0;
        
        // Fetch recommendations using the custom logic
        $schoolsData = getRecommendedSchools($courseId, $selectedRegion);
        
        // Integrate the structured result format and list of top schools for compatibility
        $course['in_district_schools'] = $schoolsData['in_district_schools'];
        $course['alternative_schools'] = $schoolsData['alternative_schools'];
        $course['has_region'] = $schoolsData['has_region'];
        $course['selected_region'] = $schoolsData['selected_region'];
        $course['school_recommendations'] = $schoolsData;
    }
    return $courses;
}

function generateRecommendations($mysqli, $assessmentId, $selectedRegion = null, $forceRegenerate = false) {
    // Step 1: Load student profile
    $stmt = $mysqli->prepare("
        SELECT sa.student_id, sa.preferred_region, s.strand_id, st.code AS strand_code
        FROM student_assessments sa
        JOIN students s ON sa.student_id = s.id
        LEFT JOIN strands st ON s.strand_id = st.id
        WHERE sa.id = ?
    ");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        return;
    }

    $studentId = (int) $result['student_id'];

    // Fetch existing explanations to prevent unnecessary re-generation
    $existingRecsStmt = $mysqli->prepare('
        SELECT r.course_id, r.explanation, r.match_percentage, c.description 
        FROM recommendations r
        JOIN courses c ON r.course_id = c.id
        WHERE r.assessment_id = ?
    ');
    $existingExplanations = [];
    if ($existingRecsStmt) {
        $existingRecsStmt->bind_param('i', $assessmentId);
        $existingRecsStmt->execute();
        $existingRes = $existingRecsStmt->get_result();
        while ($row = $existingRes->fetch_assoc()) {
            $isFallback = strpos($row['explanation'], 'Your strongest alignment for this degree comes from your') === 0;
            $isDescription = trim($row['explanation']) === trim($row['description']);
            
            if (!$isFallback && !$isDescription) {
                $existingExplanations[$row['course_id']] = [
                    'explanation' => $row['explanation'],
                    'match_percentage' => $row['match_percentage']
                ];
            }
        }
        $existingRecsStmt->close();
    }

    // Remove any prior recommendations for this assessment
    $deleteStmt = $mysqli->prepare('DELETE FROM recommendations WHERE assessment_id = ?');
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $assessmentId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    // Step 2: Score all courses directly using the new 1-stage model
    $scoredCourses = calculateCourseScores($mysqli, $assessmentId);

    if (empty($scoredCourses)) {
        return;
    }

    // Keep the top 5 courses
    $topCourses = array_slice($scoredCourses, 0, 5);

    // Step 3: Persist ranked recommendations with real calculated percentages
    // Build batch payload for Gemini and reuse valid existing explanations
    $batchPayload = [];
    $explanationsMap = [];
    foreach ($topCourses as $course) {
        $courseId = $course['id'];
        $currentMatchPct = $course['match_percentage'];
        
        if (!$forceRegenerate && isset($existingExplanations[$courseId]) && $existingExplanations[$courseId]['match_percentage'] == $currentMatchPct) {
            $explanationsMap[$courseId] = $existingExplanations[$courseId]['explanation'];
        } else {
            $breakdown = $course['breakdown'] ?? [];
            $batchPayload[] = [
                'courseId' => $courseId,
                'courseName' => $course['course_name'],
                'clusterName' => $course['cluster_name'],
                'clusterAlignment' => $breakdown['cluster_alignment'] ?? 50.0,
                'careerScore' => $breakdown['career_part'] ?? 50.0,
                'personalityScore' => $breakdown['personality_part'] ?? 50.0,
                'strandScore' => $breakdown['strand_part'] ?? 50.0,
                'skillsScore' => $breakdown['skills_part'] ?? 50.0,
                'finalScore' => $currentMatchPct
            ];
        }
    }
    
    // Call Gemini once for all new courses that need explanations
    if (!empty($batchPayload)) {
        if (php_sapi_name() === 'cli') {
            echo "Requesting " . count($batchPayload) . " new AI explanations in a single API call...\n";
        }
        $newExplanations = fetchBatchAIExplanations($batchPayload);
        foreach ($newExplanations as $courseId => $explanation) {
            $explanationsMap[$courseId] = $explanation;
        }
    }

    $rank = 1;
    foreach ($topCourses as $course) {
        $courseId = $course['id'];
        $explanation = $explanationsMap[$courseId] ?? '';

        $recStmt = $mysqli->prepare("
            INSERT INTO recommendations (assessment_id, course_id, match_percentage, explanation, rank)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$recStmt) {
            continue;
        }

        $recStmt->bind_param(
            'iidsi',
            $assessmentId,
            $courseId,
            $course['match_percentage'],
            $explanation,
            $rank
        );
        $recStmt->execute();
        $recStmt->close();

        $rank++;
    }

    // STEP 6: Job Recommendations
    $topCourses = getJobRecommendationsForCourses($mysqli, $topCourses);

    // STEP 7: School Recommendations with Specialization + Region Ranking
    $topCourses = getSchoolRecommendationsForCourses($mysqli, $topCourses, $selectedRegion, $assessmentId);

    // ── Notify student: results are ready ─────────────────────────────────
    if ($studentId > 0) {
        notify_student(
            $studentId,
            'Your Results Are Ready',
            'Your Results Are Ready — View your career and course recommendations now.',
            'info',
            'assessment_results.php'
        );
    }

    return $topCourses;
}

/**
 * Format a region string (like "1" or "1,2") into a readable label (like "1st District" or "1st and 2nd Districts")
 */
function formatDistrictLabel($regionStr) {
    if (empty($regionStr) || strcasecmp($regionStr, 'all') === 0 || strcasecmp($regionStr, 'all districts') === 0 || strcasecmp($regionStr, 'all regions') === 0) {
        return 'All Districts';
    }
    
    $parts = array_map('trim', explode(',', $regionStr));
    $formattedParts = [];
    $allNumeric = true;
    
    foreach ($parts as $p) {
        if (is_numeric($p)) {
            $suffixes = ['1'=>'st','2'=>'nd','3'=>'rd'];
            $s = $suffixes[$p] ?? 'th';
            $formattedParts[] = $p . $s;
        } else {
            $formattedParts[] = htmlspecialchars($p);
            $allNumeric = false;
        }
    }
    
    if (count($formattedParts) > 1) {
        $last = array_pop($formattedParts);
        $lbl = implode(', ', $formattedParts) . ' and ' . $last;
        if ($allNumeric) {
            $lbl .= ' Districts';
        }
        return $lbl;
    } elseif (count($formattedParts) === 1) {
        if (is_numeric($parts[0])) {
            return $formattedParts[0] . ' District';
        } else {
            return $formattedParts[0];
        }
    }
    
    return htmlspecialchars($regionStr);
}
