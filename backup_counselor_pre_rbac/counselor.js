// ==================== COUNSELOR JAVASCRIPT ====================
// Career Guidance System - Counselor Dashboard JavaScript

// ==================== COUNSELOR DASHBOARD FUNCTIONALITY ====================

// Initialize counselor dashboard
function initializeCounselorDashboard() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    
    if (!sidebar) return; // Only run on counselor dashboard page
    
    // Create sidebar overlay for mobile
    let sidebarOverlay = document.getElementById('sidebarOverlay');
    if (!sidebarOverlay) {
        sidebarOverlay = document.createElement('div');
        sidebarOverlay.id = 'sidebarOverlay';
        sidebarOverlay.className = 'sidebar-overlay';
        document.body.appendChild(sidebarOverlay);
    }
    
    // Toggle sidebar on mobile (using mobile menu toggle)
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });
    }
    
    // Sidebar toggle button (close)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // Close sidebar when clicking overlay
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !mobileMenuToggle?.contains(e.target) && !sidebarOverlay?.contains(e.target)) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        }
    });
    
    // Active nav item highlighting based on current page
    const currentPage = window.location.pathname.split('/').pop();
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'counselor_dashboard.php')) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

// ==================== UI INTERACTIONS ====================

// Add smooth hover effects to cards
function initializeCardEffects() {
    const cards = document.querySelectorAll('.overview-card, .student-card');
    
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

// Initialize user profile dropdown
function initializeUserProfile() {
    const userProfile = document.querySelector('.user-profile');
    
    if (userProfile) {
        userProfile.addEventListener('click', function() {
            // Profile dropdown interaction (UI only)
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 100);
        });
    }
}

// ==================== ACCESSIBILITY ====================

// Add keyboard navigation support
function initializeKeyboardNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        item.setAttribute('tabindex', '0');
        
        item.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                window.location.href = this.getAttribute('href');
            }
        });
    });
}

// ==================== DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize counselor dashboard functionality
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        initializeCounselorDashboard();
    }
    
    // Initialize UI interactions
    initializeCardEffects();
    initializeUserProfile();
    
    // Initialize accessibility features
    initializeKeyboardNavigation();
    
    // Log initialization for debugging
    console.log('Counselor Dashboard initialized successfully');
});

// ==================== COUNSELOR STUDENTS PAGE FUNCTIONALITY ====================

// Initialize students page functionality
function initializeStudentsPage() {
    const searchInput = document.getElementById('searchInput');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');

    // Check if we're on the students page
    if (!searchInput) return;

    // Filter function
    function filterStudentsList() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        let visibleCount = 0;

        // Filter desktop table rows
        const tableRows = document.querySelectorAll('.students-table tbody tr');
        tableRows.forEach(row => {
            if (row.cells.length === 1) return; // Skip empty/no students row

            const studentName = row.cells[0]?.textContent.toLowerCase() || '';
            const schoolId = row.cells[1]?.textContent.toLowerCase() || '';

            if (searchTerm === '' || studentName.includes(searchTerm) || schoolId.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Filter mobile student cards
        const mobileCards = document.querySelectorAll('.student-cards .student-card');
        mobileCards.forEach(card => {
            const studentName = card.querySelector('.student-info h3')?.textContent.toLowerCase() || '';
            const schoolId = card.querySelector('.school-id')?.textContent.toLowerCase() || '';

            if (searchTerm === '' || studentName.includes(searchTerm) || schoolId.includes(searchTerm)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });

        // Update count indicator if present
        const resultsCountEl = document.querySelector('.results-count');
        if (resultsCountEl) {
            resultsCountEl.textContent = `Showing ${visibleCount} student${visibleCount !== 1 ? 's' : ''}`;
        }
    }

    // Search button click handler
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Searching...</span>';
            this.disabled = true;

            setTimeout(() => {
                filterStudentsList();
                this.innerHTML = '<i class="fa-solid fa-search"></i> <span>Search</span>';
                this.disabled = false;
            }, 300);
        });
    }

    // Clear button click handler (if clearBtn exists)
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            filterStudentsList();
        });
    }

    // Input focus effects & real-time search
    if (searchInput) {
        searchInput.addEventListener('focus', function() {
            this.style.borderColor = '#fbbf24';
        });

        searchInput.addEventListener('blur', function() {
            this.style.borderColor = 'rgba(251, 191, 36, 0.2)';
        });

        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterStudentsList();
            }
        });

        // Auto-search on input change
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterStudentsList();
            }, 250);
        });
    }
}

// ==================== TABLE / CARD RESPONSIVENESS ====================

// Handle table/card visibility on resize
function handleResponsiveLayout() {
    const desktopTable = document.querySelector('.desktop-table');
    const mobileCards = document.querySelector('.mobile-cards');
    
    function updateLayout() {
        const isMobile = window.innerWidth <= 768;
        
        if (desktopTable && mobileCards) {
            if (isMobile) {
                desktopTable.style.display = 'none';
                mobileCards.style.display = 'flex';
            } else {
                desktopTable.style.display = 'block';
                mobileCards.style.display = 'none';
            }
        }
    }
    
    // Initial check
    updateLayout();
    
    // Handle window resize
    window.addEventListener('resize', updateLayout);
}

// ==================== EXTENDED DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize students page if on students page
    initializeStudentsPage();
    
    // Handle responsive table/card layout
    handleResponsiveLayout();
    
    // Log extended initialization
    console.log('Counselor Students Page functionality loaded');
});

// ==================== COUNSELOR RESULTS PAGE FUNCTIONALITY ====================

// Initialize results page functionality
function initializeResultsPage() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');
    
    // Check if we're on the results page
    if (!searchInput) return;

    // Filter function
    function filterResults() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const statusValue = statusFilter ? statusFilter.value.toLowerCase() : '';
        
        let visibleCount = 0;
        
        // Filter desktop table rows
        const tableRows = document.querySelectorAll('.results-table tbody tr');
        tableRows.forEach(row => {
            // Skip the "No results found" row
            if (row.cells.length === 1 && row.cells[0].colSpan === 6) return;
            
            const studentName = row.cells[0]?.textContent.toLowerCase() || '';
            const statusBadge = row.querySelector('.score-badge');
            
            // For status filter: we don't really have "pending" in the completed list, but let's assume it checks if score is N/A or something if needed.
            // Since the page only shows completed assessments right now, statusFilter might be unused or just filtering if we have pending.
            // We'll just filter by name.
            
            if (searchTerm === '' || studentName.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Filter mobile cards
        const mobileCards = document.querySelectorAll('.student-cards .result-card');
        mobileCards.forEach(card => {
            const studentName = card.querySelector('.student-info h3')?.textContent.toLowerCase() || '';
            
            if (searchTerm === '' || studentName.includes(searchTerm)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
        
        // Update results count
        const resultsCountEl = document.querySelector('.results-count');
        if (resultsCountEl) {
            resultsCountEl.textContent = `Showing ${visibleCount} result${visibleCount !== 1 ? 's' : ''}`;
        }
    }

    // Search button click handler
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            // Visual feedback
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Searching...</span>';
            this.disabled = true;

            setTimeout(() => {
                filterResults();
                this.innerHTML = '<i class="fa-solid fa-search"></i> <span>Search</span>';
                this.disabled = false;
            }, 400);
        });
    }

    // Clear button click handler
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            
            // Show feedback animation
            clearBtn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Cleared</span>';
            filterResults();
            
            setTimeout(() => {
                clearBtn.innerHTML = '<i class="fa-solid fa-times"></i> <span>Clear</span>';
            }, 1000);
        });
    }

    // Input focus effects
    const filterInputs = document.querySelectorAll('.search-input-group input, .search-input-group select');
    filterInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.style.borderColor = '#fbbf24';
        });

        input.addEventListener('blur', function() {
            this.style.borderColor = 'rgba(251, 191, 36, 0.2)';
        });
    });

    // Real-time search on Enter key
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchBtn) searchBtn.click();
            }
        });

        // Auto-search on input after delay
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterResults();
            }, 500);
        });
    }

    // Status filter change handler
    if (statusFilter) {
        statusFilter.addEventListener('change', filterResults);
    }

    // Score badge hover effects
    const scoreBadges = document.querySelectorAll('.score-badge');
    scoreBadges.forEach(badge => {
        badge.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
        });

        badge.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
}

// ==================== RESULTS PAGE DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize results page if on results page
    initializeResultsPage();
    
    // Log results page initialization
    console.log('Counselor Results Page functionality loaded');
});

// ==================== COUNSELOR VIEW ANSWERS PAGE FUNCTIONALITY ====================

// Initialize view answers page functionality
function initializeViewAnswersPage() {
    const categoryTabs = document.getElementById('categoryTabs');
    const tabContents = document.querySelectorAll('.tab-content');
    
    // Check if we're on the view answers page
    if (!categoryTabs) return;
    
    // Tab switching functionality
    const tabButtons = categoryTabs.querySelectorAll('.tab-btn');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');
            
            // Remove active class from all tabs
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab
            this.classList.add('active');
            
            // Show target content
            const targetContent = document.getElementById(targetTab);
            if (targetContent) {
                targetContent.classList.add('active');
            }
            
            // Log tab switch
            console.log('Switched to tab:', targetTab);
        });
    });
    
    // Question card hover effects
    const questionCards = document.querySelectorAll('.question-card');
    questionCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Option click feedback (read-only visual feedback only)
    const options = document.querySelectorAll('.option');
    options.forEach(option => {
        option.addEventListener('click', function() {
            // Visual feedback only - pulse animation
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
        });
    });
    
    // Back link hover effects
    const backLinks = document.querySelectorAll('.back-link');
    backLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            const icon = this.querySelector('i');
            if (icon) {
                icon.style.transform = 'translateX(-3px)';
            }
        });
        
        link.addEventListener('mouseleave', function() {
            const icon = this.querySelector('i');
            if (icon) {
                icon.style.transform = 'translateX(0)';
            }
        });
    });
}

// ==================== VIEW ANSWERS PAGE DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize view answers page if on view answers page
    initializeViewAnswersPage();
    
    // Initialize student search functionality
    initializeStudentSearch();
    
    // Log view answers page initialization
    console.log('Counselor View Answers Page functionality loaded');
});

// ==================== STUDENT SEARCH FUNCTIONALITY ====================

// Initialize student search
function initializeStudentSearch() {
    const studentSearchInput = document.getElementById('studentSearchInput');
    const studentSearchBtn = document.getElementById('studentSearchBtn');
    const studentDropdown = document.getElementById('studentDropdown');
    const dropdownItems = document.querySelectorAll('.dropdown-item');
    
    // Check if we're on the view answers page with search
    if (!studentSearchInput || !studentDropdown) return;
    
    // Toggle dropdown on input focus
    studentSearchInput.addEventListener('focus', function() {
        studentDropdown.classList.add('active');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!studentSearchInput.contains(e.target) && 
            !studentDropdown.contains(e.target) && 
            !studentSearchBtn.contains(e.target)) {
            studentDropdown.classList.remove('active');
        }
    });
    
    // Search button click
    if (studentSearchBtn) {
        studentSearchBtn.addEventListener('click', function() {
            const searchTerm = studentSearchInput.value.toLowerCase().trim();
            filterStudents(searchTerm, dropdownItems);
            studentDropdown.classList.add('active');
        });
    }
    
    // Real-time filtering on input
    studentSearchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        filterStudents(searchTerm, dropdownItems);
        studentDropdown.classList.add('active');
    });
    
    // Student selection from dropdown
    dropdownItems.forEach(item => {
        item.addEventListener('click', function() {
            const studentName = this.querySelector('.student-name').textContent;
            const studentId = this.dataset.studentId;
            
            studentSearchInput.value = studentName;
            
            dropdownItems.forEach(i => i.classList.remove('selected'));
            this.classList.add('selected');
            
            studentDropdown.classList.remove('active');
            
            if (studentId) {
                loadCounselorStudentAnswers(studentId);
            }
            
            studentSearchInput.style.background = 'rgba(251, 191, 36, 0.1)';
            setTimeout(() => {
                studentSearchInput.style.background = '';
            }, 500);
        });
    });

    // Auto-load student from URL (?student_id=)
    const urlParams = new URLSearchParams(window.location.search);
    const presetStudentId = urlParams.get('student_id');
    if (presetStudentId) {
        const presetItem = studentDropdown.querySelector(`.dropdown-item[data-student-id="${presetStudentId}"]`);
        if (presetItem) {
            presetItem.click();
        } else {
            loadCounselorStudentAnswers(presetStudentId);
        }
    }
    
    // Keyboard navigation for dropdown
    studentSearchInput.addEventListener('keydown', function(e) {
        const activeItems = studentDropdown.querySelectorAll('.dropdown-item:not([style*="display: none"])');
        const currentSelected = studentDropdown.querySelector('.dropdown-item.selected');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!currentSelected) {
                if (activeItems.length > 0) {
                    activeItems[0].classList.add('selected');
                }
            } else {
                const currentIndex = Array.from(activeItems).indexOf(currentSelected);
                const nextIndex = (currentIndex + 1) % activeItems.length;
                currentSelected.classList.remove('selected');
                activeItems[nextIndex].classList.add('selected');
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (currentSelected) {
                const activeArray = Array.from(activeItems);
                const currentIndex = activeArray.indexOf(currentSelected);
                const prevIndex = currentIndex === 0 ? activeArray.length - 1 : currentIndex - 1;
                currentSelected.classList.remove('selected');;
                activeArray[prevIndex].classList.add('selected');
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const selected = studentDropdown.querySelector('.dropdown-item.selected');
            if (selected) {
                selected.click();
            } else if (studentSearchBtn) {
                studentSearchBtn.click();
            }
        } else if (e.key === 'Escape') {
            studentDropdown.classList.remove('active');
            studentSearchInput.blur();
        }
    });
}

// Filter students based on search term
function filterStudents(searchTerm, items) {
    items.forEach(item => {
        const studentName = item.querySelector('.student-name').textContent.toLowerCase();
        const studentDetails = item.querySelector('.student-details').textContent.toLowerCase();
        
        if (searchTerm === '' || 
            studentName.includes(searchTerm) || 
            studentDetails.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// ==================== LOAD REAL STUDENT ANSWERS ====================

let selectedCounselorStudentId = null;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function buildLikertHtml(value, questionText, index) {
    const selectedVal = Number(value);
    let circlesHtml = '';
    for (let i = 1; i <= 5; i++) {
        const isSelected = i === selectedVal ? 'selected' : '';
        circlesHtml += `<div class="likert-circle ${isSelected}">${i}</div>`;
    }
    return `
        <div class="likert-question-card">
            <div class="likert-card-header">QUESTION ${(index ?? 0) + 1}</div>
            <div class="likert-card-body">
                <div class="likert-question-text">${escapeHtml(questionText)}</div>
                <div class="likert-scale-wrapper">
                    <div class="likert-label-left">Strongly<br>Disagree</div>
                    <div class="likert-options-row">
                        ${circlesHtml}
                    </div>
                    <div class="likert-label-right">Strongly<br>Agree</div>
                </div>
            </div>
        </div>`;
}

function buildObjectiveHtml(answer) {
    const isCorrect = String(answer.is_correct) === '1';
    return `
        <div class="answer-options">
            <div class="option selected${isCorrect ? ' correct' : ''}">
                <span class="option-label">${escapeHtml(answer.option_label || '-')}</span>
                <span class="option-text">${escapeHtml(answer.option_text || 'No option selected')}</span>
                ${isCorrect ? '<span class="option-indicator"><i class="fa-solid fa-check"></i></span>' : ''}
            </div>
        </div>`;
}

function buildOpenEndedHtml(text) {
    return `
        <div class="open-ended-answer">
            <div class="answer-label">Student's Answer:</div>
            <div class="answer-text">${escapeHtml(text)}</div>
        </div>`;
}

function buildQuestionCardHtml(answer, index) {
    const questionText = answer.question_text || 'Question not available';

    if (answer.likert_value !== null && answer.likert_value !== '') {
        return buildLikertHtml(answer.likert_value, questionText, index);
    } else if (answer.selected_option_id !== null && answer.selected_option_id !== '') {
        return `
        <div class="question-card">
            <div class="question-number">Question ${index + 1}</div>
            <div class="question-text">${escapeHtml(questionText)}</div>
            ${buildObjectiveHtml(answer)}
        </div>`;
    } else if (answer.open_answer) {
        return `
        <div class="question-card">
            <div class="question-number">Question ${index + 1}</div>
            <div class="question-text">${escapeHtml(questionText)}</div>
            ${buildOpenEndedHtml(answer.open_answer)}
        </div>`;
    } else {
        return `
        <div class="question-card">
            <div class="question-number">Question ${index + 1}</div>
            <div class="question-text">${escapeHtml(questionText)}</div>
            <p style="color:#94a3b8;">No answer recorded.</p>
        </div>`;
    }
}

function renderCounselorAnswersByTab(answers) {
    const types = ['career', 'personality', 'skills', 'strand'];

    types.forEach(type => {
        const tab = document.getElementById(type);
        if (!tab) return;

        const list = tab.querySelector('.questions-list');
        if (!list) return;

        const typeAnswers = answers.filter(a => a.question_type === type);

        if (typeAnswers.length === 0) {
            list.innerHTML = '<p style="text-align: center; color: #999; padding: 2rem;">No assessment data available for this student.</p>';
            return;
        }

        list.innerHTML = typeAnswers.map((answer, index) => buildQuestionCardHtml(answer, index)).join('');
    });
}

function updateCounselorStudentInfo(student, answers) {
    const card = document.getElementById('studentInfoCard');
    if (!card || !student) return;

    const name = `${student.first_name || ''} ${student.last_name || ''}`.trim();
    document.getElementById('studentInfoName').textContent = name || 'Unknown Student';
    document.getElementById('studentInfoGrade').textContent = student.grade_level ? `Grade ${student.grade_level}` : 'N/A';

    const strandEl = document.getElementById('studentInfoStrand');
    const strandCode = student.strand_code || 'N/A';
    strandEl.textContent = strandCode;
    strandEl.className = 'strand-badge ' + (strandCode !== 'N/A' ? strandCode.toLowerCase().replace(/\s+/g, '') : '');

    const dateEl = document.getElementById('studentInfoDate');
    if (student.completed_at) {
        dateEl.textContent = new Date(student.completed_at).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric'
        });
    } else {
        dateEl.textContent = '--';
    }

    const scoreEl = document.getElementById('studentInfoScore');
    if (student.total_score !== null && student.total_score !== undefined && student.total_score !== '') {
        scoreEl.textContent = Math.round(Number(student.total_score)) + '%';
    } else if (answers.length > 0) {
        const scored = answers.filter(a => a.score !== null && a.score !== '');
        if (scored.length > 0) {
            const avg = scored.reduce((sum, a) => sum + Number(a.score), 0) / scored.length;
            scoreEl.textContent = Math.round(avg) + '%';
        } else {
            scoreEl.textContent = '--';
        }
    } else {
        scoreEl.textContent = '--';
    }

    card.style.display = 'block';
}

async function loadCounselorStudentAnswers(studentId) {
    if (!studentId) return;

    selectedCounselorStudentId = studentId;
    const types = ['career', 'personality', 'skills', 'strand'];

    types.forEach(type => {
        const tab = document.getElementById(type);
        const list = tab?.querySelector('.questions-list');
        if (list) {
            list.innerHTML = '<p style="text-align: center; color: #999; padding: 2rem;">Loading answers...</p>';
        }
    });

    try {
        const formData = new FormData();
        formData.append('action', 'get_student_assessments');
        formData.append('student_id', studentId);

        const response = await fetch('counselor_answers.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            alert(data.message || 'Failed to load assessment answers');
            renderCounselorAnswersByTab([]);
            return;
        }

        updateCounselorStudentInfo(data.student, data.answers);
        renderCounselorAnswersByTab(data.answers);
    } catch (error) {
        console.error('Error loading counselor answers:', error);
        alert('Error loading assessment answers. Please try again.');
    }
}

// ==================== COUNSELOR PROFILE PAGE FUNCTIONALITY ====================

// Initialize profile page functionality
function initializeProfilePage() {
    const profileForm = document.getElementById('profileForm');
    const passwordForm = document.getElementById('passwordForm');
    const imageUpload = document.getElementById('imageUpload');
    const profileImage = document.getElementById('profileImage');
    const passwordToggles = document.querySelectorAll('.password-toggle');
    
    // Check if we're on the profile page
    if (!profileForm && !passwordForm) return;
    
    // Password toggle functionality
    passwordToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        });
    });
    
    // Image upload preview
    if (imageUpload && profileImage) {
        imageUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Create image element
                    profileImage.innerHTML = `<img src="${e.target.result}" alt="Profile">`;
                    console.log('Profile image updated (UI only)');
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Profile form submission
    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Clear any existing messages
            const existingMsg = profileForm.previousElementSibling;
            if (existingMsg && (existingMsg.classList.contains('success-message') || existingMsg.classList.contains('error-message'))) {
                existingMsg.remove();
            }

            const firstName = document.getElementById('firstName');
            const middleName = document.getElementById('middleName');
            const lastName = document.getElementById('lastName');
            const suffix = document.getElementById('suffix');
            const email = document.getElementById('email');
            const contactNumber = document.getElementById('contactNumber');

            function showErrorMsg(msg) {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'error-message';
                msgDiv.style.cssText = 'background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;';
                msgDiv.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> ' + msg;
                profileForm.parentNode.insertBefore(msgDiv, profileForm);
            }

            if (!firstName.value.trim() || !lastName.value.trim() || !email.value.trim() || !contactNumber?.value.trim()) {
                showErrorMsg('Please fill in all required fields.');
                return;
            }

            const saveBtn = profileForm.querySelector('.btn-save');
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'update_counselor_profile');
            formData.append('first_name', firstName.value.trim());
            formData.append('middle_name', middleName ? middleName.value.trim() : '');
            formData.append('last_name', lastName.value.trim());
            formData.append('suffix', suffix ? suffix.value : '');
            formData.append('email', email.value.trim());
            formData.append('contactNumber', contactNumber.value.trim());

            try {
                const res = await fetch('counselor_profile.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'success-message';
                    msgDiv.style.cssText = 'background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;';
                    msgDiv.innerHTML = '<i class="fa-solid fa-check-circle"></i> Profile updated successfully!';
                    profileForm.parentNode.insertBefore(msgDiv, profileForm);

                    saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Saved!</span>';
                    const profileName = document.querySelector('.profile-info h3');
                    const topBarName = document.querySelector('.top-bar .user-name');
                    
                    let newFullName = firstName.value.trim();
                    if (middleName && middleName.value.trim()) newFullName += ' ' + middleName.value.trim();
                    newFullName += ' ' + lastName.value.trim();
                    if (suffix && suffix.value) newFullName += ' ' + suffix.value;

                    if (profileName) profileName.textContent = newFullName;
                    if (topBarName) topBarName.textContent = newFullName;
                } else {
                    showErrorMsg(data.message || 'Failed to update profile.');
                }
            } catch (err) {
                showErrorMsg('Error updating profile. Please try again.');
            }

            setTimeout(() => {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            }, 1500);
        });
    }

    // Password form submission
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Clear any existing messages
            const existingMsg = passwordForm.previousElementSibling;
            if (existingMsg && (existingMsg.classList.contains('success-message') || existingMsg.classList.contains('error-message'))) {
                existingMsg.remove();
            }

            const currentPassword = document.getElementById('currentPassword');
            const newPassword = document.getElementById('newPassword');
            const confirmPassword = document.getElementById('confirmPassword');

            function showErrorMsg(msg) {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'error-message';
                msgDiv.style.cssText = 'background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;';
                msgDiv.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> ' + msg;
                passwordForm.parentNode.insertBefore(msgDiv, passwordForm);
            }

            if (!currentPassword.value || !newPassword.value || !confirmPassword.value) {
                showErrorMsg('Please fill in all password fields.');
                return;
            }

            if (newPassword.value !== confirmPassword.value) {
                showErrorMsg('New password and confirm password do not match.');
                confirmPassword.focus();
                return;
            }

            if (newPassword.value.length < 8) {
                showErrorMsg('New password must be at least 8 characters long.');
                return;
            }

            const updateBtn = passwordForm.querySelector('.btn-update');
            const originalText = updateBtn.innerHTML;
            updateBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'update_counselor_password');
            formData.append('currentPassword', currentPassword.value);
            formData.append('newPassword', newPassword.value);

            try {
                const res = await fetch('counselor_profile.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'success-message';
                    msgDiv.style.cssText = 'background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;';
                    msgDiv.innerHTML = '<i class="fa-solid fa-check-circle"></i> Password changed successfully!';
                    passwordForm.parentNode.insertBefore(msgDiv, passwordForm);

                    updateBtn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Updated!</span>';
                    currentPassword.value = '';
                    newPassword.value = '';
                    confirmPassword.value = '';
                    passwordToggles.forEach(toggle => {
                        const icon = toggle.querySelector('i');
                        const targetId = toggle.getAttribute('data-target');
                        const input = document.getElementById(targetId);
                        if (input) input.type = 'password';
                        if (icon) {
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    });
                } else {
                    showErrorMsg(data.message || 'Failed to update password.');
                }
            } catch (err) {
                showErrorMsg('Error updating password. Please try again.');
            }

            setTimeout(() => {
                updateBtn.innerHTML = originalText;
                updateBtn.disabled = false;
            }, 1500);
        });
    }
    
    // Deactivate account
    const deactivateBtn = document.getElementById('deactivateAccountBtn');
    const deactivateForm = document.getElementById('deactivateAccountForm');
    if (deactivateBtn && deactivateForm) {
        deactivateBtn.addEventListener('click', function() {
            const confirmed = confirm(
                'Are you sure you want to deactivate your account?\n\n' +
                'This action will:\n' +
                '• Disable your account access\n' +
                '• Remove you from active counselors\n\n' +
                'You can only sign in again after an administrator sets your status to Active in Settings.\n\n' +
                'Click OK to proceed or Cancel to keep your account active.'
            );
            if (confirmed) {
                deactivateForm.submit();
            }
        });
    }

    // Input focus effects
    const formInputs = document.querySelectorAll('.form-group input:not([readonly])');
    formInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.classList.add('focus');
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.classList.remove('focus');
        });
    });
}

// ==================== PROFILE PAGE DOM INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize profile page if on profile page
    if (typeof initializeProfilePage === 'function') {
        initializeProfilePage();
    }

    // Add Student functionality
    const addStudentBtn = document.getElementById('addStudentBtn');
    const addStudentModal = document.getElementById('addStudentModal');
    const closeAddModal = document.getElementById('closeAddModal');
    const cancelAdd = document.getElementById('cancelAdd');
    const addStudentForm = document.getElementById('addStudentForm');

    if (addStudentBtn && addStudentModal) {
        addStudentBtn.addEventListener('click', () => {
            addStudentModal.classList.add('active');
        });

        const closeModal = () => {
            addStudentModal.classList.remove('active');
            if (addStudentForm) addStudentForm.reset();
        };

        if (closeAddModal) closeAddModal.addEventListener('click', closeModal);
        if (cancelAdd) cancelAdd.addEventListener('click', closeModal);
        
        // Close on overlay click
        addStudentModal.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay') || e.target.classList.contains('modal')) {
                closeModal();
            }
        });

        if (addStudentForm) {
            addStudentForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
                submitBtn.disabled = true;

                const formData = new FormData(this);
                formData.append('action', 'add_student');

                try {
                    const response = await fetch('counselor_students.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    let text = await response.text();
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            let successMsg = 'Student added successfully!\n\n';
                            successMsg += 'A temporary password has been generated.\n';
                            if (data.email_sent) {
                                successMsg += 'An email with login credentials has been sent to the student.';
                            } else {
                                successMsg += 'Email sending failed, but the account was created.\n';
                                successMsg += 'Temporary Password: ' + data.generated_password;
                            }
                            
                            alert(successMsg);
                            window.location.reload();
                        } else {
                            alert(data.message || 'Failed to add student');
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }
                    } catch (jsonErr) {
                        console.error('JSON Parse Error:', jsonErr);
                        console.log('Raw Response:', text);
                        alert('A server error occurred. Please try again.');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                } catch (error) {
                    console.error('Fetch Error:', error);
                    alert('A network error occurred. Please try again.');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            });
        }
    }
    
    // Log functionality loaded
    console.log('Counselor dashboard functionality loaded');
});
