<?php
// includes/staff_sidebar.php
// Dynamic role-based navigation sidebar for staff (Admins and Counselors)

require_once __DIR__ . '/auth_rbac.php';
require_once __DIR__ . '/../system_config.php';

$currentScript = basename($_SERVER['PHP_SELF']);
$staffRole = get_staff_role();
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <?php echo getSystemLogo('logo-icon'); ?>
            <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Close sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- 1. Dashboard -->
        <a href="admin_dashboard.php" class="nav-item <?php echo ($currentScript === 'admin_dashboard.php' || $currentScript === 'counselor_dashboard.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. Manage Students -->
        <a href="manage_students.php" class="nav-item <?php echo ($currentScript === 'manage_students.php' || $currentScript === 'counselor_students.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i>
            <span>Manage Students</span>
        </a>

        <!-- 3. Assessments Group (Shared Sequence for Both Roles) -->
        <?php 
            $assessmentPages = ['manage_questions.php', 'ongoing_assessments.php', 'admin_assessment_results.php', 'counselor_results.php', 'admin-assessments.php', 'counselor_answers.php', 'assessment_verifications.php', 'counselor_review.php'];
            $isAssessmentGroupOpen = in_array($currentScript, $assessmentPages, true);
        ?>
        <div class="nav-group">
            <button class="nav-group-toggle <?php echo $isAssessmentGroupOpen ? 'open active-group' : ''; ?>" onclick="toggleNavGroup(this)">
                <i class="fa-solid fa-clipboard-check group-icon"></i>
                <span>Assessments</span>
                <i class="fa-solid fa-chevron-down chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $isAssessmentGroupOpen ? 'open' : ''; ?>">
                <?php if (has_permission('manage_questions')): ?>
                <a href="manage_questions.php" class="nav-subitem <?php echo ($currentScript === 'manage_questions.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-circle-question"></i>
                    Manage Questions
                </a>
                <?php endif; ?>

                <?php if (has_permission('view_assessment_results')): ?>
                <a href="ongoing_assessments.php" class="nav-subitem <?php echo ($currentScript === 'ongoing_assessments.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-spinner"></i>
                    Ongoing Assessments
                </a>
                <a href="admin_assessment_results.php" class="nav-subitem <?php echo ($currentScript === 'admin_assessment_results.php' || $currentScript === 'counselor_results.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-circle-check"></i>
                    Assessment History
                </a>
                <?php endif; ?>

                <?php if (has_permission('view_student_answers')): ?>
                <a href="admin-assessments.php" class="nav-subitem <?php echo ($currentScript === 'admin-assessments.php' || $currentScript === 'counselor_answers.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-eye"></i>
                    Assessment Answers
                </a>
                <?php endif; ?>
                
                <?php if (has_permission('view_assessment_results')): ?>
                <a href="assessment_verifications.php" class="nav-subitem <?php echo ($currentScript === 'assessment_verifications.php' || $currentScript === 'counselor_review.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-check"></i>
                    Assessment Verification
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (has_permission('manage_careers') || has_permission('view_schools')): ?>
        <!-- Career Group -->
        <?php 
            $careerPages = ['manage_clusters.php', 'manage_courses.php', 'manage_schools.php', 'manage_jobs.php'];
            $isCareerGroupOpen = in_array($currentScript, $careerPages, true);
        ?>
        <div class="nav-group">
            <button class="nav-group-toggle <?php echo $isCareerGroupOpen ? 'open active-group' : ''; ?>" onclick="toggleNavGroup(this)">
                <i class="fa-solid fa-briefcase group-icon"></i>
                <span>Career Management</span>
                <i class="fa-solid fa-chevron-down chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $isCareerGroupOpen ? 'open' : ''; ?>">
                <?php if (has_permission('manage_careers')): ?>
                <a href="manage_clusters.php" class="nav-subitem <?php echo ($currentScript === 'manage_clusters.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-layer-group"></i>
                    Manage Career Clusters
                </a>
                <a href="manage_courses.php" class="nav-subitem <?php echo ($currentScript === 'manage_courses.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-open"></i>
                    Manage Courses
                </a>
                <?php endif; ?>
                <a href="manage_schools.php" class="nav-subitem <?php echo ($currentScript === 'manage_schools.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-school"></i>
                    Manage Schools
                </a>
                <?php if (has_permission('manage_careers')): ?>
                <a href="manage_jobs.php" class="nav-subitem <?php echo ($currentScript === 'manage_jobs.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-hard-hat"></i>
                    Manage Jobs
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 5. Reports -->
        <?php if (has_permission('view_reports')): ?>
        <a href="reports.php" class="nav-item <?php echo ($currentScript === 'reports.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <?php endif; ?>

        <!-- 6. Activity Logs -->
        <?php if (has_permission('view_own_activity') || has_permission('view_all_activity')): ?>
        <a href="activity_logs.php" class="nav-item <?php echo ($currentScript === 'activity_logs.php' || $currentScript === 'counselor_activity.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Activity Logs</span>
        </a>
        <?php endif; ?>

        <!-- 7. Role Profile / Settings -->
        <?php if (is_counselor()): ?>
        <a href="counselor_profile.php" class="nav-item <?php echo ($currentScript === 'counselor_profile.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('manage_system_settings')): ?>
        <a href="settings.php" class="nav-item <?php echo ($currentScript === 'settings.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>

        <div class="nav-separator"></div>

        <!-- 8. Logout -->
        <a href="<?php echo is_counselor() ? 'logout.php?role=counselor' : 'logout.php?role=admin'; ?>" class="nav-item logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>
<script>
if (typeof window.toggleNavGroup === 'undefined') {
    window.toggleNavGroup = function(btn) {
        const submenu = btn.nextElementSibling;
        const isOpen = btn.classList.contains('open');
        btn.classList.toggle('open', !isOpen);
        if (submenu) submenu.classList.toggle('open', !isOpen);
    };
}
</script>
