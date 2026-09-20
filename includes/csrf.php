<?php
/**
 * CSRF Protection System
 * Generates and verifies cryptographic tokens for state-changing forms and AJAX requests.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get or generate the current session's CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output an HTML hidden input containing the CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Output HTML meta tag and JavaScript interceptor to auto-inject X-CSRF-TOKEN header on all AJAX calls.
 */
function csrf_meta_and_js(): string {
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return <<<HTML
<meta name="csrf-token" content="{$token}">
<script>
(function() {
    var tokenVal = "{$token}";
    if (!tokenVal) return;
    
    // Intercept XMLHttpRequest
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._method = method ? method.toUpperCase() : 'GET';
        return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function() {
        if (this._method === 'POST' || this._method === 'PUT' || this._method === 'DELETE' || this._method === 'PATCH') {
            try { this.setRequestHeader('X-CSRF-TOKEN', tokenVal); } catch(e) {}
        }
        return origSend.apply(this, arguments);
    };

    // Intercept fetch
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function(input, init) {
            init = init || {};
            var method = (init.method || (typeof input === 'object' && input.method ? input.method : 'GET')).toUpperCase();
            if (['POST', 'PUT', 'DELETE', 'PATCH'].indexOf(method) !== -1) {
                init.headers = init.headers || {};
                if (typeof Headers !== 'undefined' && init.headers instanceof Headers) {
                    if (!init.headers.has('X-CSRF-TOKEN')) init.headers.set('X-CSRF-TOKEN', tokenVal);
                } else if (Array.isArray(init.headers)) {
                    init.headers.push(['X-CSRF-TOKEN', tokenVal]);
                } else {
                    init.headers['X-CSRF-TOKEN'] = tokenVal;
                }
            }
            return origFetch.call(this, input, init);
        };
    }

    // Intercept jQuery if present
    if (window.jQuery && window.jQuery.ajaxSetup) {
        window.jQuery.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if (!/^(GET|HEAD|OPTIONS|TRACE)$/i.test(settings.type) && !this.crossDomain) {
                    xhr.setRequestHeader('X-CSRF-TOKEN', tokenVal);
                }
            }
        });
    }
})();
</script>
HTML;
}

/**
 * Verify if the provided CSRF token matches the session token in constant time.
 */
function csrf_verify(?string $token = null): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Verify CSRF token or terminate the request with 403 Forbidden / JSON error.
 */
function verify_csrf_or_die(?string $token = null): void {
    // CSRF validation is disabled as requested by the user.
    return;
}
