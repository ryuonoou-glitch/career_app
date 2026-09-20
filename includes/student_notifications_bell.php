<?php
/**
 * Student notifications bell — shared include.
 * Include this inside the .top-bar-actions div on any student page.
 *
 * Requires: $mysqli, $_SESSION['student_id'] or $_SESSION['student_db_id']
 */

// Resolve student DB id
$_notif_studentDbId = (int) ($_SESSION['student_db_id'] ?? 0);
if ($_notif_studentDbId <= 0 && isset($_SESSION['student_id'])) {
    $_notif_stmt = $mysqli->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
    $_notif_stmt->bind_param('s', $_SESSION['student_id']);
    $_notif_stmt->execute();
    $_notif_row = $_notif_stmt->get_result()->fetch_assoc();
    $_notif_stmt->close();
    $_notif_studentDbId = (int) ($_notif_row['id'] ?? 0);
}

$_notif_list  = [];
$_notif_unread = 0;

if ($_notif_studentDbId > 0) {
    $_notif_s = $mysqli->prepare(
        "SELECT * FROM notifications WHERE user_id = ? AND user_type = 'student'
         ORDER BY created_at DESC LIMIT 20"
    );
    $_notif_s->bind_param('i', $_notif_studentDbId);
    $_notif_s->execute();
    $_notif_res = $_notif_s->get_result();
    while ($_notif_r = $_notif_res->fetch_assoc()) {
        $_notif_list[] = $_notif_r;
        if (!(int)$_notif_r['is_read']) $_notif_unread++;
    }
    $_notif_s->close();
}

$_notif_typeIcons = [
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'error'   => 'fa-circle-xmark',
    'info'    => 'fa-bell',
];
?>
<!-- ── Student Notification Bell ─────────────────────────────────── -->
<div class="notif-bell-wrap" id="studentNotifWrap">
    <button class="notification-btn" id="studentNotifBtn" aria-label="Notifications" aria-expanded="false">
        <i class="fa-solid fa-bell"></i>
        <?php if ($_notif_unread > 0): ?>
            <span class="notification-badge" id="studentNotifBadge"><?php echo $_notif_unread > 9 ? '9+' : $_notif_unread; ?></span>
        <?php else: ?>
            <span class="notification-badge" id="studentNotifBadge" style="display:none;">0</span>
        <?php endif; ?>
    </button>

    <!-- Dropdown -->
    <div class="notif-dropdown" id="studentNotifDropdown" role="dialog" aria-label="Notifications">
        <div class="notif-dropdown-header">
            <span class="notif-dropdown-title"><i class="fa-solid fa-bell"></i> Notifications</span>
            <div style="display: flex; gap: 8px;">
                <button class="notif-mark-all-btn" id="studentClearAllNotifs" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 12px;">Clear All</button>
                <?php if ($_notif_unread > 0): ?>
                    <button class="notif-mark-all-btn" id="studentMarkAllRead">Mark all as read</button>
                <?php endif; ?>
            </div>
        </div>
        <ul class="notif-list" id="studentNotifList">
            <?php if (empty($_notif_list)): ?>
                <li class="notif-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <span>No notifications yet</span>
                </li>
            <?php else: ?>
                <?php foreach ($_notif_list as $_n): ?>
                    <?php
                        $_n_icon  = $_notif_typeIcons[$_n['type']] ?? 'fa-bell';
                        $_n_read  = (int)$_n['is_read'];
                        $_n_class = $_n_read ? 'notif-item read' : 'notif-item unread';
                        $_n_time  = date('M j, g:i A', strtotime($_n['created_at']));
                        $_n_link  = !empty($_n['link']) ? htmlspecialchars($_n['link'], ENT_QUOTES, 'UTF-8') : '#';
                    ?>
                    <li class="<?php echo $_n_class; ?>" data-id="<?php echo (int)$_n['id']; ?>" data-link="<?php echo $_n_link; ?>">
                        <span class="notif-icon notif-type-<?php echo htmlspecialchars($_n['type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid <?php echo $_n_icon; ?>"></i>
                        </span>
                        <div class="notif-body">
                            <p class="notif-title"><?php echo htmlspecialchars($_n['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="notif-message"><?php echo htmlspecialchars($_n['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <span class="notif-time"><?php echo $_n_time; ?></span>
                        </div>
                        <?php if (!$_n_read): ?><span class="notif-dot"></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

<style>
/* ── Notification Bell & Dropdown ────────────────────────────── */
.notif-bell-wrap { position: relative; }

.notif-bell-wrap .notification-btn {
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
.notif-bell-wrap .notification-btn:hover { background: rgba(245,158,11,.2); color: #f59e0b; transform: scale(1.05); }
.notif-bell-wrap .notification-btn.active { background: rgba(245,158,11,.2); color: #f59e0b; }

.notif-bell-wrap .notification-badge {
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
    animation: notif-pulse .4s ease;
}
@keyframes notif-pulse { 0%{transform:scale(0)} 70%{transform:scale(1.2)} 100%{transform:scale(1)} }

/* Dropdown */
.notif-dropdown {
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
    animation: notif-slide-in .2s ease;
}
@keyframes notif-slide-in { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.notif-dropdown.open { display: block; }

.notif-dropdown-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.notif-dropdown-title { font-weight: 700; font-size: 14px; color: #f1f5f9; display: flex; align-items: center; gap: 7px; }
.notif-dropdown-title i { color: #f59e0b; }

.notif-mark-all-btn {
    background: none; border: none;
    color: #f59e0b; font-size: 12px; font-weight: 600;
    cursor: pointer; padding: 4px 8px; border-radius: 6px;
    transition: background .15s;
}
.notif-mark-all-btn:hover { background: rgba(245,158,11,.15); }

.notif-list { list-style: none; margin: 0; padding: 0; max-height: 380px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(251, 191, 36, 0.38) rgba(15, 23, 42, 0.6); }
.notif-list::-webkit-scrollbar { width: 5px; }
.notif-list::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.5); border-radius: 10px; }
.notif-list::-webkit-scrollbar-thumb { background: rgba(251, 191, 36, 0.35); border-radius: 10px; }
.notif-list::-webkit-scrollbar-thumb:hover { background: rgba(251, 191, 36, 0.7); }

.notif-empty {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 40px 20px; color: #64748b; font-size: 14px;
}
.notif-empty i { font-size: 28px; opacity: .4; }

.notif-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    cursor: pointer;
    transition: background .15s;
    position: relative;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: rgba(255,255,255,.04); }
.notif-item.unread { background: rgba(245,158,11,.04); }

.notif-icon {
    flex-shrink: 0;
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
}
.notif-type-success { background: rgba(34,197,94,.15); color: #22c55e; }
.notif-type-warning { background: rgba(251,191,36,.15); color: #fbbf24; }
.notif-type-error   { background: rgba(239,68,68,.15);  color: #ef4444; }
.notif-type-info    { background: rgba(59,130,246,.15); color: #3b82f6; }

.notif-body { flex: 1; min-width: 0; }
.notif-title { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.notif-message { margin: 0 0 4px; font-size: 12px; color: #94a3b8; line-height: 1.4; }
.notif-time { font-size: 11px; color: #64748b; }

.notif-dot {
    position: absolute; top: 14px; right: 14px;
    width: 8px; height: 8px; border-radius: 50%;
    background: #f59e0b;
    flex-shrink: 0;
}

/* Mobile responsive styles */
@media (max-width: 768px) {
    .notif-dropdown {
        position: fixed;
        top: 60px;
        right: 10px;
        left: 10px;
        width: auto;
        max-width: calc(100vw - 20px);
        max-height: 70vh;
    }
    .notif-list {
        max-height: 50vh;
    }
}
@media (max-width: 480px) {
    .notif-dropdown {
        top: 55px;
        right: 10px;
        left: 10px;
    }
}
</style>

<script>
(function () {
    const wrap    = document.getElementById('studentNotifWrap');
    const btn     = document.getElementById('studentNotifBtn');
    const dropdown= document.getElementById('studentNotifDropdown');
    const badge   = document.getElementById('studentNotifBadge');
    const markAll = document.getElementById('studentMarkAllRead');
    const list    = document.getElementById('studentNotifList');

    if (!btn || !dropdown) return;

    // Toggle dropdown
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = dropdown.classList.toggle('open');
        btn.classList.toggle('active', open);
        btn.setAttribute('aria-expanded', open);
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) {
            dropdown.classList.remove('open');
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    // Click on a notification item → mark read, navigate
    list?.addEventListener('click', function (e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        e.preventDefault();
        
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

    const clearAll = document.getElementById('studentClearAllNotifs');
    clearAll?.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const executeClear = () => {
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=clear_all&caller_type=student'
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    list.innerHTML = '<li class="notif-empty"><i class="fa-regular fa-bell-slash"></i><span>No notifications.</span></li>';
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

    // Mark all as read
    markAll?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read&caller_type=student'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('#studentNotifList .notif-item.unread').forEach(el => {
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
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_read&id=' + id + '&caller_type=student'
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
