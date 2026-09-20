<?php
session_start();
require_once 'config.php';

if (isset($_GET['role']) && $_GET['role'] === 'student') {
    $res = $mysqli->query("SELECT * FROM students WHERE status = 'active' LIMIT 1");
    if ($res && $student = $res->fetch_assoc()) {
        $_SESSION['student_id'] = $student['student_id'];
        $_SESSION['student_db_id'] = $student['id'];
        $_SESSION['first_name'] = $student['first_name'];
        $_SESSION['last_name'] = $student['last_name'];
        $_SESSION['last_activity'] = time();
        header("Location: take_assessment.php");
        exit;
    }
}

$_SESSION['user_id'] = 1;
$_SESSION['admin_id'] = 1;
$_SESSION['is_admin'] = true;
$_SESSION['staff_id'] = 1;
$_SESSION['staff_role'] = 'admin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_name'] = 'System Administrator';
$_SESSION['staff_name'] = 'System Administrator';
$_SESSION['email'] = 'admin@example.com';
$_SESSION['last_activity'] = time();
header("Location: manage_students.php");
exit;
