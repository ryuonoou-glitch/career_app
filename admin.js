// ==================== ADMIN JAVASCRIPT ====================
// Career Guidance System - Admin Panel JavaScript

// ==================== SIDEBAR GROUP TOGGLE ====================

window.toggleNavGroup = function(btn) {
    const submenu = btn.nextElementSibling;
    const isOpen  = btn.classList.contains('open');
    btn.classList.toggle('open', !isOpen);
    if (submenu) submenu.classList.toggle('open', !isOpen);
};

// ==================== ADMIN LOGIN FUNCTIONALITY ====================

// Toggle admin password visibility
function toggleAdminPassword() {
    const passwordInput = document.getElementById('adminPassword');
    const passwordIcon = document.getElementById('adminPasswordIcon');
    const toggleBtn = document.querySelector('.admin-login-form .toggle-password');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
    
    // Hide icon if field is now empty
    if (toggleBtn && passwordInput.value.length === 0) {
        toggleBtn.classList.remove('visible');
    }
}

// Initialize admin login form
function initializeAdminLogin() {
    const adminLoginForm = document.getElementById('adminLoginForm');
    if (!adminLoginForm) return; // Only run on admin login page
    
    // Add input focus effects
    const inputs = adminLoginForm.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.classList.add('focus-within');
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.classList.remove('focus-within');
        });
    });
    
    // Password field - toggle eye icon visibility
    const passwordInput = document.getElementById('adminPassword');
    const toggleBtn = document.querySelector('.admin-login-form .toggle-password');
    
    if (passwordInput && toggleBtn) {
        // Show/hide eye icon based on input value
        passwordInput.addEventListener('input', function() {
            if (this.value.length > 0) {
                toggleBtn.classList.add('visible');
            } else {
                toggleBtn.classList.remove('visible');
            }
        });
        
        // Hide icon if field is cleared
        passwordInput.addEventListener('blur', function() {
            if (this.value.length === 0) {
                toggleBtn.classList.remove('visible');
            }
        });
    }
    
}

// ==================== ADMIN DASHBOARD FUNCTIONALITY ====================

// Initialize admin dashboard
function initializeAdminDashboard() {
    const sidebar         = document.getElementById('sidebar');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebarToggle   = document.getElementById('sidebarToggle');

    if (!sidebar) return;

    // Create overlay if it doesn't already exist in HTML
    let overlay = document.getElementById('sidebarOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sidebarOverlay';
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    function openSidebar() {
        sidebar.classList.add('active');
        overlay.classList.add('active');
    }
    function closeSidebar() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    }

    // Hamburger button opens sidebar
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
        });
    }

    // X button inside sidebar closes it
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            closeSidebar();
        });
    }

    // Tapping the overlay closes the sidebar
    overlay.addEventListener('click', closeSidebar);

    // Close sidebar when a nav link is clicked on mobile
    sidebar.querySelectorAll('.nav-item, .nav-subitem').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1024) closeSidebar();
        });
    });

    // Handle window resize — close sidebar if viewport is now desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) closeSidebar();
    });
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

    let selectedStudentId = null;
    
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
        overlay.addEventListener('click', function() {
            const modal = this.closest('.modal');
            closeModal(modal);
        });
    });
    
    // View/Edit/Delete button handlers
    async function postStudentAction(formData) {
        const res = await fetch('manage_students.php', {
            method: 'POST',
            body: formData
        });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return { success: false, message: 'Server returned an invalid response. Check for PHP errors or network issues.' };
        }
    }

    async function fetchStudent(id) {
        const fd = new FormData();
        fd.append('action', 'get_student');
        fd.append('id', id);
        return postStudentAction(fd);
    }

    function fillStudentForms(student) {
        const gradeNum = (student.grade_level || '').includes('11') ? '11' : ((student.grade_level || '').includes('12') ? '12' : '');

        const viewName = viewModal?.querySelector('.profile-info h3');
        const viewId = viewModal?.querySelector('.profile-id');
        const viewStrandBadge = viewModal?.querySelector('.profile-info .strand-badge');
        if (viewName) viewName.textContent = `${student.first_name || ''} ${student.last_name || ''}`.trim();
        if (viewId) viewId.textContent = `ID: ${student.student_id || ''}`;
        if (viewStrandBadge) viewStrandBadge.textContent = student.strand_name || 'N/A';

        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val ?? '';
        };

        setVal('editStudentId', student.id);
        setVal('editFirstName', student.first_name);
        setVal('editMiddleName', student.middle_name);
        setVal('editLastName', student.last_name);
        setVal('editSuffix', student.suffix);
        setVal('editEmail', student.email);
        setVal('editSchoolId', student.student_id);
        setVal('editGender', student.gender);
        setVal('editStatus', student.status);
        setVal('editStrand', student.strand_code || student.strand_name);
        setVal('editGradeLevel', gradeNum);
        setVal('editPhone', student.phone);
        setVal('editBirthdate', student.birthdate);
        setVal('editAddress', student.address);
    }

    document.querySelectorAll('.btn-action.view').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            selectedStudentId = id;
            const data = await fetchStudent(id);
            if (data.success) {
                fillStudentForms(data.student);
                openModal(viewModal);
            } else {
                alert(data.message || 'Failed to load student');
            }
        });
    });
    
    document.querySelectorAll('.btn-action.edit').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            selectedStudentId = id;
            const data = await fetchStudent(id);
            if (data.success) {
                fillStudentForms(data.student);
                openModal(editModal);
            } else {
                alert(data.message || 'Failed to load student');
            }
        });
    });
    
    document.querySelectorAll('.btn-action.delete').forEach(btn => {
        btn.addEventListener('click', () => {
            selectedStudentId = btn.dataset.id;
            openModal(deleteModal);
        });
    });
    
    // The form submission handlers for Add/Edit/Delete are now fully handled in manage_students.php inline script
    // to support showing temporary passwords and welcome email status.
    
    // Password toggle in Add Student modal
    const addTogglePassword = document.querySelector('#addStudentModal .toggle-password');
    if (addTogglePassword) {
        addTogglePassword.addEventListener('click', function() {
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
    
    // Search/filter for manage students is handled in manage_students.php inline script (applyFilters)

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
        
        // School ID: digits only, up to 12 (matches DB varchar(12) and form maxlength)
        const schoolIdInput = form.querySelector('input[name="schoolId"]');
        if (schoolIdInput && schoolIdInput.value) {
            const sid = schoolIdInput.value.trim();
            const schoolIdRegex = /^\d{1,12}$/;
            if (!schoolIdRegex.test(sid)) {
                showFieldError(schoolIdInput, 'School ID must be 1–12 digits only');
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
        field.addEventListener('input', function() {
            clearFieldError(this);
        });
    });
}

// ==================== MANAGE QUESTIONS FUNCTIONALITY ====================

function initializeManageQuestions() {
    // Check if we're on manage questions page
    const addQuestionBtn = document.getElementById('addQuestionBtn');
    if (!addQuestionBtn) return;
    
    // Search elements
    const searchInput = document.getElementById('searchInput');
    const searchBtn = document.getElementById('searchBtn');
    const clearFilterBtn = document.getElementById('clearFilter');
    
    // Tab navigation
    const tabButtons = document.querySelectorAll('.tab-btn[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');
    
    // Function to filter questions
    function filterQuestions(searchTerm) {
        // Get currently active tab
        const activeTab = document.querySelector('.tab-content.active');
        if (!activeTab) return;
        
        const rows = activeTab.querySelectorAll('.questions-table tbody tr');
        
        rows.forEach(row => {
            // Skip empty rows (like the "No questions" row)
            if (row.querySelector('td[colspan]')) return;
            
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm.toLowerCase()) ? '' : 'none';
        });
    }
    
    // Search button click
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            if (searchInput) {
                filterQuestions(searchInput.value);
            }
        });
    }
    
    // Search input "enter" key
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                filterQuestions(searchInput.value);
            }
        });
        
        // Optional: still allow real-time search as you type
        searchInput.addEventListener('input', function() {
            filterQuestions(searchInput.value);
        });
    }
    
    // Clear filter button
    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                filterQuestions('');
            }
        });
    }
    
    // Tab navigation
    if (tabButtons.length > 0) {
        tabButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const targetTabId = this.dataset.tab;
                
                // Update active button
                tabButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update active tab content
                tabContents.forEach(tc => tc.classList.remove('active'));
                const targetTab = document.getElementById(`${targetTabId}-tab`);
                if (targetTab) {
                    targetTab.classList.add('active');
                }
                
                // Re-apply search filter to new tab
                if (searchInput) {
                    filterQuestions(searchInput.value);
                }
            });
        });
    }
}

// ==================== DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize admin login if on admin login page
    if (document.getElementById('adminLoginForm')) {
        initializeAdminLogin();
    }
    
    // Initialize admin dashboard functionality on any admin page with sidebar
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        initializeAdminDashboard();
    }
    
    // Initialize manage students if on manage students page
    initializeManageStudents();
    
    // Initialize manage questions if on manage questions page
    initializeManageQuestions();
});
