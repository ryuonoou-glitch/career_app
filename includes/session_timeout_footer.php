<?php
if (!defined('SESSION_TIMEOUT_FOOTER_LOADED')) {
    define('SESSION_TIMEOUT_FOOTER_LOADED', true);
    echo '<script>window.CAREER_APP_BASE_URL = ' . json_encode(base_url()) . ';</script>' . "\n";
    echo '<script src="' . htmlspecialchars(base_url('includes/session_timeout.js?v=' . filemtime(__DIR__ . '/session_timeout.js'))) . '"></script>' . "\n";
    ?>
    <!-- Global Logout Modal -->
    <style>
        .logout-modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .logout-modal-overlay.show {
            display: flex;
            opacity: 1;
        }
        .logout-modal {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            transform: translateY(20px);
            transition: transform 0.2s ease;
        }
        .logout-modal-overlay.show .logout-modal {
            transform: translateY(0);
        }
        .logout-modal-icon {
            width: 64px; height: 64px;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            margin: 0 auto 20px auto;
        }
        .logout-modal h3 {
            color: #f8fafc; margin: 0 0 12px 0; font-size: 1.25rem; font-weight: 600;
        }
        .logout-modal p {
            color: #94a3b8; margin: 0 0 24px 0; font-size: 0.95rem; line-height: 1.5;
        }
        .logout-modal-actions {
            display: flex; gap: 12px; justify-content: center;
        }
        .logout-modal-actions button, .logout-modal-actions a {
            padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; display: inline-block;
        }
        .btn-logout-cancel {
            background: rgba(255, 255, 255, 0.05); color: #cbd5e1;
        }
        .btn-logout-cancel:hover { background: rgba(255, 255, 255, 0.1); }
        .btn-logout-confirm {
            background: #ef4444; color: white; text-decoration: none;
        }
        .btn-logout-confirm:hover { background: #dc2626; }
    </style>
    <div class="logout-modal-overlay" id="globalLogoutModal">
        <div class="logout-modal">
            <div class="logout-modal-icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></div>
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to end your current session and sign out?</p>
            <div class="logout-modal-actions">
                <button class="btn-logout-cancel" id="btnCancelLogout">Cancel</button>
                <a href="<?php
                    if ((isset($_SESSION['staff_role']) && $_SESSION['staff_role'] === 'counselor') || isset($_SESSION['counselor_id'])) {
                        echo 'logout.php?role=counselor';
                    } elseif (isset($_SESSION['admin_id']) || (isset($_SESSION['staff_role']) && in_array($_SESSION['staff_role'], ['admin', 'super_admin']))) {
                        echo 'logout.php?role=admin';
                    } else {
                        echo 'logout.php?role=student';
                    }
                ?>" class="btn-logout-confirm" id="btnConfirmLogout">Yes, Logout</a>
            </div>
        </div>
    </div>
    <!-- Global Custom Alert Modal -->
    <div class="logout-modal-overlay" id="globalAlertModal">
        <div class="logout-modal" style="max-width: 420px; text-align: center; border: 1px solid rgba(251, 191, 36, 0.3); border-radius: 16px; box-shadow: 0 25px 50px rgba(0,0,0,0.6);">
            <div class="logout-modal-icon" id="globalAlertIcon" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24; margin: 0 auto 1.25rem auto;">
                <i class="fa-solid fa-circle-info" id="globalAlertIconI"></i>
            </div>
            <h3 id="globalAlertTitle" style="color: #ffffff; font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Notice</h3>
            <p id="globalAlertMessage" style="color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem; word-break: break-word;">Message</p>
            <div class="logout-modal-actions" style="justify-content: center;">
                <button class="btn-logout-confirm" id="btnGlobalAlertOk" type="button" style="background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #0f172a; font-weight: 700; border: none; padding: 0.65rem 2rem; border-radius: 8px; cursor: pointer; min-width: 110px;">OK</button>
            </div>
        </div>
    </div>
    
    <!-- Global Custom Confirm Modal -->
    <div class="logout-modal-overlay" id="globalConfirmModal">
        <div class="logout-modal">
            <div class="logout-modal-icon" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;"><i class="fa-solid fa-circle-question" id="globalConfirmIcon"></i></div>
            <h3 id="globalConfirmTitle">Confirm</h3>
            <p id="globalConfirmMessage">Are you sure?</p>
            <div class="logout-modal-actions">
                <button class="btn-logout-cancel" id="btnGlobalConfirmCancel">Cancel</button>
                <button class="btn-logout-confirm" id="btnGlobalConfirmOk">Yes</button>
            </div>
        </div>
    </div>
    <script>
    (function() {
        // Global Custom Alert Function
        window.showAlert = function(message, title = 'Notice', iconClass = 'fa-circle-info') {
            const alertModal = document.getElementById('globalAlertModal');
            const msgEl = document.getElementById('globalAlertMessage');
            const titleEl = document.getElementById('globalAlertTitle');
            const iconI = document.getElementById('globalAlertIconI');
            if (alertModal && msgEl) {
                msgEl.textContent = message;
                if (titleEl) titleEl.textContent = title;
                if (iconI && iconClass) iconI.className = 'fa-solid ' + iconClass;
                alertModal.classList.add('show');
            } else {
                console.log('Notice:', message);
            }
        };

        // Override native window.alert to automatically use custom modal
        window.alert = function(message) {
            window.showAlert(message, 'Notice', 'fa-circle-exclamation');
        };

        // Global Custom Confirm Function
        window.showConfirm = function(message, title, onConfirm) {
            const confirmModal = document.getElementById('globalConfirmModal');
            const msgEl = document.getElementById('globalConfirmMessage');
            const titleEl = document.getElementById('globalConfirmTitle');
            const btnOk = document.getElementById('btnGlobalConfirmOk');
            const btnCancel = document.getElementById('btnGlobalConfirmCancel');
            
            if (confirmModal && msgEl && btnOk) {
                msgEl.textContent = message;
                if (titleEl) titleEl.textContent = title || 'Confirm';
                
                const closeAndClean = () => {
                    confirmModal.classList.remove('show');
                    btnOk.onclick = null;
                    btnCancel.onclick = null;
                };

                btnOk.onclick = function() {
                    closeAndClean();
                    if (onConfirm) onConfirm();
                };
                
                btnCancel.onclick = function() {
                    closeAndClean();
                };
                
                confirmModal.classList.add('show');
            } else {
                if (window.confirm(message)) {
                    if (onConfirm) onConfirm();
                }
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const alertModal = document.getElementById('globalAlertModal');
            const btnAlertOk = document.getElementById('btnGlobalAlertOk');
            if (alertModal && btnAlertOk) {
                btnAlertOk.addEventListener('click', function() {
                    alertModal.classList.remove('show');
                });
                alertModal.addEventListener('click', function(e) {
                    if (e.target === alertModal) alertModal.classList.remove('show');
                });
            }

            const logoutLinks = document.querySelectorAll('a[href*="logout.php"], a.logout');
            const modal = document.getElementById('globalLogoutModal');
            const btnCancel = document.getElementById('btnCancelLogout');
            const btnConfirm = document.getElementById('btnConfirmLogout');
            if (btnConfirm && btnConfirm.href) {
                window.APP_LOGOUT_URL = btnConfirm.href;
            }
            
            if (modal && btnCancel) {
                logoutLinks.forEach(link => {
                    if (link.id === 'btnConfirmLogout') return;
                    
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        btnConfirm.href = this.href; 
                        modal.classList.add('show');
                    });
                });
                
                btnCancel.addEventListener('click', function() {
                    modal.classList.remove('show');
                });
                
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.classList.remove('show');
                    }
                });
            }
        });
    })();
    </script>
    <?php
}
