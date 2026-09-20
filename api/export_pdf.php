<?php
/**
 * Admin PDF export endpoint.
 * GET type=reports | assessment_results
 * Optional: search (for assessment_results)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/system_config.php';
require_once dirname(__DIR__) . '/includes/export_pdf_data.php';
require_once dirname(__DIR__) . '/includes/export_pdf_render.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized. Please log in as admin.';
    exit;
}

$type = trim($_GET['type'] ?? '');
$search = trim($_GET['search'] ?? '');
$systemName = getSystemConfig('short_name') ?: APP_NAME;

switch ($type) {
    case 'reports':
        $data = export_get_reports_data($mysqli);
        $html = export_pdf_render_reports_html($data, $systemName);
        $filename = 'reports_' . date('Y-m-d_His');
        export_pdf_output($html, $filename);
        break;

    case 'assessment_results':
        $yearId = (int)($_GET['year_id'] ?? 0);
        $strandId = (int)($_GET['strand_id'] ?? 0);
        $data = export_get_assessment_results_data($mysqli, $search, $yearId, $strandId);
        $html = export_pdf_render_assessment_results_html($data, $systemName);
        $filename = 'assessment_results_' . date('Y-m-d_His');
        export_pdf_output($html, $filename);
        break;

    default:
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid export type. Use type=reports or type=assessment_results.';
        exit;
}
