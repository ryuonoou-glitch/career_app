<?php
/**
 * HTML templates and PDF output for admin exports.
 */

function export_pdf_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function export_pdf_base_styles(): string
{
    return '
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #0f172a; }
        .meta { font-size: 10px; color: #64748b; margin-bottom: 20px; }
        h2 { font-size: 14px; margin: 20px 0 10px; color: #334155; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
        th { background: #f1f5f9; font-weight: 600; font-size: 10px; text-transform: uppercase; }
        tr:nth-child(even) td { background: #f8fafc; }
        .stats-grid { width: 100%; margin-bottom: 16px; }
        .stats-grid td { border: 1px solid #cbd5e1; padding: 12px; vertical-align: top; width: 25%; }
        .stat-label { font-size: 9px; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .stat-value { font-size: 13px; font-weight: bold; color: #0f172a; }
        .empty { text-align: center; color: #64748b; padding: 24px; font-style: italic; }
    ';
}

function export_pdf_render_reports_html(array $data, string $systemName): string
{
    $stats = $data['stats'];
    $rows = $data['cluster_distribution'];

    $distRows = '';
    if (empty($rows)) {
        $distRows = '<tr><td colspan="3" class="empty">No strand distribution data available.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $distRows .= '<tr>'
                . '<td>' . export_pdf_escape($row['name']) . '</td>'
                . '<td>' . (int)$row['count'] . '</td>'
                . '<td>' . export_pdf_escape((string)$row['percentage']) . '%</td>'
                . '</tr>';
        }
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . export_pdf_base_styles() . '</style></head><body>'
        . '<h1>Reports &amp; Analytics</h1>'
        . '<p class="meta">' . export_pdf_escape($systemName) . ' &mdash; Generated ' . date('F j, Y g:i A') . '</p>'
        . '<table class="stats-grid"><tr>'
        . '<td><div class="stat-label">Most Chosen Cluster</div><div class="stat-value">' . export_pdf_escape($stats['most_chosen_cluster']) . '</div></td>'
        . '<td><div class="stat-label">Average Score</div><div class="stat-value">' . export_pdf_escape($stats['avg_score']) . '</div></td>'
        . '<td><div class="stat-label">Total Assessments</div><div class="stat-value">' . export_pdf_escape((string)$stats['total_assessments']) . '</div></td>'
        . '<td><div class="stat-label">Top Course</div><div class="stat-value">' . export_pdf_escape($stats['most_recommended_course']) . '</div></td>'
        . '</tr></table>'
        . '<h2>Strand Distribution (Completed Assessments)</h2>'
        . '<table><thead><tr><th>Strand</th><th>Students</th><th>Share</th></tr></thead><tbody>' . $distRows . '</tbody></table>'
        . '</body></html>';
}

function export_pdf_render_assessment_results_html(array $data, string $systemName): string
{
    $results = $data['results'];
    $distribution = $data['distribution'];
    $searchNote = $data['search'] !== '' ? ' &mdash; Filter: "' . export_pdf_escape($data['search']) . '"' : '';

    $distRows = '';
    if (empty($distribution)) {
        $distRows = '<tr><td colspan="3" class="empty">No distribution data.</td></tr>';
    } else {
        foreach ($distribution as $row) {
            $distRows .= '<tr>'
                . '<td>' . export_pdf_escape($row['name']) . '</td>'
                . '<td>' . (int)$row['count'] . '</td>'
                . '<td>' . export_pdf_escape((string)$row['percentage']) . '%</td>'
                . '</tr>';
        }
    }

    $resultRows = '';
    if (empty($results)) {
        $resultRows = '<tr><td colspan="6" class="empty">No completed assessment results found.</td></tr>';
    } else {
        foreach ($results as $row) {
            $date = $row['date_completed'] ? date('M d, Y', strtotime($row['date_completed'])) : 'N/A';
            $resultRows .= '<tr>'
                . '<td>' . export_pdf_escape($row['student_name']) . '</td>'
                . '<td>' . export_pdf_escape($row['lrn']) . '</td>'
                . '<td>' . export_pdf_escape($row['strand']) . '</td>'
                . '<td>' . export_pdf_escape($row['top_category']) . '</td>'
                . '<td>' . export_pdf_escape((string)$row['score_percentage']) . '%</td>'
                . '<td>' . export_pdf_escape($date) . '</td>'
                . '</tr>';
        }
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . export_pdf_base_styles() . '</style></head><body>'
        . '<h1>Assessment Results Report</h1>'
        . '<p class="meta">' . export_pdf_escape($systemName) . ' &mdash; Generated ' . date('F j, Y g:i A')
        . $searchNote . ' &mdash; Total: ' . (int)$data['total_completed'] . '</p>'
        . '<h2>Strand Distribution</h2>'
        . '<table><thead><tr><th>Strand</th><th>Count</th><th>Share</th></tr></thead><tbody>' . $distRows . '</tbody></table>'
        . '<h2>Assessment Results</h2>'
        . '<table><thead><tr><th>Student</th><th>School ID</th><th>Strand</th><th>Top Category</th><th>Score</th><th>Completed</th></tr></thead><tbody>'
        . $resultRows . '</tbody></table>'
        . '</body></html>';
}

function export_pdf_output(string $html, string $filename): void
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF library not installed. Run: composer install (in the career_app folder), then try again.';
        exit;
    }

    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    if ($safeName === '') {
        $safeName = 'export.pdf';
    }
    if (substr(strtolower($safeName), -4) !== '.pdf') {
        $safeName .= '.pdf';
    }

    $dompdf->stream($safeName, ['Attachment' => true]);
    exit;
}
