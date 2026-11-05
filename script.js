// ── Sidebar Collapsible Group Toggle ──────────────────────────────────────────
function toggleNavGroup(btn) {
    const submenu = btn.nextElementSibling;
    const isOpen = btn.classList.contains('open');
    btn.classList.toggle('open', !isOpen);
    if (submenu) submenu.classList.toggle('open', !isOpen);
}

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {

    // Admin Settings for Navbar
    let navbarSettings = {
        siteName: 'CareerPath',
        logoIcon: '🎯',
        navItems: {
            navHome: 'Home',
            navCareers: 'Careers',
            navCourses: 'Courses',
            navAbout: 'About Us',
            navLogin: 'Login',
            navGetStarted: 'Get Started'
        }
    };

    // Load settings from localStorage
    function loadNavbarSettings() {
        const saved = localStorage.getItem('navbarSettings');
        if (saved) {
            navbarSettings = JSON.parse(saved);
            applyNavbarSettings();
        }
    }

    // Apply settings to navbar
    function applyNavbarSettings() {
        const siteNameEl = document.getElementById('siteName');
        const logoIconEl = document.getElementById('logoIcon');

        if (siteNameEl) siteNameEl.textContent = navbarSettings.siteName;
        if (logoIconEl) logoIconEl.textContent = navbarSettings.logoIcon;

        Object.keys(navbarSettings.navItems).forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = navbarSettings.navItems[id];
            }
        });
    }

    // Save settings to localStorage
    function saveNavbarSettings() {
        localStorage.setItem('navbarSettings', JSON.stringify(navbarSettings));
    }

    // Admin Login Button Toggle with Ctrl+Q
    const adminLoginBtn = document.getElementById('adminLoginBtn');
    const adminNavBtn = document.getElementById('adminNavBtn');
    let adminTimeout;
    let isAdminVisible = false;

    function toggleAdminButton() {
        if (isAdminVisible) {
            // Hide admin button
            if (adminLoginBtn) adminLoginBtn.setAttribute('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important;');
            if (adminNavBtn) adminNavBtn.setAttribute('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important;');
            isAdminVisible = false;
            if (adminTimeout) {
                clearTimeout(adminTimeout);
                adminTimeout = null;
            }
        } else {
            // Show admin button
            if (adminLoginBtn) adminLoginBtn.setAttribute('style', 'display: block !important; visibility: visible !important; opacity: 1 !important;');
            if (adminNavBtn) adminNavBtn.setAttribute('style', 'display: block !important; visibility: visible !important; opacity: 1 !important;');
            isAdminVisible = true;

            // Auto-hide after 10 seconds
            adminTimeout = setTimeout(() => {
                if (adminLoginBtn) adminLoginBtn.setAttribute('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important;');
                if (adminNavBtn) adminNavBtn.setAttribute('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important;');
                isAdminVisible = false;
                adminTimeout = null;
            }, 10000);
        }
    }

    // Ctrl+Q key combination handler
    document.addEventListener('keydown', function (e) {
        // Check for Ctrl+Q (or Cmd+Q on Mac)
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'q') {
            e.preventDefault();
            toggleAdminButton();
        }
    });

    // Load navbar settings on page load
    if (document.getElementById('siteName')) {
        loadNavbarSettings();
    }

    // Admin Functions for Navbar Editing
    window.updateNavbarSettings = function (newSettings) {
        navbarSettings = { ...navbarSettings, ...newSettings };
        applyNavbarSettings();
        saveNavbarSettings();
    };

    window.getNavbarSettings = function () {
        return navbarSettings;
    };

    window.editNavbarText = function (elementId, newText) {
        if (navbarSettings.navItems[elementId]) {
            navbarSettings.navItems[elementId] = newText;
        } else if (elementId === 'siteName') {
            navbarSettings.siteName = newText;
        } else if (elementId === 'logoIcon') {
            navbarSettings.logoIcon = newText;
        }
        applyNavbarSettings();
        saveNavbarSettings();
    };

    // Hide admin button when clicking on it (for navigation)
    if (adminLoginBtn) {
        adminLoginBtn.addEventListener('click', function (e) {
            // Small delay to allow the link to work first
            setTimeout(() => {
                adminLoginBtn.classList.remove('show');
                adminNavBtn.style.display = 'none';
                isAdminVisible = false;
                if (adminTimeout) {
                    clearTimeout(adminTimeout);
                    adminTimeout = null;
                }
            }, 100);
        });
    }

    // Handle navbar admin button click
    if (adminNavBtn) {
        adminNavBtn.addEventListener('click', function (e) {
            // Small delay to allow the link to work first
            setTimeout(() => {
                adminLoginBtn.classList.remove('show');
                adminNavBtn.style.display = 'none';
                isAdminVisible = false;
                if (adminTimeout) {
                    clearTimeout(adminTimeout);
                    adminTimeout = null;
                }
            }, 100);
        });
    }

    // Initially hide the navbar admin button
    if (adminNavBtn) {
        adminNavBtn.style.display = 'none';
    }

    // Hamburger Menu Toggle
    const hamburgerMenu = document.querySelector('.hamburger-menu');
    const navLinks = document.querySelector('.nav-links');

    if (hamburgerMenu && navLinks) {
        hamburgerMenu.addEventListener('click', function () {
            hamburgerMenu.classList.toggle('active');
            navLinks.classList.toggle('active');

            // Prevent body scroll when menu is open
            if (navLinks.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        });

        // Close menu when clicking on a link
        const navItems = navLinks.querySelectorAll('a');
        navItems.forEach(item => {
            item.addEventListener('click', function () {
                hamburgerMenu.classList.remove('active');
                navLinks.classList.remove('active');
                document.body.style.overflow = '';
            });
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (e) {
            if (!hamburgerMenu.contains(e.target) && !navLinks.contains(e.target)) {
                hamburgerMenu.classList.remove('active');
                navLinks.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    // Smooth scrolling for navigation links
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');

            // Ignore links that are just '#'
            if (targetId === '#') {
                e.preventDefault();
                return;
            }

            try {
                const targetSection = document.querySelector(targetId);
                if (targetSection) {
                    e.preventDefault();
                    targetSection.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            } catch (err) {
                // Ignore invalid selector errors for links that start with # but aren't DOM IDs
            }
        });
    });

    // Navbar scroll effect
    const navbar = document.querySelector('.navbar');
    let lastScrollTop = 0;

    if (navbar) {
        window.addEventListener('scroll', function () {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

            // Add/remove shadow based on scroll
            if (scrollTop > 50) {
                navbar.style.boxShadow = '0 8px 40px rgba(0, 0, 0, 0.4)';
                navbar.style.background = 'rgba(15, 23, 42, 0.98)';
            } else {
                navbar.style.boxShadow = '0 4px 30px rgba(0, 0, 0, 0.3)';
                navbar.style.background = 'rgba(15, 23, 42, 0.95)';
            }

            lastScrollTop = scrollTop;
        });
    }

    // Intersection Observer for fade-in animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe elements for animation
    const animateElements = document.querySelectorAll('.category-card, .stat-item, .section-header');
    animateElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });

    // Category card hover effects
    const categoryCards = document.querySelectorAll('.category-card');
    categoryCards.forEach(card => {
        card.addEventListener('mouseenter', function () {
            this.style.transform = 'translateY(-15px) rotateX(5deg)';
            this.style.boxShadow = '0 25px 50px rgba(251, 191, 36, 0.3)';
        });

        card.addEventListener('mouseleave', function () {
            this.style.transform = 'translateY(0) rotateX(0)';
            this.style.boxShadow = '0 20px 40px rgba(251, 191, 36, 0.2)';
        });
    });

    // Add CSS for 3D transforms
    const transformStyle = document.createElement('style');
    transformStyle.textContent = `
            .category-card {
                transform-style: preserve-3d;
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
        `;
    document.head.appendChild(transformStyle);

    // Button hover effects with ripple
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function () {
            this.style.transform = 'translateY(-3px) scale(1.05)';
        });

        button.addEventListener('mouseleave', function () {
            this.style.transform = 'translateY(0) scale(1)';
        });

        // Add ripple effect on click
        button.addEventListener('click', function (e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.classList.add('ripple');

            this.appendChild(ripple);

            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });

    // Add ripple effect styles
    const rippleStyle = document.createElement('style');
    rippleStyle.textContent = `
            .btn {
                position: relative;
                overflow: hidden;
            }
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
                transform: scale(0);
                animation: ripple-animation 0.6s ease-out;
                pointer-events: none;
            }
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
    document.head.appendChild(rippleStyle);

    // Hero section parallax effect
    const heroVisual = document.querySelector('.hero-visual');
    const floatElements = document.querySelectorAll('.float-element');

    window.addEventListener('scroll', function () {
        const scrolled = window.pageYOffset;
        const parallax = scrolled * 0.5;

        if (heroVisual && scrolled < window.innerHeight) {
            heroVisual.style.transform = `translateY(${parallax}px)`;
        }

        // Animate floating elements on scroll
        floatElements.forEach((element, index) => {
            const speed = 0.5 + (index * 0.1);
            element.style.transform = `translateY(${scrolled * speed}px) rotate(${scrolled * 0.1}deg)`;
        });
    });

    // Stats counter animation
    const statNumbers = document.querySelectorAll('.stat-number');
    const statsSection = document.querySelector('.stats');

    const animateStats = () => {
        statNumbers.forEach(stat => {
            const target = stat.textContent;
            const number = parseInt(target.replace(/[^0-9]/g, ''));
            const suffix = target.replace(/[0-9]/g, '');
            let current = 0;
            const increment = number / 50;

            const updateNumber = () => {
                if (current < number) {
                    current += increment;
                    stat.textContent = Math.floor(current) + suffix;
                    requestAnimationFrame(updateNumber);
                } else {
                    stat.textContent = target;
                }
            };

            updateNumber();
        });
    };

    // Trigger stats animation when section is visible
    const statsObserver = new IntersectionObserver(function (entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateStats();
                statsObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    if (statsSection) {
        statsObserver.observe(statsSection);
    }

    // Dynamic year in footer
    const currentYear = new Date().getFullYear();
    const footerYear = document.querySelector('.footer-bottom p');
    if (footerYear) {
        footerYear.innerHTML = `&copy; ${currentYear} CareerPath. All rights reserved.`;
    }

    // Mobile App Download Button Android Highlight
    const androidBtn = document.getElementById('downloadAndroidBtn');
    if (androidBtn) {
        const userAgent = navigator.userAgent || navigator.vendor || window.opera || '';
        const isAndroid = /android/i.test(userAgent);
        if (isAndroid) {
            androidBtn.classList.add('device-matched');
        }
    }

    // Mobile menu touch optimization
    if ('ontouchstart' in window) {
        navLinks?.addEventListener('touchstart', function (e) {
            // Prevent default to improve touch responsiveness
            e.stopPropagation();
        });
    }

    // Performance optimization - Debounce scroll events
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Apply debounce to scroll handlers
    const debouncedScroll = debounce(function () {
        // Scroll-related operations here
    }, 10);

    window.addEventListener('scroll', debouncedScroll);

    // Add loading animation
    window.addEventListener('load', function () {
        document.body.style.opacity = '0';
        document.body.style.transition = 'opacity 0.5s ease';

        setTimeout(() => {
            document.body.style.opacity = '1';
        }, 100);
    });

    // Console Easter egg
    console.log('%c🚀 Welcome to CareerPath!', 'color: #fbbf24; font-size: 20px; font-weight: bold;');
    console.log('%c📚 Find your perfect career path!', 'color: #f59e0b; font-size: 14px;');

    // User profile click handler
    document.addEventListener('click', function (e) {
        const userProfile = e.target.closest('.user-profile');
        if (userProfile) {
            // Determine which profile page to redirect to
            if (document.querySelector('a[href="counselor_dashboard.php"]')) {
                // Counselor user
                window.location.href = 'counselor_profile.php';
            } else if (document.querySelector('a[href="admin_dashboard.php"]')) {
                // Admin user
                window.location.href = 'settings.php';
            } else {
                // Student user (default)
                window.location.href = 'profile.php';
            }
        }
    });
});

// Utility functions
const utils = {
    // Check if element is in viewport
    isInViewport: function (element) {
        const rect = element.getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    },

    // Add class to element
    addClass: function (element, className) {
        if (element) {
            element.classList.add(className);
        }
    },

    // Remove class from element
    removeClass: function (element, className) {
        if (element) {
            element.classList.remove(className);
        }
    },

    // Toggle class on element
    toggleClass: function (element, className) {
        if (element) {
            element.classList.toggle(className);
        }
    }
};

// Export for potential use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = utils;
}

// ==================== DASHBOARD FUNCTIONALITY ====================

function initializeDashboard() {
    const sidebar = document.getElementById('sidebar');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebarToggle = document.getElementById('sidebarToggle');
    let overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !mobileMenuToggle) return;

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sidebarOverlay';
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    function openSidebar() {
        sidebar.classList.add('active');
        if (overlay) overlay.classList.add('active');
    }

    function closeSidebar() {
        sidebar.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
    }

    // Mobile menu toggle
    mobileMenuToggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (sidebar.classList.contains('active')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    // Sidebar toggle button (for mobile / tablet)
    if (sidebarToggle) {
        ['click', 'touchend'].forEach(evt => {
            sidebarToggle.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeSidebar();
            });
        });
    }

    // Close sidebar when clicking overlay
    if (overlay) {
        ['click', 'touchend'].forEach(evt => {
            overlay.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeSidebar();
            });
        });
    }

    // Close sidebar when clicking outside on mobile/tablet (<= 1200px)
    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 1200 &&
            sidebar.classList.contains('active') &&
            !sidebar.contains(e.target) &&
            !mobileMenuToggle.contains(e.target)) {
            closeSidebar();
        }
    });

    // Close sidebar when pressing Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            closeSidebar();
        }
    });

    // Close sidebar when a nav link is clicked on mobile/tablet (<= 1200px)
    sidebar.querySelectorAll('.nav-item').forEach(link => {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 1200) {
                closeSidebar();
            }
        });
    });

    // Close sidebar when resizing to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1200) {
            closeSidebar();
        }
    });

    // Active page highlighting
    highlightActiveNavItem();

    // Load dashboard data
    loadDashboardData();
}

// Highlight active navigation item
function highlightActiveNavItem() {
    const currentPath = window.location.pathname;
    const navItems = document.querySelectorAll('.nav-item');

    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href && currentPath.includes(href.split('/').pop())) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

// Load dashboard data
function loadDashboardData() {
    // Load user data from localStorage or API
    const userData = localStorage.getItem('userData');
    if (userData) {
        const data = JSON.parse(userData);
        updateDashboardElements(data);
    } else {
        // Default data
        const defaultData = {
            userName: 'John Doe',
            assessmentsCount: 5,
            profileCompletion: 85,
            coursesCount: 12,
            schoolsCount: 8
        };
        updateDashboardElements(defaultData);
    }
}

// Update dashboard elements with data
function updateDashboardElements(data) {
    const userName = document.getElementById('userName');
    const assessmentsCount = document.getElementById('assessmentsCount');
    const profileCompletion = document.getElementById('profileCompletion');
    const coursesCount = document.getElementById('coursesCount');
    const schoolsCount = document.getElementById('schoolsCount');

    if (userName && data.userName) userName.textContent = data.userName;
    if (assessmentsCount && data.assessmentsCount) assessmentsCount.textContent = data.assessmentsCount;
    if (profileCompletion && data.profileCompletion) profileCompletion.textContent = data.profileCompletion + '%';
    if (coursesCount && data.coursesCount) coursesCount.textContent = data.coursesCount;
    if (schoolsCount && data.schoolsCount) schoolsCount.textContent = data.schoolsCount;
}

// Initialize dashboard if sidebar exists
if (document.getElementById('sidebar')) {
    initializeDashboard();
}

// ==================== ASSESSMENT FUNCTIONALITY ====================

// Assessment state
let assessmentState = {
    currentType: null,
    currentQuestion: 0,
    answers: {},
    questions: [],
    assessmentSequence: ['career', 'personality', 'skills', 'strand'],
    currentAssessmentIndex: 0,
    savedAnswers: {},
    region: ''
};

// Buffer for pending saved progress that requires user action (e.g., select region)
window.pendingAssessmentProgress = null;

// Get questions - use DB if available, otherwise fallback
function getAssessmentQuestions(type) {
    // Check if database questions are available from take_assessment.php
    if (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB[type]) {
        return window.assessmentQuestionsFromDB[type];
    }

    // Fallback to embedded questions (for pages without PHP backend)
    return assessmentQuestions[type] || [];
}

// Initialize assessment
function initializeAssessment() {
    const assessmentTypeSelection = document.getElementById('assessmentTypeSelection');
    const assessmentQuestions = document.getElementById('assessmentQuestions');
    const resultsPreview = document.getElementById('resultsPreview');

    if (!assessmentTypeSelection) return;

    // Initialize Medical & Physical Considerations Modal
    initMedicalDeclarationModal();

    // Immediately resume from MySQL if an active in-progress assessment exists on server
    if (window.serverActiveAssessment && window.serverActiveAssessment.assessmentId) {
        resumeSequentialAssessment(window.serverActiveAssessment);
        return;
    }

    // Hide individual assessment type cards and show sequential assessment
    const regionContainer = document.getElementById('regionSelectionContainer');
    if (regionContainer) {
        if (window.assessmentLimit && window.assessmentLimit.hasReachedLimit) {
            // They have reached the limit, show the region selection but disabled, and show a modal
            showRegionSelectionUI(regionContainer, true);
            showLimitReachedModal();
            return;
        }

        showRegionSelectionUI(regionContainer, false);

        // Pre-select the saved preferred region if it exists
        const preferredRegion = window.savedPreferredRegion || '';
        if (preferredRegion) {
            const checkboxes = regionContainer.querySelectorAll('input[name="preferred_region_option"]');
            const savedRegions = preferredRegion.split(',');
            if (preferredRegion === 'All') {
                checkboxes.forEach(cb => cb.checked = true);
            } else {
                checkboxes.forEach(cb => {
                    if (savedRegions.includes(cb.value)) cb.checked = true;
                });
            }
            // Trigger change event to update UI states (e.g., Enable Start Button)
            if (checkboxes.length > 0) {
                const event = new Event('change');
                checkboxes[0].dispatchEvent(event);
            }
        }
    }
}

function showLimitReachedModal() {
    const modalHtml = `
        <div id="limitReachedModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.85); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px);">
            <div style="background: #1e293b; padding: 2.5rem; border-radius: 16px; max-width: 450px; text-align: center; border: 1px solid rgba(239, 68, 68, 0.3); box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 3.5rem; color: #ef4444; margin-bottom: 1.25rem;"></i>
                <h3 style="color: #f1f5f9; margin-bottom: 0.75rem; font-size: 1.5rem; font-weight: 700;">Assessment Limit Reached</h3>
                <p style="color: #94a3b8; margin-bottom: 1.75rem; line-height: 1.6;">You have reached the maximum limit of assessments for this school year. You cannot take any more assessments at this time.</p>
                <div style="display: flex; gap: 1rem; justify-content: center;">
                    <button onclick="document.getElementById('limitReachedModal').remove();" style="background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">Close</button>
                    <button onclick="window.location.href='assessment_results.php';" style="background: #ef4444; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">View Results</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Global state for medical declaration
window.medicalDeclarationCompleted = false;

function initMedicalDeclarationModal() {
    const modal = document.getElementById('medicalDeclarationModal');
    if (!modal) return;

    const overlay = document.getElementById('medicalModalOverlay');
    const closeBtn = document.getElementById('closeMedicalModalBtn');
    const cancelBtn = document.getElementById('cancelMedicalModalBtn');
    const confirmBtn = document.getElementById('confirmMedicalModalBtn');

    const medCheckboxes = modal.querySelectorAll('input[name="modal_med_consideration"]');
    const noneCheckbox = document.getElementById('modalMedNoneCheckbox');
    const otherCheckbox = document.getElementById('modalMedOtherCheckbox');
    const otherDescContainer = document.getElementById('modalMedOtherDescContainer');
    const otherDescInput = document.getElementById('modalMedOtherDesc');
    const ackCheckbox = document.getElementById('modalMedAckCheckbox');

    function syncModalConfirmBtn() {
        if (!confirmBtn) return;
        const anyChecked = Array.from(medCheckboxes).some(cb => cb.checked);
        const ack = ackCheckbox ? ackCheckbox.checked : false;

        if (anyChecked && ack) {
            confirmBtn.removeAttribute('disabled');
            confirmBtn.style.cursor = 'pointer';
            confirmBtn.style.opacity = '1';
        } else {
            confirmBtn.setAttribute('disabled', 'disabled');
            confirmBtn.style.cursor = 'not-allowed';
            confirmBtn.style.opacity = '0.5';
        }
    }

    // Populate from savedMedicalDeclaration or assessmentState.medicalDeclaration if present
    const saved = window.savedMedicalDeclaration || (assessmentState && assessmentState.medicalDeclaration);
    if (saved && typeof saved === 'object') {
        medCheckboxes.forEach(cb => {
            if (saved[cb.value]) {
                cb.checked = true;
            }
        });
        if (saved['other']) {
            if (otherDescContainer) otherDescContainer.style.display = 'block';
            if (otherDescInput && saved['other_description']) {
                otherDescInput.value = saved['other_description'];
            }
        }
        if (ackCheckbox && saved['acknowledged']) {
            ackCheckbox.checked = true;
        }
        if (saved['acknowledged']) {
            window.medicalDeclarationCompleted = true;
            if (assessmentState) {
                assessmentState.medicalDeclaration = saved;
            }
        }
        syncModalConfirmBtn();
    }

    window.openMedicalModal = function(onSuccessCallback) {
        if (!modal) return;
        window.pendingMedicalSuccessCallback = onSuccessCallback || null;
        modal.classList.add('active');
        modal.style.display = 'flex';
        syncModalConfirmBtn();
    };

    window.closeMedicalModal = function() {
        if (!modal) return;
        modal.classList.remove('active');
        modal.style.display = 'none';
        window.pendingMedicalSuccessCallback = null;
    };

    if (closeBtn) closeBtn.onclick = window.closeMedicalModal;
    if (cancelBtn) cancelBtn.onclick = window.closeMedicalModal;
    if (overlay) overlay.onclick = window.closeMedicalModal;

    medCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (this.value === 'none' && this.checked) {
                medCheckboxes.forEach(otherCb => {
                    if (otherCb.value !== 'none') otherCb.checked = false;
                });
                if (otherDescContainer) otherDescContainer.style.display = 'none';
                if (otherDescInput) otherDescInput.value = '';
            } else if (this.value !== 'none' && this.checked) {
                if (noneCheckbox) noneCheckbox.checked = false;
            }

            if (this.value === 'other') {
                if (otherDescContainer) otherDescContainer.style.display = this.checked ? 'block' : 'none';
            }
            syncModalConfirmBtn();
        });
    });

    if (ackCheckbox) {
        ackCheckbox.addEventListener('change', syncModalConfirmBtn);
    }

    if (confirmBtn) {
        confirmBtn.onclick = function() {
            const anyChecked = Array.from(medCheckboxes).some(cb => cb.checked);
            const ack = ackCheckbox ? ackCheckbox.checked : false;
            if (!anyChecked || !ack) return;

            let medicalDeclaration = {};
            medCheckboxes.forEach(cb => {
                medicalDeclaration[cb.value] = cb.checked;
            });
            if (medicalDeclaration['other']) {
                medicalDeclaration['other_description'] = otherDescInput ? otherDescInput.value.trim() : '';
            } else {
                medicalDeclaration['other_description'] = '';
            }
            medicalDeclaration['acknowledged'] = ack;

            if (assessmentState) {
                assessmentState.medicalDeclaration = medicalDeclaration;
            }
            window.medicalDeclarationCompleted = true;

            // Close modal
            modal.classList.remove('active');
            modal.style.display = 'none';

            // Show status badge if present
            const medBadge = document.getElementById('medicalStatusBadgeContainer');
            if (medBadge) medBadge.style.display = 'block';

            // Trigger pending callback
            const cb = window.pendingMedicalSuccessCallback;
            window.pendingMedicalSuccessCallback = null;
            if (typeof cb === 'function') {
                cb();
            }
        };
    }
}

function showRegionSelectionUI(container, isDisabled = false) {
    container.innerHTML = `
            <div class="sequential-assessment-info" style="max-width: 580px; margin: 0 auto;">
                <div class="sequence-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 1.8rem;">
                    <i class="fa-solid fa-map-location-dot"></i>
                </div>
                <h3 style="font-size: 1.5rem; color: #f1f5f9; margin-bottom: 0.35rem; text-align: center;">Select Your Preferred School District(s)</h3>
                <p style="font-size: 0.78rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: #f59e0b; text-align: center; margin-bottom: 0.6rem;">
                    <i class="fa-solid fa-location-dot" style="margin-right:4px;"></i>Pangasinan
                </p>
                <p style="color: #94a3b8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.25rem; text-align: center;">
                    Choose the district(s) where you prefer to find schools. You may select multiple districts.
                </p>

                <!-- Select All toggle -->
                <label class="select-all-districts-label" id="selectAllDistrictsLabel" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                    <input type="checkbox" id="selectAllDistricts" ${isDisabled ? 'disabled' : ''}>
                    <span class="select-all-checkmark"><i class="fa-solid fa-check"></i></span>
                    <span class="select-all-text">Select All Districts</span>
                </label>
                
                <div class="region-options-grid">
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="1" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>1st District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="2" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>2nd District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="3" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>3rd District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="4" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>4th District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="5" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>5th District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                    <label class="region-option-card" ${isDisabled ? 'style="opacity: 0.5; cursor: not-allowed;" onclick="event.preventDefault(); showLimitReachedModal();"' : ''}>
                        <input type="checkbox" name="preferred_region_option" value="6" ${isDisabled ? 'disabled' : ''}>
                        <div class="region-card-content">
                            <i class="fa-solid fa-map-pin"></i>
                            <span>6th District, Pangasinan</span>
                            <span class="district-check-icon"><i class="fa-solid fa-check"></i></span>
                        </div>
                    </label>
                </div>

                <div id="medicalStatusBadgeContainer" style="text-align: center; margin: 0.75rem 0 0.25rem; display: ${window.medicalDeclarationCompleted ? 'block' : 'none'};">
                    <button type="button" id="btnEditMedicalDeclaration" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); color: #f59e0b; padding: 0.45rem 1rem; border-radius: 20px; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease;">
                        <i class="fa-solid fa-notes-medical"></i>
                        <span>Medical Considerations Declared</span>
                        <span style="color: #94a3b8; font-size: 0.78rem; text-decoration: underline;">(Review / Edit)</span>
                    </button>
                </div>
                
                <button class="start-assessment-btn" id="btnProceedToAssessment" style="width: 100%; margin-top: 1.25rem;" disabled>
                    <i class="fa-solid fa-play"></i>
                    Start Assessment
                </button>
            </div>
        `;

    // Add styling for region selection cards
    if (!document.getElementById('region-selection-styles')) {
        const styles = document.createElement('style');
        styles.id = 'region-selection-styles';
        styles.innerHTML = `
                .region-options-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 1rem;
                    margin: 1rem 0;
                }
                .region-option-card {
                    cursor: pointer;
                    display: block;
                }
                .region-option-card input[type="checkbox"] {
                    display: none;
                }
                .region-card-content {
                    padding: 1.25rem 1rem;
                    background: rgba(30, 41, 59, 0.4);
                    border: 1px solid rgba(255, 255, 255, 0.08);
                    border-radius: 12px;
                    text-align: center;
                    color: #94a3b8;
                    transition: all 0.25s ease;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 0.6rem;
                    position: relative;
                }
                .region-card-content > i:first-child {
                    font-size: 1.4rem;
                }
                .region-card-content > span:not(.district-check-icon) {
                    font-size: 0.9rem;
                    font-weight: 600;
                }
                .district-check-icon {
                    display: none;
                    position: absolute;
                    top: 8px;
                    right: 10px;
                    width: 20px;
                    height: 20px;
                    background: #f59e0b;
                    border-radius: 50%;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.65rem;
                    color: #0f172a;
                    font-weight: 900;
                }
                .region-option-card:hover .region-card-content {
                    border-color: rgba(245, 158, 11, 0.4);
                    color: #f1f5f9;
                    background: rgba(30, 41, 59, 0.7);
                }
                .region-option-card input[type="checkbox"]:checked + .region-card-content {
                    border-color: #f59e0b;
                    background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(217, 119, 6, 0.12));
                    color: #f59e0b;
                    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
                }
                .region-option-card input[type="checkbox"]:checked + .region-card-content .district-check-icon {
                    display: flex;
                }

                /* Select All Districts */
                .select-all-districts-label {
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    cursor: pointer;
                    padding: 0.75rem 1rem;
                    background: rgba(245, 158, 11, 0.05);
                    border: 1px solid rgba(245, 158, 11, 0.2);
                    border-radius: 10px;
                    margin-bottom: 0.5rem;
                    transition: all 0.2s ease;
                    user-select: none;
                }
                .select-all-districts-label:hover {
                    background: rgba(245, 158, 11, 0.1);
                    border-color: rgba(245, 158, 11, 0.4);
                }
                .select-all-districts-label input[type="checkbox"] {
                    display: none;
                }
                .select-all-checkmark {
                    width: 22px;
                    height: 22px;
                    border: 2px solid rgba(245, 158, 11, 0.4);
                    border-radius: 6px;
                    background: transparent;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                    transition: all 0.2s ease;
                    color: transparent;
                    font-size: 0.7rem;
                }
                .select-all-districts-label input[type="checkbox"]:checked ~ .select-all-checkmark,
                .select-all-districts-label.all-checked .select-all-checkmark {
                    background: #f59e0b;
                    border-color: #f59e0b;
                    color: #0f172a;
                    font-weight: 900;
                }
                .select-all-text {
                    font-size: 0.9rem;
                    font-weight: 700;
                    color: #f1f5f9;
                }

                .start-assessment-btn:disabled {
                    background: rgba(71, 85, 105, 0.4) !important;
                    color: rgba(148, 163, 184, 0.6) !important;
                    border: 1px solid rgba(255, 255, 255, 0.05) !important;
                    cursor: not-allowed !important;
                    transform: none !important;
                    box-shadow: none !important;
                    opacity: 0.6;
                }
                @media (max-width: 480px) {
                    .region-options-grid {
                        grid-template-columns: 1fr;
                    }
                }
            `;
        document.head.appendChild(styles);
    }

    // Add event listener to proceed button
    const proceedBtn = document.getElementById('btnProceedToAssessment');
    const selectAllChk = document.getElementById('selectAllDistricts');
    const selectAllLabel = document.getElementById('selectAllDistrictsLabel');
    const checkboxes = container.querySelectorAll('input[name="preferred_region_option"]');
    const regionCards = container.querySelectorAll('.region-option-card');
    const btnEditMed = container.querySelector('#btnEditMedicalDeclaration');

    function syncProceedBtn() {
        const anyRegionChecked = Array.from(checkboxes).some(cb => cb.checked);
        const isMedValid = !!(window.medicalDeclarationCompleted && assessmentState.medicalDeclaration && assessmentState.medicalDeclaration.acknowledged);

        if (proceedBtn) {
            if (anyRegionChecked && isMedValid) {
                proceedBtn.removeAttribute('disabled');
            } else {
                proceedBtn.setAttribute('disabled', 'disabled');
            }
        }
        // Sync select-all visual state
        const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
        if (selectAllChk) selectAllChk.checked = allChecked;
        if (selectAllLabel) {
            if (allChecked) selectAllLabel.classList.add('all-checked');
            else selectAllLabel.classList.remove('all-checked');
        }

        // Sync medical badge visibility
        const medBadge = document.getElementById('medicalStatusBadgeContainer');
        if (medBadge) {
            medBadge.style.display = isMedValid ? 'block' : 'none';
        }
    }

    if (!isDisabled) {
        // Intercept clicks on district cards if medical declaration not completed
        regionCards.forEach(card => {
            card.addEventListener('click', function(e) {
                if (!window.medicalDeclarationCompleted) {
                    e.preventDefault();
                    e.stopPropagation();
                    const cb = card.querySelector('input[name="preferred_region_option"]');
                    if (window.openMedicalModal) {
                        window.openMedicalModal(() => {
                            if (cb) {
                                cb.checked = true;
                            }
                            syncProceedBtn();
                        });
                    }
                }
            });
        });

        // Intercept select-all click if medical declaration not completed
        if (selectAllLabel) {
            selectAllLabel.addEventListener('click', function(e) {
                if (!window.medicalDeclarationCompleted) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.openMedicalModal) {
                        window.openMedicalModal(() => {
                            if (selectAllChk) selectAllChk.checked = true;
                            checkboxes.forEach(cb => { cb.checked = true; });
                            syncProceedBtn();
                        });
                    }
                }
            });
        }
    }

    // Handle individual checkboxes change
    checkboxes.forEach(cb => {
        cb.addEventListener('change', syncProceedBtn);
    });

    // Handle Select All change
    if (selectAllChk) {
        selectAllChk.addEventListener('change', function () {
            checkboxes.forEach(cb => { cb.checked = this.checked; });
            syncProceedBtn();
        });
    }

    // Handle Edit Medical Declaration
    if (btnEditMed) {
        btnEditMed.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.openMedicalModal) {
                window.openMedicalModal(() => {
                    syncProceedBtn();
                });
            }
        });
    }

    if (proceedBtn) {
        proceedBtn.addEventListener('click', function () {
            if (!window.medicalDeclarationCompleted || !assessmentState.medicalDeclaration || !assessmentState.medicalDeclaration.acknowledged) {
                if (window.openMedicalModal) {
                    window.openMedicalModal(() => {
                        syncProceedBtn();
                    });
                }
                return;
            }

            const selectedValues = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);

            if (selectedValues.length === 0) {
                alert('Please select at least one district to proceed.');
                return;
            }

            const allSelected = selectedValues.length === checkboxes.length;
            const selectedRegion = allSelected ? 'All' : selectedValues.join(',');
            assessmentState.region = selectedRegion;
            window.savedPreferredRegion = selectedRegion;

            // Show sequence start UI
            showSequenceStartUI(container);
        });
    }
}

function showSequenceStartUI(container) {
    container.innerHTML = `
            <div class="sequential-assessment-info">
                <div class="sequence-icon">
                    <i class="fa-solid fa-route"></i>
                </div>
                <h3>Complete Assessment Journey</h3>
                <p>You will complete 4 assessments in sequence to get comprehensive career guidance:</p>
                <div class="assessment-sequence">
                    <div class="sequence-item">
                        <span class="sequence-number">1</span>
                        <div class="sequence-content">
                            <h4>Career Assessment</h4>
                            <p>Discover your ideal career path</p>
                        </div>
                    </div>
                    <div class="sequence-item">
                        <span class="sequence-number">2</span>
                        <div class="sequence-content">
                            <h4>Personality Assessment</h4>
                            <p>Understand your work personality</p>
                        </div>
                    </div>
                    <div class="sequence-item">
                        <span class="sequence-number">3</span>
                        <div class="sequence-content">
                            <h4>Skills Assessment</h4>
                            <p>Evaluate your current abilities</p>
                        </div>
                    </div>
                    <div class="sequence-item">
                        <span class="sequence-number">4</span>
                        <div class="sequence-content">
                            <h4>Strand Assessment</h4>
                            <p>Find your academic path</p>
                        </div>
                    </div>
                </div>
                <div class="region-display" style="margin: 1.25rem 0; padding: 0.75rem 1rem; background: rgba(245, 158, 11, 0.06); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-map-location-dot" style="color: #f59e0b;"></i>
                    <span style="font-size: 0.85rem; color: #94a3b8; font-weight: 500;">
                        Target Region: <strong style="color: #f1f5f9; font-weight: 700;">${assessmentState.region === 'All' ? 'All Regions' : assessmentState.region + ' Region'}</strong>
                    </span>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem; width: 100%;">
                    <button class="btn-back" id="btnBackToRegion" style="flex: 1; padding: 0.75rem 1rem; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; color: #cbd5e1; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i class="fa-solid fa-arrow-left"></i>
                        Change Region
                    </button>
                    <button class="start-assessment-btn" onclick="startSequentialAssessment()" style="flex: 2; margin-top: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i class="fa-solid fa-play"></i>
                        Start Journey
                    </button>
                </div>
            </div>
        `;

    // Handle Change Region back button
    const backToRegionBtn = container.querySelector('#btnBackToRegion');
    if (backToRegionBtn) {
        // Apply quick hover styling
        backToRegionBtn.addEventListener('mouseenter', () => {
            backToRegionBtn.style.background = 'rgba(255, 255, 255, 0.08)';
            backToRegionBtn.style.borderColor = 'rgba(255, 255, 255, 0.15)';
            backToRegionBtn.style.color = '#fff';
        });
        backToRegionBtn.addEventListener('mouseleave', () => {
            backToRegionBtn.style.background = 'rgba(255, 255, 255, 0.04)';
            backToRegionBtn.style.borderColor = 'rgba(255, 255, 255, 0.08)';
            backToRegionBtn.style.color = '#cbd5e1';
        });
        backToRegionBtn.addEventListener('click', function () {
            showRegionSelectionUI(container);
        });
    }
}

// Initialize event listeners when DOM is loaded
function initializeEvents() {
    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');

    if (btnBack) btnBack.addEventListener('click', previousQuestion);
    if (btnNext) btnNext.addEventListener('click', nextQuestion);
    if (btnSubmit) btnSubmit.addEventListener('click', submitAssessment);

    // Add event listeners to results buttons
    const btnViewResults = document.getElementById('btnViewResults');
    const btnTakeAnother = document.getElementById('btnTakeAnother');

    if (btnViewResults) btnViewResults.addEventListener('click', viewResults);
    if (btnTakeAnother) btnTakeAnother.addEventListener('click', takeAnotherAssessment);
}

// Call initializeEvents inside document DOMContentLoaded if script is loaded
initializeEvents();

// Start sequential assessment
async function startSequentialAssessment() {
    if (!assessmentState.region) {
        alert('Please select your region before starting the assessment.');
        return;
    }

    assessmentState.currentAssessmentIndex = 0;
    assessmentState.currentType = assessmentState.assessmentSequence[0];
    assessmentState.currentQuestion = 0;
    assessmentState.answers = {};

    // Start assessment on server (if function exists from take_assessment.php)
    if (typeof startAssessmentOnServer === 'function') {
        const result = await startAssessmentOnServer(assessmentState.currentType, assessmentState.region, assessmentState.medicalDeclaration);
        if (result && result.assessment_id) {
            assessmentState.assessment_id = result.assessment_id;
        }
    }

    // Get questions from DB or fallback
    assessmentState.questions = getAssessmentQuestions(assessmentState.currentType);

    // Show questions section, hide selection
    document.getElementById('assessmentTypeSelection').style.display = 'none';
    document.getElementById('assessmentQuestions').style.display = 'block';
    document.getElementById('resultsPreview').style.display = 'none';

    // Update assessment type badge
    const categoryInfo = document.getElementById('assessmentCategoryInfo');
    if (categoryInfo) {
        categoryInfo.innerHTML = `${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment &middot; ${assessmentState.currentAssessmentIndex + 1} of 4`;
    }

    // Load first question
    loadQuestion();
    updateProgress();
    updateNavigationButtons();

    // Save initial progress
    saveAssessmentProgress();
}

function resumeSequentialAssessment(progressData) {
    if (!progressData) return;
    assessmentState.currentAssessmentIndex = progressData.currentAssessmentIndex || 0;
    assessmentState.currentType = progressData.type || assessmentState.assessmentSequence[assessmentState.currentAssessmentIndex] || assessmentState.assessmentSequence[0];
    assessmentState.currentQuestion = progressData.currentQuestion || 0;
    assessmentState.answers = Object.assign({}, progressData.answers || {});
    assessmentState.assessment_id = progressData.assessmentId || null;
    assessmentState.region = progressData.region || window.savedPreferredRegion || 'All';
    assessmentState.questions = getAssessmentQuestions(assessmentState.currentType);

    // Sync saved answers cache to prevent redundant re-saving
    if (assessmentState.assessment_id && assessmentState.answers) {
        if (!assessmentState.savedAnswers) assessmentState.savedAnswers = {};
        for (const [key, val] of Object.entries(assessmentState.answers)) {
            const parts = key.split('_');
            if (parts.length >= 2) {
                const qType = parts[0];
                const qId = parts[1];
                const saveKey = `${assessmentState.assessment_id}:${qType}:${qId}`;
                assessmentState.savedAnswers[saveKey] = typeof val === 'object' ? JSON.stringify(val) : String(val);
            }
        }
    }

    // Show questions section, hide selection
    const selection = document.getElementById('assessmentTypeSelection');
    const questions = document.getElementById('assessmentQuestions');
    const preview = document.getElementById('resultsPreview');
    if (selection) selection.style.display = 'none';
    if (questions) questions.style.display = 'block';
    if (preview) preview.style.display = 'none';

    // Update assessment type badge
    const categoryInfo = document.getElementById('assessmentCategoryInfo');
    if (categoryInfo && assessmentState.currentType) {
        categoryInfo.innerHTML = `${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment &middot; ${assessmentState.currentAssessmentIndex + 1} of 4`;
    }

    loadQuestion();
    updateProgress();
    updateNavigationButtons();

    // Persist current state in local storage for this device/browser
    saveAssessmentProgress();
}

// Load current question
function loadQuestion() {
    // Only check region selection if we are actually are on the assessment page
    const isAssessmentPage = document.getElementById('assessmentTypeSelection') && document.getElementById('assessmentQuestions');
    if (isAssessmentPage && !assessmentState.region) {
        if (assessmentState.assessment_id) {
            assessmentState.region = window.savedPreferredRegion || 'All';
        } else {
            // Buffer current progress so user can pick region to resume
            const progressData = {
                type: assessmentState.currentType,
                currentAssessmentIndex: assessmentState.currentAssessmentIndex,
                currentQuestion: assessmentState.currentQuestion,
                answers: assessmentState.answers,
                assessmentId: assessmentState.assessment_id,
                region: assessmentState.region,
                savedAt: new Date().toISOString()
            };
            window.pendingAssessmentProgress = progressData;

            // Show selection UI and prompt user
            const selection = document.getElementById('assessmentTypeSelection');
            const questions = document.getElementById('assessmentQuestions');
            if (selection) selection.style.display = 'block';
            if (questions) questions.style.display = 'none';
            return;
        }
    }

    const question = assessmentState.questions[assessmentState.currentQuestion];
    if (!question) return;

    // Update question text and counter
    const progressCounter = document.getElementById('questionProgressCounter');
    const questionText = document.getElementById('questionText');
    const questionOptions = document.getElementById('questionOptions');

    if (progressCounter) {
        const questionsPerAssessment = {
            'career': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['career']) ? window.assessmentQuestionsFromDB['career'].length : 30,
            'personality': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['personality']) ? window.assessmentQuestionsFromDB['personality'].length : 30,
            'skills': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['skills']) ? window.assessmentQuestionsFromDB['skills'].length : 30,
            'strand': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['strand']) ? window.assessmentQuestionsFromDB['strand'].length : 30
        };
        const totalAllQuestions = Object.values(questionsPerAssessment).reduce((a, b) => a + b, 0);
        const currentQuestionInAssessment = assessmentState.currentQuestion + 1;

        let completedFromPrevious = 0;
        const sequence = assessmentState.assessmentSequence;
        for (let i = 0; i < assessmentState.currentAssessmentIndex; i++) {
            completedFromPrevious += questionsPerAssessment[sequence[i]] || 30;
        }

        const totalCompletedQuestions = completedFromPrevious + currentQuestionInAssessment;
        progressCounter.textContent = `Question ${totalCompletedQuestions} of ${totalAllQuestions}`;
    }

    if (questionText) {
        questionText.textContent = question.question;
    }

    // Clear and populate options
    if (questionOptions) {
        questionOptions.innerHTML = '';

        // Handle text/open-ended questions
        if (question.type === 'text') {
            const textContainer = document.createElement('div');
            textContainer.className = 'text-answer-container';

            const textarea = document.createElement('textarea');
            textarea.className = 'text-answer-input';
            textarea.placeholder = 'Type your answer here...';
            textarea.rows = 6;
            textarea.id = 'textAnswer';

            // Restore previous answer if exists
            const answerKey = `${assessmentState.currentType}_${question.id}`;
            if (assessmentState.answers[answerKey]) {
                textarea.value = assessmentState.answers[answerKey];
            }

            // Auto-save on input
            textarea.addEventListener('input', function () {
                assessmentState.answers[answerKey] = this.value;
                updateNavigationButtons();
                saveAssessmentProgress();
            });

            textContainer.appendChild(textarea);
            questionOptions.appendChild(textContainer);
        } else if (question.type === 'likert') {
            // Handle Likert scale questions
            const likertContainer = document.createElement('div');
            likertContainer.className = 'likert-container';

            const likertLabelsContainer = document.createElement('div');
            likertLabelsContainer.className = 'likert-labels';
            likertLabelsContainer.innerHTML = `
                    <span>Strongly Disagree</span>
                    <span>Disagree</span>
                    <span>Agree</span>
                    <span>Strongly Agree</span>
                `;
            likertContainer.appendChild(likertLabelsContainer);

            const likertOptions = document.createElement('div');
            likertOptions.className = 'likert-options';

            const likertLabels = ['A', 'B', 'C', 'D'];
            likertLabels.forEach((labelText, index) => {
                const optionItem = document.createElement('div');
                optionItem.className = 'likert-item';

                const input = document.createElement('input');
                input.type = 'radio';
                input.name = `likert_${question.id}`;
                input.id = `likert_${question.id}_${labelText}`;
                input.value = (index + 1).toString();

                // Restore previous answer if exists
                if (String(assessmentState.answers[question.id]) === input.value) {
                    input.checked = true;
                    optionItem.classList.add('selected');
                }

                const label = document.createElement('label');
                label.className = 'likert-label';
                label.htmlFor = `likert_${question.id}_${labelText}`;
                label.textContent = labelText;

                optionItem.appendChild(input);
                optionItem.appendChild(label);

                // Restore previous answer if exists
                const answerKey = `${assessmentState.currentType}_${question.id}`;
                if (String(assessmentState.answers[answerKey]) === input.value) {
                    input.checked = true;
                    optionItem.classList.add('selected');
                }

                // Add click event
                optionItem.addEventListener('click', function () {
                    // Remove selected from all likert items for this question
                    document.querySelectorAll(`input[name="likert_${question.id}"]`).forEach(inp => {
                        inp.closest('.likert-item').classList.remove('selected');
                    });

                    // Add selected to clicked item
                    optionItem.classList.add('selected');
                    input.checked = true;

                    // Save answer as numeric Likert score
                    assessmentState.answers[answerKey] = input.value;

                    // Update navigation
                    updateNavigationButtons();
                    saveAssessmentProgress();
                });

                likertOptions.appendChild(optionItem);
            });

            likertContainer.appendChild(likertOptions);
            questionOptions.appendChild(likertContainer);
        } else if (Array.isArray(question.options)) {
            // Handle radio/checkbox questions
            question.options.forEach((option, index) => {
                const optionItem = document.createElement('div');
                optionItem.className = 'option-item';

                const input = document.createElement('input');
                input.type = question.type;
                input.name = 'question';
                input.id = `option_${index}`;
                input.value = option;

                const answerKey = `${assessmentState.currentType}_${question.id}`;
                if (assessmentState.answers[answerKey]) {
                    const currentAns = assessmentState.answers[answerKey];
                    let ansCompare = currentAns;
                    if (typeof currentAns === 'object' && currentAns !== null) {
                        ansCompare = currentAns.option_id ?? currentAns.text ?? currentAns.label ?? '';
                    }
                    const isOptionMatch = (ansCompare === option) ||
                        (question.option_map && question.option_map[index] && (
                            String(question.option_map[index].id) === String(ansCompare) ||
                            question.option_map[index].text === ansCompare ||
                            question.option_map[index].label === ansCompare
                        ));
                    if (question.type === 'radio' && isOptionMatch) {
                        input.checked = true;
                        optionItem.classList.add('selected');
                    } else if (question.type === 'checkbox' && (Array.isArray(currentAns) ? currentAns.includes(option) : isOptionMatch)) {
                        input.checked = true;
                        optionItem.classList.add('selected');
                    }
                }

                const label = document.createElement('label');
                label.className = 'option-label';
                label.htmlFor = `option_${index}`;
                label.textContent = option;

                if (question.option_map && question.option_map[index] && question.option_map[index].label) {
                    const badge = document.createElement('span');
                    badge.className = 'option-letter-badge';
                    badge.textContent = question.option_map[index].label;
                    optionItem.appendChild(badge);
                }

                optionItem.appendChild(input);
                optionItem.appendChild(label);

                // Add click event to option item
                optionItem.addEventListener('click', function () {
                    selectOption(this, question.type);
                });

                questionOptions.appendChild(optionItem);
            });
        }
    }

    // Update progress
    updateProgress();

    // Update navigation buttons
    updateNavigationButtons();
}

// Select option
function selectOption(optionElement, questionType) {
    const question = assessmentState.questions[assessmentState.currentQuestion];
    const input = optionElement.querySelector('input');
    const option = input.value;

    if (questionType === 'radio') {
        // Remove selected class from all options
        document.querySelectorAll('.option-item').forEach(item => {
            item.classList.remove('selected');
        });

        // Add selected class to clicked option
        optionElement.classList.add('selected');
        input.checked = true;

        // Save answer
        const answerKey = `${assessmentState.currentType}_${question.id}`;
        assessmentState.answers[answerKey] = option;
    } else if (questionType === 'checkbox') {
        // Toggle selected class
        optionElement.classList.toggle('selected');
        input.checked = !input.checked;

        // Save answer
        const answerKey = `${assessmentState.currentType}_${question.id}`;
        if (!assessmentState.answers[answerKey]) {
            assessmentState.answers[answerKey] = [];
        }

        if (input.checked) {
            assessmentState.answers[answerKey].push(option);
        } else {
            const index = assessmentState.answers[answerKey].indexOf(option);
            if (index > -1) {
                assessmentState.answers[answerKey].splice(index, 1);
            }
        }
    }

    // Update next button state
    updateNavigationButtons();

    // Auto-save
    saveAssessmentProgress();
}

// Update progress bar
function updateProgress() {
    const currentStep = document.getElementById('currentStep');
    const totalSteps = document.getElementById('totalSteps');
    const progressFill = document.getElementById('progressFill');

    // Total questions per assessment type (dynamically resolved from loaded DB questions)
    const questionsPerAssessment = {
        'career': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['career']) ? window.assessmentQuestionsFromDB['career'].length : 30,
        'personality': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['personality']) ? window.assessmentQuestionsFromDB['personality'].length : 30,
        'skills': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['skills']) ? window.assessmentQuestionsFromDB['skills'].length : 30,
        'strand': (window.assessmentQuestionsFromDB && window.assessmentQuestionsFromDB['strand']) ? window.assessmentQuestionsFromDB['strand'].length : 30
    };

    // Calculate total questions across all assessments
    const totalAllQuestions = Object.values(questionsPerAssessment).reduce((a, b) => a + b, 0);

    if (currentStep) {
        const currentQuestionInAssessment = assessmentState.currentQuestion + 1;

        // Calculate completed questions from previous assessments
        let completedFromPrevious = 0;
        const sequence = assessmentState.assessmentSequence;
        for (let i = 0; i < assessmentState.currentAssessmentIndex; i++) {
            completedFromPrevious += questionsPerAssessment[sequence[i]] || 30;
        }

        const totalCompletedQuestions = completedFromPrevious + currentQuestionInAssessment;
        currentStep.textContent = `${totalCompletedQuestions}/${totalAllQuestions}`;
    }

    if (totalSteps) {
        totalSteps.textContent = totalAllQuestions.toString();
    }

    if (progressFill) {
        const currentQuestionInAssessment = assessmentState.currentQuestion + 1;

        // Calculate completed questions from previous assessments
        let completedFromPrevious = 0;
        const sequence = assessmentState.assessmentSequence;
        for (let i = 0; i < assessmentState.currentAssessmentIndex; i++) {
            completedFromPrevious += questionsPerAssessment[sequence[i]] || 30;
        }

        const totalCompletedQuestions = completedFromPrevious + currentQuestionInAssessment;
        const progress = (totalCompletedQuestions / totalAllQuestions) * 100;
        progressFill.style.width = `${progress}%`;
    }
}

// Update navigation buttons
function updateNavigationButtons() {
    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');
    const currentQuestion = assessmentState.questions[assessmentState.currentQuestion];
    const hasQuestion = !!currentQuestion;
    const questionId = hasQuestion ? currentQuestion.id : null;
    const answerKey = `${assessmentState.currentType}_${questionId}`;
    const hasAnswer = questionId ? (assessmentState.answers[answerKey] !== undefined && (Array.isArray(assessmentState.answers[answerKey]) ? assessmentState.answers[answerKey].length > 0 : assessmentState.answers[answerKey] !== '')) : false;
    const isLastQuestion = hasQuestion && assessmentState.currentQuestion === assessmentState.questions.length - 1;
    const showNavigation = hasQuestion && assessmentState.questions.length > 0;

    const canGoBack = assessmentState.currentQuestion > 0 || assessmentState.currentAssessmentIndex > 0;
    if (btnBack) {
        btnBack.style.display = showNavigation && canGoBack ? 'flex' : 'none';
    }

    if (btnNext) {
        btnNext.style.display = showNavigation && !isLastQuestion ? 'flex' : 'none';
        btnNext.disabled = !hasAnswer;
    }

    if (btnSubmit) {
        btnSubmit.style.display = showNavigation && isLastQuestion ? 'flex' : 'none';
        btnSubmit.disabled = !hasAnswer;
    }
}

// Previous question
function previousQuestion() {
    if (assessmentState.currentQuestion > 0) {
        saveAssessmentProgress();
        assessmentState.currentQuestion--;
        loadQuestion();
        saveAssessmentProgress();
    } else if (assessmentState.currentAssessmentIndex > 0) {
        saveAssessmentProgress();
        assessmentState.currentAssessmentIndex--;
        assessmentState.currentType = assessmentState.assessmentSequence[assessmentState.currentAssessmentIndex];
        assessmentState.questions = getAssessmentQuestions(assessmentState.currentType);
        assessmentState.currentQuestion = Math.max(0, assessmentState.questions.length - 1);

        const categoryInfo = document.getElementById('assessmentCategoryInfo');
        if (categoryInfo && assessmentState.currentType) {
            categoryInfo.innerHTML = `${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment &middot; ${assessmentState.currentAssessmentIndex + 1} of 4`;
        }

        loadQuestion();
        updateProgress();
        saveAssessmentProgress();
    }
}

// Validate open-ended answer
function validateOpenEnded(answer) {
    if (!answer) return false;
    answer = answer.trim();
    if (answer.length < 10) return false;
    
    // Check for single repeated character (e.g. aaaaaaa)
    if (/^(.)\1+$/.test(answer)) return false;
    
    // Check for no vowels or spaces at all (indicative of keyboard mash)
    if (/^[^aeiou\s]{10,}$/i.test(answer)) return false;
    
    // Check for common keyboard mash patterns
    if (/(asdf|qwerty|zxcv|asdasd|ashdasdasd)/i.test(answer)) return false;
    if (/^(asd|qwe|zxc|fgh|jkl)+$/i.test(answer)) return false;
    
    return true;
}

// Next question
function nextQuestion() {
    const currentQuestion = assessmentState.questions[assessmentState.currentQuestion];
    if (currentQuestion && currentQuestion.type === 'text') {
        const answerKey = `${assessmentState.currentType}_${currentQuestion.id}`;
        if (!validateOpenEnded(assessmentState.answers[answerKey])) {
            alert("Please provide a meaningful response to continue.");
            return;
        }
    }

    if (assessmentState.currentQuestion < assessmentState.questions.length - 1) {
        saveAssessmentProgress();
        assessmentState.currentQuestion++;
        loadQuestion();
        saveAssessmentProgress();
    }
}

// Submit assessment
function submitAssessment() {
    const currentQuestion = assessmentState.questions[assessmentState.currentQuestion];
    if (currentQuestion && currentQuestion.type === 'text') {
        const answerKey = `${assessmentState.currentType}_${currentQuestion.id}`;
        if (!validateOpenEnded(assessmentState.answers[answerKey])) {
            alert("Please provide a meaningful response to continue.");
            return;
        }
    }

    saveAssessmentProgress();
    // Check if there are more assessments in the sequence
    if (assessmentState.currentAssessmentIndex < assessmentState.assessmentSequence.length - 1) {
        // Move to next assessment (UI only)
        assessmentState.currentAssessmentIndex++;
        assessmentState.currentType = assessmentState.assessmentSequence[assessmentState.currentAssessmentIndex];
        assessmentState.currentQuestion = 0;
        assessmentState.questions = getAssessmentQuestions(assessmentState.currentType);

        saveAssessmentProgress();

        // Show transition message
        const questionText = document.getElementById('questionText');
        const questionOptions = document.getElementById('questionOptions');

        if (questionText) {
            questionText.textContent = `Great job! You've completed the ${assessmentState.assessmentSequence[assessmentState.currentAssessmentIndex - 1].charAt(0).toUpperCase() + assessmentState.assessmentSequence[assessmentState.currentAssessmentIndex - 1].slice(1)} Assessment. Let's move on to the ${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment.`;
        }

        if (questionOptions) {
            questionOptions.innerHTML = `
                    <div class="transition-message">
                        <button class="continue-btn" onclick="continueToNextAssessment()">
                            Continue to ${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                `;
        }

        // Update badge
        const categoryInfo = document.getElementById('assessmentCategoryInfo');
        if (categoryInfo) {
            categoryInfo.innerHTML = `${assessmentState.currentType.charAt(0).toUpperCase() + assessmentState.currentType.slice(1)} Assessment &middot; ${assessmentState.currentAssessmentIndex + 1} of 4`;
        }

        // Update progress
        updateProgress();

        // Hide navigation buttons during transition
        const btnBack = document.getElementById('btnBack');
        const btnNext = document.getElementById('btnNext');
        const btnSubmit = document.getElementById('btnSubmit');

        if (btnBack) btnBack.style.display = 'none';
        if (btnNext) btnNext.style.display = 'none';
        if (btnSubmit) btnSubmit.style.display = 'none';

    } else {
        // All assessments completed - submit to PHP
        submitAllToPHP();
    }
}

// Submit all answers to PHP backend
async function submitAllToPHP() {
    if (!assessmentState.assessment_id) {
        alert('Error: Assessment ID is missing');
        return;
    }

    // First, save all remaining answers
    for (const question of assessmentState.questions) {
        const answerKey = `${assessmentState.currentType}_${question.id}`;
        if (assessmentState.answers[answerKey] !== undefined) {
            let ans = assessmentState.answers[answerKey];
            let score = 0;

            // Calculate score for objective (multiple choice) questions with options mapped
            if (question.option_map) {
                const opt = question.option_map.find(o => o.text === ans || o.label === ans || String(o.id) === String(ans));
                if (opt) {
                    ans = JSON.stringify({ option_id: opt.id });
                    score = opt.is_correct ? 1.0 : 0.0;
                }
            } else if (question.type === 'likert') {
                // For Likert scale questions, normalize 1-5 to 0-1 score
                const likertValue = parseInt(ans);
                if (!isNaN(likertValue) && likertValue >= 1 && likertValue <= 5) {
                    score = likertValue / 5;
                }
            }

            await saveAnswerToServer(
                question.id,
                ans,
                assessmentState.currentType,
                score
            );
        }
    }

    try {
        // Complete the assessment
        const formData = new FormData();
        formData.append('action', 'complete');
        formData.append('assessment_id', assessmentState.assessment_id);

        const response = await fetch('api/assessment.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Reset assessmentState and clear localStorage
            clearSavedAssessmentProgress();

            // Show results preview
            document.getElementById('assessmentQuestions').style.display = 'none';
            document.getElementById('resultsPreview').style.display = 'block';
        } else {
            alert(data.message || 'Failed to submit assessment');
        }
    } catch (error) {
        console.error('Error submitting assessment:', error);
        alert('Error submitting assessment. Please try again.');
    }
}

// Continue to next assessment
function continueToNextAssessment() {
    loadQuestion();
    saveAssessmentProgress();
}

// Save answer to server
async function saveAnswerToServer(questionId, answer, questionType, score = 0) {
    if (!assessmentState.assessment_id) return;

    const payload = typeof answer === 'object' ? JSON.stringify(answer) : String(answer ?? '');
    const saveKey = `${assessmentState.assessment_id}:${questionType}:${questionId}`;
    const previousPayload = assessmentState.savedAnswers?.[saveKey];

    if (previousPayload === payload) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'save_answer');
        formData.append('assessment_id', assessmentState.assessment_id);
        formData.append('question_id', questionId);
        formData.append('question_type', questionType);
        formData.append('answer', payload);
        formData.append('score', score);

        const response = await fetch('api/assessment.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            assessmentState.savedAnswers[saveKey] = payload;
        } else {
            console.error('Failed to save answer:', data.message);
            if (data.invalid_assessment) {
                clearSavedAssessmentProgress();
                alert('Your assessment session has expired due to inactivity. Returning to the start screen.');
                window.location.reload();
            }
        }
    } catch (error) {
        console.error('Error saving answer:', error);
    }
}

// Save assessment progress
function saveAssessmentProgress() {
    if (!assessmentState.currentType) return;
    const progressData = {
        type: assessmentState.currentType,
        currentAssessmentIndex: assessmentState.currentAssessmentIndex,
        currentQuestion: assessmentState.currentQuestion,
        answers: assessmentState.answers,
        assessmentId: assessmentState.assessment_id,
        region: assessmentState.region,
        savedAt: new Date().toISOString()
    };

    localStorage.setItem('assessment_progress', JSON.stringify(progressData));

    // Save all answers to server if possible
    if (assessmentState.assessment_id && assessmentState.answers) {
        // For simplicity, save current question's answer first
        const currentQuestion = assessmentState.questions[assessmentState.currentQuestion];
        if (currentQuestion) {
            const answerKey = `${assessmentState.currentType}_${currentQuestion.id}`;
            if (assessmentState.answers[answerKey] !== undefined) {
                let ans = assessmentState.answers[answerKey];
                let score = 0;

                // If this is an objective (multiple choice) question with options mapped
                if (currentQuestion.option_map) {
                    const opt = currentQuestion.option_map.find(o => o.text === ans || o.label === ans || String(o.id) === String(ans));
                    if (opt) {
                        ans = JSON.stringify({ option_id: opt.id });
                        score = opt.is_correct ? 1.0 : 0.0;
                    }
                } else if (currentQuestion.type === 'likert') {
                    // For Likert scale questions, normalize 1-5 to 0-1 score
                    const likertValue = parseInt(ans);
                    if (!isNaN(likertValue) && likertValue >= 1 && likertValue <= 5) {
                        score = likertValue / 5;
                    }
                }

                saveAnswerToServer(
                    currentQuestion.id,
                    ans,
                    assessmentState.currentType,
                    score
                );
            }
        }
    }
}

async function validateStoredAssessment(assessmentId) {
    if (!assessmentId) {
        return false;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'validate_assessment');
        formData.append('assessment_id', assessmentId);

        const response = await fetch('api/assessment.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        return data.success === true;
    } catch (error) {
        console.error('Error validating assessment:', error);
        return false;
    }
}

function clearSavedAssessmentProgress() {
    localStorage.removeItem('assessment_progress');
    window.pendingAssessmentProgress = null;
    assessmentState = {
        currentType: null,
        currentQuestion: 0,
        answers: {},
        questions: [],
        assessmentSequence: ['career', 'personality', 'skills', 'strand'],
        currentAssessmentIndex: 0,
        savedAnswers: {},
        region: ''
    };
}

// Load assessment progress
async function loadAssessmentProgress() {
    // 1. If serverActiveAssessment exists from MySQL database:
    if (window.serverActiveAssessment && window.serverActiveAssessment.assessmentId) {
        let progressData = Object.assign({}, window.serverActiveAssessment);
        try {
            const local = JSON.parse(localStorage.getItem('assessment_progress') || '{}');
            if (local && String(local.assessmentId) === String(progressData.assessmentId) && local.answers) {
                progressData.answers = Object.assign({}, local.answers, progressData.answers);
            }
        } catch (e) {}

        resumeSequentialAssessment(progressData);
        return;
    }

    // 2. Fallback to localStorage (for offline/cache)
    const savedProgress = localStorage.getItem('assessment_progress');
    if (savedProgress) {
        let progressData;
        try {
            progressData = JSON.parse(savedProgress);
        } catch (e) {
            localStorage.removeItem('assessment_progress');
            return;
        }

        // Check if progress is recent (within 24 hours)
        const savedTime = new Date(progressData.savedAt);
        const now = new Date();
        const hoursDiff = (now - savedTime) / (1000 * 60 * 60);

        if (hoursDiff < 24) {
            const hasType = !!progressData.type;
            const hasAssessmentId = !!progressData.assessmentId;
            const hasAnswers = progressData.answers && Object.keys(progressData.answers).length > 0;
            const hasMoved = (progressData.currentQuestion || 0) > 0 || (progressData.currentAssessmentIndex || 0) > 0;

            if (hasType && (hasAssessmentId || hasAnswers || hasMoved)) {
                if (hasAssessmentId) {
                    const isValid = await validateStoredAssessment(progressData.assessmentId);
                    if (!isValid) {
                        clearSavedAssessmentProgress();
                        const selection = document.getElementById('assessmentTypeSelection');
                        const questions = document.getElementById('assessmentQuestions');
                        const preview = document.getElementById('resultsPreview');
                        if (selection) selection.style.display = 'block';
                        if (questions) questions.style.display = 'none';
                        if (preview) preview.style.display = 'none';

                        const assessmentTypes = document.querySelector('.assessment-types');
                        if (assessmentTypes) {
                            const preferredRegion = window.savedPreferredRegion || '';
                            if (preferredRegion) {
                                assessmentState.region = preferredRegion;
                                showSequenceStartUI(assessmentTypes);
                            } else {
                                showRegionSelectionUI(assessmentTypes);
                            }
                        }
                        return;
                    }
                }

                if (!progressData.region) {
                    progressData.region = window.savedPreferredRegion || 'All';
                }

                // Immediately resume without prompting user
                resumeSequentialAssessment(progressData);
                return;
            }

            // Otherwise clear stale/empty progress
            localStorage.removeItem('assessment_progress');
            return;
        }

        // Clear old progress
        localStorage.removeItem('assessment_progress');
    }
}

// View results
function viewResults() {
    window.location.href = 'assessment_results.php';
}

// Take another assessment
function takeAnotherAssessment() {
    // Reset state
    assessmentState = {
        currentType: null,
        currentQuestion: 0,
        answers: {},
        questions: []
    };

    localStorage.removeItem('assessment_progress');

    // Show selection screen
    document.getElementById('assessmentTypeSelection').style.display = 'block';
    document.getElementById('assessmentQuestions').style.display = 'none';
    document.getElementById('resultsPreview').style.display = 'none';
}

// Initialize assessment if element exists
if (document.getElementById('assessmentTypeSelection')) {
    initializeAssessment();

    // Attempt to restore any saved progress (so Back/navigation returns to current question)
    (async () => {
        try { await loadAssessmentProgress(); } catch (e) { console.debug('No saved assessment progress.'); }
    })();

    // Save progress when leaving the page (refresh, close, or navigation)
    window.addEventListener('beforeunload', function () {
        saveAssessmentProgress();
    });

    // Save progress before clicking sidebar links
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        link.addEventListener('click', function () {
            saveAssessmentProgress();
        });
    });
    // When page is restored from bfcache or navigated back, resume progress
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            try { loadAssessmentProgress(); } catch (e) { /* ignore */ }
        }
    });
}

// ==================== ADMIN LOGIN FUNCTIONALITY ====================

// Toggle admin password visibility
function toggleAdminPassword() {
    const passwordInput = document.getElementById('adminPassword');
    const passwordIcon = document.getElementById('adminPasswordIcon');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}

// Initialize admin login form
function initializeAdminLogin() {
    const adminLoginForm = document.getElementById('adminLoginForm');
    if (!adminLoginForm) return; // Only run on admin login page

    // Add input focus effects
    const inputs = adminLoginForm.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function () {
            this.parentElement.classList.add('focus-within');
        });

        input.addEventListener('blur', function () {
            this.parentElement.classList.remove('focus-within');
        });
    });

    // Note: Form submission is handled by PHP backend (admin_login.php)
}

// Handle admin login submission
function handleAdminLogin(e) {
    // Get form values
    const email = document.getElementById('adminEmail')?.value.trim() || '';
    const password = document.getElementById('adminPassword')?.value || '';

    let hasError = false;

    // Validate email
    if (!email) {
        const usernameError = document.getElementById('usernameError');
        if (usernameError) {
            usernameError.textContent = 'Email is required';
            usernameError.classList.add('show');
        }
        hasError = true;
    }

    // Validate password
    if (!password) {
        const passwordError = document.getElementById('passwordError');
        if (passwordError) {
            passwordError.textContent = 'Password is required';
            passwordError.classList.add('show');
        }
        hasError = true;
    }

    // If no errors, let the form submit normally to PHP
    if (!hasError) {
        // Form will submit normally to admin_login.php
        return true;
    }

    // Prevent submission only if there are errors
    e.preventDefault();
    return false;
}

// Initialize admin login if form exists
if (document.getElementById('adminLoginForm')) {
    initializeAdminLogin();
}

// ==================== ADMIN DASHBOARD FUNCTIONALITY ====================

// Initialize admin dashboard
function initializeAdminDashboard() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');

    if (!sidebar) return; // Only run on admin dashboard page

    // Toggle sidebar on mobile (using mobile menu toggle)
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function () {
            sidebar.classList.toggle('active');
        });
    }

    // Sidebar toggle button (close)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.remove('active');
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !mobileMenuToggle?.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        }
    });

    // Handle window resize
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('active');
        }
    });

    // Active nav item highlighting based on current page
    const currentPage = window.location.pathname.split('/').pop();
    const navItems = document.querySelectorAll('.nav-item');

    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'admin_dashboard.php')) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

// Initialize admin dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', function () {
    // Only run on admin dashboard page - check if we're on admin page
    if (document.querySelector('.nav-item[href="admin_dashboard.php"]')) {
        initializeAdminDashboard();
    }
});

// ==================== SIGNUP FORM FUNCTIONALITY ====================

let signupCurrentStep = 1;
const signupTotalSteps = 4;

// Password Toggle - Show eye icon only when typing
function initializePasswordToggles() {
    const passwordInputs = document.querySelectorAll('input[type="password"], input[type="text"][id*="password"], input[type="text"][id*="Password"]');

    passwordInputs.forEach(input => {
        const parent = input.parentElement;
        const toggleBtn = parent.querySelector('.toggle-password');

        if (!toggleBtn) return;

        // Show/hide based on input value
        const updateVisibility = () => {
            if (input.value.length > 0) {
                toggleBtn.classList.add('visible');
            } else {
                toggleBtn.classList.remove('visible');
            }
        };

        // Check on input
        input.addEventListener('input', updateVisibility);

        // Initial check (in case of browser autofill)
        setTimeout(updateVisibility, 100);
    });
}

// Initialize signup when DOM is loaded
function initializeSignup() {
    const signupForm = document.getElementById('signupForm');
    if (!signupForm) return; // Only run on signup page

    initializeBirthdateRestriction();
    initializeGradeStrandFilter();
    initializePasswordToggles();
    initializeSignupValidation();
    initializePasswordStrength();
    initializeSchoolIdValidation();

    signupForm.addEventListener('submit', handleSignupSubmit);
    loadSignupSavedData();
    updateSignupProgress();
}

// Birthdate - restrict future dates
function initializeBirthdateRestriction() {
    const birthdateInput = document.getElementById('birthdate');
    if (birthdateInput) {
        const today = new Date().toISOString().split('T')[0];
        birthdateInput.setAttribute('max', today);

        // Prevent typing/selecting future dates
        birthdateInput.addEventListener('change', function () {
            validateBirthdate(this);
        });

        birthdateInput.addEventListener('blur', function () {
            if (this.value) {
                validateBirthdate(this);
            }
        });
    }
}

function validateBirthdate(input) {
    const selectedDate = new Date(input.value);
    const currentDate = new Date();
    currentDate.setHours(0, 0, 0, 0);
    const today = new Date().toISOString().split('T')[0];
    const validationIcon = input.parentElement?.querySelector('.validation-icon');

    if (selectedDate > currentDate) {
        input.value = '';
        input.style.borderColor = '#ef4444';
        if (validationIcon) {
            validationIcon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            validationIcon.classList.add('invalid');
            validationIcon.classList.remove('valid');
        }
        return false;
    } else {
        input.style.borderColor = '#22c55e';
        if (validationIcon) {
            validationIcon.innerHTML = '<i class="fa-solid fa-check"></i>';
            validationIcon.classList.add('valid');
            validationIcon.classList.remove('invalid');
        }
        return true;
    }
}

// Grade Level - Strand filtering
function initializeGradeStrandFilter() {
    const gradeLevel = document.getElementById('gradeLevel');
    const strand = document.getElementById('strand');

    if (!gradeLevel || !strand) return;

    gradeLevel.addEventListener('change', function () {
        const selectedGrade = this.value;
        const strandOptions = strand.querySelectorAll('option');

        // Reset strand selection
        strand.value = '';

        strandOptions.forEach(option => {
            if (option.value === '') return; // Keep default option

            const isGrade11 = option.classList.contains('grade11-only');
            const isGrade12 = option.classList.contains('grade12-only');

            if (selectedGrade === 'grade11' && isGrade11) {
                option.style.display = 'block';
            } else if (selectedGrade === 'grade12' && isGrade12) {
                option.style.display = 'block';
            } else if (!isGrade11 && !isGrade12) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    });
}

// Initialize signup on DOM ready
document.addEventListener('DOMContentLoaded', function () {
    initializeSignup();
});

// Step Navigation
function nextStep() {
    if (validateSignupCurrentStep()) {
        if (signupCurrentStep < signupTotalSteps) {
            saveSignupFormData();
            signupCurrentStep++;
            showSignupStep(signupCurrentStep);
            updateSignupProgress();
        }
    }
}

function prevStep() {
    if (signupCurrentStep > 1) {
        signupCurrentStep--;
        showSignupStep(signupCurrentStep);
        updateSignupProgress();
    }
}

// Expose functions globally for HTML onclick handlers
window.nextStep = nextStep;
window.prevStep = prevStep;

function showSignupStep(step) {
    document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
    document.querySelector(`.form-step[data-step="${step}"]`)?.classList.add('active');

    document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
        indicator.classList.remove('active', 'completed');
        if (index + 1 < step) {
            indicator.classList.add('completed');
        } else if (index + 1 === step) {
            indicator.classList.add('active');
        }
    });
}

function updateSignupProgress() {
    const progressFill = document.getElementById('progressFill');
    if (progressFill) {
        const progress = (signupCurrentStep / signupTotalSteps) * 100;
        progressFill.style.width = `${progress}%`;
    }
}

// Validation
function validateSignupCurrentStep() {
    const currentStepElement = document.querySelector(`.form-step[data-step="${signupCurrentStep}"]`);
    if (!currentStepElement) return true;

    const inputs = currentStepElement.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;

    inputs.forEach(input => {
        if (!validateSignupField(input)) isValid = false;
    });

    if (signupCurrentStep === 2) {
        if (!validateEmail()) isValid = false;
        if (!validatePhone()) isValid = false;
    }

    if (signupCurrentStep === 3) {
        if (!validateGradeLevel()) isValid = false;
        if (!validateStrand()) isValid = false;
    }

    if (signupCurrentStep === 4) {
        if (!validatePasswordMatch()) isValid = false;
        if (!isPasswordStrong()) isValid = false;
    }

    return isValid;
}

function validateSignupField(field) {
    const value = field.value.trim();
    const validationIcon = field.parentElement?.querySelector('.validation-icon');

    if (!value) {
        field.style.borderColor = '#ef4444';
        if (validationIcon) {
            validationIcon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            validationIcon.classList.add('invalid');
            validationIcon.classList.remove('valid');
        }
        return false;
    } else {
        field.style.borderColor = '#22c55e';
        if (validationIcon) {
            validationIcon.innerHTML = '<i class="fa-solid fa-check"></i>';
            validationIcon.classList.add('valid');
            validationIcon.classList.remove('invalid');
        }
        return true;
    }
}

function validateEmail() {
    const email = document.getElementById('email');
    const emailError = document.getElementById('emailError');
    if (!email) return true;

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!emailRegex.test(email.value)) {
        if (emailError) {
            emailError.textContent = 'Please enter a valid email address';
            emailError.classList.add('show');
        }
        email.style.borderColor = '#ef4444';
        return false;
    } else {
        if (emailError) emailError.classList.remove('show');
        email.style.borderColor = '#22c55e';
        return true;
    }
}

function validatePhone() {
    const phone = document.getElementById('phone');
    const phoneError = document.getElementById('phoneError');
    if (!phone) return true;

    const phoneRegex = /^(\+63|0)?[\d\s-]{10,}$/;

    if (!phoneRegex.test(phone.value.replace(/\s/g, ''))) {
        if (phoneError) {
            phoneError.textContent = 'Please enter a valid phone number (e.g., 09123456789)';
            phoneError.classList.add('show');
        }
        phone.style.borderColor = '#ef4444';
        return false;
    } else {
        if (phoneError) phoneError.classList.remove('show');
        phone.style.borderColor = '#22c55e';
        return true;
    }
}

function validateGradeLevel() {
    const gradeLevel = document.getElementById('gradeLevel');
    if (!gradeLevel) return true;

    if (!gradeLevel.value) {
        gradeLevel.style.borderColor = '#ef4444';
        return false;
    } else {
        gradeLevel.style.borderColor = '#22c55e';
        return true;
    }
}

function validateStrand() {
    const strand = document.getElementById('strand');
    if (!strand) return true;

    if (!strand.value) {
        strand.style.borderColor = '#ef4444';
        return false;
    } else {
        strand.style.borderColor = '#22c55e';
        return true;
    }
}

function validatePasswordMatch() {
    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirmPassword');
    if (!confirmPassword) return true;

    if (password !== confirmPassword.value) {
        confirmPassword.style.borderColor = '#ef4444';
        return false;
    } else {
        confirmPassword.style.borderColor = '#22c55e';
        return true;
    }
}

function validatePhone() {
    const phone = document.getElementById('phone');
    const phoneError = document.getElementById('phoneError');
    if (!phone) return true;

    const phoneRegex = /^(\+63|0)?[\d\s-]{10,}$/;

    if (!phoneRegex.test(phone.value.replace(/\s/g, ''))) {
        if (phoneError) {
            phoneError.textContent = 'Please enter a valid phone number (e.g., 09123456789)';
            phoneError.classList.add('show');
        }
        phone.style.borderColor = '#ef4444';
        return false;
    } else {
        if (phoneError) phoneError.classList.remove('show');
        phone.style.borderColor = '#22c55e';
        return true;
    }
}

function validateGradeLevel() {
    const gradeLevel = document.getElementById('gradeLevel');
    if (!gradeLevel) return true;

    if (!gradeLevel.value) {
        gradeLevel.style.borderColor = '#ef4444';
        return false;
    } else {
        gradeLevel.style.borderColor = '#22c55e';
        return true;
    }
}

function validateStrand() {
    const strand = document.getElementById('strand');
    if (!strand) return true;

    if (!strand.value) {
        strand.style.borderColor = '#ef4444';
        return false;
    } else {
        strand.style.borderColor = '#22c55e';
        return true;
    }
}

function validatePasswordMatch() {
    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirmPassword');
    if (!confirmPassword) return true;

    if (password !== confirmPassword.value) {
        confirmPassword.style.borderColor = '#ef4444';
        return false;
    } else {
        confirmPassword.style.borderColor = '#22c55e';
        return true;
    }
}

function initializeSignupValidation() {
    const inputs = document.querySelectorAll('input[required], select[required], textarea[required]');

    inputs.forEach(input => {
        input.addEventListener('blur', () => {
            validateSignupField(input);
            if (input.type === 'email') validateEmail();
            if (input.id === 'phone') validatePhone();
        });

        input.addEventListener('input', () => {
            if (input.style.borderColor === 'rgb(239, 68, 68)') {
                validateSignupField(input);
            }
        });
    });

    // Add specific validation for grade level and strand
    const gradeLevel = document.getElementById('gradeLevel');
    const strand = document.getElementById('strand');

    if (gradeLevel) {
        gradeLevel.addEventListener('change', () => {
            validateGradeLevel();
        });
    }

    if (strand) {
        strand.addEventListener('change', () => {
            validateStrand();
        });
    }
}

function initializePasswordStrength() {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');

    if (password) password.addEventListener('input', updatePasswordStrength);
    if (confirmPassword) confirmPassword.addEventListener('input', validatePasswordMatch);
}

function updatePasswordStrength() {
    const password = document.getElementById('password')?.value || '';
    const strengthFill = document.getElementById('strengthFill');
    const strengthText = document.getElementById('strengthText');

    const requirements = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        lower: /[a-z]/.test(password),
        number: /\d/.test(password),
        special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
    };

    updateRequirement('req-length', requirements.length);
    updateRequirement('req-upper', requirements.upper);
    updateRequirement('req-lower', requirements.lower);
    updateRequirement('req-number', requirements.number);
    updateRequirement('req-special', requirements.special);

    const metRequirements = Object.values(requirements).filter(Boolean).length;

    if (strengthFill) {
        strengthFill.className = 'strength-fill';

        if (password.length === 0) {
            if (strengthText) strengthText.textContent = 'Password strength';
        } else if (metRequirements <= 2) {
            strengthFill.classList.add('weak');
            if (strengthText) strengthText.textContent = 'Weak password';
        } else if (metRequirements === 3) {
            strengthFill.classList.add('fair');
            if (strengthText) strengthText.textContent = 'Fair password';
        } else if (metRequirements === 4) {
            strengthFill.classList.add('good');
            if (strengthText) strengthText.textContent = 'Good password';
        } else {
            strengthFill.classList.add('strong');
            if (strengthText) strengthText.textContent = 'Strong password';
        }
    }
}

function updateRequirement(id, met) {
    const req = document.getElementById(id);
    if (!req) return;

    if (met) {
        req.classList.add('met');
        const icon = req.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-check-circle';
    } else {
        req.classList.remove('met');
        const icon = req.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-circle';
    }
}

function isPasswordStrong() {
    const password = document.getElementById('password')?.value || '';
    return password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[a-z]/.test(password) &&
        /\d/.test(password) &&
        /[!@#$%^&*(),.?":{}|<>]/.test(password);
}

// Form Data Persistence
function saveSignupFormData() {
    const formData = {};
    document.querySelectorAll('#signupForm input, #signupForm select, #signupForm textarea').forEach(input => {
        if (input.name && input.type !== 'password') {
            formData[input.name] = input.value;
        }
    });
    formData._currentStep = signupCurrentStep;
    localStorage.setItem('signupFormData', JSON.stringify(formData));
}

function loadSignupSavedData() {
    const savedData = localStorage.getItem('signupFormData');
    if (!savedData) return;

    const formData = JSON.parse(savedData);

    // Restore current step
    if (formData._currentStep) {
        signupCurrentStep = parseInt(formData._currentStep);
        showSignupStep(signupCurrentStep);
        updateSignupProgress();
    }

    Object.keys(formData).forEach(key => {
        if (key === '_currentStep') return;
        const input = document.querySelector(`[name="${key}"]`);
        if (input && input.type !== 'password') {
            input.value = formData[key];
            if (input.value) input.dispatchEvent(new Event('input'));
        }
    });
}

// School ID validation
function initializeSchoolIdValidation() {
    const schoolId = document.getElementById('schoolId');
    if (schoolId) {
        schoolId.addEventListener('input', function () {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');

            // Limit to 12 digits
            if (this.value.length > 12) {
                this.value = this.value.slice(0, 12);
            }
        });
    }
}


function clearSignupSavedData() {
    localStorage.removeItem('signupFormData');
}

// Form Submission - Let PHP handle it
function handleSignupSubmit(e) {
    if (!validateSignupCurrentStep()) {
        e.preventDefault(); // Only prevent if validation fails
        return;
    }

    // Form will submit normally to PHP
    clearSignupSavedData();
}

function showSignupSuccessMessage(email) {
    const form = document.getElementById('signupForm');
    const successMessage = document.getElementById('successMessage');
    const successEmail = document.getElementById('successEmail');

    if (form) form.style.display = 'none';
    if (successMessage) successMessage.style.display = 'block';
    if (successEmail) successEmail.textContent = email;
}

// ==================== ASSESSMENT RESULTS PAGE ====================

// Initialize Assessment Results Page
function initializeAssessmentResults() {
    // Animate score bars on page load
    animateScoreBars();

    // Animate overall match percentage
    animateOverallMatch();
}

// Animate score bars with progressive fill
function animateScoreBars() {
    const scoreBars = document.querySelectorAll('.score-fill');

    scoreBars.forEach((bar, index) => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';

        setTimeout(() => {
            bar.style.width = targetWidth;
        }, 200 + (index * 150));
    });
}

// Animate overall match percentage counter
function animateOverallMatch() {
    const percentageElement = document.querySelector('.percentage-value');
    if (!percentageElement) return;

    const targetValue = parseInt(percentageElement.textContent);
    let currentValue = 0;
    const duration = 1500;
    const increment = targetValue / (duration / 16);

    const timer = setInterval(() => {
        currentValue += increment;
        if (currentValue >= targetValue) {
            currentValue = targetValue;
            clearInterval(timer);
        }
        percentageElement.textContent = Math.floor(currentValue) + '%';
    }, 16);
}

// Initialize results page when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('.overall-match-section')) {
        initializeAssessmentResults();
    }
});

// ==================== ASSESSMENT HISTORY PAGE ====================

// Toggle history details expand/collapse
function toggleHistoryDetails(historyId) {
    const detailsElement = document.getElementById('details-' + historyId);
    const historyCard = document.querySelector(`[data-history-id="${historyId}"]`);
    const toggleButton = historyCard.querySelector('.btn-toggle-details');

    if (!detailsElement) return;

    const isExpanded = detailsElement.classList.contains('show');

    if (isExpanded) {
        // Collapse
        detailsElement.classList.remove('show');
        toggleButton.classList.remove('expanded');
        toggleButton.innerHTML = '<i class="fa-solid fa-chevron-down"></i> View Details';
        historyCard.classList.remove('expanded');
    } else {
        // Expand
        detailsElement.classList.add('show');
        toggleButton.classList.add('expanded');
        toggleButton.innerHTML = '<i class="fa-solid fa-chevron-up"></i> Hide Details';
        historyCard.classList.add('expanded');

        // Animate mini progress bars
        setTimeout(() => {
            animateMiniProgressBars(detailsElement);
        }, 100);
    }
}

// Animate mini progress bars in history details
function animateMiniProgressBars(container) {
    const progressBars = container.querySelectorAll('.score-fill-mini');

    progressBars.forEach((bar, index) => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';

        setTimeout(() => {
            bar.style.width = targetWidth;
        }, index * 100);
    });
}

// ==================== RECOMMENDED COURSES PAGE ====================
// Fetch courses from backend API
function renderRecommendedCourses() {
    const container = document.getElementById('coursesContainer');
    if (!container) return;

    container.innerHTML = '<p class="loading">Loading courses...</p>';

    // TODO: Fetch from backend
    fetch('api/courses.php')
        .then(response => response.json())
        .then(courses => {
            container.innerHTML = courses.map(course => createCourseCard(course)).join('');
        })
        .catch(error => {
            container.innerHTML = '<p class="no-results">No courses available. Please complete an assessment first.</p>';
        });
}

// Create course card HTML
function createCourseCard(course) {
    return `
            <div class="course-card" data-course-id="${course.id}">
                <div class="course-image">
                    <i class="fa-solid ${course.icon || 'fa-book'}"></i>
                </div>
                <div class="course-content">
                    <h4>${course.name}</h4>
                    <p class="course-description">${course.description}</p>
                    <div class="course-pathway">
                        <i class="fa-solid fa-road"></i>
                        <span>${course.pathway}</span>
                    </div>
                    <div class="course-match">
                        <span class="match-badge ${course.matchLevel}">${course.matchPercent}% Match</span>
                    </div>
                </div>
            </div>
        `;
}

// Initialize courses page when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('coursesContainer')) {
        renderRecommendedCourses();
    }
});

// ==================== RECOMMENDED SCHOOLS PAGE ====================

// Fetch schools from backend API
function renderRecommendedSchools() {
    const container = document.getElementById('schoolsContainer');
    if (!container) return;

    container.innerHTML = '<p class="loading">Loading schools...</p>';

    // TODO: Fetch from backend
    fetch('api/schools.php')
        .then(response => response.json())
        .then(schools => {
            container.innerHTML = schools.map(school => createSchoolCard(school)).join('');
        })
        .catch(error => {
            container.innerHTML = '<p class="no-results">No schools available. Please complete an assessment first.</p>';
        });
}

// Create school card HTML
function createSchoolCard(school) {
    const programsHtml = (school.programs || []).map(program =>
        `<span class="program-tag">${program}</span>`
    ).join('');

    return `
            <div class="school-card-large" data-school-id="${school.id}">
                <div class="school-logo">
                    <i class="fa-solid fa-university"></i>
                </div>
                <div class="school-content">
                    <h4>${school.name}</h4>
                    <div class="school-address">
                        <i class="fa-solid fa-location-dot"></i>
                        <div class="address-details">
                            <span class="address-full">${school.address}</span>
                            <span class="city">${school.city}</span>
                        </div>
                    </div>
                    <div class="school-programs">
                        ${programsHtml}
                    </div>
                    <div class="school-match">
                        <span class="match-badge ${school.matchLevel}">${school.matchPercent}% Match</span>
                    </div>
                </div>
            </div>
        `;
}

// Initialize schools page when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('schoolsContainer')) {
        renderRecommendedSchools();
    }
});

// ==================== PROFILE PAGE ====================

// Toggle between view and edit modes
function toggleEditMode() {
    const personalInfoView = document.getElementById('personalInfoView');
    const personalInfoEdit = document.getElementById('personalInfoEdit');
    const academicInfoView = document.getElementById('academicInfoView');
    const academicInfoEdit = document.getElementById('academicInfoEdit');
    const formActions = document.getElementById('formActions');
    const editButton = document.querySelector('.btn-edit-profile');

    if (!personalInfoView || !personalInfoEdit) return;

    const isEditing = personalInfoView.style.display === 'none';

    if (isEditing) {
        // Switch to view mode
        personalInfoView.style.display = 'grid';
        personalInfoEdit.style.display = 'none';
        academicInfoView.style.display = 'grid';
        academicInfoEdit.style.display = 'none';
        formActions.style.display = 'none';
        if (editButton) {
            editButton.innerHTML = '<i class="fa-solid fa-pen"></i> Edit Profile';
            editButton.style.display = 'flex';
        }
    } else {
        // Switch to edit mode - populate form with current data
        personalInfoView.style.display = 'none';
        personalInfoEdit.style.display = 'flex';
        academicInfoView.style.display = 'none';
        academicInfoEdit.style.display = 'flex';
        formActions.style.display = 'flex';
        if (editButton) {
            editButton.style.display = 'none';
        }

        // Populate form fields with current data
        const firstName = document.getElementById('firstNameView')?.textContent || '';
        const middleName = document.getElementById('middleNameView')?.textContent || '';
        const lastName = document.getElementById('lastNameView')?.textContent || '';
        const suffix = document.getElementById('suffixView')?.textContent || '';
        const birthdate = document.getElementById('birthdateView')?.textContent || '';
        const phone = document.getElementById('phoneView')?.textContent || '';
        const email = document.getElementById('emailView')?.textContent || '';
        const address = document.getElementById('addressView')?.textContent || '';

        // Set form values
        if (document.getElementById('firstName')) document.getElementById('firstName').value = firstName;
        if (document.getElementById('middleName')) document.getElementById('middleName').value = middleName !== '-' ? middleName : '';
        if (document.getElementById('lastName')) document.getElementById('lastName').value = lastName;
        if (document.getElementById('suffix')) document.getElementById('suffix').value = suffix !== '-' ? suffix : '';
        if (document.getElementById('birthdate')) document.getElementById('birthdate').value = birthdate !== 'Not provided' ? birthdate : '';
        if (document.getElementById('phone')) document.getElementById('phone').value = phone !== 'Not provided' ? phone : '';
        if (document.getElementById('email')) document.getElementById('email').value = email;
        if (document.getElementById('address')) document.getElementById('address').value = address !== 'Not provided' ? address : '';
    }
}

// Trigger avatar file input
function triggerAvatarUpload() {
    const avatarInput = document.getElementById('avatarInput');
    if (avatarInput) {
        avatarInput.click();
    }
}

// Preview and upload avatar image
async function previewAvatar(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Preview first
    const reader = new FileReader();
    reader.onload = function (e) {
        const avatar = document.getElementById('profileAvatar');
        if (avatar) {
            avatar.src = e.target.result;
        }
    };
    reader.readAsDataURL(file);

    // Now upload to backend
    try {
        const formData = new FormData();
        formData.append('action', 'upload_avatar');
        formData.append('avatar', file);

        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Update all avatar images on the page
            const allAvatars = document.querySelectorAll('.user-avatar, .profile-avatar-large, #profileAvatar');
            allAvatars.forEach(img => {
                img.src = result.avatar_url;
            });

            alert(result.message);
        } else {
            alert('Failed to upload avatar: ' + result.message);
        }
    } catch (error) {
        console.error(error);
        alert('Error uploading avatar. Please try again.');
    }
}

// Save profile changes
async function saveProfile() {
    // Get form values
    const firstName = document.getElementById('firstName')?.value || '';
    const middleName = document.getElementById('middleName')?.value || '';
    const lastName = document.getElementById('lastName')?.value || '';
    const suffix = document.getElementById('suffix')?.value || '';
    const birthdate = document.getElementById('birthdate')?.value || '';
    const phone = document.getElementById('phone')?.value || '';
    const email = document.getElementById('email')?.value || '';
    const address = document.getElementById('address')?.value || '';

    const gradeLevel = document.getElementById('gradeLevel')?.value || '';
    const strand = document.getElementById('strand')?.value || '';
    const school = document.getElementById('school')?.value || '';

    // Validate required fields
    if (!firstName || !lastName || !birthdate || !phone || !email || !address) {
        alert('Please fill in all required fields.');
        return;
    }

    // Send data to backend
    try {
        const formData = new FormData();
        formData.append('action', 'update_profile');
        formData.append('firstName', firstName);
        formData.append('middleName', middleName);
        formData.append('lastName', lastName);
        formData.append('suffix', suffix);
        formData.append('birthdate', birthdate);
        formData.append('phone', phone);
        formData.append('email', email);
        formData.append('address', address);

        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            alert('Failed to update profile: ' + result.message);
            return;
        }

        // Format birthdate for display
        const birthdateObj = new Date(birthdate);
        const formattedBirthdate = birthdateObj.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // Format grade level and strand for display
        const gradeLevelMap = {
            'grade11': 'Grade 11',
            'grade12': 'Grade 12'
        };

        const strandMap = {
            'stem': 'STEM - Science, Technology, Engineering, Mathematics',
            'abm': 'ABM - Accountancy, Business, Management',
            'humss': 'HUMSS - Humanities, Social Sciences',
            'tvl': 'TVL - Technical-Vocational-Livelihood'
        };

        // Update view elements
        const fullName = `${firstName} ${middleName} ${lastName} ${suffix}`.trim();
        document.getElementById('profileFullName').textContent = fullName;
        document.getElementById('firstNameView').textContent = firstName;
        document.getElementById('middleNameView').textContent = middleName || '-';
        document.getElementById('lastNameView').textContent = lastName;
        document.getElementById('suffixView').textContent = suffix || '-';
        document.getElementById('birthdateView').textContent = formattedBirthdate;
        document.getElementById('phoneView').textContent = phone;
        document.getElementById('emailView').textContent = email;
        document.getElementById('addressView').textContent = address;

        document.getElementById('gradeLevelView').textContent = gradeLevelMap[gradeLevel] || gradeLevel;
        document.getElementById('strandView').textContent = strandMap[strand] || strand;
        document.getElementById('schoolView').textContent = school;

        // Update top bar user info (name and avatar initials) on this page
        updateTopBarUserInfo(firstName, lastName);

        // Switch back to view mode
        toggleEditMode();

        // Show success message
        alert('Profile updated successfully!');
    } catch (error) {
        console.error(error);
        alert('Error updating profile. Please try again.');
    }
}

// Helper function to update top bar user info (name and avatar initials)
function updateTopBarUserInfo(firstName, lastName) {
    // Update user name
    const userNameEl = document.querySelector('.user-dropdown .user-name');
    if (userNameEl) {
        userNameEl.textContent = `${firstName} ${lastName}`;
    }

    // Update avatar initials (if using initials SVG)
    const avatarImg = document.querySelector('.user-avatar');
    if (avatarImg && avatarImg.src.startsWith('data:image/svg')) {
        const firstInitial = firstName ? firstName.charAt(0).toUpperCase() : 'S';
        const lastInitial = lastName ? lastName.charAt(0).toUpperCase() : '';
        const newInitials = firstInitial + lastInitial;

        const oldSrc = avatarImg.src;
        // Create new SVG with updated initials
        const newSvg = `
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
                    <defs>
                        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                            <stop stop-color="#fbbf24"/>
                            <stop offset="1" stop-color="#f59e0b"/>
                        </linearGradient>
                    </defs>
                    <circle cx="20" cy="20" r="20" fill="url(#g)"/>
                    <text x="20" y="25" text-anchor="middle" font-family="Inter,Segoe UI,Arial" font-size="16" font-weight="800" fill="#0f172a">
                        ${newInitials}
                    </text>
                </svg>
            `.trim().replace(/\s+/g, ' ');
        avatarImg.src = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(newSvg);
    }
}

// Change password function
function changePassword() {
    alert('Change password feature would open a modal or redirect to a password change page.');
}

// Deactivate account function
function deactivateAccount() {
    const confirmed = confirm('Are you sure you want to deactivate your account?\n\nThis action will:\n• Disable your account access\n• Remove your profile from active users\n• Cancel any pending assessments\n\nYou can only sign in again after an administrator reactivates your account.\n\nClick OK to proceed or Cancel to keep your account active.');

    if (confirmed) {
        const form = document.getElementById('deactivateAccountForm');
        if (form) {
            form.submit();
        } else {
            window.location.href = 'login.php?account=inactive';
        }
    }
}

// ==================== PASSWORD MANAGEMENT ====================

function getPasswordResetFrom() {
    const from = document.body?.getAttribute('data-password-reset-from');
    return from === 'admin' ? 'admin' : 'student';
}

function showPasswordResetAlert(kind, message) {
    const errorEl = document.getElementById('errorMessage');
    const successEl = document.getElementById('successMessage');
    if (errorEl) {
        errorEl.style.display = kind === 'error' ? 'block' : 'none';
        if (kind === 'error') {
            errorEl.textContent = message;
        }
    }
    if (successEl) {
        successEl.style.display = kind === 'success' ? 'block' : 'none';
        if (kind === 'success') {
            successEl.textContent = message;
        }
    }
    if (!errorEl && !successEl && message) {
        alert(message);
    }
}

async function passwordResetRequest(action, fields) {
    const formData = new FormData();
    formData.append('action', action);
    if (getPasswordResetFrom() === 'admin') {
        formData.append('from', 'admin');
    }
    Object.entries(fields || {}).forEach(([key, value]) => {
        formData.append(key, value);
    });

    const response = await fetch('api/password_reset.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
    });

    let data;
    try {
        data = await response.json();
    } catch (e) {
        throw new Error('Invalid server response. Please try again.');
    }
    return data;
}

// Forgot Password - Send OTP
document.addEventListener('DOMContentLoaded', function () {
    const forgotPasswordForm = document.getElementById('forgotPasswordForm');
    if (forgotPasswordForm) {
        forgotPasswordForm.addEventListener('submit', function (e) {
            e.preventDefault();
            sendOTP();
        });
    }
});

async function sendOTP() {
    const email = document.getElementById('recoveryEmail')?.value?.trim();
    const submitBtn = document.getElementById('sendOtpBtn');

    if (!email) {
        showPasswordResetAlert('error', 'Please enter your email address.');
        return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showPasswordResetAlert('error', 'Please enter a valid email address.');
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';
    }

    try {
        const data = await passwordResetRequest('send_otp', { email });
        if (data.success) {
            showPasswordResetAlert('success', data.message || 'If this email is registered, a verification code has been sent.');
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1200);
            }
        } else {
            showPasswordResetAlert('error', data.message || 'Could not send verification code.');
        }
    } catch (err) {
        showPasswordResetAlert('error', err.message || 'Network error. Please try again.');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send OTP';
        }
    }
}

// OTP Verification
document.addEventListener('DOMContentLoaded', function () {
    const otpForm = document.getElementById('otpForm');
    if (otpForm) {
        setupOTPInputs();
        startOTPTimer();

        const resendBtn = document.getElementById('resendBtn');
        if (resendBtn) {
            resendBtn.addEventListener('click', resendOTP);
        }

        otpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            verifyOTP();
        });
    }
});

function setupOTPInputs() {
    const inputs = document.querySelectorAll('.otp-input');

    inputs.forEach((input, index) => {
        // Handle input
        input.addEventListener('input', function (e) {
            const value = e.target.value;

            // Only allow numbers
            if (!/^\d*$/.test(value)) {
                e.target.value = '';
                return;
            }

            // Move to next input if filled
            if (value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }

            checkOTPComplete();
        });

        // Handle backspace
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                inputs[index - 1].focus();
            }
        });

        // Handle paste
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text').slice(0, inputs.length);
            const numbers = pastedData.replace(/\D/g, '').split('');

            inputs.forEach((inp, i) => {
                if (numbers[i]) {
                    inp.value = numbers[i];
                }
            });

            // Focus on appropriate input
            const filledCount = numbers.length;
            if (filledCount < inputs.length) {
                inputs[filledCount].focus();
            } else {
                inputs[inputs.length - 1].focus();
                checkOTPComplete();
            }
        });
    });
}

function checkOTPComplete() {
    const inputs = document.querySelectorAll('.otp-input');
    const otp = Array.from(inputs).map(input => input.value).join('');

    if (otp.length === 6) {
        // All digits entered
        return otp;
    }
    return null;
}

async function verifyOTP() {
    const inputs = document.querySelectorAll('.otp-input');
    const otp = Array.from(inputs).map((input) => input.value).join('');

    if (otp.length !== 6) {
        showPasswordResetAlert('error', 'Please enter the complete 6-digit code.');
        return;
    }

    const fields = {};
    inputs.forEach((input, index) => {
        fields['otp' + (index + 1)] = input.value;
    });

    const submitBtn = document.querySelector('#otpForm .btn-submit');
    if (submitBtn) {
        submitBtn.disabled = true;
    }

    try {
        const data = await passwordResetRequest('verify_otp', fields);
        if (data.success && data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        if (data.success) {
            window.location.href = getPasswordResetFrom() === 'admin'
                ? 'reset_password.php?from=admin'
                : 'reset_password.php';
            return;
        }
        showPasswordResetAlert('error', data.message || 'Invalid verification code.');
        inputs.forEach((input) => { input.value = ''; });
        inputs[0]?.focus();
    } catch (err) {
        showPasswordResetAlert('error', err.message || 'Network error. Please try again.');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }
}

let otpTimerInterval;

function startOTPTimer() {
    let seconds = 300; // 5 minutes
    const timerElement = document.getElementById('otpTimer');
    const resendBtn = document.getElementById('resendBtn');

    if (!timerElement) return;

    if (resendBtn) {
        resendBtn.disabled = true;
        resendBtn.textContent = 'Resend OTP';
    }

    clearInterval(otpTimerInterval);

    otpTimerInterval = setInterval(() => {
        seconds--;

        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        timerElement.textContent = `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;

        // Unlock the resend button after 60 seconds (when timer hits 4:00)
        if (seconds <= 240 && resendBtn && resendBtn.disabled) {
            resendBtn.disabled = false;
        }

        if (seconds <= 0) {
            clearInterval(otpTimerInterval);
            timerElement.textContent = 'Expired';
            if (resendBtn) {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend OTP';
            }
        }
    }, 1000);

}

async function resendOTP() {
    const resendBtn = document.getElementById('resendBtn');
    if (resendBtn?.disabled) {
        return;
    }

    if (resendBtn) {
        resendBtn.disabled = true;
        resendBtn.textContent = 'Sending...';
    }

    try {
        const data = await passwordResetRequest('resend_otp', {});
        if (data.success) {
            showPasswordResetAlert('success', data.message || 'A new verification code has been sent.');
            startOTPTimer();
        } else {
            showPasswordResetAlert('error', data.message || 'Could not resend code.');
            if (resendBtn) {
                resendBtn.disabled = false;
            }
        }
    } catch (err) {
        showPasswordResetAlert('error', err.message || 'Network error. Please try again.');
        if (resendBtn) {
            resendBtn.disabled = false;
        }
    } finally {
        if (resendBtn) {
            resendBtn.textContent = 'Resend OTP';
        }
    }
}

window.resendOTP = resendOTP;

// Reset Password
document.addEventListener('DOMContentLoaded', function () {
    const resetPasswordForm = document.getElementById('resetPasswordForm');
    if (resetPasswordForm) {
        setupPasswordValidation();
        setupPasswordToggles(resetPasswordForm);

        resetPasswordForm.addEventListener('submit', function (e) {
            e.preventDefault();
            resetPassword();
        });
    }
});

function setupPasswordValidation() {
    const newPassword = document.getElementById('newPassword');
    if (!newPassword) return;

    newPassword.addEventListener('input', function () {
        const password = this.value;

        // Check requirements
        const hasLength = password.length >= 8;
        const hasUppercase = /[A-Z]/.test(password);
        const hasLowercase = /[a-z]/.test(password);
        const hasNumber = /\d/.test(password);
        const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);

        updateRequirement('req-length', hasLength);
        updateRequirement('req-uppercase', hasUppercase);
        updateRequirement('req-lowercase', hasLowercase);
        updateRequirement('req-number', hasNumber);
        updateRequirement('req-special', hasSpecial);
    });
}

function updateRequirement(id, met) {
    const element = document.getElementById(id);
    if (element) {
        const icon = element.querySelector('i');
        if (met) {
            element.classList.add('met');
            icon.className = 'fa-solid fa-check-circle';
        } else {
            element.classList.remove('met');
            icon.className = 'fa-solid fa-circle';
        }
    }
}

function setupPasswordToggles(form) {
    const wrappers = form.querySelectorAll('.password-input-wrapper');
    wrappers.forEach(wrapper => {
        const input = wrapper.querySelector('input');
        const toggleBtn = wrapper.querySelector('.toggle-password');
        const icon = toggleBtn?.querySelector('i');

        if (!input || !toggleBtn || !icon) return;

        input.addEventListener('input', () => {
            if (input.value.length > 0) {
                toggleBtn.classList.add('visible');
            } else {
                toggleBtn.classList.remove('visible');
            }
        });

        toggleBtn.addEventListener('click', () => {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    });
}

async function resetPassword() {
    const newPassword = document.getElementById('newPassword')?.value;
    const confirmPassword = document.getElementById('confirmPassword')?.value;
    const passwordError = document.getElementById('passwordError');
    const submitBtn = document.querySelector('#resetPasswordForm .btn-submit');

    if (!newPassword || !confirmPassword) {
        showPasswordResetAlert('error', 'Please fill in all password fields.');
        return;
    }

    const hasLength = newPassword.length >= 8;
    const hasUppercase = /[A-Z]/.test(newPassword);
    const hasLowercase = /[a-z]/.test(newPassword);
    const hasNumber = /\d/.test(newPassword);
    const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(newPassword);

    if (!hasLength || !hasUppercase || !hasLowercase || !hasNumber || !hasSpecial) {
        showPasswordResetAlert('error', 'Please meet all password requirements.');
        return;
    }

    if (newPassword !== confirmPassword) {
        if (passwordError) {
            passwordError.style.display = 'block';
        }
        return;
    }
    if (passwordError) {
        passwordError.style.display = 'none';
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Resetting...';
    }

    try {
        const data = await passwordResetRequest('reset_password', {
            newPassword,
            confirmPassword,
        });
        if (data.success && data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        if (data.success) {
            const loginPage = getPasswordResetFrom() === 'admin' ? 'admin_login.php' : 'login.php';
            window.location.href = loginPage + '?reset=success';
            return;
        }
        showPasswordResetAlert('error', data.message || 'Could not reset password.');
    } catch (err) {
        showPasswordResetAlert('error', err.message || 'Network error. Please try again.');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Reset Password';
        }
    }
}

function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    // Find the toggle button
    const parent = input.parentElement;
    const button = parent.querySelector('.toggle-password');
    if (!button) return;

    const icon = button.querySelector('i');
    if (!icon) return;

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }

    // Hide icon if field is now empty
    if (input.value.length === 0) {
        button.classList.remove('visible');
    }
}


// ==================== MANAGE STUDENTS FUNCTIONALITY ====================

function initializeManageStudents() {
    // Check if we're on the manage students page
    if (!document.getElementById('addStudentBtn')) return;

    // Modal elements
    const addModal = document.getElementById('addStudentModal');
    const editModal = document.getElementById('editStudentModal');
    const viewModal = document.getElementById('viewStudentModal');
    const deleteModal = document.getElementById('deleteModal');

    // Buttons
    const addStudentBtn = document.getElementById('addStudentBtn');
    const closeAddModal = document.getElementById('closeAddModal');
    const cancelAdd = document.getElementById('cancelAdd');
    const closeEditModal = document.getElementById('closeEditModal');
    const cancelEdit = document.getElementById('cancelEdit');
    const closeViewModal = document.getElementById('closeViewModal');
    const closeView = document.getElementById('closeView');
    const viewEditBtn = document.getElementById('viewEditBtn');
    const closeDeleteModal = document.getElementById('closeDeleteModal');
    const cancelDelete = document.getElementById('cancelDelete');
    const confirmDelete = document.getElementById('confirmDelete');

    // Forms
    const addStudentForm = document.getElementById('addStudentForm');
    const editStudentForm = document.getElementById('editStudentForm');

    // Search and filter
    const searchInput = document.getElementById('searchInput');
    const clearFilterBtn = document.getElementById('clearFilter');

    // Modal functions
    function openModal(modal) {
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(modal) {
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Add Student Modal
    if (addStudentBtn) {
        addStudentBtn.addEventListener('click', () => openModal(addModal));
    }
    if (closeAddModal) {
        closeAddModal.addEventListener('click', () => closeModal(addModal));
    }
    if (cancelAdd) {
        cancelAdd.addEventListener('click', () => closeModal(addModal));
    }

    // Edit Student Modal
    if (closeEditModal) {
        closeEditModal.addEventListener('click', () => closeModal(editModal));
    }
    if (cancelEdit) {
        cancelEdit.addEventListener('click', () => closeModal(editModal));
    }

    // View Student Modal
    if (closeViewModal) {
        closeViewModal.addEventListener('click', () => closeModal(viewModal));
    }
    if (closeView) {
        closeView.addEventListener('click', () => closeModal(viewModal));
    }
    if (viewEditBtn) {
        viewEditBtn.addEventListener('click', () => {
            closeModal(viewModal);
            openModal(editModal);
        });
    }

    // Delete Modal
    if (closeDeleteModal) {
        closeDeleteModal.addEventListener('click', () => closeModal(deleteModal));
    }
    if (cancelDelete) {
        cancelDelete.addEventListener('click', () => closeModal(deleteModal));
    }
    if (confirmDelete) {
        confirmDelete.addEventListener('click', () => {
            closeModal(deleteModal);
        });
    }

    // Close modals on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) {
                const modal = this.closest('.modal');
                closeModal(modal);
            }
        });
    });

    // View/Edit/Delete button handlers
    document.querySelectorAll('.btn-action.view').forEach(btn => {
        btn.addEventListener('click', () => openModal(viewModal));
    });

    document.querySelectorAll('.btn-action.edit').forEach(btn => {
        btn.addEventListener('click', () => openModal(editModal));
    });

    document.querySelectorAll('.btn-action.delete').forEach(btn => {
        btn.addEventListener('click', () => openModal(deleteModal));
    });

    // Form submission handlers (prevent default, UI only)
    if (addStudentForm) {
        addStudentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (validateStudentForm(this)) {
                closeModal(addModal);
                this.reset();
            }
        });
    }

    if (editStudentForm) {
        editStudentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (validateStudentForm(this)) {
                closeModal(editModal);
            }
        });
    }

    // Password toggle in Add Student modal
    const addTogglePassword = document.querySelector('#addStudentModal .toggle-password');
    if (addTogglePassword) {
        addTogglePassword.addEventListener('click', function () {
            const input = document.getElementById('password');
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye';
            }
        });
    }

    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.students-table tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }

    // Clear filter
    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                const rows = document.querySelectorAll('.students-table tbody tr');
                rows.forEach(row => row.style.display = '');
            }
        });
    }

    // Form validation helper functions
    function validateStudentForm(form) {
        let isValid = true;

        // Email validation
        const emailInput = form.querySelector('input[type="email"]');
        if (emailInput && emailInput.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value)) {
                showFieldError(emailInput, 'Please enter a valid email address');
                isValid = false;
            } else {
                clearFieldError(emailInput);
            }
        }

        // School ID validation (8 digits)
        const schoolIdInput = form.querySelector('input[name="schoolId"]');
        if (schoolIdInput && schoolIdInput.value) {
            const schoolIdRegex = /^\d{8}$/;
            if (!schoolIdRegex.test(schoolIdInput.value)) {
                showFieldError(schoolIdInput, 'School ID must be 8 digits');
                isValid = false;
            } else {
                clearFieldError(schoolIdInput);
            }
        }

        // Phone validation (09XXXXXXXXX format)
        const phoneInput = form.querySelector('input[name="phone"]');
        if (phoneInput && phoneInput.value) {
            const phoneRegex = /^09\d{9}$/;
            if (!phoneRegex.test(phoneInput.value)) {
                showFieldError(phoneInput, 'Phone must be 09XXXXXXXXX format');
                isValid = false;
            } else {
                clearFieldError(phoneInput);
            }
        }

        // Required fields
        form.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                showFieldError(field, 'This field is required');
                isValid = false;
            } else {
                clearFieldError(field);
            }
        });

        return isValid;
    }

    function showFieldError(input, message) {
        input.classList.add('error');
        const formGroup = input.closest('.form-group');
        const errorMessage = formGroup.querySelector('.error-message');
        if (errorMessage) {
            errorMessage.textContent = message;
            errorMessage.classList.add('show');
        }
    }

    function clearFieldError(input) {
        input.classList.remove('error');
        const formGroup = input.closest('.form-group');
        const errorMessage = formGroup.querySelector('.error-message');
        if (errorMessage) {
            errorMessage.classList.remove('show');
        }
    }

    // Clear errors on input
    document.querySelectorAll('.student-form input, .student-form select, .student-form textarea').forEach(field => {
        field.addEventListener('input', function () {
            clearFieldError(this);
        });
    });
}

// Initialize Manage Students on DOM load
document.addEventListener('DOMContentLoaded', function () {
    initializeManageStudents();
});
