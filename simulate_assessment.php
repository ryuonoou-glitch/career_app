<?php
require_once __DIR__ . '/config.php';

// We need a logged-in session, so we'll pass a cookie.
// But it's easier to just temporarily set a backdoor in api/assessment.php
// Or just let's log in via a POST to api/login.php and grab the cookie!

// Actually, I can just use curl with cookies!
$cookieFile = __DIR__ . '/cookie.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/career_app/api/auth.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'action' => 'login',
    'student_id' => '400123450007', // Ryu Nou
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$loginRes = curl_exec($ch);
echo "Login: $loginRes\n";

// 1. Create Assessment
curl_setopt($ch, CURLOPT_URL, 'http://localhost/career_app/api/assessment.php');
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['action' => 'create']));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$createRes = curl_exec($ch);
echo "Create: $createRes\n";
$createData = json_decode($createRes, true);

if (empty($createData['assessment_id'])) {
    die("Failed to create assessment");
}

$assessmentId = $createData['assessment_id'];
echo "Assessment ID: $assessmentId\n";

// 2. Insert answers (via direct DB since it's faster than 100 API calls)
$questions = [
    ['table' => 'questions_career', 'type' => 'career'],
    ['table' => 'questions_personality', 'type' => 'personality'],
    ['table' => 'questions_skills', 'type' => 'skills'],
    ['table' => 'questions_strand', 'type' => 'strand']
];

foreach ($questions as $q) {
    $stmt = $mysqli->query("SELECT id FROM {$q['table']} LIMIT 10");
    while ($row = $stmt->fetch_assoc()) {
        $qid = $row['id'];
        $insert = $mysqli->prepare("INSERT INTO student_answers (assessment_id, question_id, question_type, likert_value) VALUES (?, ?, ?, 3)");
        $insert->bind_param("iis", $assessmentId, $qid, $q['type']);
        $insert->execute();
        $insert->close();
    }
}
echo "Dummy answers inserted.\n";

// 3. Complete Assessment
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'action' => 'complete',
    'assessment_id' => $assessmentId,
    'total_time' => 300
]));
$completeRes = curl_exec($ch);
echo "Complete: $completeRes\n";

curl_close($ch);
