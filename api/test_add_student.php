<?php
require_once 'config.php';
require_once 'system_config.php';

$_POST = [
    'action' => 'add_student',
    'firstName' => 'Test',
    'lastName' => 'User',
    'email' => 'testuser' . time() . '@example.com',
    'schoolId' => 'STU99' . time(),
    'gender' => 'Male',
    'strand' => 'STEM',
    'gradeLevel' => '11',
    'address' => 'Test Address'
];

ob_start();
// simulate session
session_start();
$_SESSION['admin_id'] = 1;

require 'manage_students.php';
$output = ob_get_clean();
echo $output;
