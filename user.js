// ==================== USER JAVASCRIPT ====================
// Career Guidance System - User Panel JavaScript

// ==================== MOBILE MENU TOGGLE ====================

// Initialize mobile menu functionality
function initializeMobileMenu() {
    const menuBtn = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');

    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
    }

    // Close sidebar when clicking outside
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 1200) {
            if (!sidebar?.contains(e.target) && !menuBtn?.contains(e.target)) {
                sidebar?.classList.remove('active');
            }
        }
    });

    // Close sidebar on window resize to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth > 1200) {
            sidebar?.classList.remove('active');
        }
    });
}

// ==================== DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize mobile menu
    initializeMobileMenu();

    // Log initialization
    console.log('User panel JavaScript initialized successfully');
});
