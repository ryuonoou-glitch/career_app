<?php
/**
 * Notification helpers for students, admins, and counselors.
 */

/**
 * Create a bell notification for a specific student.
 *
 * @param int         $studentDbId  The student primary key (students.id)
 * @param string      $title        Short notification title
 * @param string      $message      Full notification message
 * @param string      $type         'info' | 'success' | 'warning' | 'error'
 * @param string|null $link         Optional URL the notification links to
 * @return bool True on successful insert
 */
function notify_student(int $studentDbId, string $title, string $message, string $type = 'info', ?string $link = null): bool
{
    global $mysqli;

    if ($studentDbId <= 0 || $title === '' || $message === '') {
        return false;
    }

    $userType = 'student';

    $stmt = $mysqli->prepare(
        "INSERT INTO notifications (user_id, user_type, title, message, type, link, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
    );

    if (!$stmt) {
        error_log('notify_student: prepare failed � ' . $mysqli->error);
        return false;
    }

    $stmt->bind_param('isssss', $studentDbId, $userType, $title, $message, $type, $link);
    $ok = $stmt->execute();

    if (!$ok) {
        error_log('notify_student: execute failed � ' . $stmt->error);
    }

    $stmt->close();
    return $ok;
}

/**
 * Create a bell notification for admin(s).
 * Pass null $adminId to broadcast to all admins (user_id IS NULL).
 *
 * @param string      $title
 * @param string      $message
 * @param string      $type    'info' | 'success' | 'warning' | 'error'
 * @param string|null $link
 * @param int|null    $adminId Specific admin id, or null for all admins
 * @return bool
 */
function notify_admin(string $title, string $message, string $type = 'info', ?string $link = null, ?int $adminId = null): bool
{
    global $mysqli;

    if ($title === '' || $message === '') {
        return false;
    }

    $userType = 'admin';

    $stmt = $mysqli->prepare(
        "INSERT INTO notifications (user_id, user_type, title, message, type, link, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
    );

    if (!$stmt) {
        error_log('notify_admin: prepare failed � ' . $mysqli->error);
        return false;
    }

    $stmt->bind_param('isssss', $adminId, $userType, $title, $message, $type, $link);
    $ok = $stmt->execute();

    if (!$ok) {
        error_log('notify_admin: execute failed � ' . $stmt->error);
    }

    $stmt->close();
    return $ok;
}

/**
 * Create a bell notification for a specific counselor.
 *
 * @param int         $counselorId  counselors.id
 * @param string      $title
 * @param string      $message
 * @param string      $type         'info' | 'success' | 'warning' | 'error'
 * @param string|null $link
 * @return bool
 */
function notify_counselor(int $counselorId, string $title, string $message, string $type = 'info', ?string $link = null): bool
{
    global $mysqli;

    if ($counselorId <= 0 || $title === '' || $message === '') {
        return false;
    }

    $userType = 'counselor';

    $stmt = $mysqli->prepare(
        "INSERT INTO notifications (user_id, user_type, title, message, type, link, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
    );

    if (!$stmt) {
        error_log('notify_counselor: prepare failed — ' . $mysqli->error);
        return false;
    }

    $stmt->bind_param('isssss', $counselorId, $userType, $title, $message, $type, $link);
    $ok = $stmt->execute();

    if (!$ok) {
        error_log('notify_counselor: execute failed — ' . $stmt->error);
    }

    $stmt->close();
    return $ok;
}

/**
 * Notify all active counselors.
 */
function notify_all_active_counselors(
    string $title,
    string $message,
    string $type = 'info',
    ?string $link = null
): void {
    global $mysqli;

    if ($title === '' || $message === '') {
        return;
    }

    $cStmt = $mysqli->prepare("SELECT id FROM counselors WHERE status = 'active'");
    if (!$cStmt) {
        return;
    }
    $cStmt->execute();
    $result = $cStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        notify_counselor((int) $row['id'], $title, $message, $type, $link);
    }
    $cStmt->close();
}

/**
 * Send a weekly admin summary when students complete assessments (at most once per 7 days).
 */
function maybeNotifyWeeklyAssessmentSummary(): void
{
    global $mysqli;

    $recent = $mysqli->prepare(
        "SELECT id FROM notifications
         WHERE user_type = 'admin' AND title = 'Bulk Assessment Activity'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         LIMIT 1"
    );
    if (!$recent) {
        return;
    }
    $recent->execute();
    if ($recent->get_result()->fetch_assoc()) {
        $recent->close();
        return;
    }
    $recent->close();

    $countStmt = $mysqli->prepare(
        "SELECT COUNT(DISTINCT student_id) AS cnt
         FROM student_assessments
         WHERE status = 'completed'
           AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    if (!$countStmt) {
        return;
    }
    $countStmt->execute();
    $row = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();

    $count = (int) ($row['cnt'] ?? 0);
    if ($count <= 0) {
        return;
    }

    $label = $count === 1 ? '1 student completed' : "{$count} students completed";
    notify_admin(
        'Bulk Assessment Activity',
        "{$label} their assessments this week.",
        'info',
        'admin_assessment_results.php'
    );
}
