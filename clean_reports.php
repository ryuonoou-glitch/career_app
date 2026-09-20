<?php
$file = 'reports.php';
$content = file_get_contents($file);

// 1. Remove the modal HTML
$content = preg_replace('/<!-- ==================== STUDENT DETAILS MODAL ==================== -->.*?<script src="script.js">/s', '<script src="script.js">', $content);

// 2. Remove recRenderTable and recRenderPagination calls in recFetchRecords
$content = str_replace(
    "                    recRenderStats(data.summary_stats);\n                    recRenderTable(data.records, data.total_records, data.page, data.total_pages);\n                    recRenderPagination(data.page, data.total_pages, data.total_records);\n                    recRenderCharts(data.charts);",
    "                    recRenderStats(data.summary_stats);\n                    recRenderCharts(data.charts);",
    $content
);

// 3. Remove the functions recRenderTable, recRenderPagination, recGoTo
$content = preg_replace('/\/\/ Render Table Rows \(7 Columns\).*?\/\/ Render 2 Charts \(Auto-hide cards when no data\)/s', '// Render 2 Charts (Auto-hide cards when no data)', $content);

// 4. Remove Column Sort Listeners
$content = preg_replace('/\/\/ Column Sort Listeners.*?\/\/ Export PDF Handler/s', '// Export PDF Handler', $content);

// 5. Remove STUDENT DETAILS MODAL Javascript
$content = preg_replace('/\/\/ ==================== STUDENT DETAILS MODAL ====================.*?\/\/ Notification dropdown handlers/s', '// Notification dropdown handlers', $content);

file_put_contents($file, $content);
echo "Cleaned reports.php";
?>
