/**
 * Session Timeout Handler
 * Monitors user activity and shows warning before session expires
 */

let sessionWarningShown = false;
let sessionTimeoutInterval = null;

// Configuration (matches PHP config.php)
const SESSION_TIMEOUT = 1800; // 30 minutes
const SESSION_WARNING_TIME = 1680; // 28 minutes (warn 2 minutes before logout)
const CHECK_INTERVAL = 10000; // Check every 10 seconds

function getSessionEndpoint(path) {
    const base = window.CAREER_APP_BASE_URL || '';
    if (!base) return path;
    return base.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
}

/**
 * Initialize session timeout monitoring
 */
function initializeSessionTimeout() {
    fetch(getSessionEndpoint('includes/check_session.php'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        const ct = response.headers.get('content-type') || '';
        if (!response.ok || !ct.includes('application/json')) {
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (data && data.is_logged_in) {
            startSessionMonitoring();
            addActivityListeners();
        }
    })
    .catch(error => {
        // Silently catch network or parsing hiccups on init
    });
}

/**
 * Start monitoring session timeout
 */
function startSessionMonitoring() {
    // Check session status periodically
    sessionTimeoutInterval = setInterval(checkSessionStatus, CHECK_INTERVAL);
}

/**
 * Check session status and show warning if needed
 */
function checkSessionStatus() {
    fetch(getSessionEndpoint('includes/check_session.php'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        const ct = response.headers.get('content-type') || '';
        if (!response.ok || !ct.includes('application/json')) {
            if (response.status === 401 || response.redirected || ct.includes('text/html')) {
                handleSessionExpired();
            }
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (!data) return;
        if (data.session_expired) {
            handleSessionExpired(data.expired_login_url);
        } else if (data.show_warning && !sessionWarningShown) {
            // Show warning modal
            showSessionWarning(data.time_remaining);
            sessionWarningShown = true;
        } else if (!data.show_warning && sessionWarningShown) {
            // User stayed active, hide warning
            hideSessionWarning();
            sessionWarningShown = false;
        }
    })
    .catch(error => {
        // Silently catch network hiccups without console error noise
    });
}

let countdownInterval = null;

/**
 * Show session timeout warning modal
 */
function showSessionWarning(timeRemaining) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('sessionWarningModal');
    
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.className = 'session-warning-modal';
        modal.innerHTML = `
            <div class="session-warning-content">
                <div class="warning-icon">
                    <i class="fa-solid fa-hourglass-end"></i>
                </div>
                <h2>Session Timeout Warning</h2>
                <p>Your session will expire due to inactivity.</p>
                <div class="warning-timer">
                    <span class="timer-label">Time remaining:</span>
                    <span class="timer-value" id="sessionCountdown">2:00</span>
                </div>
                <p class="warning-message">
                    Click "Stay Logged In" to continue using the system, or your session will be automatically terminated.
                </p>
                <div class="warning-actions">
                    <button class="btn btn-primary" onclick="extendSession()">
                        <i class="fa-solid fa-sync"></i> Stay Logged In
                    </button>
                    <button class="btn btn-secondary" onclick="logoutNow()">
                        <i class="fa-solid fa-sign-out-alt"></i> Logout Now
                    </button>
                </div>
            </div>
        `;
        
        // Add styles
        const style = document.createElement('style');
        style.textContent = `
            .session-warning-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                backdrop-filter: blur(4px);
                animation: fadeIn 0.3s ease-in-out;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }

            .session-warning-content {
                background: white;
                border-radius: 12px;
                padding: 2rem;
                max-width: 400px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                text-align: center;
                animation: slideUp 0.3s ease-in-out;
            }

            @keyframes slideUp {
                from {
                    transform: translateY(30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            .warning-icon {
                font-size: 3rem;
                color: #f59e0b;
                margin-bottom: 1rem;
                animation: pulse 1.5s ease-in-out infinite;
            }

            @keyframes pulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.1);
                }
            }

            .session-warning-content h2 {
                color: #1f2937;
                margin: 0.5rem 0;
                font-size: 1.5rem;
            }

            .session-warning-content > p:first-of-type {
                color: #6b7280;
                margin: 0.5rem 0 1.5rem 0;
            }

            .warning-timer {
                background: #fef3c7;
                border: 2px solid #f59e0b;
                border-radius: 8px;
                padding: 1rem;
                margin: 1rem 0;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                flex-wrap: wrap;
            }

            .timer-label {
                color: #92400e;
                font-weight: 600;
            }

            .timer-value {
                font-size: 1.75rem;
                font-weight: 700;
                color: #f59e0b;
                font-family: 'Courier New', monospace;
                min-width: 50px;
            }

            .warning-message {
                color: #6b7280;
                font-size: 0.95rem;
                margin: 1rem 0;
            }

            .warning-actions {
                display: flex;
                gap: 0.75rem;
                margin-top: 1.5rem;
                flex-direction: column;
            }

            .warning-actions button {
                padding: 0.75rem 1.5rem;
                font-size: 0.95rem;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }

            .warning-actions .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }

            .warning-actions .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }

            .warning-actions .btn-secondary {
                background: #e5e7eb;
                color: #374151;
            }

            .warning-actions .btn-secondary:hover {
                background: #d1d5db;
            }

            @media (max-width: 480px) {
                .session-warning-content {
                    margin: 1rem;
                    padding: 1.5rem;
                }
            }
        `;
        document.head.appendChild(style);
        document.body.appendChild(modal);
    }

    // Show the modal
    modal.style.display = 'flex';

    if (countdownInterval) {
        clearInterval(countdownInterval);
    }
    
    let currentRemaining = timeRemaining;
    updateSessionCountdown(currentRemaining);
    
    countdownInterval = setInterval(() => {
        currentRemaining--;
        if (currentRemaining >= 0) {
            updateSessionCountdown(currentRemaining);
        } else {
            clearInterval(countdownInterval);
        }
    }, 1000);
}

/**
 * Hide session warning modal
 */
function hideSessionWarning() {
    const modal = document.getElementById('sessionWarningModal');
    if (modal) {
        modal.style.display = 'none';
    }
    
    if (countdownInterval) {
        clearInterval(countdownInterval);
        countdownInterval = null;
    }
}

/**
 * Update the countdown timer
 */
function updateSessionCountdown(secondsRemaining) {
    const minutes = Math.floor(secondsRemaining / 60);
    const seconds = secondsRemaining % 60;
    const timerElement = document.getElementById('sessionCountdown');
    
    if (timerElement) {
        timerElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}

/**
 * Extend session by refreshing activity
 */
function extendSession() {
    fetch(getSessionEndpoint('includes/extend_session.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    })
    .then(response => {
        const ct = response.headers.get('content-type') || '';
        if (!response.ok || !ct.includes('application/json')) {
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (data && data.success) {
            hideSessionWarning();
            sessionWarningShown = false;
            console.log('Session extended successfully');
        }
    })
    .catch(error => {
        console.error('Error extending session:', error);
        alert('Failed to extend session. Please log in again.');
        window.location.href = getSessionEndpoint('login.php');
    });
}

/**
 * Logout immediately
 */
function logoutNow() {
    window.location.href = window.APP_LOGOUT_URL || getSessionEndpoint('logout.php');
}

/**
 * Handle session expiration
 */
function handleSessionExpired(loginUrl) {
    if (sessionTimeoutInterval) {
        clearInterval(sessionTimeoutInterval);
        sessionTimeoutInterval = null;
    }

    sessionStorage.clear();
    alert('Your session has expired due to inactivity. Please log in again.');
    window.location.href = loginUrl || getSessionEndpoint('login.php?session=expired');
}

/**
 * Add activity listeners to reset timeout
 */
let lastActivityPing = 0;
const ACTIVITY_PING_INTERVAL = 30000;

function addActivityListeners() {
    const events = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click'];

    events.forEach(event => {
        document.addEventListener(event, function() {
            const now = Date.now();
            if (now - lastActivityPing < ACTIVITY_PING_INTERVAL) {
                return;
            }
            lastActivityPing = now;

            fetch(getSessionEndpoint('includes/update_activity.php'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                }
            }).catch(() => {});
        }, { passive: true });
    });
}

/**
 * Cleanup on page unload
 */
window.addEventListener('beforeunload', function() {
    if (sessionTimeoutInterval) {
        clearInterval(sessionTimeoutInterval);
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', initializeSessionTimeout);
