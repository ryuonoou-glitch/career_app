<?php
// includes/auth_rbac.php
// Centralized Role-Based Access Control (RBAC) Helper for Staff Portal

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Returns whether any staff member (Admin or Counselor) is logged in.
 */
function is_staff_logged_in() {
    return get_staff_role() !== null;
}

/**
 * Returns current staff role: 'super_admin', 'admin', 'counselor', or null.
 * Seamlessly backwards-compatible with legacy session keys.
 */
function get_staff_role() {
    if (isset($_SESSION['staff_role']) && !empty($_SESSION['staff_role'])) {
        $role = strtolower($_SESSION['staff_role']);
        if ($role === 'super_admin' || $role === 'admin' || $role === 'counselor') {
            return $role;
        }
    }
    
    // Legacy session fallback
    if (isset($_SESSION['admin_id']) || isset($_SESSION['is_admin'])) {
        $role = strtolower($_SESSION['admin_role'] ?? 'admin');
        return ($role === 'super_admin') ? 'super_admin' : 'admin';
    }
    
    if (isset($_SESSION['counselor_id'])) {
        return 'counselor';
    }
    
    return null;
}

/**
 * Returns the current staff user ID.
 */
function get_staff_id() {
    if (isset($_SESSION['staff_id'])) {
        return (int)$_SESSION['staff_id'];
    }
    if (isset($_SESSION['admin_id'])) {
        return (int)$_SESSION['admin_id'];
    }
    if (isset($_SESSION['counselor_id'])) {
        return (int)$_SESSION['counselor_id'];
    }
    return 0;
}

/**
 * Returns the formatted full name of the current staff member.
 */
function get_staff_name() {
    if (!empty($_SESSION['staff_name'])) {
        return $_SESSION['staff_name'];
    }
    if (!empty($_SESSION['admin_name'])) {
        return $_SESSION['admin_name'];
    }
    if (!empty($_SESSION['counselor_name'])) {
        return $_SESSION['counselor_name'];
    }
    return 'Staff User';
}

/**
 * Convenience helper to check if current user is a Counselor.
 */
function is_counselor() {
    return get_staff_role() === 'counselor';
}

/**
 * Convenience helper to check if current user is an Admin (or Super Admin).
 */
function is_admin() {
    $role = get_staff_role();
    return $role === 'admin' || $role === 'super_admin';
}

/**
 * Evaluates whether the current staff user holds a specific permission.
 */
function has_permission($permission) {
    $role = get_staff_role();
    if (!$role) {
        return false;
    }
    
    // Super admin has unrestricted access to everything
    if ($role === 'super_admin') {
        return true;
    }
    
    $permissionsMatrix = [
        'admin' => [
            'view_dashboard',
            'manage_students',
            'view_assessment_results',
            'view_student_answers',
            'view_reports',
            'export_reports',
            'manage_questions',
            'manage_careers',
            'view_all_activity',
            'view_own_activity',
            'manage_counselors',
            'manage_system_settings'
        ],
        'counselor' => [
            'view_dashboard',
            'manage_students',
            'view_assessment_results',
            'view_student_answers',
            'view_reports',
            'export_reports',
            'manage_questions', // Full question configuration: create, edit, archive
            'view_own_activity'
        ]
    ];
    
    $allowed = $permissionsMatrix[$role] ?? [];
    return in_array($permission, $allowed, true);
}

/**
 * Enforces permission guard. If check fails, redirects to appropriate login or 403 page.
 */
function require_permission($permission) {
    if (function_exists('preventCaching')) {
        preventCaching();
    }
    
    if (!is_staff_logged_in()) {
        $loginUrl = defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php';
        header('Location: ' . $loginUrl);
        exit();
    }
    
    if (!has_permission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title><style>body{font-family:sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.box{background:#1e293b;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,0.1);text-align:center;}a{color:#38bdf8;text-decoration:none;font-weight:bold;}</style></head><body><div class="box"><h2>Access Denied</h2><p>You do not have permission to access this resource.</p><p><a href="javascript:history.back()">&larr; Go Back</a></p></div></body></html>';
        exit();
    }
}
