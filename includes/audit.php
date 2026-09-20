<?php
/**
 * Audit Log Helper Functions
 */

if (!function_exists('log_activity')) {
    /**
     * Logs an administrative action to the database.
     *
     * @param int $user_id ID of the administrator or counselor performing the action.
     * @param string $user_type Either 'admin' or 'counselor'.
     * @param string $action The action description keyword (e.g. 'add_job', 'edit_course').
     * @param string|null $target_table Table name being affected.
     * @param int|null $target_id Primary key ID of the row being affected.
     * @param string|null $description Human-readable detailed description.
     * @param string|null $old_value JSON or text payload of original state.
     * @param string|null $new_value JSON or text payload of updated state.
     * @return bool True if logged successfully, false otherwise.
     */
    function log_activity($user_id, $user_type, $action, $target_table, $target_id, $description, $old_value = null, $new_value = null) {
        global $mysqli;
        
        if (!$mysqli) {
            return false;
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $mysqli->prepare("
            INSERT INTO audit_logs (user_id, user_type, action, target_table, target_id, description, old_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            error_log("Failed to prepare statement for audit logging: " . $mysqli->error);
            return false;
        }
        
        $stmt->bind_param(
            'isssissss',
            $user_id,
            $user_type,
            $action,
            $target_table,
            $target_id,
            $description,
            $old_value,
            $new_value,
            $ip_address
        );
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}
