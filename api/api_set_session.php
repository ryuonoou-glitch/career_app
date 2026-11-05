<?php
require_once 'config.php';

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';

if ($studentId > 0) {
    $stmt = $mysqli->prepare("SELECT id, student_id FROM students WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($student = $res->fetch_assoc()) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            // Set the exact session variables your config.php expects
            $_SESSION['student_db_id'] = $student['id'];
            $_SESSION['student_id'] = $student['student_id'];
        }
        $stmt->close();
    }
}

// Redirect the WebView to the requested page
header("Location: " . $redirect);
exit;

