<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_GET['role'] ?? '';

// If role is not explicitly provided in URL, auto-detect from active session
if (empty($role)) {
    if ((isset($_SESSION['staff_role']) && $_SESSION['staff_role'] === 'counselor') || isset($_SESSION['counselor_id'])) {
        $role = 'counselor';
    } elseif ((isset($_SESSION['staff_role']) && in_array($_SESSION['staff_role'], ['admin', 'super_admin'])) || isset($_SESSION['admin_id']) || !empty($_SESSION['is_admin'])) {
        $role = 'admin';
    } elseif (isset($_SESSION['student_id'])) {
        $role = 'student';
    }
}

switch ($role) {
    case 'admin':
        unset(
            $_SESSION['admin_id'],
            $_SESSION['admin_email'],
            $_SESSION['email'],
            $_SESSION['admin_name'],
            $_SESSION['admin_role'],
            $_SESSION['is_admin'],
            $_SESSION['staff_id'],
            $_SESSION['staff_role'],
            $_SESSION['staff_name']
        );
        // Clean teardown if no student session is active
        if (!isset($_SESSION['student_id']) && !isset($_SESSION['counselor_id'])) {
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
        redirect('admin_login.php?logout=success');

    case 'counselor':
        unset(
            $_SESSION['counselor_id'],
            $_SESSION['counselor_name'],
            $_SESSION['counselor_email'],
            $_SESSION['staff_id'],
            $_SESSION['staff_role'],
            $_SESSION['staff_name'],
            $_SESSION['admin_id'],
            $_SESSION['is_admin'],
            $_SESSION['admin_role']
        );
        // Clean teardown if no other role is active
        if (!isset($_SESSION['student_id']) && !isset($_SESSION['admin_id'])) {
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
        redirect('login.php?logout=success');

    case 'student':
        unset(
            $_SESSION['student_id'],
            $_SESSION['student_db_id'],
            $_SESSION['first_name'],
            $_SESSION['last_name']
        );
        if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
        redirect('login.php?logout=success');

    default:
        // Complete fallback — destroy everything
        $isAdmin = (isset($_SESSION['staff_role']) && in_array($_SESSION['staff_role'], ['admin', 'super_admin'])) 
                   || (!empty($_SESSION['is_admin']) && empty($_SESSION['counselor_id']));
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
        if ($isAdmin) {
            redirect('admin_login.php?logout=success');
        } else {
            redirect('login.php?logout=success');
        }
}
?>
