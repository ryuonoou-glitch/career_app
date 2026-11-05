<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration
require_once 'config.php';
require_once 'system_config.php';

// RBAC Permission Check
require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('manage_questions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handled via api/questions.php
}

// Fetch questions from database tables
$careerQuestions = [];
$personalityQuestions = [];
$skillsQuestions = [];
$strandQuestions = [];

// Fetch Career Questions
$result = $mysqli->query("
    SELECT qc.*, COALESCE(qc.holland_type, '') AS classification_value, st.name AS strand_name
    FROM questions_career qc
    LEFT JOIN strands st ON qc.strand_id = st.id
    WHERE qc.is_active = 1
    ORDER BY qc.id DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $careerQuestions[] = $row;
    }
}

// Fetch Personality Questions
$result = $mysqli->query("SELECT * FROM questions_personality WHERE is_active = 1 ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $personalityQuestions[] = $row;
    }
}

// Fetch Skills Questions
$result = $mysqli->query("
    SELECT qs.*,
           COALESCE(qs.skill_category, c.name, '') AS classification_value,
           c.name AS competency_name
    FROM questions_skills qs
    LEFT JOIN competencies c ON qs.competency_id = c.id
    WHERE qs.is_active = 1
    ORDER BY qs.id DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $skillsQuestions[] = $row;
    }
}

// Fetch Strand Questions
$result = $mysqli->query("
    SELECT qs.*,
           COALESCE(st.code, qs.strand, st.name, '') AS classification_value,
           st.name AS strand_name
    FROM questions_strand qs
    LEFT JOIN strands st ON qs.strand_id = st.id
    WHERE qs.is_active = 1
    ORDER BY qs.id DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $strandQuestions[] = $row;
    }
}

$personalityTraits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];

$skillCategories = [];
$skillResult = $mysqli->query('SELECT name FROM competencies ORDER BY name');
if ($skillResult) {
    while ($row = $skillResult->fetch_assoc()) {
        $skillCategories[] = $row['name'];
    }
}
if (empty($skillCategories)) {
    $skillCategories = ['Logical Reasoning', 'Mathematical Ability', 'Communication Skills', 'Business Acumen', 'Technical Aptitude', 'Creative Thinking', 'Analytical Skills', 'Leadership Ability', 'Interpersonal Skills', 'Research Skills'];
}

$strands = [];
$strandResult = $mysqli->query("SELECT name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY grade_level, name");
if ($strandResult) {
    while ($row = $strandResult->fetch_assoc()) {
        $strands[] = $row;
    }
}

$totalQuestionsCount = count($careerQuestions) + count($personalityQuestions) + count($skillsQuestions) + count($strandQuestions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Questions - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ==================================================================
           MANAGE QUESTIONS - ULTRA-PREMIUM REDESIGN STYLES
           ================================================================== */

        :root {
            --color-career: #f59e0b;
            --color-career-glow: rgba(245, 158, 11, 0.25);
            --color-career-bg: rgba(245, 158, 11, 0.12);
            --color-personality: #a855f7;
            --color-personality-glow: rgba(168, 85, 247, 0.25);
            --color-personality-bg: rgba(168, 85, 247, 0.12);
            --color-skills: #10b981;
            --color-skills-glow: rgba(16, 185, 129, 0.25);
            --color-skills-bg: rgba(16, 185, 129, 0.12);
            --color-strand: #0ea5e9;
            --color-strand-glow: rgba(14, 165, 233, 0.25);
            --color-strand-bg: rgba(14, 165, 233, 0.12);
        }

        /* -- Page Header Area -- */
        .mq-page-intro {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .mq-intro-left h2 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0 0 0.35rem 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .mq-intro-left h2 .title-icon-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.2), rgba(245, 158, 11, 0.08));
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: #fbbf24;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .mq-intro-left p {
            font-size: 0.88rem;
            color: #94a3b8;
            margin: 0;
            max-width: 680px;
            line-height: 1.5;
        }

        .mq-header-cta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn-add-question-hero {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.75rem 1.4rem;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
            color: #0f172a;
            border: none;
            border-radius: 12px;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 18px rgba(245, 158, 11, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-add-question-hero:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.4);
            background: linear-gradient(135deg, #fde68a 0%, #fbbf24 50%, #f59e0b 100%);
        }

        .btn-add-question-hero:active {
            transform: translateY(0);
        }

        .btn-add-question-hero i {
            font-size: 1rem;
            transition: transform 0.2s ease;
        }

        .btn-add-question-hero:hover i {
            transform: rotate(90deg);
        }

        /* -- KPI Stat Summary Cards Grid -- */
        .mq-stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.85rem;
            margin-bottom: 1.75rem;
        }

        .mq-stat-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.85));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 16px;
            padding: 0.95rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            min-width: 0;
        }

        .mq-stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: rgba(255, 255, 255, 0.1);
            transition: background 0.3s;
        }

        .mq-stat-card.stat-total::after { background: linear-gradient(90deg, #6366f1, #818cf8); }
        .mq-stat-card.stat-career::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .mq-stat-card.stat-personality::after { background: linear-gradient(90deg, #8b5cf6, #c084fc); }
        .mq-stat-card.stat-skills::after { background: linear-gradient(90deg, #10b981, #34d399); }
        .mq-stat-card.stat-strand::after { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }

        .mq-stat-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }

        .mq-stat-icon-wrapper {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }

        .mq-stat-card:hover .mq-stat-icon-wrapper {
            transform: scale(1.08);
        }

        .stat-total .mq-stat-icon-wrapper {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            border: 1px solid rgba(99, 102, 241, 0.25);
        }

        .stat-career .mq-stat-icon-wrapper {
            background: var(--color-career-bg);
            color: var(--color-career);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .stat-personality .mq-stat-icon-wrapper {
            background: var(--color-personality-bg);
            color: var(--color-personality);
            border: 1px solid rgba(168, 85, 247, 0.25);
        }

        .stat-skills .mq-stat-icon-wrapper {
            background: var(--color-skills-bg);
            color: var(--color-skills);
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .stat-strand .mq-stat-icon-wrapper {
            background: var(--color-strand-bg);
            color: var(--color-strand);
            border: 1px solid rgba(14, 165, 233, 0.25);
        }

        .mq-stat-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }

        .mq-stat-label {
            font-size: 0.70rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #94a3b8;
            margin-bottom: 2px;
            white-space: normal;
            line-height: 1.25;
            word-break: normal;
        }

        .mq-stat-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.15;
            margin: 1px 0;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        .mq-stat-meta {
            font-size: 0.70rem;
            color: #64748b;
            margin-top: 2px;
            white-space: normal;
            line-height: 1.25;
            word-break: normal;
        }

        /* -- Unified Multi-Filter & Search Toolbar -- */
        .mq-toolbar-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.75));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 0.85rem 1.15rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
        }

        .mq-toolbar-main-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .mq-search-wrapper {
            position: relative;
            flex: 1 1 280px;
            min-width: 240px;
            max-width: none;
        }

        .mq-search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.85rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .mq-search-input {
            width: 100%;
            height: 38px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 0 2.2rem 0 2.4rem;
            color: #f8fafc;
            font-size: 0.84rem;
            font-family: inherit;
            outline: none;
            transition: all 0.25s ease;
            box-sizing: border-box;
        }

        .mq-search-input::placeholder {
            color: #64748b;
        }

        .mq-search-input:focus {
            border-color: #fbbf24;
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
        }

        .mq-search-input:focus + .mq-search-icon {
            color: #fbbf24;
        }

        .mq-search-clear-btn {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.08);
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: none;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.2s ease;
        }

        .mq-search-clear-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .mq-search-clear-btn.active {
            display: flex;
        }

        .mq-filters-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            flex: 1 1 auto;
        }

        .mq-filter-select-box {
            position: relative;
            flex: 1 1 auto;
            min-width: 140px;
        }

        .mq-filter-select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
            height: 38px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 0 1.8rem 0 0.75rem;
            color: #cbd5e1;
            font-size: 0.82rem;
            font-weight: 500;
            font-family: inherit;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-sizing: border-box;
            white-space: nowrap;
        }

        .mq-filter-select:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
        }

        .mq-filter-select:focus {
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
        }

        .mq-filter-select-box i {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.7rem;
            pointer-events: none;
        }

        .btn-reset-filters {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            height: 38px;
            padding: 0 0.85rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #94a3b8;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            box-sizing: border-box;
            flex-shrink: 0;
        }

        .btn-reset-filters:hover {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .mq-toolbar-status-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding-top: 0.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .mq-results-count {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-feature-settings: "tnum";
            font-weight: 500;
            color: #94a3b8;
            font-size: 0.8rem;
            white-space: nowrap;
            padding: 0.3rem 0.75rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            margin-left: auto;
        }

        .mq-results-count strong {
            color: #f1f5f9;
            font-weight: 700;
        }

        .mq-active-filter-tags {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
            flex: 1 1 auto;
        }

        .mq-active-filter-tags:empty {
            display: none;
        }

        .mq-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.25);
            color: #fbbf24;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .mq-filter-chip button {
            background: none;
            border: none;
            color: #fbbf24;
            cursor: pointer;
            padding: 0;
            margin-left: 2px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
        }

        .mq-filter-chip button:hover {
            color: #ef4444;
        }

        /* -- Glassmorphic Segmented Category Navigation Tabs -- */
        .mq-tabs-wrapper {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 0.35rem;
            margin-bottom: 1.25rem;
            display: flex;
            gap: 0.4rem;
            overflow-x: auto;
            backdrop-filter: blur(12px);
        }

        .mq-tab-pill {
            flex: 1;
            min-width: 170px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.8rem 1.1rem;
            border-radius: 10px;
            border: 1px solid transparent;
            background: transparent;
            color: #94a3b8;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            position: relative;
        }

        .mq-tab-pill i {
            font-size: 1rem;
            transition: transform 0.2s ease;
        }

        .mq-tab-pill:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
        }

        .mq-tab-pill:hover i {
            transform: scale(1.1);
        }

        .mq-tab-pill .tab-count-badge {
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            transition: all 0.2s ease;
        }

        /* Active states per category */
        .mq-tab-pill[data-tab="career"].active {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.15));
            border-color: rgba(245, 158, 11, 0.4);
            color: #fbbf24;
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.2);
        }
        .mq-tab-pill[data-tab="career"].active .tab-count-badge {
            background: #f59e0b;
            color: #0f172a;
        }

        .mq-tab-pill[data-tab="personality"].active {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.2), rgba(147, 51, 234, 0.15));
            border-color: rgba(168, 85, 247, 0.4);
            color: #c084fc;
            box-shadow: 0 4px 16px rgba(168, 85, 247, 0.2);
        }
        .mq-tab-pill[data-tab="personality"].active .tab-count-badge {
            background: #a855f7;
            color: #0f172a;
        }

        .mq-tab-pill[data-tab="skills"].active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.15));
            border-color: rgba(16, 185, 129, 0.4);
            color: #34d399;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
        }
        .mq-tab-pill[data-tab="skills"].active .tab-count-badge {
            background: #10b981;
            color: #0f172a;
        }

        .mq-tab-pill[data-tab="strand"].active {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.2), rgba(2, 132, 199, 0.15));
            border-color: rgba(14, 165, 233, 0.4);
            color: #38bdf8;
            box-shadow: 0 4px 16px rgba(14, 165, 233, 0.2);
        }
        .mq-tab-pill[data-tab="strand"].active .tab-count-badge {
            background: #0ea5e9;
            color: #0f172a;
        }

        /* -- Data Table Glass Card & Styling -- */
        .mq-table-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.85));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            margin-bottom: 2rem;
        }

        .mq-table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .mq-data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .mq-data-table thead {
            background: rgba(15, 23, 42, 0.9);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .mq-data-table th {
            padding: 1rem 1.15rem;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            white-space: nowrap;
            user-select: none;
        }

        .mq-data-table tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: all 0.2s ease;
        }

        .mq-data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }

        .mq-data-table td {
            padding: 1rem 1.15rem;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #e2e8f0;
        }

        /* Number Pill */
        .mq-no-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 28px;
            padding: 0 0.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.8rem;
            font-weight: 700;
            color: #fbbf24;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        /* Question Text */
        .mq-data-table th:first-child,
        .mq-data-table td:first-child {
            width: 70px;
            max-width: 80px;
            text-align: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .mq-question-cell {
            width: auto;
            max-width: none;
            min-width: 280px;
        }

        .mq-question-text {
            font-weight: 500;
            color: #f1f5f9;
            line-height: 1.5;
            display: block;
            word-break: break-word;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .mq-question-text:hover {
            color: #fbbf24;
        }

        /* Type Badges */
        .mq-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .mq-type-badge.likert {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .mq-type-badge.objective {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .mq-type-badge.open-ended,
        .mq-type-badge.open {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Classification Badges */
        .mq-classification-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.8rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            background: rgba(148, 163, 184, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.18);
            color: #cbd5e1;
            white-space: nowrap;
        }

        .mq-classification-badge.holland {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.25);
            color: #fcd34d;
        }

        .mq-classification-badge.trait {
            background: rgba(168, 85, 247, 0.1);
            border-color: rgba(168, 85, 247, 0.25);
            color: #d8b4fe;
        }

        .mq-classification-badge.competency {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.25);
            color: #6ee7b7;
        }

        .mq-classification-badge.strand {
            background: rgba(14, 165, 233, 0.1);
            border-color: rgba(14, 165, 233, 0.25);
            color: #7dd3fc;
        }

        /* Difficulty Dot Indicator */
        .mq-difficulty-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: capitalize;
            color: #94a3b8;
        }

        .mq-difficulty-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        .mq-difficulty-dot.easy { background: #10b981; box-shadow: 0 0 6px #10b981; }
        .mq-difficulty-dot.medium { background: #f59e0b; box-shadow: 0 0 6px #f59e0b; }
        .mq-difficulty-dot.hard { background: #ef4444; box-shadow: 0 0 6px #ef4444; }

        /* Floating Glass Action Buttons */
        .mq-actions-cluster {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(15, 23, 42, 0.6);
            padding: 0.25rem 0.35rem;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .btn-mq-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .btn-mq-action.view {
            background: rgba(59, 130, 246, 0.12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.25);
        }
        .btn-mq-action.view:hover {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(59, 130, 246, 0.5);
            transform: translateY(-1px);
        }

        .btn-mq-action.edit {
            background: rgba(245, 158, 11, 0.12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .btn-mq-action.edit:hover {
            background: #f59e0b;
            color: #0f172a;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.5);
            transform: translateY(-1px);
        }

        .btn-mq-action.delete {
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        .btn-mq-action.delete:hover {
            background: #ef4444;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(239, 68, 68, 0.5);
            transform: translateY(-1px);
        }

        /* Empty State */
        .mq-empty-state {
            padding: 4rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        .mq-empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #64748b;
        }

        .mq-empty-state h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
        }

        .mq-empty-state p {
            font-size: 0.88rem;
            color: #94a3b8;
            max-width: 420px;
            margin: 0;
            line-height: 1.5;
        }

        /* -- Modals Modern Theme Overrides -- */
        .modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .modal.active {
            display: flex;
        }

        .modal-overlay {
            position: absolute;
            inset: 0;
            background: rgba(3, 7, 18, 0.75);
            backdrop-filter: blur(8px);
        }

        .modal-content {
            position: relative;
            z-index: 1;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(251, 191, 36, 0.1);
            width: 100%;
            max-width: 750px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalPopIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(10px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.85);
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .modal-header h2 i {
            color: #fbbf24;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .modal-body {
            padding: 1.75rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.15rem 1.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.85);
            flex-shrink: 0;
        }

        /* Form Controls in Modal */
        .question-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .question-form .form-row.full-width {
            display: block;
            width: 100%;
        }

        .question-form .form-row.full-width .form-group,
        .question-form .form-group.full-width {
            grid-column: 1 / -1;
            width: 100%;
        }

        .question-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .question-form label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .question-form label .required {
            color: #f87171;
        }

        .question-form input[type="text"],
        .question-form select,
        .question-form textarea {
            width: 100%;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 0.7rem 0.95rem;
            color: #f8fafc;
            font-size: 0.9rem;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .question-form input[type="text"]:focus,
        .question-form select:focus,
        .question-form textarea:focus {
            border-color: #fbbf24;
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
        }

        .question-form textarea {
            min-height: 120px;
            width: 100% !important;
            resize: vertical;
            line-height: 1.6;
            font-size: 0.92rem;
            padding: 0.85rem 1rem;
        }

        /* Objective Options Builder */
        .options-section {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 0.5rem;
        }

        .options-section h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #fbbf24;
            margin: 0 0 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .options-section .options-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .options-section .options-row:last-child {
            margin-bottom: 0;
        }

        .option-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .option-input-card {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 0.4rem 0.6rem;
            transition: border-color 0.2s;
        }

        .option-input-card:focus-within {
            border-color: #fbbf24;
        }

        .option-input-card input[type="radio"] {
            accent-color: #10b981;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .option-input-card .radio-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #94a3b8;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }

        .option-input-card input[type="text"] {
            border: none;
            background: transparent;
            padding: 0.4rem 0.25rem;
            color: #f8fafc;
            font-size: 0.88rem;
        }

        .option-input-card input[type="text"]:focus {
            box-shadow: none;
            background: transparent;
        }

        /* -- View Question Modal Custom Styling -- */
        #viewQuestionModal .modal-content {
            max-width: 680px;
            width: 100%;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(251, 191, 36, 0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        #viewQuestionModal .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.9);
            flex-shrink: 0;
            text-align: center;
        }

        #viewQuestionModal .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }

        #viewQuestionModal .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(251, 191, 36, 0.15);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        #viewQuestionModal .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        #viewQuestionModal .header-titles h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.3;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        #viewQuestionModal .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }

        #viewQuestionModal .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }

        #viewQuestionModal .view-modal-body {
            padding: 1.5rem 1.75rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            text-align: left;
        }

        #viewQuestionModal .question-hero-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-left: 4px solid #fbbf24;
            border-radius: 12px;
            padding: 1.25rem 1.4rem;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            text-align: left;
        }

        #viewQuestionModal .q-tag-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #fbbf24;
        }

        #viewQuestionModal .view-question-text {
            font-size: 1.05rem;
            font-weight: 600;
            color: #f8fafc;
            line-height: 1.6;
            margin: 0;
            text-align: left;
        }

        #viewQuestionModal .question-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.85rem;
        }

        #viewQuestionModal .meta-card-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            text-align: left;
        }

        #viewQuestionModal .meta-card-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        #viewQuestionModal .meta-card-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f1f5f9;
        }

        #viewQuestionModal .options-container-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            text-align: left;
        }

        #viewQuestionModal .options-header-row h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        #viewQuestionModal .view-options-list {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        #viewQuestionModal .view-option-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.65rem 0.9rem;
            border-radius: 8px;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        #viewQuestionModal .view-option-item.is-correct {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.3);
        }

        #viewQuestionModal .option-left-content {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        #viewQuestionModal .option-circle-badge {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: rgba(251, 191, 36, 0.15);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: #fbbf24;
            font-weight: 700;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #viewQuestionModal .view-option-item.is-correct .option-circle-badge {
            background: rgba(16, 185, 129, 0.2);
            border-color: rgba(16, 185, 129, 0.4);
            color: #34d399;
        }

        #viewQuestionModal .option-text-val {
            color: #f1f5f9;
            font-size: 0.88rem;
            font-weight: 500;
        }

        #viewQuestionModal .badge-correct-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            background: rgba(16, 185, 129, 0.18);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            font-size: 0.72rem;
            font-weight: 700;
        }

        #viewQuestionModal .view-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.15rem 1.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.85);
            flex-shrink: 0;
        }

        #viewQuestionModal .btn-close-view-modal {
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        #viewQuestionModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }

        #viewQuestionModal .btn-edit-question-modal {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.6rem 1.35rem;
            border-radius: 8px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: 1px solid rgba(251, 191, 36, 0.4);
            color: #0f172a;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        #viewQuestionModal .btn-edit-question-modal:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.45);
        }

        /* -- Delete Modal Custom Styling -- */
        .delete-confirm-box {
            text-align: center;
            padding: 1rem 0;
        }

        .delete-icon-pulse {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            border: 2px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1.25rem;
            animation: pulseDelete 2s infinite;
        }

        @keyframes pulseDelete {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .delete-confirm-box h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0 0 0.5rem 0;
        }

        .delete-confirm-box p {
            color: #94a3b8;
            font-size: 0.9rem;
            margin: 0 0 1.25rem 0;
        }

        .delete-preview-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            text-align: left;
            font-size: 0.92rem;
            color: #f1f5f9;
            font-style: italic;
            line-height: 1.5;
        }

        /* -- Responsive Rules -- */
        @media (max-width: 1380px) {
            .mq-stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1100px) {
            .mq-toolbar-main-row {
                flex-wrap: wrap;
            }
            .mq-search-wrapper {
                flex: 1 1 100%;
                min-width: 100%;
            }
            .mq-filters-group {
                width: 100%;
            }
        }

        @media (max-width: 860px) {
            .mq-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .question-form .form-row {
                grid-template-columns: 1fr;
            }
            .options-section .options-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 580px) {
            .mq-stats-grid {
                grid-template-columns: 1fr;
            }
            .mq-search-wrapper {
                max-width: 100%;
            }
            .mq-filters-group {
                width: 100%;
            }
            .mq-filter-select-box {
                flex: 1;
            }
            .mq-filter-select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Dashboard Container -->
    <div class="dashboard-container">
        
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Manage Questions</h1>
                </div>
                <?php 
                $userName = get_staff_name();
                $notifications = [];
                $unreadCount = 0;
                $staffId = get_staff_id();
                $staffRole = get_staff_role();
                $userType = ($staffRole === 'counselor') ? 'counselor' : 'admin';
                $adminProfilePic = null;
                
                if ($staffId) {
                    if ($userType === 'admin') {
                        $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                        if ($profileStmt) {
                            $profileStmt->bind_param('i', $staffId);
                            $profileStmt->execute();
                            $profileResult = $profileStmt->get_result();
                            $adminData = $profileResult->fetch_assoc();
                            $adminProfilePic = $adminData['profile_picture'] ?? null;
                            $profileStmt->close();
                        }
                    }

                    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? AND is_read = 0");
                    if ($countStmt) {
                        $countStmt->bind_param('is', $staffId, $userType);
                        $countStmt->execute();
                        $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                        $countStmt->close();
                    }
                    
                    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = ? ORDER BY created_at DESC LIMIT 10");
                    if ($notifStmt) {
                        $notifStmt->bind_param('is', $staffId, $userType);
                        $notifStmt->execute();
                        $result = $notifStmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $notifications[] = $row;
                        }
                        $notifStmt->close();
                    }
                }
                ?>
                <div class="top-bar-actions">
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <i class="fa-solid fa-bell"></i>
                            <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <a href="#" class="clear-all-notifs" onclick="clearAllNotifications(event)" style="color: #ef4444; font-size: 0.8rem; text-decoration: none;">Clear All</a>
                                    <?php if ($unreadCount > 0): ?>
                                    <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notification-list">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                                        <div class="notification-icon <?php echo $notif['type']; ?>">
                                            <i class="fa-solid <?php echo $notif['type'] === 'success' ? 'fa-check-circle' : ($notif['type'] === 'warning' ? 'fa-exclamation-triangle' : ($notif['type'] === 'error' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                            <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <span class="notification-time"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-notifications">
                                        <i class="fa-solid fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="user-profile" onclick="window.location.href='<?php echo is_counselor() ? 'counselor_profile.php' : 'settings.php'; ?>'" title="<?php echo is_counselor() ? 'My Profile' : 'Settings'; ?>">
                        <div class="user-avatar <?php echo is_counselor() ? 'counselor-avatar' : ''; ?>">
                            <?php if ($adminProfilePic && file_exists(__DIR__ . '/' . $adminProfilePic)): ?>
                                <img src="<?php echo htmlspecialchars($adminProfilePic); ?>" alt="Admin" class="avatar-img">
                            <?php else: ?>
                                <i class="fa-solid <?php echo is_counselor() ? 'fa-user-tie' : 'fa-user-shield'; ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Questions Content -->
            <div class="dashboard-content">
                
                <!-- Page Intro & Add CTA Header -->
                <div class="mq-page-intro">
                    <div class="mq-intro-left">
                        <h2>
                            <span class="title-icon-badge"><i class="fa-solid fa-circle-question"></i></span>
                            Assessment Questionnaire Management
                        </h2>
                        <p>Configure assessment test banks, Holland RIASEC career typologies, Big Five personality dimensions, skills competencies, and strand-specific questionnaires.</p>
                    </div>
                    <div class="mq-header-cta">
                        <button class="btn-add-question-hero" id="addQuestionBtn">
                            <i class="fa-solid fa-plus"></i>
                            <span>Add New Question</span>
                        </button>
                    </div>
                </div>

                <!-- KPI Stat Summary Cards -->
                <div class="mq-stats-grid">
                    <div class="mq-stat-card stat-total" data-switch-tab="all" title="View all active questions">
                        <div class="mq-stat-icon-wrapper">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="mq-stat-info">
                            <span class="mq-stat-label">Total Questions</span>
                            <span class="mq-stat-value"><?php echo $totalQuestionsCount; ?></span>
                            <span class="mq-stat-meta">Active Bank Items</span>
                        </div>
                    </div>

                    <div class="mq-stat-card stat-career" data-switch-tab="career" title="Switch to Career Interest tab">
                        <div class="mq-stat-icon-wrapper">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <div class="mq-stat-info">
                            <span class="mq-stat-label">Career Interest</span>
                            <span class="mq-stat-value"><?php echo count($careerQuestions); ?></span>
                            <span class="mq-stat-meta">RIASEC Holland Codes</span>
                        </div>
                    </div>

                    <div class="mq-stat-card stat-personality" data-switch-tab="personality" title="Switch to Personality tab">
                        <div class="mq-stat-icon-wrapper">
                            <i class="fa-solid fa-brain"></i>
                        </div>
                        <div class="mq-stat-info">
                            <span class="mq-stat-label">Personality</span>
                            <span class="mq-stat-value"><?php echo count($personalityQuestions); ?></span>
                            <span class="mq-stat-meta">Big Five Dimensions</span>
                        </div>
                    </div>

                    <div class="mq-stat-card stat-skills" data-switch-tab="skills" title="Switch to Skills Assessment tab">
                        <div class="mq-stat-icon-wrapper">
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <div class="mq-stat-info">
                            <span class="mq-stat-label">Skills Assessment</span>
                            <span class="mq-stat-value"><?php echo count($skillsQuestions); ?></span>
                            <span class="mq-stat-meta">Core Competencies</span>
                        </div>
                    </div>

                    <div class="mq-stat-card stat-strand" data-switch-tab="strand" title="Switch to Strand-Based tab">
                        <div class="mq-stat-icon-wrapper">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <div class="mq-stat-info">
                            <span class="mq-stat-label">Strand-Based</span>
                            <span class="mq-stat-value"><?php echo count($strandQuestions); ?></span>
                            <span class="mq-stat-meta">Academic Tracks</span>
                        </div>
                    </div>
                </div>

                <!-- Glassmorphic Category Navigation Tabs -->
                <div class="mq-tabs-wrapper" id="categoryTabs">
                    <button class="mq-tab-pill active" data-tab="career">
                        <i class="fa-solid fa-briefcase"></i>
                        <span>Career Interest</span>
                        <span class="tab-count-badge"><?php echo count($careerQuestions); ?></span>
                    </button>
                    <button class="mq-tab-pill" data-tab="personality">
                        <i class="fa-solid fa-brain"></i>
                        <span>Personality</span>
                        <span class="tab-count-badge"><?php echo count($personalityQuestions); ?></span>
                    </button>
                    <button class="mq-tab-pill" data-tab="skills">
                        <i class="fa-solid fa-star"></i>
                        <span>Skills Assessment</span>
                        <span class="tab-count-badge"><?php echo count($skillsQuestions); ?></span>
                    </button>
                    <button class="mq-tab-pill" data-tab="strand">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Strand-Based</span>
                        <span class="tab-count-badge"><?php echo count($strandQuestions); ?></span>
                    </button>
                </div>

                <!-- Unified Multi-Filter & Search Toolbar -->
                <div class="mq-toolbar-card">
                    <div class="mq-toolbar-main-row">
                        <!-- Search Box -->
                        <div class="mq-search-wrapper">
                            <i class="fa-solid fa-magnifying-glass mq-search-icon"></i>
                            <input type="text" id="searchInput" class="mq-search-input" placeholder="Search questions by keyword or classification..." autocomplete="off">
                            <button type="button" class="mq-search-clear-btn" id="searchClearBtn" title="Clear search">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Filter Dropdowns -->
                        <div class="mq-filters-group">
                            <!-- Question Type Filter -->
                            <div class="mq-filter-select-box">
                                <select id="filterType" class="mq-filter-select">
                                    <option value="">All Question Types</option>
                                    <option value="likert">Likert Scale (1-5)</option>
                                    <option value="objective">Multiple Choice (Objective)</option>
                                    <option value="open-ended">Open-Ended</option>
                                </select>
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>

                            <!-- Classification Sub-filter (Dynamically changes options per tab) -->
                            <div class="mq-filter-select-box" id="classificationFilterWrapper">
                                <select id="filterClassification" class="mq-filter-select">
                                    <option value="">All Classifications</option>
                                    <!-- Populated via JS according to active category tab -->
                                </select>
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>

                            <!-- Difficulty Filter -->
                            <div class="mq-filter-select-box">
                                <select id="filterDifficulty" class="mq-filter-select">
                                    <option value="">All Difficulties</option>
                                    <option value="easy">Easy</option>
                                    <option value="medium">Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>

                            <!-- Reset All Filters Button -->
                            <button type="button" class="btn-reset-filters" id="clearFilter" title="Reset all search queries and filters">
                                <i class="fa-solid fa-rotate-left"></i>
                                <span>Reset</span>
                            </button>
                        </div>
                    </div>

                    <!-- Toolbar Status Row: Active Filter Chips & Results Count Badge -->
                    <div class="mq-toolbar-status-row">
                        <div class="mq-active-filter-tags" id="activeFilterTags"></div>
                        <div class="mq-results-count" id="resultsCountBadge">
                            <i class="fa-solid fa-layer-group" style="color: #64748b;"></i>
                            <span id="resultsCountText">Showing all <strong id="visibleResultsCount"><?php echo count($careerQuestions); ?></strong> questions</span>
                            <span id="totalCategoryCount" style="display: none;"><?php echo count($careerQuestions); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 1: CAREER INTEREST QUESTIONS
                     ========================================================== -->
                <div class="tab-content active" id="career-tab">
                    <div class="mq-table-card">
                        <div class="mq-table-responsive">
                            <table class="mq-data-table questions-table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px; text-align: center;">No.</th>
                                        <th>Question</th>
                                        <th style="width: 140px;">Type</th>
                                        <th style="width: 160px;">Holland Type</th>
                                        <th style="width: 110px;">Difficulty</th>
                                        <th style="width: 130px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($careerQuestions as $index => $q): 
                                        $qNum = $index + 1;
                                        $diff = strtolower($q['difficulty'] ?? 'medium');
                                        $qType = strtolower($q['question_type'] ?? 'likert');
                                        $classVal = $q['classification_value'] ?? $q['holland_type'] ?? 'General';
                                    ?>
                                    <tr data-id="<?php echo $q['id']; ?>" 
                                        data-category="career" 
                                        data-type="<?php echo htmlspecialchars($qType); ?>" 
                                        data-difficulty="<?php echo htmlspecialchars($diff); ?>" 
                                        data-classification="<?php echo htmlspecialchars(strtolower($classVal)); ?>">
                                        <td style="text-align: center;">
                                            <span class="mq-no-pill" title="Item #<?php echo $qNum; ?> (ID: #<?php echo $q['id']; ?>)">#<?php echo str_pad($qNum, 2, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td class="mq-question-cell">
                                            <span class="mq-question-text" title="<?php echo htmlspecialchars($q['question_text']); ?>">
                                                <?php echo htmlspecialchars($q['question_text']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-type-badge <?php echo htmlspecialchars($qType); ?>">
                                                <i class="fa-solid <?php echo $qType === 'likert' ? 'fa-sliders' : ($qType === 'objective' ? 'fa-list-check' : 'fa-pen-nib'); ?>"></i>
                                                <?php echo ucfirst($q['question_type'] ?? 'Likert'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-classification-badge holland">
                                                <i class="fa-solid fa-compass"></i>
                                                <?php echo htmlspecialchars($classVal); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-difficulty-badge">
                                                <span class="mq-difficulty-dot <?php echo htmlspecialchars($diff); ?>"></span>
                                                <?php echo ucfirst($diff); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="mq-actions-cluster">
                                                <button class="btn-mq-action view" data-id="<?php echo $q['id']; ?>" data-category="career" title="View Details">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-mq-action edit" data-id="<?php echo $q['id']; ?>" data-category="career" title="Edit Question">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-mq-action delete" data-id="<?php echo $q['id']; ?>" data-category="career" title="Delete Question">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($careerQuestions)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="mq-empty-state">
                                                <div class="mq-empty-icon"><i class="fa-solid fa-briefcase"></i></div>
                                                <h3>No Career Questions Found</h3>
                                                <p>Get started by adding your first RIASEC career interest question.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 2: PERSONALITY QUESTIONS
                     ========================================================== -->
                <div class="tab-content" id="personality-tab">
                    <div class="mq-table-card">
                        <div class="mq-table-responsive">
                            <table class="mq-data-table questions-table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px; text-align: center;">No.</th>
                                        <th>Question</th>
                                        <th style="width: 140px;">Type</th>
                                        <th style="width: 160px;">Big Five Trait</th>
                                        <th style="width: 110px;">Difficulty</th>
                                        <th style="width: 130px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($personalityQuestions as $index => $q): 
                                        $qNum = $index + 1;
                                        $diff = strtolower($q['difficulty'] ?? 'medium');
                                        $qType = strtolower($q['question_type'] ?? 'likert');
                                        $classVal = $q['trait'] ?? 'General';
                                    ?>
                                    <tr data-id="<?php echo $q['id']; ?>" 
                                        data-category="personality" 
                                        data-type="<?php echo htmlspecialchars($qType); ?>" 
                                        data-difficulty="<?php echo htmlspecialchars($diff); ?>" 
                                        data-classification="<?php echo htmlspecialchars(strtolower($classVal)); ?>">
                                        <td style="text-align: center;">
                                            <span class="mq-no-pill" title="Item #<?php echo $qNum; ?> (ID: #<?php echo $q['id']; ?>)">#<?php echo str_pad($qNum, 2, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td class="mq-question-cell">
                                            <span class="mq-question-text" title="<?php echo htmlspecialchars($q['question_text']); ?>">
                                                <?php echo htmlspecialchars($q['question_text']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-type-badge <?php echo htmlspecialchars($qType); ?>">
                                                <i class="fa-solid <?php echo $qType === 'likert' ? 'fa-sliders' : ($qType === 'objective' ? 'fa-list-check' : 'fa-pen-nib'); ?>"></i>
                                                <?php echo ucfirst($q['question_type'] ?? 'Likert'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-classification-badge trait">
                                                <i class="fa-solid fa-brain"></i>
                                                <?php echo htmlspecialchars($classVal); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-difficulty-badge">
                                                <span class="mq-difficulty-dot <?php echo htmlspecialchars($diff); ?>"></span>
                                                <?php echo ucfirst($diff); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="mq-actions-cluster">
                                                <button class="btn-mq-action view" data-id="<?php echo $q['id']; ?>" data-category="personality" title="View Details">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-mq-action edit" data-id="<?php echo $q['id']; ?>" data-category="personality" title="Edit Question">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-mq-action delete" data-id="<?php echo $q['id']; ?>" data-category="personality" title="Delete Question">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($personalityQuestions)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="mq-empty-state">
                                                <div class="mq-empty-icon"><i class="fa-solid fa-brain"></i></div>
                                                <h3>No Personality Questions Found</h3>
                                                <p>Create personality inventory questions across the Big Five dimensions.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 3: SKILLS ASSESSMENT QUESTIONS
                     ========================================================== -->
                <div class="tab-content" id="skills-tab">
                    <div class="mq-table-card">
                        <div class="mq-table-responsive">
                            <table class="mq-data-table questions-table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px; text-align: center;">No.</th>
                                        <th>Question</th>
                                        <th style="width: 140px;">Type</th>
                                        <th style="width: 170px;">Skill Competency</th>
                                        <th style="width: 110px;">Difficulty</th>
                                        <th style="width: 130px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($skillsQuestions as $index => $q): 
                                        $qNum = $index + 1;
                                        $diff = strtolower($q['difficulty'] ?? 'medium');
                                        $qType = strtolower($q['question_type'] ?? 'objective');
                                        $classVal = $q['classification_value'] ?? $q['competency_name'] ?? 'General';
                                    ?>
                                    <tr data-id="<?php echo $q['id']; ?>" 
                                        data-category="skills" 
                                        data-type="<?php echo htmlspecialchars($qType); ?>" 
                                        data-difficulty="<?php echo htmlspecialchars($diff); ?>" 
                                        data-classification="<?php echo htmlspecialchars(strtolower($classVal)); ?>">
                                        <td style="text-align: center;">
                                            <span class="mq-no-pill" title="Item #<?php echo $qNum; ?> (ID: #<?php echo $q['id']; ?>)">#<?php echo str_pad($qNum, 2, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td class="mq-question-cell">
                                            <span class="mq-question-text" title="<?php echo htmlspecialchars($q['question_text']); ?>">
                                                <?php echo htmlspecialchars($q['question_text']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-type-badge <?php echo htmlspecialchars($qType); ?>">
                                                <i class="fa-solid <?php echo $qType === 'objective' ? 'fa-list-check' : ($qType === 'likert' ? 'fa-sliders' : 'fa-pen-nib'); ?>"></i>
                                                <?php echo ucfirst($q['question_type'] ?? 'Objective'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-classification-badge competency">
                                                <i class="fa-solid fa-star"></i>
                                                <?php echo htmlspecialchars($classVal); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-difficulty-badge">
                                                <span class="mq-difficulty-dot <?php echo htmlspecialchars($diff); ?>"></span>
                                                <?php echo ucfirst($diff); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="mq-actions-cluster">
                                                <button class="btn-mq-action view" data-id="<?php echo $q['id']; ?>" data-category="skills" title="View Details">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-mq-action edit" data-id="<?php echo $q['id']; ?>" data-category="skills" title="Edit Question">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-mq-action delete" data-id="<?php echo $q['id']; ?>" data-category="skills" title="Delete Question">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($skillsQuestions)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="mq-empty-state">
                                                <div class="mq-empty-icon"><i class="fa-solid fa-star"></i></div>
                                                <h3>No Skills Questions Found</h3>
                                                <p>Add competency assessment questions to evaluate student technical and soft skills.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                     TAB 4: STRAND-BASED QUESTIONS
                     ========================================================== -->
                <div class="tab-content" id="strand-tab">
                    <div class="mq-table-card">
                        <div class="mq-table-responsive">
                            <table class="mq-data-table questions-table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px; text-align: center;">No.</th>
                                        <th>Question</th>
                                        <th style="width: 140px;">Type</th>
                                        <th style="width: 160px;">Target Strand</th>
                                        <th style="width: 110px;">Difficulty</th>
                                        <th style="width: 130px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($strandQuestions as $index => $q): 
                                        $qNum = $index + 1;
                                        $diff = strtolower($q['difficulty'] ?? 'medium');
                                        $qType = strtolower($q['question_type'] ?? 'objective');
                                        $classVal = $q['classification_value'] ?? $q['strand_name'] ?? 'General';
                                    ?>
                                    <tr data-id="<?php echo $q['id']; ?>" 
                                        data-category="strand" 
                                        data-type="<?php echo htmlspecialchars($qType); ?>" 
                                        data-difficulty="<?php echo htmlspecialchars($diff); ?>" 
                                        data-classification="<?php echo htmlspecialchars(strtolower($classVal)); ?>">
                                        <td style="text-align: center;">
                                            <span class="mq-no-pill" title="Item #<?php echo $qNum; ?> (ID: #<?php echo $q['id']; ?>)">#<?php echo str_pad($qNum, 2, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td class="mq-question-cell">
                                            <span class="mq-question-text" title="<?php echo htmlspecialchars($q['question_text']); ?>">
                                                <?php echo htmlspecialchars($q['question_text']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-type-badge <?php echo htmlspecialchars($qType); ?>">
                                                <i class="fa-solid <?php echo $qType === 'objective' ? 'fa-list-check' : ($qType === 'likert' ? 'fa-sliders' : 'fa-pen-nib'); ?>"></i>
                                                <?php echo ucfirst($q['question_type'] ?? 'Objective'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-classification-badge strand">
                                                <i class="fa-solid fa-graduation-cap"></i>
                                                <?php echo htmlspecialchars($classVal); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mq-difficulty-badge">
                                                <span class="mq-difficulty-dot <?php echo htmlspecialchars($diff); ?>"></span>
                                                <?php echo ucfirst($diff); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="mq-actions-cluster">
                                                <button class="btn-mq-action view" data-id="<?php echo $q['id']; ?>" data-category="strand" title="View Details">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-mq-action edit" data-id="<?php echo $q['id']; ?>" data-category="strand" title="Edit Question">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-mq-action delete" data-id="<?php echo $q['id']; ?>" data-category="strand" title="Delete Question">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($strandQuestions)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="mq-empty-state">
                                                <div class="mq-empty-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                                <h3>No Strand Questions Found</h3>
                                                <p>Create academic track and strand specific questions to guide student senior high paths.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- ==========================================================
         ADD QUESTION MODAL
         ========================================================== -->
    <div class="modal" id="addQuestionModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-circle-plus"></i> Add New Assessment Question</h2>
                <button class="modal-close" id="closeAddModal" title="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="addQuestionForm" class="question-form" action="api/questions.php" method="POST">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="questionCategory">Assessment Category <span class="required">*</span></label>
                            <select id="questionCategory" name="category" required>
                                <option value="">Select Category</option>
                                <option value="career">Career Interest</option>
                                <option value="personality">Personality</option>
                                <option value="skills">Skills Assessment</option>
                                <option value="strand">Strand-Based</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="questionType">Question Format / Type <span class="required">*</span></label>
                            <select id="questionType" name="type" required>
                                <option value="">Select Type</option>
                                <option value="likert">Likert Scale (1-5)</option>
                                <option value="objective">Objective (Multiple Choice)</option>
                                <option value="open-ended">Open-Ended</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row full-width">
                        <div class="form-group full-width">
                            <label for="questionText">Question <span class="required">*</span></label>
                            <textarea id="questionText" name="text" rows="4" required placeholder="Type the question clearly..."></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="difficultyLevel">Difficulty Level</label>
                            <select id="difficultyLevel" name="difficulty">
                                <option value="easy">Easy</option>
                                <option value="medium" selected>Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>

                        <!-- Holland Type (Career) -->
                        <div class="form-group category-meta-field" id="careerMetaFields" style="display: none;">
                            <label for="careerHollandType">Holland RIASEC Code <span class="required">*</span></label>
                            <select id="careerHollandType" name="holland_type">
                                <?php foreach (['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'] as $hollandType): ?>
                                <option value="<?php echo $hollandType; ?>"<?php echo $hollandType === 'Investigative' ? ' selected' : ''; ?>><?php echo $hollandType; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Personality Trait -->
                        <div class="form-group category-meta-field" id="personalityMetaFields" style="display: none;">
                            <label for="personalityTrait">Big Five Trait Dimension <span class="required">*</span></label>
                            <select id="personalityTrait">
                                <option value="">Select Trait</option>
                                <?php foreach ($personalityTraits as $trait): ?>
                                <option value="<?php echo $trait; ?>"><?php echo $trait; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Skill Category -->
                        <div class="form-group category-meta-field" id="skillsMetaFields" style="display: none;">
                            <label for="skillCategory">Skill Competency Area <span class="required">*</span></label>
                            <select id="skillCategory">
                                <option value="">Select Competency</option>
                                <?php foreach ($skillCategories as $categoryName): ?>
                                <option value="<?php echo htmlspecialchars($categoryName); ?>"><?php echo htmlspecialchars($categoryName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Strand -->
                        <div class="form-group category-meta-field" id="strandMetaFields" style="display: none;">
                            <label for="strandSelect">Target Senior High Strand <span class="required">*</span></label>
                            <select id="strandSelect">
                                <option value="">Select Strand</option>
                                <?php foreach ($strands as $strand): ?>
                                <option value="<?php echo htmlspecialchars($strand['code']); ?>"><?php echo htmlspecialchars($strand['name']); ?> (<?php echo htmlspecialchars($strand['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Objective Question Options Section -->
                    <div class="conditional-field" id="optionsField" style="display: none;">
                        <div class="options-section">
                            <h4><i class="fa-solid fa-list-check"></i> Objective Answer Choices (Select Correct Radio)</h4>
                            <div class="options-row">
                                <div class="option-group">
                                    <label>Option A <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="correctAnswer" value="A" id="correctA">
                                        <label for="correctA" class="radio-label">Correct</label>
                                        <input type="text" name="optionA" placeholder="Enter option A text">
                                    </div>
                                </div>
                                <div class="option-group">
                                    <label>Option B <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="correctAnswer" value="B" id="correctB">
                                        <label for="correctB" class="radio-label">Correct</label>
                                        <input type="text" name="optionB" placeholder="Enter option B text">
                                    </div>
                                </div>
                            </div>
                            <div class="options-row">
                                <div class="option-group">
                                    <label>Option C <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="correctAnswer" value="C" id="correctC">
                                        <label for="correctC" class="radio-label">Correct</label>
                                        <input type="text" name="optionC" placeholder="Enter option C text">
                                    </div>
                                </div>
                                <div class="option-group">
                                    <label>Option D <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="correctAnswer" value="D" id="correctD">
                                        <label for="correctD" class="radio-label">Correct</label>
                                        <input type="text" name="optionD" placeholder="Enter option D text">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelAdd">Cancel</button>
                <button type="submit" class="btn-primary" form="addQuestionForm">
                    <i class="fa-solid fa-check"></i>
                    <span>Save Question</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ==========================================================
         EDIT QUESTION MODAL
         ========================================================== -->
    <div class="modal" id="editQuestionModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Assessment Question</h2>
                <button class="modal-close" id="closeEditModal" title="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editQuestionForm" class="question-form">
                    <input type="hidden" id="editQuestionId" name="id" value="1">
                    <input type="hidden" name="action" value="edit">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editCategory">Assessment Category <span class="required">*</span></label>
                            <select id="editCategory" name="category" required>
                                <option value="career">Career Interest</option>
                                <option value="personality">Personality</option>
                                <option value="skills">Skills Assessment</option>
                                <option value="strand">Strand-Based</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="editType">Question Format / Type <span class="required">*</span></label>
                            <select id="editType" name="type" required>
                                <option value="likert">Likert Scale (1-5)</option>
                                <option value="objective">Objective (Multiple Choice)</option>
                                <option value="open-ended">Open-Ended</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row full-width">
                        <div class="form-group full-width">
                            <label for="editText">Question <span class="required">*</span></label>
                            <textarea id="editText" name="text" rows="4" required placeholder="Type the question clearly..."></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editDifficulty">Difficulty Level</label>
                            <select id="editDifficulty" name="difficulty">
                                <option value="easy">Easy</option>
                                <option value="medium" selected>Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>

                        <!-- Edit Holland Type (Career) -->
                        <div class="form-group category-meta-field" id="editCareerMetaFields" style="display: none;">
                            <label for="editHollandType">Holland RIASEC Code <span class="required">*</span></label>
                            <select id="editHollandType" name="holland_type">
                                <?php foreach (['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'] as $hollandType): ?>
                                <option value="<?php echo $hollandType; ?>"<?php echo $hollandType === 'Investigative' ? ' selected' : ''; ?>><?php echo $hollandType; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Edit Personality Trait -->
                        <div class="form-group category-meta-field" id="editPersonalityMetaFields" style="display: none;">
                            <label for="editPersonalityTrait">Big Five Trait Dimension <span class="required">*</span></label>
                            <select id="editPersonalityTrait">
                                <option value="">Select Trait</option>
                                <?php foreach ($personalityTraits as $trait): ?>
                                <option value="<?php echo $trait; ?>"><?php echo $trait; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Edit Skill Category -->
                        <div class="form-group category-meta-field" id="editSkillsMetaFields" style="display: none;">
                            <label for="editSkillCategory">Skill Competency Area <span class="required">*</span></label>
                            <select id="editSkillCategory">
                                <option value="">Select Competency</option>
                                <?php foreach ($skillCategories as $categoryName): ?>
                                <option value="<?php echo htmlspecialchars($categoryName); ?>"><?php echo htmlspecialchars($categoryName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Edit Strand -->
                        <div class="form-group category-meta-field" id="editStrandMetaFields" style="display: none;">
                            <label for="editStrandSelect">Target Senior High Strand <span class="required">*</span></label>
                            <select id="editStrandSelect">
                                <option value="">Select Strand</option>
                                <?php foreach ($strands as $strand): ?>
                                <option value="<?php echo htmlspecialchars($strand['code']); ?>"><?php echo htmlspecialchars($strand['name']); ?> (<?php echo htmlspecialchars($strand['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Edit Options Section - Objective Questions -->
                    <div class="conditional-field" id="editOptionsField" style="display: none;">
                        <div class="options-section">
                            <h4><i class="fa-solid fa-list-check"></i> Objective Answer Choices</h4>
                            <div class="options-row">
                                <div class="option-group">
                                    <label>Option A <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="editCorrectAnswer" value="A" id="editCorrectA">
                                        <label for="editCorrectA" class="radio-label">Correct</label>
                                        <input type="text" id="editOptionA" name="editOptionA" placeholder="Enter option A text">
                                    </div>
                                </div>
                                <div class="option-group">
                                    <label>Option B <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="editCorrectAnswer" value="B" id="editCorrectB">
                                        <label for="editCorrectB" class="radio-label">Correct</label>
                                        <input type="text" id="editOptionB" name="editOptionB" placeholder="Enter option B text">
                                    </div>
                                </div>
                            </div>
                            <div class="options-row">
                                <div class="option-group">
                                    <label>Option C <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="editCorrectAnswer" value="C" id="editCorrectC">
                                        <label for="editCorrectC" class="radio-label">Correct</label>
                                        <input type="text" id="editOptionC" name="editOptionC" placeholder="Enter option C text">
                                    </div>
                                </div>
                                <div class="option-group">
                                    <label>Option D <span class="required">*</span></label>
                                    <div class="option-input-card">
                                        <input type="radio" name="editCorrectAnswer" value="D" id="editCorrectD">
                                        <label for="editCorrectD" class="radio-label">Correct</label>
                                        <input type="text" id="editOptionD" name="editOptionD" placeholder="Enter option D text">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelEdit">Cancel</button>
                <button type="submit" class="btn-primary" form="editQuestionForm">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ==========================================================
         VIEW QUESTION DETAILS MODAL
         ========================================================== -->
    <div class="modal" id="viewQuestionModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Question Specifications</h2>
                        <p>Detailed parameters, categorization, and objective options</p>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal" title="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="view-modal-body">
                <!-- Question Hero Card -->
                <div class="question-hero-card">
                    <span class="q-tag-label"><i class="fa-solid fa-quote-left"></i> Question</span>
                    <p class="view-question-text" id="viewText">Loading question details...</p>
                </div>

                <!-- 4-Box Metadata Grid -->
                <div class="question-meta-grid">
                    <div class="meta-card-item">
                        <span class="meta-card-label">Category</span>
                        <span class="meta-card-value" id="viewCategory">Career Interest</span>
                    </div>
                    <div class="meta-card-item">
                        <span class="meta-card-label">Format / Type</span>
                        <span class="meta-card-value" id="viewType">Likert Scale</span>
                    </div>
                    <div class="meta-card-item">
                        <span class="meta-card-label">Difficulty</span>
                        <span class="meta-card-value" id="viewDifficulty">Medium</span>
                    </div>
                    <div class="meta-card-item" id="viewCompetencySection">
                        <span class="meta-card-label" id="viewClassificationLabel">Holland RIASEC</span>
                        <span class="meta-card-value" id="viewCompetency" style="color: #a5b4fc;">N/A</span>
                    </div>
                </div>

                <!-- Options Section for Objective Questions -->
                <div class="options-container-card" id="viewOptionsSection" style="display: none;">
                    <div class="options-header-row">
                        <h4><i class="fa-solid fa-list-check" style="color: #38bdf8;"></i> Answer Choices</h4>
                    </div>
                    <div class="view-options-list" id="viewOptionsList"></div>
                </div>
            </div>

            <div class="view-modal-footer">
                <button type="button" class="btn-close-view-modal" id="closeView">Close</button>
                <button type="button" class="btn-edit-question-modal" id="viewEditBtn">
                    <i class="fa-solid fa-pen"></i>
                    <span>Edit Question</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ==========================================================
         DELETE CONFIRMATION MODAL
         ========================================================== -->
    <div class="modal" id="deleteModal">
        <div class="modal-overlay"></div>
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h2 style="color: #f87171;"><i class="fa-solid fa-triangle-exclamation"></i> Delete Question</h2>
                <button class="modal-close" id="closeDeleteModal" title="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-box">
                    <div class="delete-icon-pulse">
                        <i class="fa-solid fa-trash-can"></i>
                    </div>
                    <h3>Are you sure you want to delete this question?</h3>
                    <p>This will remove the question from current and upcoming assessments. This action cannot be undone.</p>
                    <div class="delete-preview-card">
                        <span id="deleteQuestionText">Question text loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete" style="background: #ef4444; border: none; padding: 0.65rem 1.4rem; border-radius: 8px; font-weight: 700; color: #ffffff; cursor: pointer;">
                    <i class="fa-solid fa-trash-can"></i>
                    <span>Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Status Modal -->
    <div class="modal" id="statusModal" style="z-index: 11000;">
        <div class="modal-overlay"></div>
        <div class="modal-content" style="max-width: 420px; text-align: center; border-radius: 16px; padding: 1.5rem;">
            <div class="modal-body" style="padding: 1rem;">
                <div id="statusIcon" style="font-size: 3.5rem; margin-bottom: 1rem;"></div>
                <h2 id="statusTitle" style="font-size: 1.3rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;"></h2>
                <p id="statusMessage" style="color: #cbd5e1; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5;"></p>
                <button type="button" class="btn-primary" id="statusOkBtn" style="width: 100%; justify-content: center; padding: 0.75rem; font-size: 0.95rem;">OK</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // Manage Questions JavaScript - High-Performance Multi-Filter & Interactive Operations
        document.addEventListener('DOMContentLoaded', function() {
            
            // -- Status Toast Modal Helper --
            function showStatusModal(title, message, isSuccess, callback = null) {
                const modal = document.getElementById('statusModal');
                const icon = document.getElementById('statusIcon');
                const titleEl = document.getElementById('statusTitle');
                const msgEl = document.getElementById('statusMessage');
                const okBtn = document.getElementById('statusOkBtn');

                if (isSuccess) {
                    icon.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i>';
                } else {
                    icon.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i>';
                }

                titleEl.textContent = title;
                msgEl.textContent = message;
                modal.classList.add('active');

                const handleClose = () => {
                    modal.classList.remove('active');
                    okBtn.removeEventListener('click', handleClose);
                    if (callback) callback();
                };

                okBtn.addEventListener('click', handleClose);
            }

            // -- Category Definitions for Dynamic Classification Sub-filters --
            const CATEGORY_CLASSIFICATIONS = {
                career: [
                    'Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'
                ],
                personality: [
                    'Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'
                ],
                skills: <?php echo json_encode(array_values($skillCategories)); ?>,
                strand: <?php echo json_encode(array_values(array_map(function($s){ return $s['code']; }, $strands))); ?>
            };

            let currentActiveTab = 'career';

            // Populate classification filter based on active tab
            function updateClassificationFilterOptions(category) {
                const selectEl = document.getElementById('filterClassification');
                if (!selectEl) return;
                
                selectEl.innerHTML = '<option value="">All Classifications</option>';
                const list = CATEGORY_CLASSIFICATIONS[category] || [];
                list.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.toLowerCase();
                    opt.textContent = item;
                    selectEl.appendChild(opt);
                });
            }

            // -- Tab Switching Logic --
            const tabBtns = document.querySelectorAll('.mq-tab-pill');
            const tabContents = document.querySelectorAll('.tab-content');

            function switchTab(tabId) {
                currentActiveTab = tabId;
                
                // Update tab buttons
                tabBtns.forEach(b => {
                    b.classList.toggle('active', b.dataset.tab === tabId);
                });

                // Update tab content displays
                tabContents.forEach(c => {
                    c.classList.toggle('active', c.id === `${tabId}-tab`);
                });

                // Update classification filter options
                updateClassificationFilterOptions(tabId);

                // Run filter on active tab
                applyFilters();
            }

            tabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    switchTab(btn.dataset.tab);
                });
            });

            // Quick-switch via Top KPI Stat Cards
            document.querySelectorAll('.mq-stat-card[data-switch-tab]').forEach(card => {
                card.addEventListener('click', () => {
                    const target = card.dataset.switchTab;
                    if (target && target !== 'all') {
                        switchTab(target);
                    } else if (target === 'all') {
                        switchTab('career');
                    }
                });
            });

            // -- Multi-Filter & Search Engine --
            const searchInput = document.getElementById('searchInput');
            const searchClearBtn = document.getElementById('searchClearBtn');
            const filterType = document.getElementById('filterType');
            const filterClassification = document.getElementById('filterClassification');
            const filterDifficulty = document.getElementById('filterDifficulty');
            const clearFilterBtn = document.getElementById('clearFilter');
            const activeFilterTags = document.getElementById('activeFilterTags');
            const visibleResultsCountEl = document.getElementById('visibleResultsCount');
            const totalCategoryCountEl = document.getElementById('totalCategoryCount');

            function applyFilters() {
                const query = searchInput.value.toLowerCase().trim();
                const selectedType = filterType.value.toLowerCase().trim();
                const selectedClass = filterClassification.value.toLowerCase().trim();
                const selectedDiff = filterDifficulty.value.toLowerCase().trim();

                // Toggle search clear 'x' button
                searchClearBtn.classList.toggle('active', query.length > 0);

                const activeTabEl = document.getElementById(`${currentActiveTab}-tab`);
                if (!activeTabEl) return;

                const rows = activeTabEl.querySelectorAll('.mq-data-table tbody tr[data-id]');
                let visibleCount = 0;
                let totalCount = rows.length;

                rows.forEach(row => {
                    const rowText = (row.querySelector('.mq-question-text')?.textContent || '').toLowerCase();
                    const rowType = (row.dataset.type || '').toLowerCase();
                    const rowClass = (row.dataset.classification || '').toLowerCase();
                    const rowDiff = (row.dataset.difficulty || '').toLowerCase();

                    const matchesSearch = !query || rowText.includes(query) || rowClass.includes(query);
                    const matchesType = !selectedType || rowType === selectedType || (selectedType === 'open-ended' && rowType === 'open');
                    const matchesClass = !selectedClass || rowClass.includes(selectedClass);
                    const matchesDiff = !selectedDiff || rowDiff === selectedDiff;

                    if (matchesSearch && matchesType && matchesClass && matchesDiff) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Update counts
                const isFiltered = Boolean(query || selectedType || selectedClass || selectedDiff);
                const resultsCountTextEl = document.getElementById('resultsCountText');
                if (resultsCountTextEl) {
                    if (isFiltered) {
                        resultsCountTextEl.innerHTML = `Showing <strong id="visibleResultsCount">${visibleCount}</strong> of <strong id="totalCategoryCount">${totalCount}</strong> questions matching`;
                    } else {
                        resultsCountTextEl.innerHTML = `Showing all <strong id="visibleResultsCount">${totalCount}</strong> questions`;
                    }
                } else {
                    if (visibleResultsCountEl) visibleResultsCountEl.textContent = visibleCount;
                    if (totalCategoryCountEl) totalCategoryCountEl.textContent = totalCount;
                }

                // Handle no results message
                let noResultsRow = activeTabEl.querySelector('.no-matching-results-row');
                if (visibleCount === 0 && totalCount > 0) {
                    if (!noResultsRow) {
                        noResultsRow = document.createElement('tr');
                        noResultsRow.className = 'no-matching-results-row';
                        noResultsRow.innerHTML = `
                            <td colspan="6">
                                <div class="mq-empty-state">
                                    <div class="mq-empty-icon"><i class="fa-solid fa-filter-circle-xmark"></i></div>
                                    <h3>No Questions Found</h3>
                                    <p>No questions in this category match your search filters.</p>
                                    <button type="button" class="btn-reset-filters" onclick="document.getElementById('clearFilter').click();" style="margin-top: 0.5rem;">
                                        <i class="fa-solid fa-rotate-left"></i> Reset Filters
                                    </button>
                                </div>
                            </td>
                        `;
                        const tbody = activeTabEl.querySelector('.mq-data-table tbody');
                        if (tbody) tbody.appendChild(noResultsRow);
                    }
                    noResultsRow.style.display = '';
                } else if (noResultsRow) {
                    noResultsRow.style.display = 'none';
                }

                // Render active filter chips
                renderActiveFilterChips(query, selectedType, selectedClass, selectedDiff);
            }

            function renderActiveFilterChips(query, type, classification, difficulty) {
                if (!activeFilterTags) return;
                activeFilterTags.innerHTML = '';

                if (query) {
                    const chip = document.createElement('span');
                    chip.className = 'mq-filter-chip';
                    chip.innerHTML = `Search: "${escHtml(query)}" <button type="button" data-clear="search"><i class="fa-solid fa-xmark"></i></button>`;
                    activeFilterTags.appendChild(chip);
                }
                if (type) {
                    const chip = document.createElement('span');
                    chip.className = 'mq-filter-chip';
                    chip.innerHTML = `Type: ${escHtml(type)} <button type="button" data-clear="type"><i class="fa-solid fa-xmark"></i></button>`;
                    activeFilterTags.appendChild(chip);
                }
                if (classification) {
                    const chip = document.createElement('span');
                    chip.className = 'mq-filter-chip';
                    chip.innerHTML = `Class: ${escHtml(classification)} <button type="button" data-clear="classification"><i class="fa-solid fa-xmark"></i></button>`;
                    activeFilterTags.appendChild(chip);
                }
                if (difficulty) {
                    const chip = document.createElement('span');
                    chip.className = 'mq-filter-chip';
                    chip.innerHTML = `Difficulty: ${escHtml(difficulty)} <button type="button" data-clear="difficulty"><i class="fa-solid fa-xmark"></i></button>`;
                    activeFilterTags.appendChild(chip);
                }

                // Attach remove events to chip buttons
                activeFilterTags.querySelectorAll('button[data-clear]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const key = btn.dataset.clear;
                        if (key === 'search') searchInput.value = '';
                        if (key === 'type') filterType.value = '';
                        if (key === 'classification') filterClassification.value = '';
                        if (key === 'difficulty') filterDifficulty.value = '';
                        applyFilters();
                    });
                });
            }

            // Real-time search & filter listeners
            searchInput.addEventListener('input', applyFilters);
            searchClearBtn.addEventListener('click', () => {
                searchInput.value = '';
                searchInput.focus();
                applyFilters();
            });
            filterType.addEventListener('change', applyFilters);
            filterClassification.addEventListener('change', applyFilters);
            filterDifficulty.addEventListener('change', applyFilters);

            clearFilterBtn.addEventListener('click', () => {
                searchInput.value = '';
                filterType.value = '';
                filterClassification.value = '';
                filterDifficulty.value = '';
                applyFilters();
            });

            // Keyboard shortcut '/' to search
            document.addEventListener('keydown', (e) => {
                if (e.key === '/' && document.activeElement !== searchInput && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                    e.preventDefault();
                    searchInput.focus();
                }
            });

            // Initialize classification filter for default tab
            updateClassificationFilterOptions('career');

            // -- Modal Handling & API Operations --
            const addModal = document.getElementById('addQuestionModal');
            const editModal = document.getElementById('editQuestionModal');
            const viewModal = document.getElementById('viewQuestionModal');
            const deleteModal = document.getElementById('deleteModal');

            // Open Add Modal
            document.getElementById('addQuestionBtn').addEventListener('click', () => {
                document.getElementById('addQuestionForm').reset();
                document.getElementById('questionCategory').value = currentActiveTab;
                updateConditionalFields();
                addModal.classList.add('active');
            });

            // Close Modals Handlers
            document.getElementById('closeAddModal').addEventListener('click', () => addModal.classList.remove('active'));
            document.getElementById('cancelAdd').addEventListener('click', () => addModal.classList.remove('active'));

            document.getElementById('closeEditModal').addEventListener('click', () => editModal.classList.remove('active'));
            document.getElementById('cancelEdit').addEventListener('click', () => editModal.classList.remove('active'));

            document.getElementById('closeViewModal').addEventListener('click', () => viewModal.classList.remove('active'));
            document.getElementById('closeView').addEventListener('click', () => viewModal.classList.remove('active'));

            document.getElementById('closeDeleteModal').addEventListener('click', () => deleteModal.classList.remove('active'));
            document.getElementById('cancelDelete').addEventListener('click', () => deleteModal.classList.remove('active'));

            // Overlay click dismiss
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('active');
                });
            });

            // API Helper functions
            let selectedQuestion = { id: null, category: null };

            async function apiPost(formData) {
                const res = await fetch('api/questions.php', { method: 'POST', body: formData });
                return res.json();
            }

            async function apiGetQuestion(id, category) {
                const fd = new FormData();
                fd.append('action', 'get');
                fd.append('id', id);
                fd.append('category', category);
                return apiPost(fd);
            }

            function escHtml(str) {
                if (!str) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            // Render View Details Modal
            function renderViewModal(question, category) {
                const categoryLabels = {
                    career: 'Career Interest',
                    personality: 'Personality',
                    skills: 'Skills Assessment',
                    strand: 'Strand-Based'
                };
                const categoryColors = {
                    career: '#f59e0b',
                    personality: '#a855f7',
                    skills: '#10b981',
                    strand: '#0ea5e9'
                };

                const catEl = document.getElementById('viewCategory');
                if (catEl) {
                    catEl.textContent = categoryLabels[category] || category;
                    catEl.style.color = categoryColors[category] || '#f8fafc';
                }

                const typeEl = document.getElementById('viewType');
                if (typeEl) {
                    const typeMap = {
                        likert: 'Likert Scale (1-5)',
                        objective: 'Multiple Choice (MCQ)',
                        'open-ended': 'Open-Ended'
                    };
                    typeEl.textContent = typeMap[question.question_type] || question.question_type;
                }

                const textEl = document.getElementById('viewText');
                if (textEl) textEl.textContent = question.question_text || '-';

                const diffEl = document.getElementById('viewDifficulty');
                if (diffEl) {
                    const diff = (question.difficulty || 'Medium');
                    diffEl.textContent = diff.charAt(0).toUpperCase() + diff.slice(1);
                    const diffColors = {
                        easy: '#34d399',
                        medium: '#fbbf24',
                        hard: '#f87171'
                    };
                    diffEl.style.color = diffColors[(diff || '').toLowerCase()] || '#f8fafc';
                }

                const competencySection = document.getElementById('viewCompetencySection');
                const classification_value = question.classification_value || question.trait || question.holland_type;
                
                if (classification_value) {
                    competencySection.style.display = 'flex';
                    const labelText = category === 'career' ? 'Holland RIASEC' : 
                                     category === 'personality' ? 'Big Five Trait' :
                                     category === 'skills' ? 'Skill Competency' : 'Target Strand';
                    document.getElementById('viewClassificationLabel').textContent = labelText;
                    document.getElementById('viewCompetency').textContent = classification_value;
                } else {
                    competencySection.style.display = 'none';
                }

                const optionsSection = document.getElementById('viewOptionsSection');
                const optionsList = document.getElementById('viewOptionsList');
                optionsList.innerHTML = '';
                if (question.options && question.options.length) {
                    optionsSection.style.display = 'block';
                    question.options.forEach(opt => {
                        const isCorrect = String(opt.is_correct) === '1';
                        const optionDiv = document.createElement('div');
                        optionDiv.className = 'view-option-item' + (isCorrect ? ' is-correct' : '');
                        optionDiv.innerHTML = `
                            <div class="option-left-content">
                                <span class="option-circle-badge">${escHtml(opt.option_label)}</span>
                                <span class="option-text-val">${escHtml(opt.option_text)}</span>
                            </div>
                            ${isCorrect ? '<span class="badge-correct-pill"><i class="fa-solid fa-circle-check"></i> Correct Answer</span>' : ''}
                        `;
                        optionsList.appendChild(optionDiv);
                    });
                } else {
                    optionsSection.style.display = 'none';
                }
            }

            // Fill Edit Question Form
            function fillEditForm(question, category) {
                const editCategorySelect = document.getElementById('editCategory');
                const editTypeSelect = document.getElementById('editType');

                document.getElementById('editQuestionId').value = question.id;
                editCategorySelect.value = category;

                const allowedTypes = getAllowedTypesForCategory(category);
                applyTypeOptionFilter(editTypeSelect, allowedTypes);

                const desiredType = question.question_type;
                editTypeSelect.value = (allowedTypes.includes(desiredType)) ? desiredType : (allowedTypes[0] || '');
                document.getElementById('editText').value = question.question_text;
                document.getElementById('editDifficulty').value = question.difficulty || 'medium';

                const classificationValue = question.classification_value || question.trait || '';
                if (category === 'career') {
                    document.getElementById('editHollandType').value = classificationValue || question.holland_type || 'Investigative';
                } else if (category === 'personality') {
                    document.getElementById('editPersonalityTrait').value = classificationValue;
                } else if (category === 'skills') {
                    document.getElementById('editSkillCategory').value = classificationValue;
                } else if (category === 'strand') {
                    const strandSelect = document.getElementById('editStrandSelect');
                    const strandCode = question.strand || classificationValue;
                    strandSelect.value = strandCode;
                    if (!strandSelect.value && classificationValue) {
                        Array.from(strandSelect.options).forEach(opt => {
                            if (opt.textContent.includes(classificationValue)) {
                                strandSelect.value = opt.value;
                            }
                        });
                    }
                }
                updateEditCategoryMetaFields(category);

                const editOptionsField = document.getElementById('editOptionsField');
                if (editTypeSelect.value === 'objective') {
                    editOptionsField.style.display = 'block';
                    const byLabel = {};
                    (question.options || []).forEach(o => { byLabel[o.option_label] = o; });
                    ['A','B','C','D'].forEach(letter => {
                        const opt = byLabel[letter];
                        const input = document.getElementById('editOption' + letter);
                        if (input) input.value = opt ? opt.option_text : '';
                        const radio = document.getElementById('editCorrect' + letter);
                        if (radio) radio.checked = opt ? (String(opt.is_correct) === '1') : false;
                    });
                } else {
                    editOptionsField.style.display = 'none';
                }

                editTypeSelect.dispatchEvent(new Event('change'));
            }

            // Click View Action
            document.querySelectorAll('.btn-mq-action.view').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const id = btn.dataset.id;
                    const category = btn.dataset.category;
                    selectedQuestion = { id, category };
                    const data = await apiGetQuestion(id, category);
                    if (!data.success) {
                        showStatusModal('Error', data.message || 'Failed to load question details', false);
                        return;
                    }
                    renderViewModal(data.question, category);
                    viewModal.classList.add('active');
                });
            });

            // Click Edit Action
            document.querySelectorAll('.btn-mq-action.edit').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const id = btn.dataset.id;
                    const category = btn.dataset.category;
                    selectedQuestion = { id, category };
                    const data = await apiGetQuestion(id, category);
                    if (!data.success) {
                        showStatusModal('Error', data.message || 'Failed to load question for editing', false);
                        return;
                    }
                    fillEditForm(data.question, category);
                    editModal.classList.add('active');
                });
            });

            // Click Delete Action
            document.querySelectorAll('.btn-mq-action.delete').forEach(btn => {
                btn.addEventListener('click', () => {
                    selectedQuestion = { id: btn.dataset.id, category: btn.dataset.category };
                    const row = btn.closest('tr');
                    const qText = row?.querySelector('.mq-question-text')?.textContent?.trim() || '';
                    const preview = document.getElementById('deleteQuestionText');
                    if (preview) preview.textContent = `"${qText}"`;
                    deleteModal.classList.add('active');
                });
            });

            // View modal to Edit modal transition
            document.getElementById('viewEditBtn').addEventListener('click', () => {
                if (!selectedQuestion.id || !selectedQuestion.category) return;
                viewModal.classList.remove('active');
                apiGetQuestion(selectedQuestion.id, selectedQuestion.category).then(data => {
                    if (data.success) {
                        fillEditForm(data.question, selectedQuestion.category);
                        editModal.classList.add('active');
                    } else {
                        showStatusModal('Error', data.message || 'Failed to load question for editing', false);
                    }
                });
            });

            // -- Dynamic Form Fields Helpers --
            const categorySelect = document.getElementById('questionCategory');
            const typeSelect = document.getElementById('questionType');
            const optionsField = document.getElementById('optionsField');
            const careerMetaFields = document.getElementById('careerMetaFields');
            const personalityMetaFields = document.getElementById('personalityMetaFields');
            const skillsMetaFields = document.getElementById('skillsMetaFields');
            const strandMetaFields = document.getElementById('strandMetaFields');
            const editCareerMetaFields = document.getElementById('editCareerMetaFields');
            const editPersonalityMetaFields = document.getElementById('editPersonalityMetaFields');
            const editSkillsMetaFields = document.getElementById('editSkillsMetaFields');
            const editStrandMetaFields = document.getElementById('editStrandMetaFields');

            const QUESTION_TYPE_OPTIONS = [
                { value: 'likert', label: 'Likert Scale (1-5)' },
                { value: 'objective', label: 'Objective (Multiple Choice)' },
                { value: 'open-ended', label: 'Open-Ended' }
            ];

            function getAllowedTypesForCategory(category) {
                if (category === 'career' || category === 'personality') {
                    return ['likert', 'open-ended'];
                }
                if (category === 'skills' || category === 'strand') {
                    return ['objective', 'open-ended'];
                }
                return ['likert', 'objective', 'open-ended'];
            }

            function applyTypeOptionFilter(selectEl, allowedTypes) {
                const currentValue = selectEl.value;
                selectEl.querySelectorAll('option:not([value=""])').forEach(opt => opt.remove());

                QUESTION_TYPE_OPTIONS.forEach(opt => {
                    if (allowedTypes.includes(opt.value)) {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        selectEl.appendChild(option);
                    }
                });

                if (currentValue && allowedTypes.includes(currentValue)) {
                    selectEl.value = currentValue;
                } else if (allowedTypes.length > 0) {
                    selectEl.value = allowedTypes[0];
                }
            }

            function updateCategoryMetaFields(category) {
                if (careerMetaFields) careerMetaFields.style.display = category === 'career' ? '' : 'none';
                if (personalityMetaFields) personalityMetaFields.style.display = category === 'personality' ? '' : 'none';
                if (skillsMetaFields) skillsMetaFields.style.display = category === 'skills' ? '' : 'none';
                if (strandMetaFields) strandMetaFields.style.display = category === 'strand' ? '' : 'none';
            }

            function updateEditCategoryMetaFields(category) {
                if (editCareerMetaFields) editCareerMetaFields.style.display = category === 'career' ? '' : 'none';
                if (editPersonalityMetaFields) editPersonalityMetaFields.style.display = category === 'personality' ? '' : 'none';
                if (editSkillsMetaFields) editSkillsMetaFields.style.display = category === 'skills' ? '' : 'none';
                if (editStrandMetaFields) editStrandMetaFields.style.display = category === 'strand' ? '' : 'none';
            }

            function getClassificationValue(category, form = 'add') {
                const ids = form === 'edit'
                    ? {
                        career: 'editHollandType',
                        personality: 'editPersonalityTrait',
                        skills: 'editSkillCategory',
                        strand: 'editStrandSelect'
                    }
                    : {
                        career: 'careerHollandType',
                        personality: 'personalityTrait',
                        skills: 'skillCategory',
                        strand: 'strandSelect'
                    };

                const fieldId = ids[category];
                return fieldId ? (document.getElementById(fieldId)?.value || '') : '';
            }

            function updateConditionalFields() {
                const category = categorySelect.value;
                applyTypeOptionFilter(typeSelect, getAllowedTypesForCategory(category));
                const type = typeSelect.value;

                updateCategoryMetaFields(category);
                optionsField.style.display = type === 'objective' ? 'block' : 'none';
            }

            categorySelect.addEventListener('change', updateConditionalFields);
            typeSelect.addEventListener('change', function() {
                optionsField.style.display = this.value === 'objective' ? 'block' : 'none';
            });

            // Edit Type & Category changes
            const editTypeSelect = document.getElementById('editType');
            const editCategorySelect = document.getElementById('editCategory');
            const editOptionsField = document.getElementById('editOptionsField');

            editTypeSelect.addEventListener('change', function() {
                editOptionsField.style.display = this.value === 'objective' ? 'block' : 'none';
            });

            editCategorySelect.addEventListener('change', function() {
                applyTypeOptionFilter(editTypeSelect, getAllowedTypesForCategory(this.value));
                updateEditCategoryMetaFields(this.value);
                editTypeSelect.dispatchEvent(new Event('change'));
            });

            // -- Form Submissions (Add / Edit / Delete) --
            
            // Add Form Submit
            document.getElementById('addQuestionForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const category = document.getElementById('questionCategory').value;
                const type = document.getElementById('questionType').value;
                const text = document.getElementById('questionText').value;

                if (!category || !type || !text.trim()) {
                    showStatusModal('Error', 'Please fill in all required question fields', false);
                    return;
                }

                const classificationValue = getClassificationValue(category);
                if (!classificationValue) {
                    showStatusModal('Error', 'Please select the required categorization (Trait, Competency, Strand, or Holland Code)', false);
                    return;
                }

                if (type === 'objective') {
                    const options = ['A', 'B', 'C', 'D'];
                    let hasCorrect = false;
                    let allFilled = true;

                    options.forEach(opt => {
                        const input = document.querySelector(`input[name="option${opt}"]`);
                        const radio = document.getElementById(`correct${opt}`);
                        if (!input || !input.value.trim()) allFilled = false;
                        if (radio && radio.checked) hasCorrect = true;
                    });

                    if (!allFilled) {
                        showStatusModal('Error', 'Please fill in all 4 multiple-choice options (A-D)', false);
                        return;
                    }
                    if (!hasCorrect) {
                        showStatusModal('Error', 'Please select the correct answer option using the radio buttons', false);
                        return;
                    }
                }

                const formData = new FormData(this);
                formData.set('classification_value', classificationValue);
                
                fetch('api/questions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        addModal.classList.remove('active');
                        document.getElementById('addQuestionForm').reset();
                        showStatusModal('Success', 'Question added to assessment bank successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add question', false);
                    }
                })
                .catch(error => {
                    showStatusModal('Error', 'Network error adding question: ' + error.message, false);
                });
            });

            // Edit Form Submit
            document.getElementById('editQuestionForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const type = document.getElementById('editType').value;
                const text = document.getElementById('editText').value;
                const category = document.getElementById('editCategory').value;
                
                if (!text.trim()) {
                    showStatusModal('Error', 'Please enter the question text', false);
                    return;
                }

                const classificationValue = getClassificationValue(category, 'edit');
                if (!classificationValue) {
                    showStatusModal('Error', 'Please select the required categorization', false);
                    return;
                }
                
                if (type === 'objective') {
                    const options = ['A', 'B', 'C', 'D'];
                    let hasCorrect = false;
                    let allFilled = true;
                    
                    options.forEach(opt => {
                        const input = document.getElementById('editOption' + opt);
                        const radio = document.getElementById('editCorrect' + opt);
                        if (!input || !input.value.trim()) allFilled = false;
                        if (radio && radio.checked) hasCorrect = true;
                    });
                    
                    if (!allFilled) {
                        showStatusModal('Error', 'Please fill in all 4 multiple-choice options (A-D)', false);
                        return;
                    }
                    if (!hasCorrect) {
                        showStatusModal('Error', 'Please select the correct answer radio button', false);
                        return;
                    }
                }
                
                const fd = new FormData();
                fd.append('action', 'edit');
                fd.append('id', document.getElementById('editQuestionId').value);
                fd.append('category', category);
                fd.append('old_category', selectedQuestion.category);
                fd.append('text', text);
                fd.append('difficulty', document.getElementById('editDifficulty').value);
                fd.append('classification_value', classificationValue);
                fd.append('holland_type', document.getElementById('editHollandType')?.value || '');
                fd.append('type', type);

                if (type === 'objective') {
                    fd.append('correctAnswer', document.querySelector('input[name="editCorrectAnswer"]:checked')?.value || '');
                    fd.append('optionA', document.getElementById('editOptionA').value);
                    fd.append('optionB', document.getElementById('editOptionB').value);
                    fd.append('optionC', document.getElementById('editOptionC').value);
                    fd.append('optionD', document.getElementById('editOptionD').value);
                }

                apiPost(fd).then(data => {
                    if (data.success) {
                        editModal.classList.remove('active');
                        showStatusModal('Success', 'Question updated successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update question', false);
                    }
                }).catch(() => showStatusModal('Error', 'Network error updating question. Please try again.', false));
            });

            // Delete Confirm Submit
            document.getElementById('confirmDelete').addEventListener('click', () => {
                if (!selectedQuestion.id || !selectedQuestion.category) return;
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', selectedQuestion.id);
                fd.append('category', selectedQuestion.category);
                apiPost(fd).then(data => {
                    if (data.success) {
                        deleteModal.classList.remove('active');
                        showStatusModal('Success', 'Question removed from question bank!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to delete question', false);
                    }
                }).catch(() => showStatusModal('Error', 'Network error deleting question. Please try again.', false));
            });
        });
    </script>
    <script>
        // Notification dropdown toggle
        document.getElementById('notificationBtn')?.addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        });

        document.addEventListener('click', function() {
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) dropdown.style.display = 'none';
        });

        document.getElementById('notificationDropdown')?.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        function clearAllNotifications(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.showConfirm === 'function') {
                window.showConfirm("Are you sure you want to clear all notifications?", "Clear Notifications", executeClear);
            } else if (confirm("Are you sure you want to clear all notifications?")) {
                executeClear();
            }
            
            function executeClear() {
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=clear_all'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const list = document.querySelector('.notification-list');
                        if (list) {
                            list.innerHTML = '<div class="no-notifications"><p>No notifications.</p></div>';
                        }
                        const badge = document.getElementById('notificationBadge');
                        if (badge) {
                            badge.textContent = '0';
                            badge.style.display = 'none';
                        }
                        const markAllBtn = document.querySelector('.mark-all-read');
                        if (markAllBtn) markAllBtn.style.display = 'none';
                    }
                });
            }
        }

        function markAllRead(e) {
            e.preventDefault();
            e.stopPropagation();
            
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read&caller_type=admin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    const badge = document.getElementById('notificationBadge');
                    if (badge) badge.style.display = 'none';
                    document.querySelector('.mark-all-read')?.remove();
                }
            });
        }

        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                if (this.classList.contains('unread')) {
                    const notifId = this.dataset.id;
                    fetch('api/notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&id=' + notifId + '&caller_type=admin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.classList.remove('unread');
                            const badge = document.getElementById('notificationBadge');
                            if (badge) {
                                const currentCount = parseInt(badge.textContent) || 0;
                                if (currentCount > 1) {
                                    badge.textContent = currentCount - 1;
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                        }
                    });
                }
            });
        });
    </script>
    <script src="admin.js"></script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
