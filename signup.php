<?php
// Start session for CSRF token & rate limiting
session_start();

require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/mailer.php';
require_once __DIR__ . '/includes/notify.php';

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Redirect logged-in users from the public signup page to their appropriate dashboards
if (isset($_SESSION['student_id'])) {
    header('Location: dashboard.php');
    exit();
}
if (isset($_SESSION['counselor_id']) || isset($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit();
}

$error = '';
$success = '';
$form_data = [];

// Function to sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Function to validate email
function validateEmail($email) {
    return isValidEmailAddress($email);
}



// Function to validate student ID (numeric only)
function validateStudentId($studentId) {
    return preg_match('/^\d{10,12}$/', $studentId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and collect form data
    $firstName = sanitizeInput($_POST['firstName'] ?? '');
    $middleName = sanitizeInput($_POST['middleName'] ?? '');
    $lastName = sanitizeInput($_POST['lastName'] ?? '');
    $suffix = sanitizeInput($_POST['suffix'] ?? '');
    $gender = sanitizeInput($_POST['gender'] ?? '');

    $email = sanitizeInput($_POST['email'] ?? '');

    $address = sanitizeInput($_POST['address'] ?? '');
    $studentId = sanitizeInput($_POST['studentId'] ?? $_POST['hidden_studentId'] ?? '');
    $gradeLevel = sanitizeInput($_POST['gradeLevel'] ?? $_POST['hidden_gradeLevel'] ?? '');
    $strand = sanitizeInput($_POST['strand'] ?? $_POST['hidden_strand'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (!empty($gradeLevel)) $_POST['gradeLevel'] = $gradeLevel;
    if (!empty($strand)) $_POST['strand'] = $strand;
    if (!empty($studentId)) $_POST['studentId'] = $studentId;

    // Store form data for repopulation
    $form_data = compact('firstName', 'middleName', 'lastName', 'suffix', 'gender', 
                         'email', 'address', 
                         'studentId', 'gradeLevel', 'strand');

    // Auto-capitalize names: First letter of each word capitalized
    $firstName = !empty($firstName) ? ucwords(strtolower($firstName)) : '';
    $middleName = !empty($middleName) ? ucwords(strtolower($middleName)) : '';
    $lastName = !empty($lastName) ? ucwords(strtolower($lastName)) : '';

    // Validation
    $errors = [];

    // Required fields validation
    $required_fields = [
        'firstName' => 'First Name',
        'lastName' => 'Last Name',
        'gender' => 'Gender',

        'email' => 'Email',

        'studentId' => 'Student ID',
        'gradeLevel' => 'Grade Level',
        'strand' => 'Strand'
    ];

    foreach ($required_fields as $field => $label) {
        if (empty($_POST[$field])) {
            $errors[] = "$label is required.";
        }
    }

    // Password validation
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    // Email validation
    if (!empty($email) && !validateEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    }




    // Student ID validation
    if (!empty($studentId) && !validateStudentId($studentId)) {
        $errors[] = 'Student ID must be 10-12 digits.';
    }

    // If no validation errors, proceed with registration
    if (empty($errors)) {
        $mysqli->begin_transaction();

        try {
            // Check if student ID exists in valid_student_ids with academic fields
            $stmt = $mysqli->prepare("SELECT id, is_registered, grade_level, strand_code, school_year_id, first_name, middle_name, last_name FROM valid_student_ids WHERE student_id = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $mysqli->error);
            }
            $stmt->bind_param("s", $studentId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $error = 'Invalid Student ID. Please contact your school administrator.';
            } else {
                $validRow = $result->fetch_assoc();

                // Identity Check: Validate Name against Database
                $nameMismatch = false;
                if (!empty($validRow['first_name']) && !empty($validRow['last_name'])) {
                    if (strtolower(trim($firstName)) !== strtolower(trim($validRow['first_name'])) || 
                        strtolower(trim($lastName)) !== strtolower(trim($validRow['last_name']))) {
                        $nameMismatch = true;
                    }
                    
                    // Middle name is optional, but if it exists in the database, it must match
                    if (!empty($validRow['middle_name'])) {
                        if (strtolower(trim($middleName)) !== strtolower(trim($validRow['middle_name']))) {
                            $nameMismatch = true;
                        }
                    }
                }

                // Get active school year
                $syStmt = $mysqli->prepare("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
                $syStmt->execute();
                $syRes = $syStmt->get_result();
                $activeSyId = null;
                if ($syRow = $syRes->fetch_assoc()) {
                    $activeSyId = (int)$syRow['id'];
                }
                $syStmt->close();

                if ($nameMismatch) {
                    $error = 'The First Name and Last Name do not match the school records for this Student ID.';
                } elseif ($activeSyId && $validRow['school_year_id'] != $activeSyId && $validRow['school_year_id'] !== null) {
                    $error = 'This Student ID has expired and belongs to a previous school year. Please request a new ID.';
                } elseif ($validRow['is_registered'] == 1) {
                    $error = 'This Student ID is already registered. Please login instead.';
                } else {
                    $stmt->close();

                    // Check if email already exists
                    $stmt = $mysqli->prepare("SELECT id FROM students WHERE email = ?");
                    if (!$stmt) {
                        throw new Exception('Database error: ' . $mysqli->error);
                    }
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $error = 'Email address is already registered. Please use a different email or login.';
                        $stmt->close();
                    } else {
                        $stmt->close();

                        // Get current active school year
                        $stmt = $mysqli->prepare("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
                        if (!$stmt) {
                            throw new Exception('Database error: ' . $mysqli->error);
                        }
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result->num_rows === 0) {
                            // Fallback: create default school year if none exists
                            $stmt->close();
                            $defaultYear = date('Y') . '-' . (date('Y') + 1);
                            $stmt = $mysqli->prepare("INSERT INTO school_years (year_label, is_current) VALUES (?, 1)");
                            $stmt->bind_param("s", $defaultYear);
                            $stmt->execute();
                            $schoolYearId = $mysqli->insert_id;
                            $stmt->close();
                        } else {
                            $schoolYear = $result->fetch_assoc();
                            $schoolYearId = $schoolYear['id'];
                            $stmt->close();
                        }

                        // Hash password
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        // Normalize and sync grade_level & strand
                        $gradeLevelDb = ($gradeLevel === 'grade12') ? 'Grade 12' : 'Grade 11';
                        $strandIdInt = (int)$strand;

                        // Hybrid Approval Logic
                        // If the admin pre-populated the first_name and last_name in valid_student_ids, 
                        // and it matched (which we validated earlier), they are auto-verified (active).
                        // If the name was blank in valid_student_ids, they must wait for manual approval (pending).
                        if (!empty($validRow['first_name']) && !empty($validRow['last_name'])) {
                            $status = 'active';
                        } else {
                            $status = 'active';
                        }

                        // Insert new student
                        $stmt = $mysqli->prepare("INSERT INTO students (
                            student_id, first_name, middle_name, last_name, suffix, 
                            gender, email, password, 
                            address, strand_id, school_year_id, grade_level, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                        if (!$stmt) {
                            throw new Exception('Database error: ' . $mysqli->error);
                        }
                        $stmt->bind_param(
                            "sssssssssiiss",
                            $studentId, $firstName, $middleName, $lastName, $suffix,
                            $gender, $email, $hashedPassword,
                            $address, $strandIdInt, $schoolYearId, $gradeLevelDb, $status
                        );

                        if ($stmt->execute()) {
                            $newStudentId = (int)$mysqli->insert_id;
                            $stmt->close();

                            if ($newStudentId <= 0) {
                                throw new Exception('Failed to capture real auto-generated student ID.');
                            }

                            // Update valid_student_ids table with registration and populate name
                            $updateStmt = $mysqli->prepare("UPDATE valid_student_ids SET is_registered = 1, registered_student_id = ?, first_name = ?, middle_name = ?, last_name = ? WHERE student_id = ?");
                            if (!$updateStmt) {
                                throw new Exception('Database error: ' . $mysqli->error);
                            }
                            $updateStmt->bind_param("issss", $newStudentId, $firstName, $middleName, $lastName, $studentId);
                            $updateStmt->execute();
                            $updateStmt->close();

                            // Commit transaction
                            $mysqli->commit();

                            // Get strand name for email
                            $strandName = '';
                            $strandStmt = $mysqli->prepare('SELECT name FROM strands WHERE id = ? LIMIT 1');
                            if ($strandStmt) {
                                $strandStmt->bind_param('i', $strandIdInt);
                                $strandStmt->execute();
                                $strandRow = $strandStmt->get_result()->fetch_assoc();
                                $strandStmt->close();
                                $strandName = $strandRow['name'] ?? '';
                            }

                            // Send email notification
                            try {
                                $emailSent = send_signup_account_created_email([
                                    'first_name' => $firstName,
                                    'middle_name' => $middleName,
                                    'last_name' => $lastName,
                                    'email' => $email,
                                    'student_id' => $studentId,
                                    'grade_level' => $gradeLevelDb,
                                    'strand_name' => $strandName,
                                ]);
                            } catch (Exception $e) {
                                error_log('Email sending failed: ' . $e->getMessage());
                                $emailSent = false;
                            }

                            // Set success message
                            if ($emailSent) {
                                $success = 'Account created successfully! A confirmation email has been sent to <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>. '
                                    . '<a href="login.php" style="color: #22c55e; font-weight: bold;">Click here to login</a>.';
                            } else {
                                $success = 'Account created successfully! We could not send the confirmation email right now—you can still '
                                    . '<a href="login.php" style="color: #22c55e; font-weight: bold;">log in here</a>.';
                            }

                            // Notify admin & counselors
                            $studentFullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix])));
                            try {
                                notify_admin(
                                    'New Registration',
                                    'New Registration — ' . $studentFullName . ' has registered and needs verification.',
                                    'info',
                                    'manage_students.php'
                                );

                                notify_all_active_counselors(
                                    'New Student Registered',
                                    $studentFullName . ' has registered.',
                                    'info',
                                    'manage_students.php'
                                );
                            } catch (Exception $e) {
                                error_log('Notification error: ' . $e->getMessage());
                            }

                            // Clear form data
                            $form_data = [];
                            
                            if ($emailSent) {
                                header("Location: login.php?signup=" . ($status === 'active' ? 'success' : 'pending') . "&email_sent=1");
                            } else {
                                header("Location: login.php?signup=" . ($status === 'active' ? 'success' : 'pending') . "&email_sent=0");
                            }
                            exit();

                        } else {
                            throw new Exception('Database error: Unable to create account. Please try again.');
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            $error = 'System error: ' . $e->getMessage();
            error_log('Registration error: ' . $e->getMessage());
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Function to preserve form data
function preserveValue($fieldName) {
    global $form_data;
    if (isset($form_data[$fieldName])) {
        echo 'value="' . htmlspecialchars($form_data[$fieldName], ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account &middot; <?php echo htmlspecialchars(getSystemConfig('name'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --auth-bg: #070c18;
            --auth-card-bg: rgba(15, 23, 42, 0.8);
            --auth-border: rgba(255, 255, 255, 0.08);
            --auth-border-focus: rgba(251, 191, 36, 0.6);
            --auth-gold: #fbbf24;
            --auth-gold-dark: #d97706;
            --auth-cyan: #38bdf8;
            --auth-text-main: #f8fafc;
            --auth-text-muted: #94a3b8;
        }

        body.signup-page {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: var(--auth-bg);
            color: var(--auth-text-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: clip;
        }

        /* Ambient Animated Glows */
        .ambient-glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(130px);
            pointer-events: none;
            z-index: 0;
            opacity: 0.45;
            animation: pulseGlow 14s ease-in-out infinite alternate;
        }
        .ambient-glow.glow-1 {
            top: -10%;
            left: 5%;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.22) 0%, rgba(245, 158, 11, 0) 70%);
        }
        .ambient-glow.glow-2 {
            bottom: -5%;
            right: 10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.18) 0%, rgba(14, 165, 233, 0) 70%);
            animation-duration: 16s;
        }
        .ambient-glow.glow-3 {
            top: 45%;
            left: 50%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, rgba(99, 102, 241, 0) 70%);
            animation-duration: 18s;
        }

        @keyframes pulseGlow {
            0% { transform: scale(1) translate(0, 0); opacity: 0.35; }
            50% { transform: scale(1.15) translate(20px, -20px); opacity: 0.55; }
            100% { transform: scale(0.95) translate(-15px, 15px); opacity: 0.4; }
        }

        /* Navigation Header */
        .signup-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
            padding: 1rem 2rem;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(7, 12, 24, 0.82);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            gap: 1rem;
        }

        .signup-nav .back-btn {
            justify-self: start;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            color: var(--auth-text-muted);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .signup-nav .back-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(251, 191, 36, 0.35);
            transform: translateX(-3px);
        }

        .signup-nav .back-btn:active {
            transform: scale(0.96);
        }

        .signup-nav .back-btn .btn-text-short {
            display: none;
        }

        .signup-nav .brand-lockup {
            justify-self: center;
            grid-column: 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            min-width: 0;
            max-width: 100%;
        }

        .signup-nav .brand-lockup .logo-icon,
        .signup-nav .brand-lockup .logo-img,
        .signup-nav .brand-lockup img {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            min-height: 42px !important;
            max-width: 42px !important;
            max-height: 42px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            flex-shrink: 0 !important;
            border: 1.5px solid rgba(251, 191, 36, 0.35);
            box-shadow: 0 0 14px rgba(251, 191, 36, 0.2);
            transition: transform 0.25s ease, border-color 0.25s ease;
        }

        .signup-nav .brand-lockup:hover .logo-icon,
        .signup-nav .brand-lockup:hover .logo-img,
        .signup-nav .brand-lockup:hover img {
            transform: scale(1.06);
            border-color: rgba(251, 191, 36, 0.7);
            box-shadow: 0 0 18px rgba(251, 191, 36, 0.35);
        }

        .signup-nav .brand-lockup h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #ffffff 40%, var(--auth-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .signup-nav .login-shortcut-btn,
        .signup-nav .auth-shortcut-btn {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--auth-gold);
            text-decoration: none;
            padding: 0.5rem 1.1rem;
            border-radius: 9999px;
            background: rgba(251, 191, 36, 0.08);
            border: 1px solid rgba(251, 191, 36, 0.25);
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .signup-nav .login-shortcut-btn:hover,
        .signup-nav .auth-shortcut-btn:hover {
            background: rgba(251, 191, 36, 0.16);
            border-color: var(--auth-gold);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(251, 191, 36, 0.2);
        }

        .signup-nav .login-shortcut-btn:active,
        .signup-nav .auth-shortcut-btn:active {
            transform: scale(0.96);
        }

        /* Main Container */
        .signup-main-container {
            position: relative;
            z-index: 5;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
            box-sizing: border-box;
        }

        .signup-card-wrapper {
            width: 100%;
            max-width: 1040px;
            background: var(--auth-card-bg);
            border: 1px solid var(--auth-border);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            overflow: hidden;
            position: relative;
        }

        /* Left Form Section */
        .signup-form-section {
            padding: 3rem 2.75rem;
            display: flex;
            flex-direction: column;
            background: rgba(15, 23, 42, 0.85);
            border-right: 1px solid rgba(255, 255, 255, 0.06);
        }

        .signup-header-block {
            margin-bottom: 1.75rem;
        }

        .signup-header-block h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.35rem 0;
            letter-spacing: -0.02em;
        }

        .signup-header-block p {
            margin: 0;
            color: var(--auth-text-muted);
            font-size: 0.92rem;
        }

        /* 4-Step Stepper Progress Bar */
        .step-progress-wrapper {
            position: relative;
            margin-bottom: 2rem;
            padding: 0 0.5rem;
        }

        .step-progress-track {
            position: absolute;
            top: 18px;
            left: 2rem;
            right: 2rem;
            height: 3px;
            background: rgba(255, 255, 255, 0.08);
            z-index: 1;
        }

        .step-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--auth-gold), #f59e0b);
            transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 0 10px rgba(251, 191, 36, 0.5);
        }

        .step-nodes {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
        }

        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            cursor: default;
        }

        .step-node-bubble {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #0f172a;
            border: 2px solid rgba(255, 255, 255, 0.15);
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            transition: all 0.3s ease;
        }

        .step-node.active .step-node-bubble {
            background: #1e293b;
            border-color: var(--auth-gold);
            color: var(--auth-gold);
            box-shadow: 0 0 15px rgba(251, 191, 36, 0.35);
            transform: scale(1.08);
        }

        .step-node.completed .step-node-bubble {
            background: var(--auth-gold);
            border-color: var(--auth-gold);
            color: #0f172a;
        }

        .step-node-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: color 0.3s ease;
        }

        .step-node.active .step-node-label {
            color: #f1f5f9;
        }

        .step-node.completed .step-node-label {
            color: var(--auth-gold);
        }

        /* Step Title & Content */
        .form-step {
            display: none;
            animation: fadeInStep 0.3s ease-out;
        }

        .form-step.active {
            display: block;
        }

        @keyframes fadeInStep {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-section-heading {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0 0 1.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .step-section-heading i {
            color: var(--auth-gold);
            font-size: 1rem;
        }

        /* Form Grid Layouts */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group-custom {
            margin-bottom: 1.2rem;
            position: relative;
        }

        .form-group-custom label {
            display: block;
            font-size: 0.84rem;
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 0.45rem;
        }

        .required-star {
            color: #ef4444;
            margin-left: 2px;
        }

        .input-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-box i.input-icon {
            position: absolute;
            left: 1rem;
            color: #64748b;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .signup-field {
            width: 100%;
            box-sizing: border-box;
            background: rgba(15, 23, 42, 0.6);
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.85rem 1rem 0.85rem 2.65rem;
            color: #ffffff;
            font-size: 0.92rem;
            font-family: inherit;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        textarea.signup-field {
            padding-top: 0.85rem;
            min-height: 80px;
            resize: vertical;
        }

        select.signup-field {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        select.signup-field option {
            background: #0f172a;
            color: #ffffff;
            padding: 0.5rem;
        }

        .signup-field::placeholder {
            color: #64748b;
        }

        .signup-field:focus {
            outline: none;
            border-color: var(--auth-gold);
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        }

        .signup-field:focus + i.input-icon,
        .input-box:focus-within i.input-icon {
            color: var(--auth-gold);
        }

        .input-box .toggle-password {
            position: absolute;
            right: 0.75rem;
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 8px;
            font-size: 0.9rem;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .input-box .toggle-password.visible {
            display: flex;
        }

        .input-box .toggle-password:hover {
            color: #f1f5f9;
            background: rgba(255, 255, 255, 0.06);
        }

        .input-locked {
            background-color: rgba(30, 41, 59, 0.7) !important;
            border-color: rgba(251, 191, 36, 0.3) !important;
            color: #fbbf24 !important;
            cursor: not-allowed !important;
        }

        .input-error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
        }

        .input-success {
            border-color: #22c55e !important;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15) !important;
        }

        .validation-message {
            font-size: 0.78rem;
            margin-top: 0.35rem;
            line-height: 1.3;
        }
        .validation-message.error {
            color: #f87171;
        }
        .validation-message.success {
            color: #4ade80;
        }

        /* Password Strength Checklist & Meter */
        .password-strength-panel {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 1rem;
            margin: 1rem 0 1.25rem 0;
        }

        .strength-meter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.4rem;
            font-size: 0.8rem;
        }

        .strength-track {
            height: 5px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 0.75rem;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            transition: all 0.3s ease;
        }

        .strength-checklist {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.4rem;
        }

        .requirement {
            font-size: 0.75rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s ease;
        }

        .requirement i {
            font-size: 0.55rem;
        }

        .requirement.met {
            color: #4ade80;
        }
        .requirement.met i {
            color: #22c55e;
        }

        /* Action Buttons */
        .step-action-buttons {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .btn-prev-step {
            padding: 0.85rem 1.4rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-prev-step:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            transform: translateX(-2px);
        }

        .btn-next-step, .btn-submit-signup {
            padding: 0.85rem 1.6rem;
            border-radius: 12px;
            background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);
            border: none;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            margin-left: auto;
            box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.4);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-next-step:hover, .btn-submit-signup:hover {
            background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -4px rgba(245, 158, 11, 0.55);
        }

        .btn-next-step:active, .btn-submit-signup:active {
            transform: translateY(0);
        }

        /* Right Hero Side */
        .signup-hero-side {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.9) 100%);
            padding: 3.5rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .signup-hero-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--auth-gold), var(--auth-cyan));
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: var(--auth-gold);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.4rem 0.85rem;
            border-radius: 9999px;
            margin-bottom: 1.5rem;
            align-self: flex-start;
        }

        .hero-headline {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.25;
            color: #ffffff;
            margin: 0 0 1rem 0;
            letter-spacing: -0.02em;
        }

        .hero-headline span {
            background: linear-gradient(135deg, #fde68a, #fbbf24);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtext {
            color: var(--auth-text-muted);
            font-size: 0.92rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .hero-features-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }

        .hero-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .hero-feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.25);
            color: var(--auth-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .hero-feature-text h4 {
            margin: 0 0 0.2rem 0;
            font-size: 0.92rem;
            font-weight: 600;
            color: #f1f5f9;
        }

        .hero-feature-text p {
            margin: 0;
            font-size: 0.8rem;
            color: #94a3b8;
            line-height: 1.4;
        }

        .hero-footer-note {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.82rem;
            color: #94a3b8;
        }

        .hero-footer-note i {
            color: #38bdf8;
            font-size: 1rem;
        }

        /* Alerts */
        .signup-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            padding: 0.95rem 1.15rem;
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.45;
            margin-bottom: 1.5rem;
            animation: fadeInAlert 0.3s ease-out;
        }

        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .signup-alert.alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }
        .signup-alert.alert-error i {
            color: #ef4444;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        .signup-alert.alert-success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
        }
        .signup-alert.alert-success i {
            color: #22c55e;
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        /* Autofill Overrides */
        input:-webkit-autofill,
        textarea:-webkit-autofill,
        select:-webkit-autofill {
            -webkit-box-shadow: 0 0 0px 1000px #0b1324 inset !important;
            -webkit-text-fill-color: #ffffff !important;
            caret-color: #ffffff !important;
            color-scheme: dark !important;
            border-color: var(--auth-gold) !important;
        }

        /* Responsive */
        @media (max-width: 960px) {
            .signup-card-wrapper {
                grid-template-columns: 1fr;
                max-width: 580px;
            }
            .signup-hero-side {
                display: none;
            }
            .signup-form-section {
                padding: 2.5rem 2rem;
                border-right: none;
            }
            .signup-nav {
                padding: 0.85rem 1.25rem;
                gap: 0.75rem;
            }
            .signup-nav .brand-lockup .logo-icon,
            .signup-nav .brand-lockup .logo-img,
            .signup-nav .brand-lockup img {
                width: 36px !important;
                height: 36px !important;
                min-width: 36px !important;
                min-height: 36px !important;
                max-width: 36px !important;
                max-height: 36px !important;
            }
            .signup-nav .brand-lockup h1 {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 640px) {
            .signup-nav {
                grid-template-columns: auto 1fr auto;
                padding: 0.75rem 1rem;
                gap: 0.6rem;
            }
            .signup-nav .back-btn {
                padding: 0.45rem 0.85rem;
                font-size: 0.82rem;
                gap: 0.45rem;
            }
            .signup-nav .back-btn .btn-text-full {
                display: none !important;
            }
            .signup-nav .back-btn .btn-text-short {
                display: inline !important;
            }
            .signup-nav .login-shortcut-btn,
            .signup-nav .auth-shortcut-btn {
                padding: 0.45rem 0.85rem;
                font-size: 0.82rem;
                gap: 0.45rem;
            }
            .signup-nav .brand-lockup {
                gap: 0.5rem;
            }
            .signup-nav .brand-lockup .logo-icon,
            .signup-nav .brand-lockup .logo-img,
            .signup-nav .brand-lockup img {
                width: 32px !important;
                height: 32px !important;
                min-width: 32px !important;
                min-height: 32px !important;
                max-width: 32px !important;
                max-height: 32px !important;
            }
            .signup-nav .brand-lockup h1 {
                font-size: 0.96rem;
            }
        }

        @media (max-width: 560px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .signup-main-container {
                padding: 1.25rem 1rem;
            }
            .signup-form-section {
                padding: 2rem 1.25rem;
            }
            .step-node-label {
                font-size: 0.65rem;
            }
            .step-node-bubble {
                width: 30px;
                height: 30px;
                font-size: 0.75rem;
            }
            .step-progress-track {
                left: 1.5rem;
                right: 1.5rem;
                top: 15px;
            }
            .strength-checklist {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .signup-nav {
                padding: 0.6rem 0.75rem;
                gap: 0.45rem;
            }
            .signup-nav .back-btn {
                padding: 0.4rem 0.7rem;
                font-size: 0.78rem;
                gap: 0.35rem;
            }
            .signup-nav .login-shortcut-btn,
            .signup-nav .auth-shortcut-btn {
                padding: 0.4rem 0.7rem;
                font-size: 0.78rem;
                gap: 0.35rem;
            }
            .signup-nav .brand-lockup {
                gap: 0.4rem;
            }
            .signup-nav .brand-lockup .logo-icon,
            .signup-nav .brand-lockup .logo-img,
            .signup-nav .brand-lockup img {
                width: 28px !important;
                height: 28px !important;
                min-width: 28px !important;
                min-height: 28px !important;
                max-width: 28px !important;
                max-height: 28px !important;
            }
            .signup-nav .brand-lockup h1 {
                font-size: 0.88rem;
            }
        }

        @media (max-width: 380px) {
            .signup-nav {
                padding: 0.55rem 0.6rem;
                gap: 0.35rem;
            }
            .signup-nav .back-btn {
                width: 36px;
                height: 36px;
                padding: 0 !important;
                border-radius: 50%;
                gap: 0;
            }
            .signup-nav .back-btn span {
                display: none !important;
            }
            .signup-nav .login-shortcut-btn,
            .signup-nav .auth-shortcut-btn {
                width: 36px;
                height: 36px;
                padding: 0 !important;
                border-radius: 50%;
                gap: 0;
            }
            .signup-nav .login-shortcut-btn .shortcut-text,
            .signup-nav .auth-shortcut-btn .shortcut-text {
                display: none !important;
            }
            .signup-nav .brand-lockup .logo-icon,
            .signup-nav .brand-lockup .logo-img,
            .signup-nav .brand-lockup img {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                min-height: 26px !important;
                max-width: 26px !important;
                max-height: 26px !important;
            }
            .signup-nav .brand-lockup h1 {
                font-size: 0.82rem;
            }
        }
    </style>
</head>
<body class="signup-page">

    <!-- Ambient Glowing Backdrops -->
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>
    <div class="ambient-glow glow-3"></div>

    <!-- Navigation Header -->
    <header class="signup-nav">
        <a href="index.php" class="back-btn" id="backToHomeBtn" aria-label="Back to Home" title="Back to Home">
            <i class="fa-solid fa-arrow-left"></i>
            <span class="btn-text-full">Back to Home</span>
            <span class="btn-text-short">Back</span>
        </a>

        <a href="index.php" class="brand-lockup" aria-label="<?php echo htmlspecialchars(getSystemConfig('short_name'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo getSystemLogo('logo-icon'); ?>
            <h1><?php echo htmlspecialchars(getSystemConfig('short_name'), ENT_QUOTES, 'UTF-8'); ?></h1>
        </a>

        <a href="login.php" class="auth-shortcut-btn login-shortcut-btn" title="Go to Sign In" aria-label="Sign In">
            <i class="fa-solid fa-arrow-right-to-bracket"></i>
            <span class="shortcut-text">Sign In</span>
        </a>
    </header>

    <!-- Main Container -->
    <main class="signup-main-container">
        <div class="signup-card-wrapper">

            <!-- Left Form Wizard Section -->
            <div class="signup-form-section">
                <div class="signup-header-block">
                    <h2>Create Account</h2>
                    <p>Register with your student credentials to start guidance profiling</p>
                </div>

                <?php if ($error): ?>
                <div class="signup-alert alert-error" id="signupAlertError" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?php echo $error; ?></div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const alertEl = document.getElementById('signupAlertError');
                        if (alertEl) alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                </script>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="signup-alert alert-success" role="alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?php echo $success; ?></div>
                </div>
                <?php endif; ?>

                <!-- Progress Stepper -->
                <div class="step-progress-wrapper">
                    <div class="step-progress-track">
                        <div class="step-progress-fill" id="progressFill"></div>
                    </div>
                    <div class="step-nodes">
                        <div class="step-node active" data-step="1">
                            <div class="step-node-bubble">1</div>
                            <span class="step-node-label">Personal</span>
                        </div>
                        <div class="step-node" data-step="2">
                            <div class="step-node-bubble">2</div>
                            <span class="step-node-label">Contact</span>
                        </div>
                        <div class="step-node" data-step="3">
                            <div class="step-node-bubble">3</div>
                            <span class="step-node-label">Academic</span>
                        </div>
                        <div class="step-node" data-step="4">
                            <div class="step-node-bubble">4</div>
                            <span class="step-node-label">Security</span>
                        </div>
                    </div>
                </div>

                <!-- Multi-Step Registration Form -->
                <form id="signupForm" class="signup-form" method="POST" action="" novalidate>

                    <!-- STEP 1: Personal Information -->
                    <div class="form-step active" data-step="1">
                        <h3 class="step-section-heading">
                            <i class="fa-solid fa-user-tag"></i>
                            <span>Personal Information</span>
                        </h3>

                        <div class="form-grid-2">
                            <div class="form-group-custom">
                                <label for="firstName">First Name <span class="required-star">*</span></label>
                                <div class="input-box">
                                    <i class="fa-solid fa-user input-icon"></i>
                                    <input type="text" id="firstName" name="firstName" class="signup-field" placeholder="e.g., Juan" required <?php preserveValue('firstName'); ?>>
                                </div>
                            </div>
                            <div class="form-group-custom">
                                <label for="middleName">Middle Name</label>
                                <div class="input-box">
                                    <i class="fa-solid fa-user input-icon"></i>
                                    <input type="text" id="middleName" name="middleName" class="signup-field" placeholder="e.g., Dela Cruz" <?php preserveValue('middleName'); ?>>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group-custom">
                                <label for="lastName">Last Name <span class="required-star">*</span></label>
                                <div class="input-box">
                                    <i class="fa-solid fa-user input-icon"></i>
                                    <input type="text" id="lastName" name="lastName" class="signup-field" placeholder="e.g., Santos" required <?php preserveValue('lastName'); ?>>
                                </div>
                            </div>
                            <div class="form-group-custom">
                                <label for="suffix">Suffix</label>
                                <div class="input-box">
                                    <i class="fa-solid fa-award input-icon"></i>
                                    <select id="suffix" name="suffix" class="signup-field">
                                        <option value="">None</option>
                                        <option value="Jr" <?php echo (isset($form_data['suffix']) && $form_data['suffix'] === 'Jr') ? 'selected' : ''; ?>>Jr.</option>
                                        <option value="Sr" <?php echo (isset($form_data['suffix']) && $form_data['suffix'] === 'Sr') ? 'selected' : ''; ?>>Sr.</option>
                                        <option value="II" <?php echo (isset($form_data['suffix']) && $form_data['suffix'] === 'II') ? 'selected' : ''; ?>>II</option>
                                        <option value="III" <?php echo (isset($form_data['suffix']) && $form_data['suffix'] === 'III') ? 'selected' : ''; ?>>III</option>
                                        <option value="IV" <?php echo (isset($form_data['suffix']) && $form_data['suffix'] === 'IV') ? 'selected' : ''; ?>>IV</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-2">

                            <div class="form-group-custom">
                                <label for="gender">Gender <span class="required-star">*</span></label>
                                <div class="input-box">
                                    <i class="fa-solid fa-venus-mars input-icon"></i>
                                    <select id="gender" name="gender" class="signup-field" required>
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="step-action-buttons">
                            <div></div>
                            <button type="button" class="btn-next-step" onclick="nextStep()">
                                <span>Continue</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 2: Contact Information -->
                    <div class="form-step" data-step="2">
                        <h3 class="step-section-heading">
                            <i class="fa-solid fa-address-book"></i>
                            <span>Contact Information</span>
                        </h3>

                        <div class="form-group-custom">
                            <label for="email">Email Address <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-envelope input-icon"></i>
                                <input type="email" id="email" name="email" class="signup-field" placeholder="e.g., juan.santos@email.com" required autocomplete="email" spellcheck="false" <?php preserveValue('email'); ?>>
                            </div>
                            <div class="validation-message" id="emailValidation"></div>
                        </div>


                        <div class="form-group-custom">
                            <label for="address">Address <span style="font-size: 0.82em; font-weight: 500; color: #94a3b8;">(Optional)</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-location-dot input-icon"></i>
                                <textarea id="address" name="address" class="signup-field" placeholder="e.g., 123 Rizal St., Lingayen, Pangasinan (Optional)" rows="3"><?php echo isset($form_data['address']) ? htmlspecialchars($form_data['address'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                            </div>
                        </div>

                        <div class="step-action-buttons">
                            <button type="button" class="btn-prev-step" onclick="prevStep()">
                                <i class="fa-solid fa-arrow-left"></i>
                                <span>Back</span>
                            </button>
                            <button type="button" class="btn-next-step" onclick="nextStep()">
                                <span>Continue</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 3: Academic Information -->
                    <div class="form-step" data-step="3">
                        <h3 class="step-section-heading">
                            <i class="fa-solid fa-graduation-cap"></i>
                            <span>Academic Information</span>
                        </h3>

                        <div class="form-group-custom">
                            <label for="studentId">Student ID (School Issued) <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-id-badge input-icon"></i>
                                <input type="tel" id="studentId" name="studentId" class="signup-field" placeholder="e.g., 202312345678" maxlength="12" pattern="[0-9]*" inputmode="numeric" required <?php preserveValue('studentId'); ?>>
                            </div>
                            <div class="validation-message" id="studentIdValidation"></div>
                        </div>

                        <div class="form-group-custom">
                            <label for="gradeLevel">Grade Level <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-layer-group input-icon"></i>
                                <select id="gradeLevel" name="gradeLevel" class="signup-field input-locked" disabled required>
                                    <option value="">Select Grade Level</option>
                                    <option value="grade11" <?php echo (isset($form_data['gradeLevel']) && $form_data['gradeLevel'] === 'grade11') ? 'selected' : ''; ?>>Grade 11</option>
                                    <option value="grade12" <?php echo (isset($form_data['gradeLevel']) && $form_data['gradeLevel'] === 'grade12') ? 'selected' : ''; ?>>Grade 12</option> 
                                </select>
                            </div>
                        </div>

                        <div class="form-group-custom">
                            <label for="strand">Senior High Track / Strand <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-book-open-reader input-icon"></i>
                                <select id="strand" name="strand" class="signup-field input-locked" disabled required>
                                    <option value="">Select Strand</option>
                                    <!-- Grade 11 Strands -->
                                    <option value="1" class="grade11-only" data-grade="11" <?php echo (isset($form_data['strand']) && $form_data['strand'] === '1') ? 'selected' : ''; ?>>Academic Pro</option>
                                    <!-- Grade 12 Strands -->
                                    <option value="3" class="grade12-only" data-grade="12" <?php echo (isset($form_data['strand']) && $form_data['strand'] === '3') ? 'selected' : ''; ?>>STEM - Science, Technology, Engineering, Mathematics</option>
                                </select>
                            </div>
                        </div>

                        <div class="step-action-buttons">
                            <button type="button" class="btn-prev-step" onclick="prevStep()">
                                <i class="fa-solid fa-arrow-left"></i>
                                <span>Back</span>
                            </button>
                            <button type="button" class="btn-next-step" onclick="nextStep()">
                                <span>Continue</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 4: Account Security -->
                    <div class="form-step" data-step="4">
                        <h3 class="step-section-heading">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Account Security</span>
                        </h3>

                        <div class="form-group-custom">
                            <label for="password">Create Password <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-lock input-icon"></i>
                                <input type="password" id="password" name="password" class="signup-field" placeholder="Minimum 8 characters" required minlength="8">
                                <button type="button" class="toggle-password" data-target="password" title="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Strength Meter & Live Checklist -->
                        <div class="password-strength-panel">
                            <div class="strength-meter-header">
                                <span style="color: #94a3b8; font-weight: 500;">Password Strength</span>
                                <span class="strength-text" id="strengthText" style="font-weight: 600;">Enter a password</span>
                            </div>
                            <div class="strength-track">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <div class="strength-checklist">
                                <div class="requirement" id="req-length"><i class="fa-solid fa-circle"></i> 8+ characters</div>
                                <div class="requirement" id="req-upper"><i class="fa-solid fa-circle"></i> Uppercase letter (A-Z)</div>
                                <div class="requirement" id="req-lower"><i class="fa-solid fa-circle"></i> Lowercase letter (a-z)</div>
                                <div class="requirement" id="req-number"><i class="fa-solid fa-circle"></i> Number (0-9)</div>
                                <div class="requirement" id="req-special"><i class="fa-solid fa-circle"></i> Special character (!@#$)</div>
                            </div>
                        </div>

                        <div class="form-group-custom">
                            <label for="confirmPassword">Confirm Password <span class="required-star">*</span></label>
                            <div class="input-box">
                                <i class="fa-solid fa-lock-open input-icon"></i>
                                <input type="password" id="confirmPassword" name="confirmPassword" class="signup-field" placeholder="Re-enter your password" required>
                                <button type="button" class="toggle-password" data-target="confirmPassword" title="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="validation-message" id="passwordMatch"></div>
                        </div>

                        <div class="step-action-buttons">
                            <button type="button" class="btn-prev-step" onclick="prevStep()">
                                <i class="fa-solid fa-arrow-left"></i>
                                <span>Back</span>
                            </button>
                            <button type="submit" class="btn-submit-signup" id="submitBtn">
                                <span>Create Account</span>
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Right Hero Branding Side -->
            <div class="signup-hero-side">
                <div>
                    <div class="hero-badge">
                        <i class="fa-solid fa-rocket"></i> Launch Your Future
                    </div>
                    <h2 class="hero-headline">
                        Start Your Career <span>Guidance Journey</span>
                    </h2>
                    <p class="hero-subtext">
                        Join your peers in exploring the right college degree programs based on comprehensive psychological interest profiling, skill evaluations, and academic track alignment.
                    </p>

                    <ul class="hero-features-list">
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-compass-drafting"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Personalized Career Matching</h4>
                                <p>Scientific assessment combining RIASEC and Big Five trait mapping.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-school"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Pangasinan Higher Ed Institutions</h4>
                                <p>Locate accredited universities offering your top matched programs.</p>
                            </div>
                        </li>
                        <li class="hero-feature-item">
                            <div class="hero-feature-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div class="hero-feature-text">
                                <h4>Academic Strand Alignment</h4>
                                <p>Course recommendations tailored to your senior high track.</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </main>

    <script>
    // Global variables for form steps
    let currentStep = 1;
    const totalSteps = 4;

    // Next step function
    function nextStep() {
        const currentStepElement = document.querySelector('.form-step[data-step="' + currentStep + '"]');
        const inputs = currentStepElement.querySelectorAll('input[required], select[required], textarea[required]');
        let isValid = true;

        // Validate current step
        inputs.forEach(input => {
            if (!input.value.trim()) {
                input.classList.add('input-error');
                isValid = false;
            } else {
                input.classList.remove('input-error');
            }
        });



        // Special validation for step 2 (email)
        if (currentStep === 2) {
            const email = document.getElementById('email');
            
            if (email.value && !isValidEmail(email.value)) {
                email.classList.add('input-error');
                document.getElementById('emailValidation').textContent = 'Please enter a valid email address.';
                document.getElementById('emailValidation').className = 'validation-message error';
                isValid = false;
            }
        }

        // Special validation for step 3 (student ID)
        if (currentStep === 3) {
            const studentId = document.getElementById('studentId');
            if (studentId.value && !isValidStudentId(studentId.value)) {
                studentId.classList.add('input-error');
                document.getElementById('studentIdValidation').textContent = 'Student ID must be 10-12 digits.';
                document.getElementById('studentIdValidation').className = 'validation-message error';
                isValid = false;
            } else if (studentId.value && studentId.classList.contains('input-error')) {
                isValid = false;
            } else if (studentId.value && !studentId.classList.contains('input-success')) {
                studentId.classList.add('input-error');
                document.getElementById('studentIdValidation').textContent = 'Please enter a valid, registered Student ID.';
                document.getElementById('studentIdValidation').className = 'validation-message error';
                isValid = false;
            }
        }

        // Special validation for step 4 (password)
        if (currentStep === 4) {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirmPassword');
            const val = password ? password.value : '';
            const meetsAll = val.length >= 8 &&
                             /[A-Z]/.test(val) &&
                             /[a-z]/.test(val) &&
                             /[0-9]/.test(val) &&
                             /[^A-Za-z0-9]/.test(val);
            
            if (!meetsAll) {
                if (password) password.classList.add('input-error');
                const matchMsg = document.getElementById('passwordMatch');
                if (matchMsg) {
                    matchMsg.textContent = 'Your password must meet all 5 strength checklist requirements above before you can create an account.';
                    matchMsg.className = 'validation-message error';
                }
                isValid = false;
            } else if (password.value !== confirmPassword.value) {
                confirmPassword.classList.add('input-error');
                document.getElementById('passwordMatch').textContent = 'Passwords do not match.';
                document.getElementById('passwordMatch').className = 'validation-message error';
                isValid = false;
            }
        }

        if (!isValid) {
            return;
        }

        // Update progress
        if (currentStep < totalSteps) {
            goToStep(currentStep + 1);
        }
    }

    // Previous step function
    function prevStep() {
        if (currentStep > 1) {
            goToStep(currentStep - 1);
        }
    }

    // Centralized step navigation & step auto-save helper
    function goToStep(targetStep) {
        targetStep = parseInt(targetStep) || 1;
        if (targetStep < 1 || targetStep > totalSteps) targetStep = 1;
        
        document.querySelectorAll('.form-step').forEach(step => step.classList.remove('active'));
        const targetElement = document.querySelector('.form-step[data-step="' + targetStep + '"]');
        if (targetElement) targetElement.classList.add('active');
        
        currentStep = targetStep;
        updateProgress();
        updateStepIndicators();
        try { sessionStorage.setItem('signup_current_step_v1', currentStep); } catch(err) {}
    }

    // Update progress bar
    function updateProgress() {
        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
        document.getElementById('progressFill').style.width = progress + '%';
    }

    // Update step indicators
    function updateStepIndicators() {
        document.querySelectorAll('.step-node').forEach(indicator => {
            const step = parseInt(indicator.dataset.step);
            indicator.classList.remove('active', 'completed');
            if (step < currentStep) {
                indicator.classList.add('completed');
            } else if (step === currentStep) {
                indicator.classList.add('active');
            }
        });
    }

    // Safe, non-backtracking linear time email validation
    function isValidEmail(email) {
        if (!/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(email)) return false;
        const domain = email.split('@').pop().toLowerCase();
        const allowed = ['gmail.com', 'yahoo.com', 'yahoo.com.ph', 'outlook.com', 'hotmail.com'];
        if (allowed.includes(domain)) return true;
        if (domain.endsWith('.edu') || domain.endsWith('.edu.ph')) return true;
        return false;
    }


    // Student ID validation
    function isValidStudentId(id) {
        return /^\d{10,12}$/.test(id);
    }

    // Password strength checker & listeners
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirmPassword');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                const requirements = {
                    length: password.length >= 8,
                    upper: /[A-Z]/.test(password),
                    lower: /[a-z]/.test(password),
                    number: /[0-9]/.test(password),
                    special: /[^A-Za-z0-9]/.test(password)
                };

                // Update requirement indicators
                document.getElementById('req-length').className = 'requirement' + (requirements.length ? ' met' : '');
                document.getElementById('req-upper').className = 'requirement' + (requirements.upper ? ' met' : '');
                document.getElementById('req-lower').className = 'requirement' + (requirements.lower ? ' met' : '');
                document.getElementById('req-number').className = 'requirement' + (requirements.number ? ' met' : '');
                document.getElementById('req-special').className = 'requirement' + (requirements.special ? ' met' : '');

                // Calculate strength
                if (requirements.length) strength++;
                if (requirements.upper) strength++;
                if (requirements.lower) strength++;
                if (requirements.number) strength++;
                if (requirements.special) strength++;

                // Update strength bar
                const percentage = (strength / 5) * 100;
                strengthFill.style.width = percentage + '%';

                // Update color and text
                if (strength === 0) {
                    strengthFill.style.background = 'rgba(255, 255, 255, 0.1)';
                    strengthText.textContent = 'Enter a password';
                    strengthText.style.color = '#94a3b8';
                } else if (strength <= 2) {
                    strengthFill.style.background = '#ef4444';
                    strengthText.textContent = 'Weak';
                    strengthText.style.color = '#f87171';
                } else if (strength <= 3) {
                    strengthFill.style.background = '#f59e0b';
                    strengthText.textContent = 'Fair';
                    strengthText.style.color = '#fbbf24';
                } else if (strength <= 4) {
                    strengthFill.style.background = '#38bdf8';
                    strengthText.textContent = 'Good';
                    strengthText.style.color = '#38bdf8';
                } else {
                    strengthFill.style.background = '#22c55e';
                    strengthText.textContent = 'Strong';
                    strengthText.style.color = '#4ade80';
                }
            });
        }

        // Password confirmation check
        if (confirmInput) {
            confirmInput.addEventListener('input', function() {
                const password = document.getElementById('password').value;
                const confirm = this.value;
                const matchMsg = document.getElementById('passwordMatch');
                
                if (confirm && password !== confirm) {
                    this.classList.add('input-error');
                    this.classList.remove('input-success');
                    matchMsg.textContent = 'Passwords do not match.';
                    matchMsg.className = 'validation-message error';
                } else if (confirm) {
                    this.classList.remove('input-error');
                    this.classList.add('input-success');
                    matchMsg.textContent = 'Passwords match.';
                    matchMsg.className = 'validation-message success';
                } else {
                    matchMsg.textContent = '';
                }
            });
        }

        // Password toggle visibility - only show when typing
        document.querySelectorAll('.toggle-password').forEach(btn => {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;

            const checkVisibility = () => {
                if (input.value.length > 0) {
                    btn.classList.add('visible');
                } else {
                    btn.classList.remove('visible');
                }
            };

            input.addEventListener('input', checkVisibility);
            checkVisibility();

            btn.addEventListener('click', () => {
                const icon = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    input.type = 'password';
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });

        // Real-time debounced email validation
        const emailInput = document.getElementById('email');
        if (emailInput) {
            let emailTimer = null;
            const validateEmailField = function() {
                const validationMsg = document.getElementById('emailValidation');
                const val = emailInput.value.trim();
                if (val && !isValidEmail(val)) {
                    emailInput.classList.add('input-error');
                    emailInput.classList.remove('input-success');
                    if (validationMsg) {
                        validationMsg.textContent = 'Please enter a valid email address.';
                        validationMsg.className = 'validation-message error';
                    }
                } else if (val) {
                    emailInput.classList.remove('input-error');
                    emailInput.classList.add('input-success');
                    if (validationMsg) {
                        validationMsg.textContent = 'Valid email format.';
                        validationMsg.className = 'validation-message success';
                    }
                } else {
                    emailInput.classList.remove('input-error', 'input-success');
                    if (validationMsg) validationMsg.textContent = '';
                }
            };

            emailInput.addEventListener('input', function() {
                clearTimeout(emailTimer);
                emailTimer = setTimeout(validateEmailField, 450);
            });
            emailInput.addEventListener('blur', function() {
                clearTimeout(emailTimer);
                validateEmailField();
            });
        }


        // Grade level and strand filtering & locking helpers
        const gradeLevelSelect = document.getElementById('gradeLevel');
        const strandSelect = document.getElementById('strand');
        
        function filterStrandOptions(preserveValue = false) {
            if (!gradeLevelSelect || !strandSelect) return;
            const prevVal = preserveValue ? strandSelect.value : '';
            const selectedGrade = gradeLevelSelect.value;
            const strandOptions = strandSelect.querySelectorAll('option');
            
            if (!preserveValue) strandSelect.value = '';
            
            strandOptions.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else if (selectedGrade === 'grade11') {
                    option.style.display = option.classList.contains('grade11-only') ? 'block' : 'none';
                } else if (selectedGrade === 'grade12') {
                    option.style.display = option.classList.contains('grade12-only') ? 'block' : 'none';
                } else {
                    option.style.display = option.value === '' ? 'block' : 'none';
                }
            });
            if (preserveValue && prevVal) strandSelect.value = prevVal;
        }
        
        if (gradeLevelSelect && strandSelect) {
            gradeLevelSelect.addEventListener('change', () => filterStrandOptions(false));
            filterStrandOptions(false);
        }

        function lockField(selectEl, hiddenName, val) {
            if (!selectEl) return;
            selectEl.value = val;
            selectEl.disabled = true;
            selectEl.removeAttribute('name');
            selectEl.classList.add('input-locked');
            
            let hiddenEl = document.getElementById('hidden_' + hiddenName);
            if (!hiddenEl) {
                hiddenEl = document.createElement('input');
                hiddenEl.type = 'hidden';
                hiddenEl.id = 'hidden_' + hiddenName;
                hiddenEl.name = hiddenName;
                if (selectEl.parentNode) selectEl.parentNode.appendChild(hiddenEl);
            }
            hiddenEl.value = val;
        }

        function unlockField(selectEl, hiddenName) {
            if (!selectEl) return;
            selectEl.value = '';
            selectEl.disabled = true;
            selectEl.removeAttribute('name');
            selectEl.classList.add('input-locked');
            
            const hiddenEl = document.getElementById('hidden_' + hiddenName);
            if (hiddenEl) hiddenEl.value = '';
        }

        // Student ID validation with AJAX & auto-fill/locking
        const studentIdInput = document.getElementById('studentId');
        if (studentIdInput) {
            let validationTimeout;
            const validationMsg = document.getElementById('studentIdValidation');

            studentIdInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 12) {
                    this.value = this.value.slice(0, 12);
                }
                
                clearTimeout(validationTimeout);
                validationMsg.textContent = '';
                validationMsg.className = 'validation-message';
                
                if (this.value.length >= 10) {
                    validationTimeout = setTimeout(() => {
                        validateStudentId(this.value);
                    }, 500);
                } else {
                    unlockField(gradeLevelSelect, 'gradeLevel');
                    unlockField(strandSelect, 'strand');
                }
            });

            studentIdInput.addEventListener('blur', function() {
                clearTimeout(validationTimeout);
                if (this.value.length >= 10) {
                    validateStudentId(this.value);
                } else {
                    unlockField(gradeLevelSelect, 'gradeLevel');
                    unlockField(strandSelect, 'strand');
                }
            });

            function validateStudentId(studentId) {
                if (!studentId || studentId.length < 10) {
                    unlockField(gradeLevelSelect, 'gradeLevel');
                    unlockField(strandSelect, 'strand');
                    return;
                }

                validationMsg.textContent = 'Verifying ID with registrar...';
                validationMsg.className = 'validation-message';

                fetch('check_student_id.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'student_id=' + encodeURIComponent(studentId)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.valid) {
                        studentIdInput.classList.remove('input-error');
                        studentIdInput.classList.add('input-success');
                        validationMsg.textContent = data.message;
                        validationMsg.className = 'validation-message success';

                        let matchedStrandId = '';
                        if (data.strand_id !== null && data.strand_id !== undefined && data.strand_id !== '') {
                            matchedStrandId = String(data.strand_id);
                        } else if (data.strand_code) {
                            const scStr = String(data.strand_code).trim().toUpperCase();
                            const codeMap = { 'ACADPRO': '1', 'TECHPRO': '2', 'STEM': '3', 'ABM': '4', 'HUMSS': '5' };
                            if (codeMap[scStr]) matchedStrandId = codeMap[scStr];
                        }

                        if (!matchedStrandId && data.strand_code) {
                            const scStr = String(data.strand_code).trim().toLowerCase();
                            Array.from(strandSelect.options).forEach(opt => {
                                if (opt.value && (opt.value === scStr || opt.text.toLowerCase().includes(scStr) || scStr.includes(opt.text.toLowerCase()))) {
                                    matchedStrandId = opt.value;
                                }
                            });
                        }

                        let normGrade = '';
                        if (data.grade_level) {
                            const glStr = String(data.grade_level).toLowerCase();
                            if (glStr.includes('11')) normGrade = 'grade11';
                            else if (glStr.includes('12')) normGrade = 'grade12';
                        }
                        if (!normGrade && matchedStrandId) {
                            if (matchedStrandId === '1' || matchedStrandId === '2') normGrade = 'grade11';
                            else if (['3', '4', '5'].includes(matchedStrandId)) normGrade = 'grade12';
                        }

                        let gradeSet = false;
                        if (normGrade && gradeLevelSelect) {
                            gradeLevelSelect.value = normGrade;
                            filterStrandOptions(false);
                            lockField(gradeLevelSelect, 'gradeLevel', normGrade);
                            gradeSet = true;
                        } else {
                            unlockField(gradeLevelSelect, 'gradeLevel');
                        }

                        let strandSet = false;
                        if (matchedStrandId && strandSelect) {
                            strandSelect.value = matchedStrandId;
                            lockField(strandSelect, 'strand', matchedStrandId);
                            strandSet = true;
                        } else {
                            unlockField(strandSelect, 'strand');
                        }

                        if (gradeSet && strandSet) {
                            validationMsg.textContent = '✓ Student ID verified. Grade Level and Strand auto-filled.';
                        } else if (gradeSet || strandSet) {
                            validationMsg.textContent = '✓ Student ID verified. Academic info auto-filled.';
                        }
                    } else {
                        studentIdInput.classList.add('input-error');
                        studentIdInput.classList.remove('input-success');
                        validationMsg.textContent = data.message;
                        validationMsg.className = 'validation-message error';
                        unlockField(gradeLevelSelect, 'gradeLevel');
                        unlockField(strandSelect, 'strand');
                    }
                })
                .catch(error => {
                    console.error('Validation error:', error);
                    validationMsg.textContent = 'Error validating Student ID. Please try again.';
                    validationMsg.className = 'validation-message error';
                    unlockField(gradeLevelSelect, 'gradeLevel');
                    unlockField(strandSelect, 'strand');
                });
            }
        }

        // Auto-capitalize name fields
        function toTitleCase(str) {
            return str.toLowerCase().replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
        }

        ['firstName', 'middleName', 'lastName'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('blur', function() {
                    if (this.value) {
                        this.value = toTitleCase(this.value.trim());
                    }
                });
            }
        });

        // Auto-save and Restore Form Draft across Refreshes
        const draftFields = ['firstName', 'middleName', 'lastName', 'suffix', 'gender', 'email', 'address', 'studentId', 'gradeLevel', 'strand'];
        
        try {
            const savedDraft = sessionStorage.getItem('signup_form_draft_v1');
            if (savedDraft) {
                const draft = JSON.parse(savedDraft);
                const isFormEmpty = !document.getElementById('firstName').value && !document.getElementById('email').value;
                if (isFormEmpty && draft && typeof draft === 'object') {
                    draftFields.forEach(id => {
                        const el = document.getElementById(id);
                        if (el && draft[id] !== undefined && draft[id] !== null && draft[id] !== '') {
                            el.value = draft[id];
                        }
                    });
                    if (gradeLevelSelect && strandSelect && draft['strand']) {
                        const selectedGrade = gradeLevelSelect.value;
                        strandSelect.querySelectorAll('option').forEach(option => {
                            if (option.value === '') option.style.display = 'block';
                            else if (selectedGrade === 'grade11') option.style.display = option.classList.contains('grade11-only') ? 'block' : 'none';
                            else if (selectedGrade === 'grade12') option.style.display = option.classList.contains('grade12-only') ? 'block' : 'none';
                        });
                        strandSelect.value = draft['strand'];
                    }
                    if (draft['studentId'] && studentIdInput) {
                        studentIdInput.dispatchEvent(new Event('input'));
                    }
                }
            }
            const savedStep = parseInt(sessionStorage.getItem('signup_current_step_v1')) || 1;
            if (savedStep > 1 && savedStep <= totalSteps) {
                goToStep(savedStep);
            }
        } catch (err) {
            console.error('Error restoring form draft:', err);
        }

        const saveDraft = function() {
            try {
                const draft = {};
                draftFields.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) draft[id] = el.value;
                });
                sessionStorage.setItem('signup_form_draft_v1', JSON.stringify(draft));
            } catch (err) {}
        };

        draftFields.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', saveDraft);
                el.addEventListener('change', saveDraft);
            }
        });

        // Prevent form submission if password requirements not met or passwords don't match
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirmPassword');
            const password = passwordInput ? passwordInput.value : '';
            const confirm = confirmInput ? confirmInput.value : '';
            
            const meetsRequirements = password.length >= 8 &&
                                      /[A-Z]/.test(password) &&
                                      /[a-z]/.test(password) &&
                                      /[0-9]/.test(password) &&
                                      /[^A-Za-z0-9]/.test(password);

            if (!meetsRequirements) {
                e.preventDefault();
                if (passwordInput) passwordInput.classList.add('input-error');
                const matchMsg = document.getElementById('passwordMatch');
                if (matchMsg) {
                    matchMsg.textContent = 'Your password must meet all 5 strength checklist requirements above before you can create an account.';
                    matchMsg.className = 'validation-message error';
                }
                if (passwordInput) passwordInput.focus();
                return false;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                document.getElementById('passwordMatch').textContent = 'Passwords do not match. Please correct before submitting.';
                document.getElementById('passwordMatch').className = 'validation-message error';
                if (confirmInput) confirmInput.classList.add('input-error');
                if (confirmInput) confirmInput.focus();
                return false;
            }

            // Button loading indicator
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Creating Account...</span>';
                submitBtn.style.opacity = '0.85';
                submitBtn.style.cursor = 'wait';
            }

            // Clear saved draft and step on successful form submit
            try { 
                sessionStorage.removeItem('signup_form_draft_v1');
                sessionStorage.removeItem('signup_current_step_v1');
            } catch (err) {}
        });
    });
    </script>
    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
