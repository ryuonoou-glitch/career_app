<?php
// System Configuration - Shared across all pages (Database Version)
require_once __DIR__ . '/config.php';

$systemConfigDefaults = [
    'name'        => '',
    'short_name'  => '',
    'school_name' => '',
    'email'       => '',
    'contact'     => '',
    'address'     => '',
    'logo'        => null,
    'school_year' => '',
    'school_years' => []
];

// Load from database if available
$systemConfigFromDB = [];

// Check if we have database connection
if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_error) {
    $result = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            // Try to decode JSON for complex values
            $decoded = json_decode($value, true);
            $systemConfigFromDB[$key] = ($decoded !== null && json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
        }
    }
}

// Use database config directly, fallback to defaults
$systemConfig = array_replace_recursive($systemConfigDefaults, $systemConfigFromDB);

// Helper function to get config values
function getSystemConfig($key) {
    global $systemConfig;
    return $systemConfig[$key] ?? '';
}

function setSystemConfig($updates) {
    if (!is_array($updates)) {
        return false;
    }

    global $systemConfig, $mysqli;
    
    // Update local config
    $systemConfig = array_replace_recursive($systemConfig, $updates);
    
    // Save to database if connection available
    if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_error) {
        $stmt = $mysqli->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
        
        foreach ($updates as $key => $value) {
            // Serialize arrays/objects as JSON
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
        $stmt->close();
    }
    
    // JSON file backup logic has been removed as the DB is the source of truth.
    
    return true;
}

// Helper function to get logo HTML
function getSystemLogo($class = '') {
    $logo = getSystemConfig('logo');
    $classStr = $class ? " class=\"logo-img {$class}\"" : ' class="logo-img"';
    
    if (!empty($logo) && file_exists($logo)) {
        // If logo file exists, display it as an image
        return '<img src="' . htmlspecialchars($logo) . '" alt="Logo"' . $classStr . '>';
    } else {
        // Fallback to emoji icon in circular container
        return '<span' . $classStr . '>🎯</span>';
    }
}

// Helper function to get the active current school_year_id from database
function getCurrentSchoolYearId($db = null) {
    global $mysqli;
    $dbConnection = $db ?? $mysqli;
    if (isset($dbConnection) && $dbConnection instanceof mysqli && !$dbConnection->connect_error) {
        $yearResult = $dbConnection->query("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1");
        if ($yearResult && $yearResult->num_rows > 0) {
            $row = $yearResult->fetch_assoc();
            if (!empty($row['id'])) {
                return (int)$row['id'];
            }
        }
    }
    return 1; // Fallback default
}
?>
