<?php
/**
 * Counselor notifications bell — shared include.
 * Include inside .top-bar-actions on counselor pages (requires counselor session).
 *
 * Requires: $mysqli, $_SESSION['counselor_id']
 */

$_counselorNotifId = (int) ($_SESSION['counselor_id'] ?? 0);
$_counselorNotifList = [];
$_counselorNotifUnread = 0;

if ($_counselorNotifId > 0) {
    $_counselorNotifCount = $mysqli->prepare(
        "SELECT COUNT(*) AS count FROM notifications
         WHERE user_id = ? AND user_type = 'counselor' AND is_read = 0"
    );
    $_counselorNotifCount->bind_param('i', $_counselorNotifId);
    $_counselorNotifCount->execute();
    $_counselorNotifUnread = (int) ($_counselorNotifCount->get_result()->fetch_assoc()['count'] ?? 0);
    $_counselorNotifCount->close();

    $_counselorNotifStmt = $mysqli->prepare(
        "SELECT * FROM notifications WHERE user_id = ? AND user_type = 'counselor'
         ORDER BY created_at DESC LIMIT 20"
    );
    $_counselorNotifStmt->bind_param('i', $_counselorNotifId);
    $_counselorNotifStmt->execute();
    $_counselorNotifRes = $_counselorNotifStmt->get_result();
    while ($_counselorNotifRow = $_counselorNotifRes->fetch_assoc()) {
        $_counselorNotifList[] = $_counselorNotifRow;
    }
    $_counselorNotifStmt->close();
}

$_counselorNotifTypeIcons = [
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'error'   => 'fa-circle-xmark',
    'info'    => 'fa-bell',
];
?>
<div class="notif-bell-wrap counselor-notif-bell" id="counselorNotifWrap">
    <button class="notification-btn" id="counselorNotifBtn" aria-label="Notifications" aria-expanded="false">
        <i class="fa-solid fa-bell"></i>
        <?php if ($_counselorNotifUnread > 0): ?>
            <span class="notification-badge" id="counselorNotifBadge"><?php echo $_counselorNotifUnread > 9 ? '9+' : $_counselorNotifUnread; ?></span>
        <?php else: ?>
            <span class="notification-badge" id="counselorNotifBadge" style="display:none;">0</span>
        <?php endif; ?>
    </button>

    <div class="notif-dropdown" id="counselorNotifDropdown" role="dialog" aria-label="Notifications">
        <div class="notif-dropdown-header">
            <span class="notif-dropdown-title"><i class="fa-solid fa-bell"></i> Notifications</span>
            <div style="display: flex; gap: 8px;">
                <button class="notif-mark-all-btn" id="counselorClearAllNotifs" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 12px;">Clear All</button>
                <?php if ($_counselorNotifUnread > 0): ?>
                    <button class="notif-mark-all-btn" id="counselorMarkAllRead">Mark all as read</button>
                <?php endif; ?>
            </div>
        </div>
        <ul class="notif-list" id="counselorNotifList">
            <?php if (empty($_counselorNotifList)): ?>
                <li class="notif-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <span>No notifications yet</span>
                </li>
            <?php else: ?>
                <?php foreach ($_counselorNotifList as $_cn): ?>
                    <?php
                        $_cnIcon  = $_counselorNotifTypeIcons[$_cn['type']] ?? 'fa-bell';
                        $_cnRead  = (int) $_cn['is_read'];
                        $_cnClass = $_cnRead ? 'notif-item read' : 'notif-item unread';
                        $_cnTime  = date('M j, g:i A', strtotime($_cn['created_at']));
                        $_cnLink  = !empty($_cn['link']) ? htmlspecialchars($_cn['link'], ENT_QUOTES, 'UTF-8') : '#';
                    ?>
                    <li class="<?php echo $_cnClass; ?>" data-id="<?php echo (int) $_cn['id']; ?>" data-link="<?php echo $_cnLink; ?>">
                        <span class="notif-icon notif-type-<?php echo htmlspecialchars($_cn['type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid <?php echo $_cnIcon; ?>"></i>
                        </span>
                        <div class="notif-body">
                            <p class="notif-title"><?php echo htmlspecialchars($_cn['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="notif-message"><?php echo htmlspecialchars($_cn['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <span class="notif-time"><?php echo $_cnTime; ?></span>
                        </div>
                        <?php if (!$_cnRead): ?><span class="notif-dot"></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

<style>
.counselor-notif-bell.notif-bell-wrap { position: relative; }

.counselor-notif-bell .notification-btn {
    position: relative;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.1);
    color: #e2e8f0;
    width: 40px; height: 40px;
    border-radius: 10px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    transition: background .2s, transform .2s;
}
.counselor-notif-bell .notification-btn:hover { background: rgba(245,158,11,.2); color: #f59e0b; transform: scale(1.05); }
.counselor-notif-bell .notification-btn.active { background: rgba(245,158,11,.2); color: #f59e0b; }

.counselor-notif-bell .notification-badge {
    position: absolute;
    top: -5px; right: -5px;
    background: #ef4444;
    color: #fff;
    font-size: 10px; font-weight: 700;
    min-width: 18px; height: 18px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
    border: 2px solid var(--sidebar-bg, #0f172a);
}

.counselor-notif-bell .notif-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 360px;
    background: #1e293b;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
    z-index: 9999;
    overflow: hidden;
}
.counselor-notif-bell .notif-dropdown.open { display: block; }

.counselor-notif-bell .notif-dropdown-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.counselor-notif-bell .notif-dropdown-title { font-weight: 700; font-size: 14px; color: #f1f5f9; display: flex; align-items: center; gap: 7px; }
.counselor-notif-bell .notif-dropdown-title i { color: #f59e0b; }
.counselor-notif-bell .notif-mark-all-btn {
    background: none; border: none;
    color: #f59e0b; font-size: 12px; font-weight: 600;
    cursor: pointer; padding: 4px 8px; border-radius: 6px;
}
.counselor-notif-bell .notif-mark-all-btn:hover { background: rgba(245,158,11,.15); }

.counselor-notif-bell .notif-list { list-style: none; margin: 0; padding: 0; max-height: 380px; overflow-y: auto; }
.counselor-notif-bell .notif-empty {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 40px 20px; color: #64748b; font-size: 14px;
}
.counselor-notif-bell .notif-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    cursor: pointer;
    position: relative;
}
.counselor-notif-bell .notif-item:hover { background: rgba(255,255,255,.04); }
.counselor-notif-bell .notif-item.unread { background: rgba(245,158,11,.04); }
.counselor-notif-bell .notif-icon {
    flex-shrink: 0;
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
}
.counselor-notif-bell .notif-type-success { background: rgba(34,197,94,.15); color: #22c55e; }
.counselor-notif-bell .notif-type-warning { background: rgba(251,191,36,.15); color: #fbbf24; }
.counselor-notif-bell .notif-type-error   { background: rgba(239,68,68,.15);  color: #ef4444; }
.counselor-notif-bell .notif-type-info    { background: rgba(59,130,246,.15); color: #3b82f6; }
.counselor-notif-bell .notif-body { flex: 1; min-width: 0; }
.counselor-notif-bell .notif-title { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #f1f5f9; }
.counselor-notif-bell .notif-message { margin: 0 0 4px; font-size: 12px; color: #94a3b8; line-height: 1.4; }
.counselor-notif-bell .notif-time { font-size: 11px; color: #64748b; }
.counselor-notif-bell .notif-dot {
    position: absolute; top: 14px; right: 14px;
    width: 8px; height: 8px; border-radius: 50%;
    background: #f59e0b;
}

/* Mobile responsive styles */
@media (max-width: 768px) {
    .counselor-notif-bell .notif-dropdown {
        position: fixed;
        top: 60px;
        right: 10px;
        left: 10px;
        width: auto;
        max-width: calc(100vw - 20px);
        max-height: 70vh;
    }
    .counselor-notif-bell .notif-list {
        max-height: 50vh;
    }
}
@media (max-width: 480px) {
    .counselor-notif-bell .notif-dropdown {
        top: 55px;
        right: 10px;
        left: 10px;
    }
}
</style>

<script>
(function () {
    const wrap     = document.getElementById('counselorNotifWrap');
    const btn      = document.getElementById('counselorNotifBtn');
    const dropdown = document.getElementById('counselorNotifDropdown');
    const badge    = document.getElementById('counselorNotifBadge');
    const markAll  = document.getElementById('counselorMarkAllRead');
    const list     = document.getElementById('counselorNotifList');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = dropdown.classList.toggle('open');
        btn.classList.toggle('active', open);
        btn.setAttribute('aria-expanded', open);
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) {
            dropdown.classList.remove('open');
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    list?.addEventListener('click', function (e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id   = item.dataset.id;
        const link = item.dataset.link;

        const navigate = () => {
            if (link && link !== '#') {
                window.location.href = link;
            }
        };

        if (item.classList.contains('unread')) {
            markOneRead(id, item).then(navigate).catch(navigate);
        } else {
            navigate();
        }
    });

    const clearAll = document.getElementById('counselorClearAllNotifs');
    clearAll?.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const executeClear = () => {
            fetch('api/notifications.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'clear_all', caller_type: 'counselor' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const list = document.getElementById('counselorNotifList');
                    if (list) list.innerHTML = '<li class="notif-empty"><i class="fa-regular fa-bell-slash"></i><span>No notifications.</span></li>';
                    updateBadge(0);
                    if (markAll) markAll.style.display = 'none';
                }
            });
        };
        if (typeof window.showConfirm === 'function') {
            window.showConfirm("Are you sure you want to clear all notifications?", "Clear Notifications", executeClear);
        } else if (confirm("Are you sure you want to clear all notifications?")) {
            executeClear();
        }
    });

    markAll?.addEventListener('click', function (e) {
        if (e) e.preventDefault();
        fetch('api/notifications.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'mark_all_read', caller_type: 'counselor' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('#counselorNotifList .notif-item.unread').forEach(el => {
                    el.classList.remove('unread');
                    el.classList.add('read');
                    el.querySelector('.notif-dot')?.remove();
                });
                updateBadge(0);
                markAll.style.display = 'none';
            }
        });
    });

    function markOneRead(id, item) {
        return fetch('api/notifications.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'mark_read', id: id, caller_type: 'counselor' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                item.classList.remove('unread');
                item.classList.add('read');
                item.querySelector('.notif-dot')?.remove();
                const current = parseInt(badge.textContent) || 0;
                updateBadge(Math.max(0, current - 1));
            }
            return data;
        });
    }

    function updateBadge(count) {
        if (count <= 0) {
            badge.style.display = 'none';
            badge.textContent = '0';
        } else {
            badge.style.display = 'flex';
            badge.textContent = count > 9 ? '9+' : count;
        }
    }
})();
</script>
