<?php
/**
 * rescore_all.php
 * ---------------
 * Re-runs the full scoring pipeline (saveCategoryScores ? saveCompetencyScores ?
 * generateRecommendations) for every completed assessment in the database.
 *
 * Run via browser: http://localhost/career_app/tools/rescore_all.php?token=rescore2025
 * Or via CLI:      php tools/rescore_all.php
 */

// -- Security ------------------------------------------------------------------
define('RESCORE_SECRET', 'rescore2025');

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($token !== RESCORE_SECRET) {
        http_response_code(403);
        die('<h2 style="font-family:monospace;color:red;">403 Forbidden — pass ?token=rescore2025 to run.</h2>');
    }
}

// -- Bootstrap -----------------------------------------------------------------
chdir(__DIR__ . '/../');
require_once 'config.php';
require_once 'includes/recommendation_scoring.php';

// -- Output helper -------------------------------------------------------------
function out(string $msg, bool $nl = true): void {
    global $isCli;
    if ($isCli) {
        echo $msg . ($nl ? "\n" : '');
    } else {
        echo $msg . ($nl ? '<br>' : '');
        ob_flush(); flush();
    }
}

// -- HTML wrapper (browser only) -----------------------------------------------
if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Rescore All</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0f172a;color:#e2e8f0;font-family:"Courier New",monospace;padding:2rem;font-size:.83rem;line-height:1.8}
        h1{color:#a78bfa;font-size:1.4rem;margin-bottom:.5rem}
        .ok{color:#34d399}.err{color:#f87171}.dim{color:#64748b}.warn{color:#fbbf24}
        hr{border:none;border-top:1px solid #334155;margin:.6rem 0}
        .summary{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:1.2rem;margin-top:1.5rem;max-width:480px}
        .summary p{margin-bottom:.3rem}
    </style></head><body>';
    echo '<h1>&#x1F504; Rescore All Students</h1><hr>';
    ob_start();
}

// -- Fetch all completed assessments ------------------------------------------
$res = $mysqli->query("
    SELECT sa.id AS assessment_id, sa.student_id, sa.preferred_region,
           s.first_name, s.last_name
    FROM student_assessments sa
    JOIN students s ON sa.student_id = s.id
    WHERE sa.status = 'completed'
    ORDER BY sa.id ASC
");

if (!$res) {
    out('<span class="err">ERROR: Could not query assessments — ' . htmlspecialchars($mysqli->error) . '</span>');
    exit(1);
}

$total   = $res->num_rows;
$success = 0;
$failed  = 0;
$startTime = microtime(true);

out("<span class=\"dim\">Found {$total} completed assessment(s). Starting rescore...</span>");
out('<hr>');

while ($row = $res->fetch_assoc()) {
    $assessmentId = (int) $row['assessment_id'];
    $studentId    = (int) $row['student_id'];
    $name         = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: "Student #{$studentId}";
    $region       = $row['preferred_region'] ?? null;

    out("Assessment #<b>{$assessmentId}</b> &mdash; {$name} &hellip; ", false);

    try {
        // 1. Recompute per-category averages
        saveCategoryScores($mysqli, $assessmentId);

        // 2. Recompute per-competency scores
        saveCompetencyScores($mysqli, $assessmentId);

        // 3. Recompute total_score
        $scoreStmt = $mysqli->prepare("SELECT AVG(percentage) AS avg FROM category_scores WHERE assessment_id = ?");
        $scoreStmt->bind_param('i', $assessmentId);
        $scoreStmt->execute();
        $totalScore = (float)($scoreStmt->get_result()->fetch_assoc()['avg'] ?? 0);
        $scoreStmt->close();

        $updateStmt = $mysqli->prepare("UPDATE student_assessments SET total_score = ? WHERE id = ?");
        $updateStmt->bind_param('di', $totalScore, $assessmentId);
        $updateStmt->execute();
        $updateStmt->close();

        // 4. Regenerate recommendations (force = true to refresh AI explanations)
        generateRecommendations($mysqli, $assessmentId, $region, true);

        out('<span class="ok">&#10003; OK</span> (score=' . round($totalScore, 1) . '%)');
        $success++;

    } catch (Throwable $e) {
        out('<span class="err">&#10007; FAILED: ' . htmlspecialchars($e->getMessage()) . '</span>');
        $failed++;
    }
}

$elapsed = round(microtime(true) - $startTime, 2);

out('<hr>');

if (!$isCli) {
    $buf = ob_get_clean();
    echo $buf;
    echo '<div class="summary">';
    echo '<p><span class="ok">&#9989;  Success : ' . $success . '</span></p>';
    echo '<p><span class="err">&#10060;  Failed  : ' . $failed  . '</span></p>';
    echo '<p><span class="warn">&#9203;  Time    : ' . $elapsed . 's</span></p>';
    echo '</div>';
    echo '</body></html>';
} else {
    echo "?  Success : {$success}\n";
    echo "?  Failed  : {$failed}\n";
    echo "??  Time    : {$elapsed}s\n";
}

exit($failed > 0 ? 1 : 0);
