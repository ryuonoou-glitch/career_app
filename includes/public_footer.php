<?php
// public_footer.php - Footer for public/auth pages
?>
<style>
body {
    background-color: #0f172a !important;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    margin: 0;
}
.auth-container, .signup-container, .login-container, .admin-login-container {
    flex: 1 0 auto;
    box-sizing: border-box;
}
.public-footer {
    flex-shrink: 0;
}
</style>
<footer class="public-footer" style="text-align: center; padding: 1.5rem 1.5rem; color: #94a3b8; font-size: 0.85rem; width: 100%; box-sizing: border-box; background: #0b1120; border-top: 1px solid rgba(255, 255, 255, 0.05);">
    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(getSystemConfig('name') ?: 'Career Guidance System'); ?>. All Rights Reserved.</p>
    <p style="margin-top: 0.5rem; display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
        <a href="terms.php" style="color: #fbbf24; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#fcd34d'" onmouseout="this.style.color='#fbbf24'">Terms & Conditions</a> <span style="opacity:0.3">|</span> 
        <a href="privacy.php" style="color: #fbbf24; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#fcd34d'" onmouseout="this.style.color='#fbbf24'">Privacy Policy</a>
    </p>
</footer>


