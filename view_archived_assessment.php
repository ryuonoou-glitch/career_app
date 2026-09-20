<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';

// Check if admin or counselor is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    header('Location: admin_login.php');
    exit();
}

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    die("Invalid student ID.");
}

// Fetch student details
$stmt = $mysqli->prepare("SELECT first_name, last_name, student_id FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student not found.");
}

// Fetch archived assessment
$stmt = $mysqli->prepare("SELECT snapshot_json, archived_at, grade_level FROM archived_assessments WHERE student_id = ? ORDER BY archived_at DESC LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$archive = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$archive) {
    die("No archived assessment found for this student.");
}

$snapshot = json_decode($archive['snapshot_json'], true);
$assessment = $snapshot['assessment'] ?? [];
$categoryScores = $snapshot['category_scores'] ?? [];
$recommendations = $snapshot['recommendations'] ?? [];

// Helper to get course names since course_id is in recommendations
$courseNames = [];
if (!empty($recommendations)) {
    $courseIds = array_column($recommendations, 'course_id');
    if (!empty($courseIds)) {
        $idsStr = implode(',', array_map('intval', $courseIds));
        $res = $mysqli->query("SELECT id, course_name FROM courses WHERE id IN ($idsStr)");
        while($row = $res->fetch_assoc()) {
            $courseNames[$row['id']] = $row['course_name'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Assessment - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .archive-header { background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; }
        .archive-header h2 { color: #f59e0b; margin: 0 0 0.5rem 0; }
        .archive-header p { color: #cbd5e1; margin: 0; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; }
        .card h3 { margin-top: 0; color: #f1f5f9; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; }
        .score-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(148, 163, 184, 0.1); color: #cbd5e1; }
        .score-row:last-child { border-bottom: none; }
        .badge { background: #38bdf8; color: #0f172a; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: 600; font-size: 0.8rem; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #94a3b8; text-decoration: none; margin-bottom: 1rem; transition: color 0.2s; }
        .back-link:hover { color: #f8fafc; }
    </style>
</head>
<body>
    <div class="dashboard-container" style="padding: 2rem; max-width: 1200px; margin: 0 auto; height: auto; min-height: 100vh;">
        <?php 
        $backUrl = 'reports.php';
        $backText = 'Back to Reports';
        ?>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?php echo htmlspecialchars($backText); ?></a>
        
        <div class="archive-header">
            <h2><i class="fa-solid fa-box-archive"></i> Historical Record: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
            <p><strong>School ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?> &nbsp;|&nbsp; <strong>Archived On:</strong> <?php echo date('F d, Y', strtotime($archive['archived_at'])); ?> &nbsp;|&nbsp; <strong>Grade Level:</strong> <?php echo htmlspecialchars($archive['grade_level']); ?></p>
        </div>

        <div class="grid-2">
            <div class="card">
                <h3><i class="fa-solid fa-chart-bar"></i> Category Scores</h3>
                <?php if (empty($categoryScores)): ?>
                    <p>No category scores recorded.</p>
                <?php else: ?>
                    <?php foreach ($categoryScores as $cs): ?>
                        <div class="score-row">
                            <span><?php echo htmlspecialchars($cs['category']); ?></span>
                            <span><?php echo htmlspecialchars($cs['percentage']); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                    <div class="score-row" style="margin-top: 1rem; border-top: 2px solid var(--border-color); padding-top: 1rem;">
                        <strong>Total Score</strong>
                        <strong><?php echo htmlspecialchars($assessment['total_score'] ?? '0'); ?> / <?php echo htmlspecialchars($assessment['max_score'] ?? '0'); ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3><i class="fa-solid fa-bullseye"></i> Top Course Recommendations</h3>
                <?php if (empty($recommendations)): ?>
                    <p>No recommendations recorded.</p>
                <?php else: ?>
                    <?php foreach ($recommendations as $rec): ?>
                        <div class="score-row" style="align-items: center;">
                            <span><?php echo htmlspecialchars($courseNames[$rec['course_id']] ?? 'Unknown Course'); ?></span>
                            <span class="badge">Match: <?php echo htmlspecialchars($rec['match_percentage']); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
