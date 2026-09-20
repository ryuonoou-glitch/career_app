<?php
require_once 'config.php';
require_once 'system_config.php';
require_once __DIR__ . '/vendor/autoload.php';

requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;

$student = getCurrentStudent();
if (!$student) {
    header('Location: login.php');
    exit;
}

// Construct full student name (middle name as initial: John D. Doe)
$nameParts = [];
if (!empty($student['first_name'])) $nameParts[] = $student['first_name'];
if (!empty($student['middle_name'])) $nameParts[] = strtoupper(substr($student['middle_name'], 0, 1)) . '.';
if (!empty($student['last_name'])) $nameParts[] = $student['last_name'];
$studentName = trim(implode(' ', $nameParts));
if (!empty($student['suffix'])) $studentName .= ' ' . $student['suffix'];

// System info
$systemName = getSystemConfig('full_name') ?: getSystemConfig('short_name') ?: 'Career Guidance System';
$systemShortName = getSystemConfig('short_name') ?: 'CGS';

// ── Fetch latest assessment ──────────────────────────────────────────────────
$assessmentId   = null;
$completionDate = null;
$totalScore     = 0;
$careerScore = $personalityScore = $skillsScore = $strandScore = 0;

$assessmentIdParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assessmentIdParam > 0) {
    $stmt = $mysqli->prepare(
        "SELECT id, completed_at, total_score FROM student_assessments
         WHERE id = ? AND student_id = ? AND status = 'completed' LIMIT 1"
    );
    $stmt->bind_param("ii", $assessmentIdParam, $student['id']);
} else {
    $stmt = $mysqli->prepare(
        "SELECT id, completed_at, total_score FROM student_assessments
         WHERE student_id = ? AND status = 'completed'
         ORDER BY completed_at DESC LIMIT 1"
    );
    $stmt->bind_param("i", $student['id']);
}
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assessment) {
    // No results – redirect back
    header('Location: assessment_results.php');
    exit;
}

$assessmentId   = $assessment['id'];
$completionDate = date('F d, Y', strtotime($assessment['completed_at']));
$totalScore     = round((float)$assessment['total_score'], 1);

// ── Category scores ──────────────────────────────────────────────────────────
$stmt = $mysqli->prepare(
    "SELECT category, percentage
     FROM category_scores WHERE assessment_id = ?"
);
$stmt->bind_param("i", $assessmentId);
$stmt->execute();
$catResult = $stmt->get_result();
while ($row = $catResult->fetch_assoc()) {
    $pct = round((float)$row['percentage'], 1);
    switch ($row['category']) {
        case 'career':      $careerScore      = $pct; break;
        case 'personality': $personalityScore = $pct; break;
        case 'skills':      $skillsScore      = $pct; break;
        case 'strand':      $strandScore      = $pct; break;
    }
}
$stmt->close();

// Overall avg for display
$overallScore = round(($careerScore + $personalityScore + $skillsScore + $strandScore) / 4, 1);

// ── Recommended Courses ──────────────────────────────────────────────────────
$recommendations = [];
$stmt = $mysqli->prepare(
    "SELECT c.course_name, c.description, c.possible_careers, r.match_percentage, r.explanation, r.rank
     FROM recommendations r
     JOIN courses c ON r.course_id = c.id
     JOIN student_assessments sa ON r.assessment_id = sa.id
     WHERE sa.student_id = ?
     ORDER BY r.rank ASC, r.match_percentage DESC"
);
$stmt->bind_param("i", $student['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recommendations[] = $row;
$stmt->close();

// ── Recommended Schools ──────────────────────────────────────────────────────
$recommendedSchools = [];
$stmt = $mysqli->prepare(
    "SELECT DISTINCT s.name, s.address, s.email, s.contact, s.website,
            c.course_name, r.match_percentage
     FROM schools s
     JOIN course_schools cs ON s.id = cs.school_id
     JOIN courses c ON cs.course_id = c.id
     JOIN recommendations r ON c.id = r.course_id
     JOIN student_assessments sa ON r.assessment_id = sa.id
     WHERE sa.student_id = ? AND sa.status = 'completed'
     ORDER BY r.match_percentage DESC, s.name ASC"
);
$stmt->bind_param("i", $student['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recommendedSchools[] = $row;
$stmt->close();

// ── Collect all unique job / career recommendations ──────────────────────────
$allCareers = [];
foreach ($recommendations as $course) {
    if (!empty($course['possible_careers'])) {
        $parts = array_map('trim', explode(',', $course['possible_careers']));
        foreach ($parts as $career) {
            if ($career !== '' && !in_array($career, $allCareers)) {
                $allCareers[] = $career;
            }
        }
    }
}

// ── Helper: progress bar HTML ─────────────────────────────────────────────────
function progressBar(float $pct, string $color): string {
    $safe = htmlspecialchars($pct . '%');
    return "
    <div style='background:#e8edf2;border-radius:8px;height:12px;overflow:hidden;margin-top:6px;'>
      <div style='background:{$color};width:{$safe};height:12px;border-radius:8px;'></div>
    </div>";
}

// ── Score colour helper ───────────────────────────────────────────────────────
function scoreColor(float $pct): string {
    if ($pct >= 80) return '#10b981';
    if ($pct >= 60) return '#3b82f6';
    if ($pct >= 40) return '#f59e0b';
    return '#ef4444';
}

function scoreLabel(float $pct): string {
    if ($pct >= 80) return 'Excellent';
    if ($pct >= 60) return 'Good';
    if ($pct >= 40) return 'Average';
    return 'Needs Improvement';
}

// ── Build HTML for PDF ────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 11px;
    color: #1e293b;
    background: #fff;
    line-height: 1.5;
  }

  /* ── COVER HEADER ── */
  .cover-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d6a9f 50%, #1a5276 100%);
    color: #fff;
    padding: 32px 36px 28px;
    margin-bottom: 24px;
  }
  .cover-header .org-name {
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
  }
  .cover-header .doc-title {
    font-size: 26px;
    font-weight: bold;
    margin: 10px 0 6px;
    letter-spacing: 1px;
  }
  .cover-header .doc-subtitle {
    font-size: 12px;
    opacity: 0.85;
  }
  .cover-header .meta-row {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
    font-size: 11px;
    opacity: 0.9;
  }
  .cover-header .meta-item strong {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.75;
    margin-bottom: 2px;
  }

  /* ── SECTION TITLES ── */
  .section-title {
    font-size: 14px;
    font-weight: bold;
    color: #1e3a5f;
    border-left: 4px solid #2d6a9f;
    padding: 4px 0 4px 12px;
    margin: 0 24px 14px;
  }

  /* ── OVERALL SCORE BANNER ── */
  .overall-banner {
    margin: 0 24px 24px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #93c5fd;
    border-radius: 10px;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .overall-circle {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .overall-circle .ov-num {
    color: #fff;
    font-size: 20px;
    font-weight: bold;
    text-align: center;
    line-height: 1;
  }
  .overall-circle .ov-unit {
    color: rgba(255,255,255,0.8);
    font-size: 9px;
    text-align: center;
  }
  .overall-info h3 {
    font-size: 15px;
    font-weight: bold;
    color: #1e3a5f;
    margin-bottom: 3px;
  }
  .overall-info p { color: #475569; font-size: 11px; }

  /* ── SCORE CARDS GRID ── */
  .score-grid {
    margin: 0 24px 24px;
    display: table;
    width: calc(100% - 48px);
    border-collapse: separate;
    border-spacing: 10px 0;
  }
  .score-grid-row { display: table-row; }
  .score-cell {
    display: table-cell;
    width: 25%;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 12px;
    vertical-align: top;
    text-align: center;
  }
  .score-cell .cat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 6px;
    font-weight: bold;
  }
  .score-cell .cat-number {
    font-size: 22px;
    font-weight: bold;
    margin-bottom: 2px;
  }
  .score-cell .cat-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: bold;
    color: #fff;
    margin-top: 4px;
  }

  /* ── CONTENT AREA ── */
  .content-area { margin: 0 24px 20px; }

  /* ── COURSE ITEM ── */
  .course-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #2d6a9f;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 10px;
    page-break-inside: avoid;
  }
  .course-item .course-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
  }
  .course-item h4 {
    font-size: 12px;
    font-weight: bold;
    color: #1e3a5f;
    flex: 1;
  }
  .match-badge {
    background: #2d6a9f;
    color: #fff;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: bold;
    white-space: nowrap;
    margin-left: 10px;
  }
  .course-item p { color: #475569; font-size: 10px; margin-bottom: 4px; }
  .course-item .careers-line {
    color: #0f766e;
    font-size: 10px;
    font-weight: bold;
    margin-top: 4px;
  }
  .course-item .why-line {
    color: #64748b;
    font-size: 10px;
    font-style: italic;
    margin-top: 4px;
    border-top: 1px solid #e2e8f0;
    padding-top: 4px;
  }

  /* ── SCHOOL ITEM ── */
  .school-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #059669;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 10px;
    page-break-inside: avoid;
  }
  .school-item h4 {
    font-size: 12px;
    font-weight: bold;
    color: #065f46;
    margin-bottom: 5px;
  }
  .school-item .info-row { color: #475569; font-size: 10px; margin-bottom: 2px; }
  .school-item .course-tag {
    display: inline-block;
    background: #d1fae5;
    color: #065f46;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: bold;
    margin-top: 5px;
  }

  /* ── JOB PILLS ── */
  .jobs-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .job-pill {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #93c5fd;
    color: #1e40af;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
    white-space: nowrap;
  }

  /* ── FOOTER ── */
  .pdf-footer {
    margin-top: 28px;
    border-top: 1px solid #e2e8f0;
    padding: 12px 24px 0;
    display: flex;
    justify-content: space-between;
    font-size: 9px;
    color: #94a3b8;
  }

  /* ── PAGE BREAK ── */
  .page-break { page-break-before: always; }

  /* ── NO DATA ── */
  .no-data { color: #94a3b8; font-style: italic; font-size: 11px; text-align: center; padding: 16px; }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════ HEADER -->
<div class="cover-header">
  <div class="org-name"><?= htmlspecialchars($systemName) ?></div>
  <div class="doc-title">Assessment Results Report</div>
  <div class="doc-subtitle">Comprehensive Career Guidance Assessment Summary</div>
  <div class="meta-row">
    <div class="meta-item">
      <strong>Student Name</strong>
      <?= htmlspecialchars($studentName) ?>
    </div>
    <div class="meta-item">
      <strong>Student ID</strong>
      <?= htmlspecialchars($student['student_id'] ?? $student['id']) ?>
    </div>
    <div class="meta-item">
      <strong>Assessment Date</strong>
      <?= htmlspecialchars($completionDate) ?>
    </div>
    <div class="meta-item">
      <strong>Generated</strong>
      <?= date('F d, Y') ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ OVERALL SCORE -->
<div class="section-title">Overall Assessment Score</div>
<div class="overall-banner">
  <div class="overall-circle">
    <div>
      <div class="ov-num"><?= $overallScore ?></div>
      <div class="ov-unit">/ 100%</div>
    </div>
  </div>
  <div class="overall-info">
    <h3><?= scoreLabel($overallScore) ?> Performance</h3>
    <p>Based on your responses across all four assessment categories, you scored an average of <strong><?= $overallScore ?>%</strong>. This report outlines your individual category scores and personalized recommendations to guide your academic and career journey.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════ CATEGORY SCORES -->
<div class="section-title">Category Scores</div>
<table class="score-grid">
  <tr class="score-grid-row">
    <td class="score-cell">
      <div class="cat-label">Career</div>
      <div class="cat-number" style="color:<?= scoreColor($careerScore) ?>"><?= $careerScore ?>%</div>
      <?= progressBar($careerScore, scoreColor($careerScore)) ?>
      <span class="cat-badge" style="background:<?= scoreColor($careerScore) ?>"><?= scoreLabel($careerScore) ?></span>
    </td>
    <td class="score-cell">
      <div class="cat-label">Personality</div>
      <div class="cat-number" style="color:<?= scoreColor($personalityScore) ?>"><?= $personalityScore ?>%</div>
      <?= progressBar($personalityScore, scoreColor($personalityScore)) ?>
      <span class="cat-badge" style="background:<?= scoreColor($personalityScore) ?>"><?= scoreLabel($personalityScore) ?></span>
    </td>
    <td class="score-cell">
      <div class="cat-label">Skills</div>
      <div class="cat-number" style="color:<?= scoreColor($skillsScore) ?>"><?= $skillsScore ?>%</div>
      <?= progressBar($skillsScore, scoreColor($skillsScore)) ?>
      <span class="cat-badge" style="background:<?= scoreColor($skillsScore) ?>"><?= scoreLabel($skillsScore) ?></span>
    </td>
    <td class="score-cell">
      <div class="cat-label">Strand</div>
      <div class="cat-number" style="color:<?= scoreColor($strandScore) ?>"><?= $strandScore ?>%</div>
      <?= progressBar($strandScore, scoreColor($strandScore)) ?>
      <span class="cat-badge" style="background:<?= scoreColor($strandScore) ?>"><?= scoreLabel($strandScore) ?></span>
    </td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════ RECOMMENDED COURSES -->
<div class="section-title">Recommended Courses</div>
<div class="content-area">
  <?php if (empty($recommendations)): ?>
    <div class="no-data">No course recommendations available yet. Complete an assessment to receive personalized recommendations.</div>
  <?php else: ?>
    <?php foreach ($recommendations as $idx => $course): ?>
    <div class="course-item">
      <div class="course-top">
        <h4><?= ($idx + 1) ?>. <?= htmlspecialchars($course['course_name']) ?></h4>
        <span class="match-badge"><?= number_format($course['match_percentage'], 1) ?>% Match</span>
      </div>
      <?php if (!empty($course['description'])): ?>
        <p><?= htmlspecialchars($course['description']) ?></p>
      <?php endif; ?>
      <?php if (!empty($course['possible_careers'])): ?>
        <div class="careers-line">Possible Careers: <?= htmlspecialchars($course['possible_careers']) ?></div>
      <?php endif; ?>
      <?php if (!empty($course['explanation'])): ?>
        <div class="why-line">Why this course: <?= htmlspecialchars($course['explanation']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════ RECOMMENDED SCHOOLS -->
<div class="page-break"></div>
<div class="section-title">Recommended Schools</div>
<div class="content-area">
  <?php if (empty($recommendedSchools)): ?>
    <div class="no-data">No school recommendations available. Complete an assessment to see schools that match your recommended courses.</div>
  <?php else: ?>
    <?php foreach ($recommendedSchools as $school): ?>
    <div class="school-item">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <h4><?= htmlspecialchars($school['name']) ?></h4>
        <?php if (isset($school['match_percentage'])): ?>
          <span class="match-badge" style="background:#059669;"><?= number_format($school['match_percentage'], 1) ?>% Match</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($school['address'])): ?>
        <div class="info-row">📍 <?= htmlspecialchars($school['address']) ?></div>
      <?php endif; ?>
      <?php if (!empty($school['email'])): ?>
        <div class="info-row">✉ <?= htmlspecialchars($school['email']) ?></div>
      <?php endif; ?>
      <?php if (!empty($school['contact'])): ?>
        <div class="info-row">📞 <?= htmlspecialchars($school['contact']) ?></div>
      <?php endif; ?>
      <?php if (!empty($school['website'])): ?>
        <div class="info-row">🌐 <?= htmlspecialchars($school['website']) ?></div>
      <?php endif; ?>
      <?php if (!empty($school['course_name'])): ?>
        <span class="course-tag"><?= htmlspecialchars($school['course_name']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════ JOB RECOMMENDATIONS -->
<div class="section-title">Job Recommendations</div>
<div class="content-area">
  <?php if (empty($allCareers)): ?>
    <div class="no-data">No job recommendations available yet.</div>
  <?php else: ?>
    <div class="jobs-grid">
      <?php foreach ($allCareers as $career): ?>
        <span class="job-pill">💼 <?= htmlspecialchars($career) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════ FOOTER -->
<div class="pdf-footer">
  <span><?= htmlspecialchars($systemName) ?> – Confidential Student Report</span>
  <span>Generated on <?= date('F d, Y \a\t h:i A') ?></span>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── Render PDF ────────────────────────────────────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('dpi', 120);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'Assessment_Results_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $studentName) . '_' . date('Ymd') . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);
exit;
