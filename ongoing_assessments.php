<?php
// Ongoing Assessments - Full Management & Real-Time Monitoring Hub
// Displays all ongoing assessments with live telemetry, KPI stats, pagination, search, strand filter, sort, and auto-refresh.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';

require_once __DIR__ . '/includes/auth_rbac.php';
require_permission('view_assessment_results');

// Auto-expire abandoned assessments (24 hours timeout) to wipe them from the admin view in real-time
$expireStmt = $mysqli->prepare("
    UPDATE student_assessments 
    SET status = 'abandoned' 
    WHERE status = 'in_progress' 
      AND (
          (expires_at IS NOT NULL AND expires_at < NOW())
          OR 
          (expires_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
      )
");
if ($expireStmt) {
    $expireStmt->execute();
    $expireStmt->close();
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    if ($_POST['action'] === 'get_ongoing') {
        $search    = trim($_POST['search']    ?? '');
        $strand_id = (int)($_POST['strand_id'] ?? 0);
        $sort      = $_POST['sort'] ?? 'recently_started';
        $page      = max(1, (int)($_POST['page'] ?? 1));
        $per_page  = 10;
        $offset    = ($page - 1) * $per_page;

        // Whitelist sort options
        $sort_map = [
            'recently_started' => 'sa.created_at DESC',
            'oldest_started'   => 'sa.created_at ASC',
            'progress_high'    => 'answered_count DESC, sa.created_at DESC',
            'progress_low'     => 'answered_count ASC, sa.created_at DESC',
            'student_name_asc' => 'student_name ASC',
            'student_name_desc'=> 'student_name DESC',
        ];
        $order_by = $sort_map[$sort] ?? 'sa.created_at DESC';

        $filter_status = $_POST['status'] ?? 'in_progress';

        // Build WHERE clauses for assessments
        $where_parts = [];
        if ($filter_status === 'in_progress') {
            $where_parts[] = "sa.status = 'in_progress'";
            $where_parts[] = "sa.id IN (SELECT MAX(id) FROM student_assessments WHERE status = 'in_progress' GROUP BY student_id)";
        } else if ($filter_status === 'completed') {
            $where_parts[] = "sa.status = 'completed'";
            $where_parts[] = "sa.completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $where_parts[] = "sa.id IN (SELECT MAX(id) FROM student_assessments WHERE status = 'completed' GROUP BY student_id)";
        } else {
            $where_parts[] = "sa.id IN (SELECT MAX(id) FROM student_assessments GROUP BY student_id)";
        }
        $params        = [];
        $types         = '';

        if ($search !== '') {
            $where_parts[] = "(CONCAT(COALESCE(s.first_name,''), ' ', COALESCE(s.last_name,'')) LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
            $search_like = '%' . $search . '%';
            $params[]  = $search_like;
            $params[]  = $search_like;
            $params[]  = $search_like;
            $types    .= 'sss';
        }

        if ($strand_id > 0) {
            $where_parts[] = 's.strand_id = ?';
            $params[]  = $strand_id;
            $types    .= 'i';
        }

        $where_sql = implode(' AND ', $where_parts);

        // Calculate Overall KPIs (Total Active, Recent < 30min, Avg Answered, Leading Strand)
        $kpi_sql = "
            SELECT 
                COUNT(sa.id) AS total_active,
                SUM(CASE WHEN sa.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) AS recent_starters,
                COALESCE(AVG(ans_count.cnt), 0) AS avg_answered
            FROM student_assessments sa
            LEFT JOIN students s ON sa.student_id = s.id
            LEFT JOIN (
                SELECT assessment_id, COUNT(*) AS cnt 
                FROM student_answers 
                GROUP BY assessment_id
            ) ans_count ON sa.id = ans_count.assessment_id
            WHERE sa.status = 'in_progress'
              AND sa.id IN (SELECT MAX(id) FROM student_assessments WHERE status = 'in_progress' GROUP BY student_id)
        ";
        $kpi_res = $mysqli->query($kpi_sql);
        $kpi_data = $kpi_res ? $kpi_res->fetch_assoc() : ['total_active' => 0, 'recent_starters' => 0, 'avg_answered' => 0];

        // Leading Strand among active assessments
        $leading_strand_sql = "
            SELECT COALESCE(st.code, st.name, 'N/A') AS strand_code, COUNT(sa.id) as cnt
            FROM student_assessments sa
            JOIN students s ON sa.student_id = s.id
            LEFT JOIN strands st ON s.strand_id = st.id
            WHERE sa.status = 'in_progress'
              AND sa.id IN (SELECT MAX(id) FROM student_assessments WHERE status = 'in_progress' GROUP BY student_id)
            GROUP BY s.strand_id
            ORDER BY cnt DESC
            LIMIT 1
        ";
        $leading_strand_res = $mysqli->query($leading_strand_sql);
        $leading_strand_row = $leading_strand_res ? $leading_strand_res->fetch_assoc() : null;
        $leading_strand = $leading_strand_row ? $leading_strand_row['strand_code'] : 'None';

        // Count total matching rows
        $count_sql  = "
            SELECT COUNT(*) AS total
            FROM student_assessments sa
            LEFT JOIN students s ON sa.student_id = s.id
            WHERE $where_sql
        ";
        $count_stmt = $mysqli->prepare($count_sql);
        if ($types) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $total_rows  = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        $total_pages = max(1, (int)ceil($total_rows / $per_page));
        $page        = min($page, $total_pages);

        // Fetch paginated rows with category breakdowns
        $data_sql = "
            SELECT sa.id AS assessment_id, 
                   sa.student_id,
                   sa.created_at,
                   sa.status AS assessment_status,
                   CONCAT(
                       COALESCE(s.first_name, ''),
                       IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                       ' ', COALESCE(s.last_name, ''),
                       IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                   ) AS student_name,
                   s.first_name,
                   s.last_name,
                   s.student_id AS lrn_student_id,
                   s.email AS student_email,
                   s.grade_level,
                   s.profile_picture,
                   st.name AS strand,
                   st.code AS strand_code,
                   COUNT(sa2.id) AS answered_count,
                   SUM(CASE WHEN sa2.question_type = 'career' THEN 1 ELSE 0 END) AS career_answered,
                   SUM(CASE WHEN sa2.question_type = 'personality' THEN 1 ELSE 0 END) AS personality_answered,
                   SUM(CASE WHEN sa2.question_type = 'skills' THEN 1 ELSE 0 END) AS skills_answered,
                   SUM(CASE WHEN sa2.question_type = 'strand' THEN 1 ELSE 0 END) AS strand_answered
            FROM student_assessments sa
            LEFT JOIN students s ON sa.student_id = s.id
            LEFT JOIN strands st ON s.strand_id = st.id
            LEFT JOIN student_answers sa2 ON sa.id = sa2.assessment_id
            WHERE $where_sql
            GROUP BY sa.id
            ORDER BY $order_by
            LIMIT ?, ?
        ";

        $data_stmt = $mysqli->prepare($data_sql);
        $bind_types  = $types . 'ii';
        $bind_params = array_merge($params, [$offset, $per_page]);
        $data_stmt->bind_param($bind_types, ...$bind_params);
        $data_stmt->execute();
        $result      = $data_stmt->get_result();
        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $assessments[] = $row;
        }
        $data_stmt->close();

        $db_time_result = $mysqli->query("SELECT CURRENT_TIMESTAMP as server_time");
        $server_time = $db_time_result->fetch_assoc()['server_time'];

        $response = [
            'success'     => true,
            'server_time' => $server_time,
            'assessments' => $assessments,
            'total'       => $total_rows,
            'total_pages' => $total_pages,
            'page'        => $page,
            'kpis'        => [
                'total_active'    => (int)$kpi_data['total_active'],
                'recent_starters' => (int)$kpi_data['recent_starters'],
                'avg_answered'    => round((float)$kpi_data['avg_answered'], 1),
                'leading_strand'  => $leading_strand,
            ]
        ];
    }

    echo json_encode($response);
    exit;
}

// ==================== PAGE DATA ====================
$userName        = get_staff_name();
$staffId         = get_staff_id();
$userType        = is_counselor() ? 'counselor' : 'admin';
$adminProfilePic = null;
$notifications   = [];
$unreadCount     = 0;

if (is_admin() && $staffId) {
    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
    if ($profileStmt) {
        $profileStmt->bind_param('i', $staffId);
        $profileStmt->execute();
        $adminData       = $profileStmt->get_result()->fetch_assoc();
        $adminProfilePic = $adminData['profile_picture'] ?? null;
        $profileStmt->close();
    }
}

if ($staffId) {
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
        $notifResult = $notifStmt->get_result();
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notifStmt->close();
    }
}

// Fetch strands for filter dropdown
$strands_result = $mysqli->query("SELECT id, name, code FROM strands WHERE code IN ('ACADPRO', 'STEM') ORDER BY name ASC");
$strands        = [];
while ($s = $strands_result->fetch_assoc()) {
    $strands[] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ongoing Assessments - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        
        :root {
            --oa-amber: #f59e0b;
            --oa-emerald: #10b981;
            --oa-sky: #0ea5e9;
            --oa-indigo: #6366f1;
            --oa-purple: #a855f7;
            --oa-rose: #f43f5e;
        }

        /* -- Page Header Area -- */
        .oa-header-hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
            gap: 1.25rem;
        }

        .oa-hero-left {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .oa-back-nav {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #94a3b8;
            font-size: 0.84rem;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s ease;
            margin-bottom: 0.25rem;
            width: fit-content;
        }

        .oa-back-nav:hover {
            color: #fbbf24;
        }

        .oa-hero-title {
            font-size: 1.55rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .oa-title-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.2), rgba(2, 132, 199, 0.08));
            border: 1px solid rgba(14, 165, 233, 0.3);
            color: #38bdf8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .oa-hero-subtitle {
            font-size: 0.88rem;
            color: #94a3b8;
            margin: 0;
            max-width: 680px;
            line-height: 1.5;
        }

        /* -- Telemetry & Auto-Refresh Controls -- */
        .oa-telemetry-cluster {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 0.55rem 0.85rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        }

        .oa-live-pulse-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #34d399;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .oa-pulse-circle {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: oaPulse 1.8s infinite;
        }

        @keyframes oaPulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .oa-refresh-ticker {
            font-size: 0.82rem;
            color: #cbd5e1;
            font-weight: 600;
            min-width: 110px;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        .oa-interval-select {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 0.35rem 0.65rem;
            color: #e2e8f0;
            font-size: 0.8rem;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .oa-interval-select:hover {
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .oa-btn-refresh-manual {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.85rem;
        }

        .oa-btn-refresh-manual:hover {
            background: rgba(14, 165, 233, 0.2);
            color: #38bdf8;
            border-color: rgba(14, 165, 233, 0.35);
        }

        /* -- KPI Stat Summary Cards Grid -- */
        .oa-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.15rem;
            margin-bottom: 1.75rem;
        }

        .oa-kpi-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.85));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 1.25rem 1.35rem;
            display: flex;
            align-items: center;
            gap: 1.15rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .oa-kpi-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: rgba(255, 255, 255, 0.1);
        }

        .oa-kpi-card.kpi-active::after { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
        .oa-kpi-card.kpi-recent::after { background: linear-gradient(90deg, #10b981, #34d399); }
        .oa-kpi-card.kpi-progress::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .oa-kpi-card.kpi-strand::after { background: linear-gradient(90deg, #8b5cf6, #c084fc); }

        .oa-kpi-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }

        .oa-kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }

        .oa-kpi-card:hover .oa-kpi-icon {
            transform: scale(1.08);
        }

        .kpi-active .oa-kpi-icon {
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(14, 165, 233, 0.25);
        }

        .kpi-recent .oa-kpi-icon {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .kpi-progress .oa-kpi-icon {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .kpi-strand .oa-kpi-icon {
            background: rgba(139, 92, 246, 0.15);
            color: #c084fc;
            border: 1px solid rgba(139, 92, 246, 0.25);
        }

        .oa-kpi-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .oa-kpi-label {
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 2px;
            white-space: normal;
            word-wrap: break-word;
        }

        .oa-kpi-val {
            font-size: 1.55rem;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.2;
            margin: 0;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
            white-space: normal;
            word-wrap: break-word;
        }

        .oa-kpi-meta {
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 2px;
            white-space: normal;
            word-wrap: break-word;
        }

        /* -- Multi-Filter & Search Toolbar -- */
        .oa-toolbar-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.75));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
        }

        .oa-toolbar-main-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .oa-search-wrapper {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 460px;
        }

        .oa-search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .oa-search-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.7rem 2.8rem 0.7rem 2.6rem;
            color: #f8fafc;
            font-size: 0.9rem;
            font-family: inherit;
            outline: none;
            transition: all 0.25s ease;
            box-sizing: border-box;
        }

        .oa-search-input::placeholder {
            color: #64748b;
        }

        .oa-search-input:focus {
            border-color: #38bdf8;
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .oa-search-input:focus + .oa-search-icon {
            color: #38bdf8;
        }

        .oa-search-clear-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.08);
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: none;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }

        .oa-search-clear-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .oa-search-clear-btn.active {
            display: flex;
        }

        .oa-filters-group {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .oa-filter-select-box {
            position: relative;
        }

        .oa-filter-select {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 0.65rem 2.2rem 0.65rem 0.9rem;
            color: #cbd5e1;
            font-size: 0.84rem;
            font-weight: 500;
            font-family: inherit;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 150px;
        }

        .oa-filter-select:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
        }

        .oa-filter-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .oa-filter-select-box i {
            position: absolute;
            right: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.75rem;
            pointer-events: none;
        }

        .btn-oa-reset-filters {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #94a3b8;
            font-size: 0.84rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-oa-reset-filters:hover {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .oa-toolbar-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 0.82rem;
            color: #94a3b8;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .oa-results-counter {
            font-feature-settings: "tnum";
            font-weight: 600;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .oa-results-counter .counter-pill {
            padding: 0.15rem 0.55rem;
            border-radius: 20px;
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid rgba(56, 189, 248, 0.25);
        }

        /* -- Data Table Glass Card & Styling -- */
        .oa-table-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.85));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            margin-bottom: 2rem;
        }

        .oa-table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .oa-data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .oa-data-table thead {
            background: rgba(15, 23, 42, 0.9);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .oa-data-table th {
            padding: 1rem 1.15rem;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            white-space: nowrap;
            user-select: none;
        }

        .oa-data-table tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: all 0.2s ease;
        }

        .oa-data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }

        .oa-data-table td {
            padding: 1.05rem 1.15rem;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #e2e8f0;
        }

        /* Number Pill */
        .oa-num-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 28px;
            padding: 0 0.45rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.8rem;
            font-weight: 700;
            color: #38bdf8;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        /* Student Identity Cell */
        .oa-student-cell {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .oa-student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, 0.12);
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
        }

        .oa-student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .oa-student-details {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .oa-student-name {
            font-size: 0.94rem;
            font-weight: 700;
            color: #f8fafc;
            line-height: 1.3;
        }

        .oa-student-meta {
            font-size: 0.76rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-top: 2px;
        }

        .oa-grade-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Strand Track Badge */
        .oa-strand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.25);
            color: #38bdf8;
            white-space: nowrap;
        }

        /* Progress Cell & Bar */
        .oa-progress-container {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            min-width: 190px;
        }

        .oa-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.78rem;
        }

        .oa-progress-count {
            font-weight: 700;
            color: #f1f5f9;
            font-feature-settings: "tnum";
        }

        .oa-progress-pct {
            font-weight: 800;
            color: #38bdf8;
            font-feature-settings: "tnum";
        }

        .oa-progress-track {
            width: 100%;
            height: 8px;
            background: rgba(15, 23, 42, 0.8);
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.06);
            position: relative;
        }

        .oa-progress-fill {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #0ea5e9 0%, #38bdf8 100%);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
            transition: width 0.4s ease;
        }

        .oa-progress-fill.near-complete {
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            box-shadow: 0 0 10px rgba(52, 211, 153, 0.5);
        }

        .oa-progress-fill.starting {
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%);
            box-shadow: 0 0 10px rgba(251, 191, 36, 0.5);
        }

        .oa-category-pills {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 2px;
        }

        .oa-cat-tag {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.05);
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .oa-cat-tag.has-answers {
            background: rgba(14, 165, 233, 0.12);
            color: #7dd3fc;
            border-color: rgba(14, 165, 233, 0.25);
        }

        /* Elapsed Time Cell */
        .oa-time-cell {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            white-space: nowrap;
        }

        .oa-time-ago {
            font-weight: 600;
            color: #f1f5f9;
            font-size: 0.84rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .oa-time-ago i {
            color: #94a3b8;
            font-size: 0.78rem;
        }

        .oa-time-exact {
            font-size: 0.74rem;
            color: #64748b;
        }

        /* Status Badge */
        .oa-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.28);
            color: #34d399;
            white-space: nowrap;
        }

        .oa-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 6px #10b981;
        }

        /* Action Cluster */
        .oa-actions-cluster {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(15, 23, 42, 0.6);
            padding: 0.25rem 0.4rem;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .btn-oa-action {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-oa-action.snapshot {
            background: rgba(14, 165, 233, 0.12);
            color: #38bdf8;
            border-color: rgba(14, 165, 233, 0.25);
        }

        .btn-oa-action.snapshot:hover {
            background: #0ea5e9;
            color: #ffffff;
            box-shadow: 0 0 14px rgba(14, 165, 233, 0.45);
            transform: translateY(-1px);
        }

        .btn-oa-action.inspect {
            background: rgba(251, 191, 36, 0.12);
            color: #fbbf24;
            border-color: rgba(251, 191, 36, 0.25);
        }

        .btn-oa-action.inspect:hover {
            background: #f59e0b;
            color: #0f172a;
            box-shadow: 0 0 14px rgba(245, 158, 11, 0.45);
            transform: translateY(-1px);
        }

        /* Empty State */
        .oa-empty-state {
            padding: 4.5rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.15rem;
        }

        .oa-empty-radar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(14, 165, 233, 0.08);
            border: 1px solid rgba(14, 165, 233, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #38bdf8;
            position: relative;
        }

        .oa-empty-radar::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 1px dashed rgba(14, 165, 233, 0.3);
            animation: oaRadarSpin 12s linear infinite;
        }

        @keyframes oaRadarSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .oa-empty-state h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
        }

        .oa-empty-state p {
            font-size: 0.88rem;
            color: #94a3b8;
            max-width: 440px;
            margin: 0;
            line-height: 1.5;
        }

        /* -- Pagination -- */
        .oa-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.15rem 1.35rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(15, 23, 42, 0.6);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .oa-page-info {
            font-size: 0.82rem;
            color: #94a3b8;
            font-weight: 500;
            font-feature-settings: "tnum";
        }

        .oa-page-controls {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .oa-page-btn {
            min-width: 32px;
            height: 32px;
            padding: 0 0.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .oa-page-btn:hover:not(:disabled) {
            background: rgba(56, 189, 248, 0.15);
            border-color: rgba(56, 189, 248, 0.3);
            color: #38bdf8;
        }

        .oa-page-btn.active {
            background: #0ea5e9;
            border-color: #0ea5e9;
            color: #ffffff;
            box-shadow: 0 0 10px rgba(14, 165, 233, 0.4);
        }

        .oa-page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .oa-page-ellipsis {
            color: #64748b;
            padding: 0 0.25rem;
        }

        /* -- In-Page Quick Snapshot Modal -- */
        .oa-modal {
            position: fixed;
            inset: 0;
            z-index: 1050;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .oa-modal.active {
            display: flex;
        }

        .oa-modal-overlay {
            position: absolute;
            inset: 0;
            background: rgba(3, 7, 18, 0.8);
            backdrop-filter: blur(8px);
        }

        .oa-modal-content {
            position: relative;
            z-index: 1;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(56, 189, 248, 0.15);
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: oaPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes oaPopIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .oa-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.9);
        }

        .oa-modal-title-wrap {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .oa-modal-icon-badge {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.3);
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }

        .oa-modal-header h2 {
            font-size: 1.15rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0;
        }

        .oa-modal-header p {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 2px 0 0 0;
        }

        .oa-modal-close {
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

        .oa-modal-close:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .oa-modal-body {
            padding: 1.5rem 1.75rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* Student Snapshot Hero Card */
        .oa-snap-student-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .oa-snap-student-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .oa-snap-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.15);
            overflow: hidden;
            flex-shrink: 0;
        }

        .oa-snap-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .oa-snap-details h3 {
            font-size: 1.05rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0 0 3px 0;
        }

        .oa-snap-details p {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        /* 4-Quadrant Category Progress Grid */
        .oa-snap-categories-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .oa-snap-cat-box {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }

        .oa-snap-cat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .oa-snap-cat-title {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.84rem;
            font-weight: 700;
            color: #f1f5f9;
        }

        .oa-snap-cat-val {
            font-size: 0.8rem;
            font-weight: 700;
            color: #94a3b8;
            font-feature-settings: "tnum";
        }

        .oa-snap-cat-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            overflow: hidden;
        }

        .oa-snap-cat-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.3s ease;
        }

        .cat-career .oa-snap-cat-title i { color: #f59e0b; }
        .cat-career .oa-snap-cat-fill { background: #f59e0b; }

        .cat-personality .oa-snap-cat-title i { color: #a855f7; }
        .cat-personality .oa-snap-cat-fill { background: #a855f7; }

        .cat-skills .oa-snap-cat-title i { color: #10b981; }
        .cat-skills .oa-snap-cat-fill { background: #10b981; }

        .cat-strand .oa-snap-cat-title i { color: #0ea5e9; }
        .cat-strand .oa-snap-cat-fill { background: #0ea5e9; }

        .oa-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.15rem 1.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.9);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .btn-oa-modal-close {
            padding: 0.65rem 1.25rem;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-oa-modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
        }

        .btn-oa-modal-view-full {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.65rem 1.35rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            border: none;
            color: #ffffff;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(14, 165, 233, 0.35);
            transition: all 0.2s ease;
        }

        .btn-oa-modal-view-full:hover {
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            box-shadow: 0 6px 18px rgba(14, 165, 233, 0.5);
            transform: translateY(-1px);
        }

        /* -- Responsive Rules -- */
        @media (max-width: 1200px) {
            .oa-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .oa-snap-categories-grid {
                grid-template-columns: 1fr;
            }
            .oa-toolbar-main-row {
                flex-direction: column;
                align-items: stretch;
            }
            .oa-search-wrapper {
                max-width: 100%;
            }
            .oa-filters-group {
                width: 100%;
            }
            .oa-filter-select-box {
                flex: 1;
            }
            .oa-filter-select {
                width: 100%;
            }
        }

        @media (max-width: 580px) {
            .oa-kpi-grid {
                grid-template-columns: 1fr;
            }
            .oa-header-hero {
                flex-direction: column;
                align-items: stretch;
            }
            .oa-telemetry-cluster {
                width: 100%;
                justify-content: space-between;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body data-user-logged-in="true">
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
                <h1>Ongoing Assessments</h1>
            </div>
            <div class="top-bar-actions">
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown" style="display:none;">
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
                        <?php elseif (is_counselor()): ?>
                            <i class="fa-solid fa-user-tie"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-user-shield"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-dropdown">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="dashboard-content">

            <!-- Hero Header & Telemetry Controls -->
            <div class="oa-header-hero">
                <div class="oa-hero-left">
                    <a href="admin_dashboard.php" class="oa-back-nav">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Dashboard</span>
                    </a>
                    <h2 class="oa-hero-title">
                        <span class="oa-title-badge"><i class="fa-solid fa-satellite-dish"></i></span>
                        Active Assessments Live Monitoring
                    </h2>
                    <p class="oa-hero-subtitle">Real-time telemetry tracking active student assessments, response velocity, question completion milestones, and category progression.</p>
                </div>

                <!-- Live Auto-Refresh Hub -->
                <div class="oa-telemetry-cluster">
                    <div class="oa-live-pulse-badge">
                        <span class="oa-pulse-circle"></span>
                        <span>Live Telemetry</span>
                    </div>
                    <span class="oa-refresh-ticker" id="oaCountdownText">Refresh in 10s</span>
                    <select id="oaIntervalSelect" class="oa-interval-select" title="Auto-refresh rate">
                        <option value="5">5s (Rapid)</option>
                        <option value="10" selected>10s (Standard)</option>
                        <option value="30">30s (Relaxed)</option>
                        <option value="60">60s (Slow)</option>
                        <option value="0">Paused</option>
                    </select>
                    <button type="button" id="oaManualRefreshBtn" class="oa-btn-refresh-manual" title="Refresh data now">
                        <i class="fa-solid fa-rotate-right" id="oaRefreshIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Real-Time KPI Stat Summary Cards -->
            <div class="oa-kpi-grid">
                <!-- KPI 1: Active Sessions -->
                <div class="oa-kpi-card kpi-active">
                    <div class="oa-kpi-icon">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div class="oa-kpi-info">
                        <span class="oa-kpi-label">Active Test Takers</span>
                        <span class="oa-kpi-val" id="kpiActiveCount">0</span>
                        <span class="oa-kpi-meta">Currently in progress</span>
                    </div>
                </div>

                <!-- KPI 2: Recent Starters (<30m) -->
                <div class="oa-kpi-card kpi-recent">
                    <div class="oa-kpi-icon">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                    <div class="oa-kpi-info">
                        <span class="oa-kpi-label">Recent Starters</span>
                        <span class="oa-kpi-val" id="kpiRecentCount">0</span>
                        <span class="oa-kpi-meta">Started in last 30 mins</span>
                    </div>
                </div>

                <!-- KPI 3: Avg Answered Progress -->
                <div class="oa-kpi-card kpi-progress">
                    <div class="oa-kpi-icon">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <div class="oa-kpi-info">
                        <span class="oa-kpi-label">Avg. Progress</span>
                        <span class="oa-kpi-val" id="kpiAvgProgress">0</span>
                        <span class="oa-kpi-meta">Answers per active test</span>
                    </div>
                </div>

                <!-- KPI 4: Leading Strand Track -->
                <div class="oa-kpi-card kpi-strand">
                    <div class="oa-kpi-icon">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div class="oa-kpi-info">
                        <span class="oa-kpi-label">Leading Track</span>
                        <span class="oa-kpi-val" id="kpiLeadingStrand">None</span>
                        <span class="oa-kpi-meta">Most active strand cohort</span>
                    </div>
                </div>
            </div>

            <!-- Unified Multi-Filter & Search Toolbar -->
            <div class="oa-toolbar-card">
                <div class="oa-toolbar-main-row">
                    <!-- Search Input Box -->
                    <div class="oa-search-wrapper">
                        <i class="fa-solid fa-magnifying-glass oa-search-icon"></i>
                        <input type="text" id="oaSearchInput" class="oa-search-input" placeholder="Search by student name, Student ID, or email..." autocomplete="off">
                        <button type="button" class="oa-search-clear-btn" id="oaSearchClearBtn" title="Clear search query">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Filters Group -->
                    <div class="oa-filters-group">
                        <!-- Status Filter -->
                        <div class="oa-filter-select-box">
                            <select id="oaStatusSelect" class="oa-filter-select">
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="all">All</option>
                            </select>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <!-- Strand Filter -->
                        <div class="oa-filter-select-box">
                            <select id="oaStrandSelect" class="oa-filter-select">
                                <option value="0">All Strands / Tracks</option>
                                <?php foreach ($strands as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars($s['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <!-- Sort Order -->
                        <div class="oa-filter-select-box">
                            <select id="oaSortSelect" class="oa-filter-select">
                                <option value="recently_started">Recently Started (Newest)</option>
                                <option value="oldest_started">Oldest Active Session</option>
                                <option value="progress_high">Progress (Highest First)</option>
                                <option value="progress_low">Progress (Lowest First)</option>
                                <option value="student_name_asc">Student Name (A &rarr; Z)</option>
                                <option value="student_name_desc">Student Name (Z &rarr; A)</option>
                            </select>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <!-- Reset All Filters -->
                        <button type="button" class="btn-oa-reset-filters" id="oaResetFiltersBtn" title="Reset all search filters">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span>Reset</span>
                        </button>
                    </div>
                </div>

                <!-- Toolbar Metadata & Results Counter -->
                <div class="oa-toolbar-meta-row">
                    <div class="oa-results-counter">
                        <i class="fa-solid fa-list-check" style="color: #38bdf8;"></i>
                        <span>Live Sessions:</span>
                        <span class="counter-pill" id="oaResultsCountPill">0 Active</span>
                    </div>
                    <div class="oa-live-update-time">
                        Last synced: <span id="oaLastSyncedTime">Just now</span>
                    </div>
                </div>
            </div>

            <!-- Elevated Real-Time Table Section -->
            <div class="oa-table-card">
                <div class="oa-table-responsive">
                    <table class="oa-data-table">
                        <thead>
                            <tr>
                                <th>Student Identity</th>
                                <th style="width: 170px;">Strand Track</th>
                                <th style="width: 260px;">Completion Progress</th>
                                <th style="width: 160px;">Started Session</th>
                                <th style="width: 130px;">Status</th>
                                <th style="width: 220px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="oaTableBody">
                            <tr>
                                <td colspan="6" class="oa-empty-state">
                                    <div class="oa-empty-radar">
                                        <i class="fa-solid fa-satellite-dish"></i>
                                    </div>
                                    <h3>Connecting to Assessment Stream...</h3>
                                    <p>Scanning active student test sessions...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar -->
                <div class="oa-pagination" id="oaPagination">
                    <!-- Populated via JavaScript -->
                </div>
            </div>

        </div><!-- /.dashboard-content -->
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<!-- ==========================================================
     IN-PAGE QUICK SNAPSHOT MODAL
     ========================================================== -->
<div class="oa-modal" id="oaSnapshotModal">
    <div class="oa-modal-overlay" id="oaSnapshotOverlay"></div>
    <div class="oa-modal-content">
        <div class="oa-modal-header">
            <div class="oa-modal-title-wrap">
                <div class="oa-modal-icon-badge">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div>
                    <h2>Assessment Progress Snapshot</h2>
                    <p>Live category breakdown and time metrics</p>
                </div>
            </div>
            <button type="button" class="oa-modal-close" id="oaCloseSnapshotBtn" title="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="oa-modal-body">
            <!-- Student Header Snapshot -->
            <div class="oa-snap-student-card">
                <div class="oa-snap-student-left">
                    <div class="oa-snap-avatar" id="snapAvatar">JD</div>
                    <div class="oa-snap-details">
                        <h3 id="snapStudentName">Student Name</h3>
                        <p>
                            <span id="snapStrandBadge" class="oa-strand-badge" style="padding: 0.15rem 0.5rem; font-size: 0.72rem;">STEM</span>
                        </p>
                    </div>
                </div>
                <div class="oa-status-badge">
                    <span class="oa-status-dot"></span>
                    <span>Active Now</span>
                </div>
            </div>

            <!-- 4-Quadrant Category Progress Grid -->
            <div class="oa-snap-categories-grid">
                <!-- Category 1: Career Interest -->
                <div class="oa-snap-cat-box cat-career">
                    <div class="oa-snap-cat-header">
                        <span class="oa-snap-cat-title"><i class="fa-solid fa-briefcase"></i> Career RIASEC</span>
                        <span class="oa-snap-cat-val" id="snapCareerVal">0 / 30</span>
                    </div>
                    <div class="oa-snap-cat-bar">
                        <div class="oa-snap-cat-fill" id="snapCareerFill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Category 2: Personality Big Five -->
                <div class="oa-snap-cat-box cat-personality">
                    <div class="oa-snap-cat-header">
                        <span class="oa-snap-cat-title"><i class="fa-solid fa-brain"></i> Personality</span>
                        <span class="oa-snap-cat-val" id="snapPersonalityVal">0 / 30</span>
                    </div>
                    <div class="oa-snap-cat-bar">
                        <div class="oa-snap-cat-fill" id="snapPersonalityFill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Category 3: Skills Competency -->
                <div class="oa-snap-cat-box cat-skills">
                    <div class="oa-snap-cat-header">
                        <span class="oa-snap-cat-title"><i class="fa-solid fa-star"></i> Skills Assessment</span>
                        <span class="oa-snap-cat-val" id="snapSkillsVal">0 / 30</span>
                    </div>
                    <div class="oa-snap-cat-bar">
                        <div class="oa-snap-cat-fill" id="snapSkillsFill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Category 4: Strand-Based Track -->
                <div class="oa-snap-cat-box cat-strand">
                    <div class="oa-snap-cat-header">
                        <span class="oa-snap-cat-title"><i class="fa-solid fa-graduation-cap"></i> Strand Specific</span>
                        <span class="oa-snap-cat-val" id="snapStrandVal">0 / 30</span>
                    </div>
                    <div class="oa-snap-cat-bar">
                        <div class="oa-snap-cat-fill" id="snapStrandFill" style="width: 0%;"></div>
                    </div>
                </div>
            </div>

        </div>

        <div class="oa-modal-footer">
            <button type="button" class="btn-oa-modal-close" id="oaDismissSnapshotBtn">Close Preview</button>
            <a href="#" id="snapViewFullBtn" class="btn-oa-modal-view-full" target="_blank">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                <span>Inspect Answers Sheet</span>
            </a>
        </div>
    </div>
</div>

<script src="admin.js"></script>
<script>
// ==================== ONGOING ASSESSMENTS REAL-TIME ENGINE ====================

document.addEventListener('DOMContentLoaded', function () {

    const TOTAL_STANDARD_QUESTIONS = 80; // 20 per category x 4

    let state = {
        search: '',
        status: 'in_progress',
        strand_id: 0,
        sort: 'recently_started',
        page: 1,
        intervalSeconds: 10,
        remainingSeconds: 10,
        isLoading: false,
        timerId: null,
        tickerId: null,
        currentAssessments: [],
        serverTime: null
    };

    // DOM Elements
    const searchInput = document.getElementById('oaSearchInput');
    const searchClearBtn = document.getElementById('oaSearchClearBtn');
    const statusSelect = document.getElementById('oaStatusSelect');
    const strandSelect = document.getElementById('oaStrandSelect');
    const sortSelect = document.getElementById('oaSortSelect');
    const resetFiltersBtn = document.getElementById('oaResetFiltersBtn');
    const intervalSelect = document.getElementById('oaIntervalSelect');
    const manualRefreshBtn = document.getElementById('oaManualRefreshBtn');
    const refreshIcon = document.getElementById('oaRefreshIcon');
    const countdownText = document.getElementById('oaCountdownText');
    const lastSyncedTime = document.getElementById('oaLastSyncedTime');
    const resultsCountPill = document.getElementById('oaResultsCountPill');
    const tableBody = document.getElementById('oaTableBody');
    const paginationContainer = document.getElementById('oaPagination');

    // KPI Elements
    const kpiActiveCount = document.getElementById('kpiActiveCount');
    const kpiRecentCount = document.getElementById('kpiRecentCount');
    const kpiAvgProgress = document.getElementById('kpiAvgProgress');
    const kpiLeadingStrand = document.getElementById('kpiLeadingStrand');

    // Modal Elements
    const snapshotModal = document.getElementById('oaSnapshotModal');
    const snapshotOverlay = document.getElementById('oaSnapshotOverlay');
    const closeSnapshotBtn = document.getElementById('oaCloseSnapshotBtn');
    const dismissSnapshotBtn = document.getElementById('oaDismissSnapshotBtn');
    const snapAvatar = document.getElementById('snapAvatar');
    const snapStudentName = document.getElementById('snapStudentName');
    const snapStrandBadge = document.getElementById('snapStrandBadge');
    const snapCareerVal = document.getElementById('snapCareerVal');
    const snapCareerFill = document.getElementById('snapCareerFill');
    const snapPersonalityVal = document.getElementById('snapPersonalityVal');
    const snapPersonalityFill = document.getElementById('snapPersonalityFill');
    const snapSkillsVal = document.getElementById('snapSkillsVal');
    const snapSkillsFill = document.getElementById('snapSkillsFill');
    const snapStrandVal = document.getElementById('snapStrandVal');
    const snapStrandFill = document.getElementById('snapStrandFill');
    const snapViewFullBtn = document.getElementById('snapViewFullBtn');

    // -- Helper: Format Initials --
    function getInitials(name) {
        if (!name) return 'ST';
        const parts = name.trim().split(' ').filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return (name.substring(0, 2)).toUpperCase();
    }

    // -- Helper: Relative Elapsed Time --
    function formatTimeAgo(dateStr) {
        if (!dateStr) return 'Just now';
        const now = state.serverTime ? new Date(state.serverTime.replace(/-/g, '/')) : new Date();
        const date = new Date(dateStr.replace(/-/g, '/'));
        const diffSec = Math.max(0, Math.floor((now - date) / 1000));
        
        if (diffSec < 60) return 'Just now';
        const diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return `${diffMin}m ago`;
        const diffHr = Math.floor(diffMin / 60);
        if (diffHr < 24) return `${diffHr}h ${diffMin % 60}m ago`;
        return `${Math.floor(diffHr / 24)}d ago`;
    }

    function formatExactTime(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(/-/g, '/'));
        return d.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true
        });
    }

    function esc(text) {
        if (!text) return '';
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    // -- Core Fetch Function --
    async function fetchOngoingData(showSpinner = false) {
        if (state.isLoading) return;
        state.isLoading = true;

        if (showSpinner) {
            refreshIcon.classList.add('fa-spin');
        }

        try {
            const fd = new FormData();
            fd.append('action', 'get_ongoing');
            fd.append('search', state.search);
            fd.append('status', state.status);
            fd.append('strand_id', state.strand_id);
            fd.append('sort', state.sort);
            fd.append('page', state.page);

            const res = await fetch('ongoing_assessments.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                state.serverTime = data.server_time || null;
                state.currentAssessments = data.assessments || [];
                renderKPIs(data.kpis);
                renderTable(data.assessments, data.total, data.page, data.total_pages);
                renderPagination(data.page, data.total_pages, data.total);
                
                // Update last synced time
                const now = new Date();
                lastSyncedTime.textContent = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
                resultsCountPill.textContent = `${data.total} Active`;
            }
        } catch (err) {
            console.error('Ongoing assessments sync error:', err);
        } finally {
            state.isLoading = false;
            refreshIcon.classList.remove('fa-spin');
        }
    }

    // -- Render KPIs --
    function renderKPIs(kpis) {
        if (!kpis) return;
        kpiActiveCount.textContent = kpis.total_active || 0;
        kpiRecentCount.textContent = kpis.recent_starters || 0;
        kpiAvgProgress.textContent = `${kpis.avg_answered || 0} / ${TOTAL_STANDARD_QUESTIONS}`;
        kpiLeadingStrand.textContent = kpis.leading_strand || 'None';
    }

    // -- Render Data Table --
    function renderTable(assessments, total, page, totalPages) {
        if (!assessments || assessments.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="6">
                        <div class="oa-empty-state">
                            <div class="oa-empty-radar">
                                <i class="fa-solid fa-hourglass-end"></i>
                            </div>
                            <h3>No Active Assessments Found</h3>
                            <p>${state.search || state.strand_id > 0 ? 'No ongoing assessments match your filter criteria.' : 'There are currently no active students taking assessments. New test sessions will appear here live in real-time.'}</p>
                            ${state.search || state.strand_id > 0 ? '<button type="button" class="btn-oa-reset-filters" onclick="document.getElementById(\'oaResetFiltersBtn\').click();"><i class="fa-solid fa-rotate-left"></i> Reset Search</button>' : ''}
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        assessments.forEach((a, idx) => {
            const answeredCount = parseInt(a.answered_count || 0, 10);
            const pct = Math.min(100, Math.round((answeredCount / TOTAL_STANDARD_QUESTIONS) * 100));
            
            // Progress color class
            let fillClass = 'starting';
            if (pct >= 75) fillClass = 'near-complete';
            else if (pct >= 25) fillClass = '';

            const careerAns = parseInt(a.career_answered || 0, 10);
            const persAns = parseInt(a.personality_answered || 0, 10);
            const skillsAns = parseInt(a.skills_answered || 0, 10);
            const strandAns = parseInt(a.strand_answered || 0, 10);

            const timeAgo = formatTimeAgo(a.created_at);
            const exactTime = formatExactTime(a.created_at);
            const initials = getInitials(a.student_name);
            const avatarHtml = a.profile_picture ? `<img src="${esc(a.profile_picture)}" alt="Avatar">` : initials;
            const strandName = a.strand_code || a.strand || 'N/A';

            html += `
                <tr data-id="${a.assessment_id}">
                    <td>
                        <div class="oa-student-cell">
                            <div class="oa-student-details">
                                <span class="oa-student-name">${esc(a.student_name)}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="oa-strand-badge">
                            <i class="fa-solid fa-graduation-cap"></i>
                            ${esc(strandName)}
                        </span>
                    </td>
                    <td>
                        <div class="oa-progress-container">
                            <div class="oa-progress-header">
                                <span class="oa-progress-count">${answeredCount} / ${TOTAL_STANDARD_QUESTIONS} answered</span>
                                <span class="oa-progress-pct">${pct}%</span>
                            </div>
                            <div class="oa-progress-track">
                                <div class="oa-progress-fill ${fillClass}" style="width: ${pct}%;"></div>
                            </div>
                            <div class="oa-category-pills">
                                <span class="oa-cat-tag ${careerAns > 0 ? 'has-answers' : ''}" title="Career RIASEC: ${careerAns}/30">C: ${careerAns}</span>
                                <span class="oa-cat-tag ${persAns > 0 ? 'has-answers' : ''}" title="Personality Big Five: ${persAns}/30">P: ${persAns}</span>
                                <span class="oa-cat-tag ${skillsAns > 0 ? 'has-answers' : ''}" title="Skills Competency: ${skillsAns}/30">S: ${skillsAns}</span>
                                <span class="oa-cat-tag ${strandAns > 0 ? 'has-answers' : ''}" title="Strand-Based: ${strandAns}/30">T: ${strandAns}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="oa-time-cell" title="Started at: ${esc(exactTime)}">
                            <span class="oa-time-ago"><i class="fa-solid fa-clock"></i> ${timeAgo}</span>
                            <span class="oa-time-exact">${exactTime}</span>
                        </div>
                    </td>
                    <td>
                        ${a.assessment_status === 'completed' ? `
                            <span class="oa-status-badge" style="background: rgba(39, 174, 96, 0.1); color: #2ecc71; border-color: rgba(46, 204, 113, 0.3);">
                                <i class="fa-solid fa-check-circle"></i>
                                <span>Completed</span>
                            </span>
                        ` : `
                            <span class="oa-status-badge">
                                <span class="oa-status-dot"></span>
                                <span>In Progress</span>
                            </span>
                        `}
                    </td>
                    <td style="text-align: center;">
                        <div class="oa-actions-cluster">
                            <button type="button" class="btn-oa-action snapshot" data-assessment-id="${a.assessment_id}" title="Quick Progress Snapshot">
                                <i class="fa-solid fa-bolt"></i>
                                <span>Snapshot</span>
                            </button>
                            <a href="admin-assessments.php?id=${a.assessment_id}" class="btn-oa-action inspect" title="View Full Answers Sheet" target="_blank">
                                <i class="fa-solid fa-eye"></i>
                                <span>Answers</span>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
        });

        tableBody.innerHTML = html;

        // Attach Snapshot modal open triggers
        tableBody.querySelectorAll('button.snapshot').forEach(btn => {
            btn.addEventListener('click', () => {
                const assessmentId = parseInt(btn.dataset.assessmentId, 10);
                const assessment = state.currentAssessments.find(item => parseInt(item.assessment_id, 10) === assessmentId);
                if (assessment) {
                    openSnapshotModal(assessment);
                }
            });
        });
    }

    function strPad(num, size) {
        let s = num + "";
        while (s.length < size) s = "0" + s;
        return s;
    }

    // -- Render Pagination --
    function renderPagination(page, totalPages, total) {
        if (totalPages <= 1) {
            paginationContainer.innerHTML = `<span class="oa-page-info">Showing all ${total} active assessment${total !== 1 ? 's' : ''}</span>`;
            return;
        }

        const perPage = 10;
        const from = (page - 1) * perPage + 1;
        const to = Math.min(page * perPage, total);

        let html = `<span class="oa-page-info">Showing ${from}-${to} of ${total} active sessions</span>`;
        html += `<div class="oa-page-controls">`;

        // Prev
        html += `<button type="button" class="oa-page-btn" ${page === 1 ? 'disabled' : ''} data-page="${page - 1}"><i class="fa-solid fa-chevron-left"></i></button>`;

        // Page buttons
        const delta = 2;
        for (let p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || (p >= page - delta && p <= page + delta)) {
                html += `<button type="button" class="oa-page-btn ${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`;
            } else if (p === page - delta - 1 || p === page + delta + 1) {
                html += `<span class="oa-page-ellipsis">&hellip;</span>`;
            }
        }

        // Next
        html += `<button type="button" class="oa-page-btn" ${page === totalPages ? 'disabled' : ''} data-page="${page + 1}"><i class="fa-solid fa-chevron-right"></i></button>`;
        html += `</div>`;

        paginationContainer.innerHTML = html;

        // Attach pagination click handlers
        paginationContainer.querySelectorAll('button.oa-page-btn[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetPage = parseInt(btn.dataset.page, 10);
                if (targetPage >= 1 && targetPage <= totalPages && targetPage !== page) {
                    state.page = targetPage;
                    fetchOngoingData(true);
                }
            });
        });
    }

    // -- Open Quick Snapshot Modal --
    function openSnapshotModal(a) {
        const initials = getInitials(a.student_name);
        if (a.profile_picture) {
            snapAvatar.innerHTML = `<img src="${esc(a.profile_picture)}" alt="Avatar">`;
        } else {
            snapAvatar.textContent = initials;
        }

        snapStudentName.textContent = a.student_name || 'Student';
        snapStrandBadge.textContent = a.strand_code || a.strand || 'N/A';

        // Categories
        const cVal = parseInt(a.career_answered || 0, 10);
        const pVal = parseInt(a.personality_answered || 0, 10);
        const sVal = parseInt(a.skills_answered || 0, 10);
        const tVal = parseInt(a.strand_answered || 0, 10);

        snapCareerVal.textContent = `${cVal} / 30`;
        snapCareerFill.style.width = `${Math.min(100, Math.round((cVal / 30) * 100))}%`;

        snapPersonalityVal.textContent = `${pVal} / 30`;
        snapPersonalityFill.style.width = `${Math.min(100, Math.round((pVal / 30) * 100))}%`;

        snapSkillsVal.textContent = `${sVal} / 30`;
        snapSkillsFill.style.width = `${Math.min(100, Math.round((sVal / 30) * 100))}%`;

        snapStrandVal.textContent = `${tVal} / 30`;
        snapStrandFill.style.width = `${Math.min(100, Math.round((tVal / 30) * 100))}%`;

        // Inspect CTA Link
        snapViewFullBtn.href = `admin-assessments.php?id=${a.assessment_id}`;

        snapshotModal.classList.add('active');
    }

    function closeSnapshotModal() {
        snapshotModal.classList.remove('active');
    }

    closeSnapshotBtn.addEventListener('click', closeSnapshotModal);
    dismissSnapshotBtn.addEventListener('click', closeSnapshotModal);
    snapshotOverlay.addEventListener('click', closeSnapshotModal);

    // -- Auto-Refresh & Ticker Management --
    function setupRefreshTimers() {
        clearInterval(state.timerId);
        clearInterval(state.tickerId);

        if (state.intervalSeconds <= 0) {
            countdownText.textContent = 'Auto-refresh Paused';
            return;
        }

        state.remainingSeconds = state.intervalSeconds;
        countdownText.textContent = `Refresh in ${state.remainingSeconds}s`;

        // Ticker (1 second interval)
        state.tickerId = setInterval(() => {
            if (state.intervalSeconds <= 0) return;
            state.remainingSeconds--;
            if (state.remainingSeconds <= 0) {
                state.remainingSeconds = state.intervalSeconds;
                fetchOngoingData(false);
            }
            countdownText.textContent = `Refresh in ${state.remainingSeconds}s`;
        }, 1000);
    }

    // -- Event Handlers --

    // Search Input with Debounce & Clear Toggle
    let searchDebounceTimer = null;
    searchInput.addEventListener('input', function () {
        const val = this.value.trim();
        searchClearBtn.classList.toggle('active', val.length > 0);
        
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            state.search = val;
            state.page = 1;
            fetchOngoingData(true);
            setupRefreshTimers();
        }, 300);
    });

    searchClearBtn.addEventListener('click', function () {
        searchInput.value = '';
        searchClearBtn.classList.remove('active');
        state.search = '';
        state.page = 1;
        fetchOngoingData(true);
        setupRefreshTimers();
        searchInput.focus();
    });

    // Keyboard shortcut '/' to search
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement !== searchInput && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            searchInput.focus();
        }
    });

    // Filter Listeners
    statusSelect.addEventListener('change', function () {
        state.status = this.value;
        state.page = 1;
        fetchOngoingData(true);
    });

    // Strand Filter Dropdown
    strandSelect.addEventListener('change', function () {
        state.strand_id = parseInt(this.value, 10);
        state.page = 1;
        fetchOngoingData(true);
        setupRefreshTimers();
    });

    // Sort Dropdown
    sortSelect.addEventListener('change', function () {
        state.sort = this.value;
        state.page = 1;
        fetchOngoingData(true);
        setupRefreshTimers();
    });

    // Reset Filters Button
    resetFiltersBtn.addEventListener('click', function () {
        searchInput.value = '';
        searchClearBtn.classList.remove('active');
        strandSelect.value = '0';
        sortSelect.value = 'recently_started';

        state.search = '';
        state.status = 'in_progress';
        state.strand_id = 0;
        state.sort = 'recently_started';
        state.page = 1;

        statusSelect.value = 'in_progress';
        strandSelect.value = '0';

        fetchOngoingData(true);
        setupRefreshTimers();
    });

    // Interval Selector Dropdown
    intervalSelect.addEventListener('change', function () {
        state.intervalSeconds = parseInt(this.value, 10);
        setupRefreshTimers();
    });

    // Manual Refresh Button
    manualRefreshBtn.addEventListener('click', function () {
        fetchOngoingData(true);
        setupRefreshTimers();
    });

    // -- Initial Run --
    fetchOngoingData(true);
    setupRefreshTimers();
});

// -- Notification Dropdown Toggle --
document.getElementById('notificationBtn')?.addEventListener('click', function (e) {
    e.stopPropagation();
    const dd = document.getElementById('notificationDropdown');
    if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
});

document.addEventListener('click', function () {
    const dd = document.getElementById('notificationDropdown');
    if (dd) dd.style.display = 'none';
});

document.getElementById('notificationDropdown')?.addEventListener('click', function (e) {
    e.stopPropagation();
});

function markAllRead(e) {
    e.preventDefault(); 
    e.stopPropagation();
    fetch('api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_all_read&caller_type=admin'
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-item.unread').forEach(el => el.classList.remove('unread'));
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
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.classList.remove('unread');
                    const badge = document.getElementById('notificationBadge');
                    if (badge) {
                        const current = parseInt(badge.textContent) || 0;
                        if (current > 1) {
                            badge.textContent = current - 1;
                        } else {
                            badge.style.display = 'none';
                            badge.textContent = '0';
                        }
                    }
                }
            });
        }
    });
});
</script>
<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
