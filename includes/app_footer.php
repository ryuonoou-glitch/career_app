<?php
// app_footer.php - Footer for internal dashboard pages
?>
<style>
/* Ensure browser window doesn't scroll past header; scrollbar stays strictly inside main-content */
html, body {
    height: 100% !important;
    overflow: hidden !important;
}
.dashboard-container {
    height: 100vh !important;
    overflow: hidden !important;
}
.main-content {
    height: 100vh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    display: block !important;
    box-sizing: border-box !important;
    scrollbar-width: thin;
    scrollbar-color: rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6);
}
.main-content::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.main-content::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.6);
    border-radius: 10px;
}
.main-content::-webkit-scrollbar-thumb {
    background: rgba(251, 191, 36, 0.35);
    border-radius: 10px;
    transition: background 0.2s ease;
}
.main-content::-webkit-scrollbar-thumb:hover {
    background: rgba(251, 191, 36, 0.7);
}
/* Force content above footer to take full height so footer sits off-screen below the fold */
.main-content > div:not(.app-footer) {
    min-height: 95vh !important;
    box-sizing: border-box;
}
/* Fix action buttons so they stay in a clean horizontal row and never wrap or cut off ACTIONS header */
.action-buttons {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 0.4rem !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
}
.data-table th:last-child,
.data-table td:last-child {
    white-space: nowrap !important;
    text-align: center !important;
    min-width: 110px !important;
}
/* Sleek gold scrollbars on table containers */
.table-container {
    scrollbar-width: thin;
    scrollbar-color: rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6);
}
.table-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.table-container::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.6);
    border-radius: 10px;
}
.table-container::-webkit-scrollbar-thumb {
    background: rgba(251, 191, 36, 0.35);
    border-radius: 10px;
}
.table-container::-webkit-scrollbar-thumb:hover {
    background: rgba(251, 191, 36, 0.7);
}
</style>
<footer class="app-footer" style="text-align: center; padding: 2rem 1.5rem; color: #94a3b8; font-size: 0.85rem; border-top: 1px solid rgba(255, 255, 255, 0.05); margin-top: 4rem; clear: both; background: rgba(15, 23, 42, 0.4);">
    &copy; 2026 RHS Career Guidance System. All Rights Reserved.
</footer>


