<?php
// Notification System Include for Admin Pages
// Include this file after database connection to enable notifications

// Get admin name
$userName = $_SESSION['admin_name'] ?? 'Admin User';

// Get notifications for current admin
$notifications = [];
$unreadCount = 0;
$adminId = $_SESSION['admin_id'] ?? null;

if ($adminId && isset($conn)) {
    // Get unread count
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
    $countStmt->bind_param('i', $adminId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $unreadCount = $countResult->fetch_assoc()['count'] ?? 0;
    $countStmt->close();
    
    // Get recent notifications (last 10)
    $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
    $notifStmt->bind_param('i', $adminId);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result();
    while ($row = $notifResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $notifStmt->close();
} elseif ($adminId && isset($mysqli)) {
    // Alternative for mysqli variable name
    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
    $countStmt->bind_param('i', $adminId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $unreadCount = $countResult->fetch_assoc()['count'] ?? 0;
    $countStmt->close();
    
    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
    $notifStmt->bind_param('i', $adminId);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result();
    while ($row = $notifResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $notifStmt->close();
}
?>

<!-- Top Bar Actions with Notification Bell -->
<div class="top-bar-actions">
    <div class="notification-wrapper">
        <button class="notification-btn" id="notificationBtn">
            <i class="fa-solid fa-bell"></i>
            <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
        </button>
        <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                                        <div class="notification-header">
                                <h4>Notifications</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <a href="#" class="clear-all-notifs" onclick="clearAllNotifications(event)" style="color: #ef4444; font-size: 0.8rem; text-decoration: none;">Clear All</a>
                                    <?php if ($unreadCount > 0): ?>
                                    <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                                    <?php endif; ?>
                                </div>
                            </div>
            <div class="notification-list">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                        <div class="notification-icon <?php echo $notif['type']; ?>">
                            <i class="fa-solid <?php echo $notif['type'] === 'success' ? 'fa-check-circle' : ($notif['type'] === 'warning' ? 'fa-exclamation-triangle' : ($notif['type'] === 'error' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                        </div>
                        <div class="notification-content">
                            <p class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                            <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                            <span class="notification-time"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-notifications">
                        <i class="fa-solid fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="user-profile">
        <div class="user-avatar">
            <i class="fa-solid fa-user-shield"></i>
        </div>
        <div class="user-dropdown">
            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
        </div>
    </div>
</div>

<!-- Notification JavaScript -->
<script>
    // Notification dropdown toggle
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
        document.getElementById('notificationDropdown').style.display = 'none';
    });

    // Prevent dropdown close when clicking inside
    document.getElementById('notificationDropdown').addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Mark all notifications as read
    function markAllRead(e) {
        e.preventDefault();
        e.stopPropagation();
        
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read&caller_type=<?php echo (function_exists('is_counselor') && is_counselor()) ? 'counselor' : 'admin'; ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                const badge = document.getElementById('notificationBadge');
                badge.textContent = '0';
                badge.style.display = 'none';
                document.querySelector('.mark-all-read')?.remove();
            }
        });
    }

    // Mark single notification as read on click
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            if (this.classList.contains('unread')) {
                const notifId = this.dataset.id;
                
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_read&id=' + notifId + '&caller_type=<?php echo (function_exists('is_counselor') && is_counselor()) ? 'counselor' : 'admin'; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.classList.remove('unread');
                        const badge = document.getElementById('notificationBadge');
                        const currentCount = parseInt(badge.textContent);
                        if (currentCount > 0) {
                            const newCount = currentCount - 1;
                            badge.textContent = newCount;
                            if (newCount === 0) {
                                badge.style.display = 'none';
                            }
                        }
                    }
                });
            }
        });
    });
</script>
