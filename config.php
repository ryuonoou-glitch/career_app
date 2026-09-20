<?php
// Career Guidance System - Configuration

// Prevent multiple loads
if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);
    
    // Load local .env file if present
    if (file_exists(__DIR__ . '/.env')) {
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $val) = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim($val, " \t\n\r\0\x0B\"'");
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $val;
                    }
                    if (!array_key_exists($key, $_SERVER)) {
                        $_SERVER[$key] = $val;
                    }
                    @putenv("$key=$val");
                }
            }
        }
    }

    // Database configuration (Check $_ENV first in case putenv/getenv is disabled by host)
    $db_host = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
    $db_name = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'riverview_data');
    $db_user = $_ENV['DB_USER'] ?? (getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
    $db_pass = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

    // Create database connection
    try {
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        
        if ($mysqli->connect_error) {
            die('Database connection failed: ' . $mysqli->connect_error);
        }
    } catch (Exception $e) {
        die('Database connection failed: ' . $e->getMessage());
    }

    $mysqli->set_charset('utf8mb4');

    require_once __DIR__ . '/includes/db_helpers.php';


    // ── RBAC Migration: extend admins.role ENUM to include super_admin ──────
    // Safe no-op if already applied.
    $roleCol = $mysqli->query("SHOW COLUMNS FROM admins LIKE 'role'");
    if ($roleCol && ($roleColRow = $roleCol->fetch_assoc())) {
        if (stripos($roleColRow['Type'], 'super_admin') === false) {
            $mysqli->query("ALTER TABLE admins MODIFY COLUMN role ENUM('super_admin','Admin') NOT NULL DEFAULT 'Admin'");
            // Promote the first/original admin (id=1) to super_admin
            $mysqli->query("UPDATE admins SET role = 'super_admin' WHERE id = 1");
        }
        $roleCol->free();
    }

    // Ensure counselors.status supports suspended (same options as students)
    $statusCol = $mysqli->query("SHOW COLUMNS FROM counselors LIKE 'status'");
    if ($statusCol && ($col = $statusCol->fetch_assoc()) && stripos($col['Type'], 'suspended') === false) {
        $mysqli->query("ALTER TABLE counselors MODIFY COLUMN status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'");
    }
    if ($statusCol) {
        $statusCol->free();
    }

    // Ensure counselors has middle_name column
    $middleNameCol = $mysqli->query("SHOW COLUMNS FROM counselors LIKE 'middle_name'");
    if ($middleNameCol && $middleNameCol->num_rows === 0) {
        $mysqli->query("ALTER TABLE counselors ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL AFTER first_name");
    }
    if ($middleNameCol) {
        $middleNameCol->free();
    }

    // Ensure valid_student_ids has uk_student_year unique constraint (student_id, school_year_id)
    $ukIndex = $mysqli->query("SHOW INDEX FROM valid_student_ids WHERE Key_name = 'uk_student_year'");
    if ($ukIndex && $ukIndex->num_rows === 0) {
        $mysqli->query("ALTER TABLE valid_student_ids ADD UNIQUE KEY uk_student_year (student_id, school_year_id)");
    }
    if ($ukIndex) {
        $ukIndex->free();
    }

    // Application settings
    define('APP_NAME', 'CareerPath');
    
    // Auto-detect the base URL based on the script location
    $scriptName = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptName === '\\' || $scriptName === '/') {
        $scriptName = '';
    }
    define('BASE_URL', $scriptName . '/');

    // Email configuration (loaded from environment)
    define('MAIL_ENABLED', filter_var(getenv('MAIL_ENABLED') !== false ? getenv('MAIL_ENABLED') : true, FILTER_VALIDATE_BOOLEAN));
    define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') !== false ? getenv('MAIL_FROM_EMAIL') : 'noreply@example.com');
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') !== false ? getenv('MAIL_FROM_NAME') : APP_NAME);
    define('MAIL_USE_SMTP', filter_var(getenv('MAIL_USE_SMTP') !== false ? getenv('MAIL_USE_SMTP') : true, FILTER_VALIDATE_BOOLEAN));
    define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') !== false ? getenv('MAIL_SMTP_HOST') : 'smtp.gmail.com');
    define('MAIL_SMTP_PORT', getenv('MAIL_SMTP_PORT') !== false ? (int)getenv('MAIL_SMTP_PORT') : 587);
    define('MAIL_SMTP_USER', getenv('MAIL_SMTP_USER') !== false ? getenv('MAIL_SMTP_USER') : '');
    define('MAIL_SMTP_PASS', getenv('MAIL_SMTP_PASS') !== false ? getenv('MAIL_SMTP_PASS') : '');
    define('MAIL_SMTP_SECURE', getenv('MAIL_SMTP_SECURE') !== false ? getenv('MAIL_SMTP_SECURE') : 'tls');
    
    // Timezone
    date_default_timezone_set('Asia/Manila');

    // Session timeout (seconds of inactivity before logout)
    define('SESSION_TIMEOUT', 1800);
    define('SESSION_WARNING_TIME', 1680);

    // Admin session timeout (5 hours)
    define('ADMIN_SESSION_TIMEOUT', 18000);
    define('ADMIN_SESSION_WARNING_TIME', 17880);

    function isSessionActivityEndpoint() {
        $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        return in_array($script, ['check_session.php', 'extend_session.php', 'update_activity.php'], true);
    }

    function isApiRequest() {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strpos($script, '/api/') !== false
            || stripos($accept, 'application/json') !== false
            || strtolower($xrw) === 'xmlhttprequest';
    }

    function isAuthenticatedSession() {
        return isset($_SESSION['student_id']) || isset($_SESSION['counselor_id']) || isset($_SESSION['admin_id']);
    }

    function clearSession() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    function getSessionExpiredLoginPage() {
        if (isset($_SESSION['admin_id']) && !isset($_SESSION['student_id']) && !isset($_SESSION['counselor_id'])) {
            return 'admin_login.php?session=expired';
        }
        return 'login.php?session=expired';
    }

    function enforceSessionTimeout() {
        if (!isAuthenticatedSession()) {
            return;
        }

        $now = time();
        $lastActivity = $_SESSION['last_activity'] ?? $now;
        
        $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
        $timeout = $isAdmin ? ADMIN_SESSION_TIMEOUT : SESSION_TIMEOUT;

        if (($now - $lastActivity) > $timeout) {
            clearSession();

            if (isApiRequest()) {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'session_expired' => true,
                    'message' => 'Session expired due to inactivity.',
                ]);
                exit;
            }

            redirect(getSessionExpiredLoginPage());
        }

        $_SESSION['last_activity'] = $now;
    }

    // Session start
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (!isSessionActivityEndpoint()) {
        enforceSessionTimeout();
    }

    // Automatically inject CSRF meta tag and hidden inputs into HTML output
    if (!isApiRequest() && !isSessionActivityEndpoint()) {
        ob_start(function($buffer) {
            if (stripos($buffer, '</head>') !== false) {
                $metaAndJs = '';
                
                // Inject Favicon based on system_config logo or fallback to emoji
                $faviconUrl = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎯</text></svg>";
                if (function_exists('getSystemConfig') && function_exists('base_url')) {
                    $systemLogo = getSystemConfig('logo');
                    if (!empty($systemLogo)) {
                        // Use __DIR__ because during ob_end_flush at shutdown, the cwd might change
                        $logoPath = __DIR__ . '/' . $systemLogo;
                        if (file_exists($logoPath)) {
                            $faviconUrl = base_url($systemLogo);
                        }
                    }
                }
                $faviconHtml = '<link rel="icon" href="' . $faviconUrl . '">';
                
                $buffer = str_ireplace('</head>', $metaAndJs . "\n" . $faviconHtml . "\n</head>", $buffer);
            }
            if (stripos($buffer, '<form') !== false) {
                $buffer = preg_replace_callback('/<form[^>]+method=["\']?post["\']?[^>]*>/i', function($matches) {
                    $formTag = $matches[0];
                    return $formTag;
                }, $buffer);
            }
            return $buffer;
        });
    }

    // Helper: base URL
    function base_url($path = '') {
        return BASE_URL . $path;
    }

    // Helper: redirect
    function redirect($page) {
        header("Location: " . base_url($page));
        exit();
    }

    // Check login
    function isLoggedIn() {
        return isset($_SESSION['student_id']);
    }

    /**
     * Returns true if the currently logged-in admin has the super_admin role.
     */
    function isSuperAdmin(): bool {
        return isset($_SESSION['admin_id'])
            && isset($_SESSION['admin_role'])
            && $_SESSION['admin_role'] === 'super_admin';
    }

    function preventCaching() {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Past date
        }
    }

    /**
     * Aborts the request with a 403 Forbidden response if the current admin
     * is not a super_admin. Returns JSON for API/AJAX requests; redirects
     * to settings.php for normal page requests.
     */
    function requireSuperAdmin(): void {
        preventCaching();
        if (!isset($_SESSION['admin_id']) || !isSuperAdmin()) {
            if (isApiRequest() || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
                && isset($_SERVER['HTTP_X_REQUESTED_WITH']))) {
                // AJAX / API: return JSON 403
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Forbidden: Super Admin access required.',
                ]);
                exit;
            }
            // Regular page request: redirect away
            if (!isset($_SESSION['admin_id'])) {
                header('Location: ' . BASE_URL . 'index.php');
            } else {
                header('Location: ' . BASE_URL . 'settings.php');
            }
            exit;
        }
    }

    /**
     * End session and send student to login when account is not active (self-deactivated or admin).
     */
    function invalidateInactiveStudentSession() {
        global $mysqli;

        if (!isset($_SESSION['student_id'])) {
            return;
        }

        $status = null;
        if (!empty($_SESSION['student_db_id'])) {
            $stmt = $mysqli->prepare('SELECT status FROM students WHERE id = ? LIMIT 1');
            if ($stmt) {
                $dbId = (int) $_SESSION['student_db_id'];
                $stmt->bind_param('i', $dbId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $status = $row['status'] ?? null;
            }
        } else {
            $stmt = $mysqli->prepare('SELECT status FROM students WHERE student_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $_SESSION['student_id']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $status = $row['status'] ?? null;
            }
        }

        if ($status !== 'active' && $status !== 'graduated') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            redirect('login.php?account=inactive');
        }
    }

    /**
     * End session when counselor account is inactive or suspended (admin action or self-deactivate).
     */
    function invalidateInactiveCounselorSession() {
        global $mysqli;

        if (!isset($_SESSION['counselor_id'])) {
            return;
        }

        $counselorId = (int) $_SESSION['counselor_id'];
        $stmt = $mysqli->prepare('SELECT status FROM counselors WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $counselorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (($row['status'] ?? '') !== 'active') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            redirect('login.php?account=inactive');
        }
    }

    if (isset($_SESSION['counselor_id']) && !isset($_SESSION['admin_id'])) {
        invalidateInactiveCounselorSession();
    }

    // Require login
    function requireLogin() {
        preventCaching();
        if (!isLoggedIn()) {
            redirect('index.php');
        }
        invalidateInactiveStudentSession();
    }

    // Get current student
    function getCurrentStudent() {
        global $mysqli;

        if (!isLoggedIn()) return null;

        $stmt = $mysqli->prepare("
            SELECT s.*, st.name AS strand_name, st.code AS strand_code, sy.year_label AS school_year
            FROM students s
            LEFT JOIN strands st ON s.strand_id = st.id
            LEFT JOIN school_years sy ON s.school_year_id = sy.id
            WHERE s.student_id = ?
        ");

        $stmt->bind_param("s", $_SESSION['student_id']);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    function getStudentDisplayName(?array $student): string
    {
        if (!$student) {
            return 'Student';
        }

        $nameParts = [];
        if (!empty($student['first_name'])) {
            $nameParts[] = $student['first_name'];
        }
        if (!empty($student['middle_name'])) {
            $nameParts[] = strtoupper(substr($student['middle_name'], 0, 1)) . '.';
        }
        if (!empty($student['last_name'])) {
            $nameParts[] = $student['last_name'];
        }

        $name = trim(implode(' ', $nameParts));
        if (!empty($student['suffix'])) {
            $name .= ' ' . $student['suffix'];
        }

        return htmlspecialchars($name);
    }

    function getStudentAvatarUri(?array $student, ?string $projectRoot = null): string
    {
        if ($projectRoot === null) {
            $projectRoot = __DIR__;
        }

        $firstInitial = $student && !empty($student['first_name']) ? strtoupper(substr($student['first_name'], 0, 1)) : 'S';
        $lastInitial = $student && !empty($student['last_name']) ? strtoupper(substr($student['last_name'], 0, 1)) : '';
        $studentInitials = $firstInitial . $lastInitial;

        $profilePicture = $student['profile_picture'] ?? '';
        if (!empty($profilePicture) && file_exists($projectRoot . '/' . $profilePicture)) {
            return $profilePicture . '?v=' . filemtime($projectRoot . '/' . $profilePicture);
        }

        $avatarSvg = "<svg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'>" .
            "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop stop-color='#fbbf24'/><stop offset='1' stop-color='#f59e0b'/></linearGradient></defs>" .
            "<circle cx='20' cy='20' r='20' fill='url(#g)'/> " .
            "<text x='20' y='25' text-anchor='middle' font-family='Inter,Segoe UI,Arial' font-size='16' font-weight='800' fill='#0f172a'>" . htmlspecialchars($studentInitials, ENT_QUOTES, 'UTF-8') . "</text>" .
            "</svg>";

        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($avatarSvg);
    }

    // Get database connection function
    function getDBConnection() {
        global $mysqli;
        return $mysqli;
    }
}
?>

