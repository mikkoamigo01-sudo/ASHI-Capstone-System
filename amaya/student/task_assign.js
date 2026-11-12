        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }
        });

        // Mobile menu toggle
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

// Section navigation (keep existing code)
const menuLinks = document.querySelectorAll('.menu-link');
const contentSections = document.querySelectorAll('.content-section');
const pageTitle = document.getElementById('pageTitle');

const sectionTitles = {
    'dashboard': 'Dashboard',
    'subjects': 'My Subjects',
    'tasks': 'Tasks',
    'submissions': 'My Submissions',
    'lectures': 'Lectures',
    'grades': 'My Grades',
    'profile': 'My Profile',
    'logout': 'Logout'
};

menuLinks.forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        
        const targetSection = this.getAttribute('data-section');
        
        // Check if logout is clicked
        if (targetSection === 'logout') {
            handleLogout();
            return;
        }
        
        // Remove active class from all menu links
        menuLinks.forEach(l => l.classList.remove('active'));
        
        // Add active class to clicked link
        this.classList.add('active');
        
        // Hide all content sections
        contentSections.forEach(section => section.classList.remove('active'));
        
        // Show target section
        const targetElement = document.getElementById(targetSection);
        if (targetElement) {
            targetElement.classList.add('active');
        }
        
        // Update page title
        if (sectionTitles[targetSection]) {
            pageTitle.textContent = sectionTitles[targetSection];
        }
        
        // Close mobile menu after selection
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('mobile-open');
        }
    });
});

// Enhanced Logout handler with confirmation
function handleLogout() {
    // Create custom confirmation modal (better UX than browser confirm)
    const confirmLogout = confirm('⚠️ Logout Confirmation\n\nAre you sure you want to logout from your account?');
    
    if (confirmLogout) {
        // Optional: Show loading state
        const logoutBtn = document.querySelector('[data-section="logout"]');
        if (logoutBtn) {
            logoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="menu-text">Logging out...</span>';
        }
        
        // Redirect to logout page
        setTimeout(() => {
            window.location.href = 'logout.php';
        }, 500);
    }
}

let allSubmissions = [];
let filteredSubmissions = [];

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on the submissions section
    const submissionsSection = document.getElementById('submissions');
    if (submissionsSection) {
        loadSubmissions();
        setupEventListeners();
    }
});

// Fetch submissions from server
async function loadSubmissions() {
    const loadingState = document.getElementById('loadingState');
    const emptyState = document.getElementById('emptyState');
    const submissionsContainer = document.getElementById('submissionsContainer');
    
    try {
        // Show loading state
        loadingState.style.display = 'block';
        emptyState.style.display = 'none';
        submissionsContainer.innerHTML = '';
        
        // Fetch data from PHP
        const response = await fetch('fetch_submissions.php');
        const data = await response.json();
        
        // Hide loading state
        loadingState.style.display = 'none';
        
        if (data.error) {
            console.error('Error:', data.error);
            showError('Failed to load submissions. Please try again.');
            return;
        }
        
        if (data.success) {
            allSubmissions = data.submissions;
            filteredSubmissions = [...allSubmissions];
            
            // Update statistics
            updateStatistics(data.stats);
            
            // Populate subject filter
            populateSubjectFilter();
            
            // Display submissions
            if (allSubmissions.length === 0) {
                emptyState.style.display = 'block';
            } else {
                displaySubmissions(filteredSubmissions);
            }
        }
        
    } catch (error) {
        console.error('Error loading submissions:', error);
        loadingState.style.display = 'none';
        showError('Failed to load submissions. Please check your connection.');
    }
}

// Update statistics cards
function updateStatistics(stats) {
    document.getElementById('totalSubmissions').textContent = stats.total;
    document.getElementById('gradedSubmissions').textContent = stats.graded;
    document.getElementById('pendingSubmissions').textContent = stats.pending;
    document.getElementById('averageGrade').textContent = stats.average_grade + '%';
}

// Populate subject filter dropdown
function populateSubjectFilter() {
    const subjectFilter = document.getElementById('subjectFilter');
    const subjects = [...new Set(allSubmissions.map(s => s.subject_name))];
    
    // Clear existing options except "All Subjects"
    subjectFilter.innerHTML = '<option value="all">All Subjects</option>';
    
    // Add unique subjects
    subjects.forEach(subject => {
        const option = document.createElement('option');
        option.value = subject;
        option.textContent = subject;
        subjectFilter.appendChild(option);
    });
}

// Display submissions in the container
function displaySubmissions(submissions) {
    const container = document.getElementById('submissionsContainer');
    const noResultsState = document.getElementById('noResultsState');
    const emptyState = document.getElementById('emptyState');
    
    container.innerHTML = '';
    
    if (submissions.length === 0) {
        container.style.display = 'none';
        noResultsState.style.display = 'block';
        emptyState.style.display = 'none';
        return;
    }
    
    container.style.display = 'flex';
    noResultsState.style.display = 'none';
    emptyState.style.display = 'none';
    
    submissions.forEach(submission => {
        const card = createSubmissionCard(submission);
        container.appendChild(card);
    });
}

// Create individual submission card
function createSubmissionCard(submission) {
    const card = document.createElement('div');
    card.className = 'submission-card';
    card.style.borderLeftColor = submission.theme_color || '#1a73e8';
    
    // Determine status badge
    let statusBadgeHTML = '';
    let scoreHTML = '';
    
    if (submission.status === 'graded' || submission.status === 'returned') {
        const percentage = (submission.score / submission.max_points * 100).toFixed(1);
        statusBadgeHTML = `
            <span class="status-badge status-${submission.status}">
                <i class="fas fa-check-circle"></i>
                ${submission.status.charAt(0).toUpperCase() + submission.status.slice(1)}
            </span>
        `;
        scoreHTML = `
            <div class="score-display">${submission.score}/${submission.max_points}</div>
            <div class="score-text">${percentage}%</div>
        `;
    } else if (submission.status === 'late') {
        statusBadgeHTML = `
            <span class="status-badge status-late">
                <i class="fas fa-exclamation-circle"></i>
                Late Submission
            </span>
        `;
        scoreHTML = `<div class="pending-text">Pending Review</div>`;
    } else {
        statusBadgeHTML = `
            <span class="status-badge status-submitted">
                <i class="fas fa-clock"></i>
                Submitted
            </span>
        `;
        scoreHTML = `<div class="pending-text">Pending Review</div>`;
    }
    
    // Format dates
    const submittedDate = formatDate(submission.submitted_at);
    const dueDate = formatDate(submission.due_date);
    
    // Teacher feedback section
    let feedbackHTML = '';
    if (submission.teacher_feedback && submission.teacher_feedback.trim() !== '') {
        feedbackHTML = `
            <div class="teacher-feedback">
                <div class="feedback-header">
                    <i class="fas fa-comment-dots"></i>
                    <span>Teacher's Feedback</span>
                </div>
                <p class="feedback-text">${escapeHtml(submission.teacher_feedback)}</p>
            </div>
        `;
    }
    
    card.innerHTML = `
        <div class="submission-card-header">
            <div class="submission-info">
                <span class="submission-type-badge badge-${submission.assignment_type}">
                    ${submission.assignment_type}
                </span>
                <h3 class="submission-title">${escapeHtml(submission.assignment_title)}</h3>
                <div class="submission-meta">
                    <span class="subject-badge" style="background-color: ${submission.theme_color || '#1a73e8'}">
                        <i class="fas fa-book"></i>
                        ${escapeHtml(submission.subject_name)}
                    </span>
                    <div class="meta-item">
                        <i class="fas fa-user-tie"></i>
                        <span>${escapeHtml(submission.teacher_name)}</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Due: ${dueDate}</span>
                    </div>
                </div>
            </div>
            <div class="submission-status">
                ${statusBadgeHTML}
                ${scoreHTML}
            </div>
        </div>
        ${feedbackHTML}
        <div class="submission-card-footer">
            <div class="submission-date">
                <i class="fas fa-upload"></i>
                <span>Submitted on ${submittedDate}</span>
            </div>
            <a href="view_assignment.php?id=${submission.assignment_id}&submission_id=${submission.submission_id}" class="view-btn">
                <span>View Details</span>
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    `;
    
    return card;
}

// Setup event listeners for filters and search
function setupEventListeners() {
    const statusFilter = document.getElementById('statusFilter');
    const subjectFilter = document.getElementById('subjectFilter');
    const typeFilter = document.getElementById('typeFilter');
    const searchInput = document.getElementById('searchSubmissions');
    
    if (statusFilter) {
        statusFilter.addEventListener('change', applyFilters);
    }
    
    if (subjectFilter) {
        subjectFilter.addEventListener('change', applyFilters);
    }
    
    if (typeFilter) {
        typeFilter.addEventListener('change', applyFilters);
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', debounce(applyFilters, 300));
    }
}

// Apply all filters
function applyFilters() {
    const statusFilter = document.getElementById('statusFilter').value;
    const subjectFilter = document.getElementById('subjectFilter').value;
    const typeFilter = document.getElementById('typeFilter').value;
    const searchTerm = document.getElementById('searchSubmissions').value.toLowerCase();
    
    filteredSubmissions = allSubmissions.filter(submission => {
        // Status filter
        const statusMatch = statusFilter === 'all' || submission.status === statusFilter;
        
        // Subject filter
        const subjectMatch = subjectFilter === 'all' || submission.subject_name === subjectFilter;
        
        // Type filter
        const typeMatch = typeFilter === 'all' || submission.assignment_type === typeFilter;
        
        // Search filter
        const searchMatch = searchTerm === '' || 
            submission.assignment_title.toLowerCase().includes(searchTerm) ||
            submission.subject_name.toLowerCase().includes(searchTerm) ||
            submission.teacher_name.toLowerCase().includes(searchTerm);
        
        return statusMatch && subjectMatch && typeMatch && searchMatch;
    });
    
    displaySubmissions(filteredSubmissions);
}

// Utility function: Format date
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    const options = { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return date.toLocaleDateString('en-US', options);
}

// Utility function: Escape HTML to prevent XSS
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// Utility function: Debounce for search input
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

// Show error message
function showError(message) {
    const container = document.getElementById('submissionsContainer');
    container.innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Error</h3>
            <p>${message}</p>
        </div>
    `;
}

// Refresh submissions (can be called from other parts of the page)
function refreshSubmissions() {
    loadSubmissions();
}

// Export functions for external use if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        loadSubmissions,
        refreshSubmissions
    };
}

//==============================================================================

// Global state
let allTasks = [];
let currentFilter = 'all';
let currentSort = 'due_date';

// DOM Elements
const tasksGrid = document.getElementById('tasksGrid');
const loadingState = document.getElementById('loadingState');
const emptyState = document.getElementById('emptyState');
const filterTabs = document.querySelectorAll('.filter-tab');
const sortSelect = document.getElementById('sortBy');

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    fetchTasks();
    setupEventListeners();
});

// Setup Event Listeners
function setupEventListeners() {
    // Filter tabs
    filterTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            filterTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter = tab.dataset.filter;
            renderTasks();
        });
    });

    // Sort dropdown
    sortSelect.addEventListener('change', (e) => {
        currentSort = e.target.value;
        renderTasks();
    });
}

// Fetch tasks from server
async function fetchTasks() {
    try {
        showLoading();
        
        const response = await fetch('fetch_tasks.php');
        const data = await response.json();
        
        if (data.success) {
            allTasks = data.tasks;
            renderTasks();
        } else {
            showError(data.message || 'Failed to load tasks');
        }
    } catch (error) {
        console.error('Error fetching tasks:', error);
        showError('Failed to connect to server');
    }
}

// Render tasks
function renderTasks() {
    hideLoading();
    
    let filteredTasks = filterTasks(allTasks);
    filteredTasks = sortTasks(filteredTasks);
    
    if (filteredTasks.length === 0) {
        showEmpty();
        return;
    }
    
    hideEmpty();
    tasksGrid.innerHTML = '';
    
    filteredTasks.forEach(task => {
        const taskCard = createTaskCard(task);
        tasksGrid.appendChild(taskCard);
    });
}

// Filter tasks
function filterTasks(tasks) {
    if (currentFilter === 'all') {
        return tasks;
    }
    
    return tasks.filter(task => {
        const status = getTaskStatus(task);
        return status === currentFilter;
    });
}

// Sort tasks
function sortTasks(tasks) {
    const sorted = [...tasks];
    
    switch (currentSort) {
        case 'due_date':
            sorted.sort((a, b) => {
                const dateA = new Date(a.due_date);
                const dateB = new Date(b.due_date);
                return dateA - dateB;
            });
            break;
            
        case 'subject':
            sorted.sort((a, b) => {
                return a.subject_name.localeCompare(b.subject_name);
            });
            break;
            
        case 'points':
            sorted.sort((a, b) => {
                return parseFloat(b.max_points) - parseFloat(a.max_points);
            });
            break;
            
        case 'created':
            sorted.sort((a, b) => {
                const dateA = new Date(a.created_at);
                const dateB = new Date(b.created_at);
                return dateB - dateA;
            });
            break;
    }
    
    return sorted;
}

// Get task status
function getTaskStatus(task) {
    const now = new Date();
    const dueDate = new Date(task.due_date);
    
    if (dueDate < now) {
        return 'overdue';
    }
    
    return 'pending';
}

// Create task card
function createTaskCard(task) {
    const status = getTaskStatus(task);
    const dueDate = new Date(task.due_date);
    
    const card = document.createElement('div');
    card.className = 'task-card';
    
    card.innerHTML = `
        <div class="card-header">
            <span class="subject-tag" style="background-color: ${task.theme_color}20; color: ${task.theme_color};">
                ${escapeHtml(task.subject_name)}
            </span>
            <span class="status-badge ${status}">${capitalizeFirst(status)}</span>
        </div>
        
        <h3 class="task-title">${escapeHtml(task.title)}</h3>
        
        ${task.description ? `<p class="task-description">${escapeHtml(task.description)}</p>` : ''}
        
        <div class="task-meta">
            <span class="meta-item">
                <i class="fas fa-calendar"></i>
                <span>Due: ${formatDate(task.due_date)}</span>
            </span>
            <span class="meta-item">
                <i class="fas fa-clock"></i>
                <span>${formatTime(task.due_date)}</span>
            </span>
            <span class="meta-item">
                <i class="fas fa-user"></i>
                <span>${escapeHtml(task.teacher_name)}</span>
            </span>
        </div>
        
        <div class="card-footer">
            <span class="points-badge">${task.max_points} pts</span>
            <a href="view_assignment.php?id=${task.id}" class="view-btn">
                <span>View Details</span>
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    `;
    
    return card;
}

// Utility functions
function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    const dateOnly = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    
    if (dateOnly.getTime() === today.getTime()) {
        return 'Today';
    } else if (dateOnly.getTime() === tomorrow.getTime()) {
        return 'Tomorrow';
    }
    
    const options = { month: 'short', day: 'numeric', year: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit', 
        hour12: true 
    });
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showLoading() {
    loadingState.classList.remove('hidden');
    emptyState.classList.add('hidden');
}

function hideLoading() {
    loadingState.classList.add('hidden');
}

function showEmpty() {
    emptyState.classList.remove('hidden');
}

function hideEmpty() {
    emptyState.classList.add('hidden');
}

function showError(message) {
    hideLoading();
    tasksGrid.innerHTML = `
        <div class="empty-state" style="grid-column: 1 / -1;">
            <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>
            <p>${escapeHtml(message)}</p>
        </div>
    `;
}