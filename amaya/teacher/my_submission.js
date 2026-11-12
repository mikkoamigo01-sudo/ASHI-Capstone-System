let allSubmissions = [];
let filteredSubmissions = [];

// Initialize submissions when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Load submissions when section is first viewed
    const submissionsLink = document.querySelector('[data-section="submissions"]');
    if (submissionsLink) {
        submissionsLink.addEventListener('click', function() {
            loadSubmissions();
        });
    }

    // Initialize badge count
    updateSubmissionsBadge();

    // Set up search and filter listeners
    setupSubmissionsListeners();
});

// Set up event listeners for search and filters
function setupSubmissionsListeners() {
    const searchInput = document.getElementById('submissionSearch');
    const sortSelect = document.getElementById('sortSubmissions');
    const filterSubject = document.getElementById('filterSubject');
    const filterStatus = document.getElementById('filterStatus');

    if (searchInput) {
        searchInput.addEventListener('input', debounce(filterSubmissions, 300));
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', filterSubmissions);
    }

    if (filterSubject) {
        filterSubject.addEventListener('change', filterSubmissions);
    }

    if (filterStatus) {
        filterStatus.addEventListener('change', filterSubmissions);
    }
}

// Debounce function for search input
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

// Load submissions from server
async function loadSubmissions() {
    const container = document.getElementById('submissionsContainer');
    const loadingState = document.getElementById('loadingState');
    const emptyState = document.getElementById('emptyState');
    const grid = document.getElementById('submissionsGrid');

    // Show loading state
    loadingState.style.display = 'flex';
    emptyState.style.display = 'none';
    grid.style.display = 'none';

    try {
        const response = await fetch('get_submissions.php');
        const data = await response.json();

        if (data.success) {
            allSubmissions = data.submissions;
            filteredSubmissions = [...allSubmissions];

            // Populate subject filter dropdown
            populateSubjectFilter(data.subjects);

            // Display submissions
            filterSubmissions();

            loadingState.style.display = 'none';
        } else {
            throw new Error(data.message || 'Failed to load submissions');
        }
    } catch (error) {
        console.error('Error loading submissions:', error);
        loadingState.innerHTML = `
            <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
            <p>Error loading submissions. Please try again.</p>
        `;
    }
}

// Populate subject filter dropdown
function populateSubjectFilter(subjects) {
    const filterSubject = document.getElementById('filterSubject');
    if (!filterSubject) return;

    // Keep the "All Subjects" option
    filterSubject.innerHTML = '<option value="all">All Subjects</option>';

    subjects.forEach(subject => {
        const option = document.createElement('option');
        option.value = subject.id;
        option.textContent = subject.subject_name;
        filterSubject.appendChild(option);
    });
}

// Filter and sort submissions
function filterSubmissions() {
    const searchTerm = document.getElementById('submissionSearch')?.value.toLowerCase() || '';
    const sortBy = document.getElementById('sortSubmissions')?.value || 'newest';
    const subjectFilter = document.getElementById('filterSubject')?.value || 'all';
    const statusFilter = document.getElementById('filterStatus')?.value || 'ungraded';

    // Filter submissions
    filteredSubmissions = allSubmissions.filter(submission => {
        // Search filter
        const matchesSearch = 
            submission.student_name.toLowerCase().includes(searchTerm) ||
            submission.assignment_title.toLowerCase().includes(searchTerm) ||
            submission.subject_name.toLowerCase().includes(searchTerm);

        // Subject filter
        const matchesSubject = subjectFilter === 'all' || submission.subject_id == subjectFilter;

        // Status filter
        let matchesStatus = true;
        if (statusFilter === 'ungraded') {
            matchesStatus = submission.status === 'submitted' || submission.status === 'late';
        } else if (statusFilter === 'submitted') {
            matchesStatus = submission.status === 'submitted';
        } else if (statusFilter === 'late') {
            matchesStatus = submission.status === 'late';
        }

        return matchesSearch && matchesSubject && matchesStatus;
    });

    // Sort submissions
    sortSubmissionsData(sortBy);

    // Display submissions
    displaySubmissions();
}

// Sort submissions based on selected criteria
function sortSubmissionsData(sortBy) {
    switch (sortBy) {
        case 'newest':
            filteredSubmissions.sort((a, b) => new Date(b.submitted_at) - new Date(a.submitted_at));
            break;
        case 'oldest':
            filteredSubmissions.sort((a, b) => new Date(a.submitted_at) - new Date(b.submitted_at));
            break;
        case 'student_name':
            filteredSubmissions.sort((a, b) => a.student_name.localeCompare(b.student_name));
            break;
        case 'subject':
            filteredSubmissions.sort((a, b) => a.subject_name.localeCompare(b.subject_name));
            break;
        case 'due_date':
            filteredSubmissions.sort((a, b) => new Date(a.due_date) - new Date(b.due_date));
            break;
    }
}

function displaySubmissions() {
    const grid = document.getElementById('submissionsGrid');
    const emptyState = document.getElementById('emptyState');
    const loadingState = document.getElementById('loadingState');

    loadingState.style.display = 'none';

    if (filteredSubmissions.length === 0) {
        grid.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }

    grid.style.display = 'flex';
    emptyState.style.display = 'none';

    grid.innerHTML = filteredSubmissions.map(submission => {
        // Profile picture path:
        // - student_form.php saves to: uploads/profiles/filename.jpg
        // - Database stores: uploads/profiles/filename.jpg
        // - PHP strips to: filename.jpg
        // - We need: ../student/uploads/profiles/filename.jpg (with 's')
        const avatarContent = submission.profile_picture
            ? `<img src="../student/uploads/profiles/${submission.profile_picture}" alt="${escapeHtml(submission.student_name)}" onerror="this.parentElement.innerHTML='${getInitials(submission.student_name)}'">`
            : getInitials(submission.student_name);

        // Status badge class
        const statusClass = submission.status === 'late' ? 'late' : 'submitted';
        const statusLabel = submission.status === 'late' ? 'Late' : 'Submitted';

        // Attachment count
        const hasAttachments = submission.attachment_count > 0;

        return `
        <div class="submission-card" onclick="openGradeModal(${submission.id})">

            <!-- TOP ROW: Avatar + Name (fixed top-left) + Badge -->
            <div class="submission-card-header">
                <div class="student-info">
                    <div class="student-avatar">${avatarContent}</div>
                    <div class="student-details">
                        <h4>${escapeHtml(submission.student_name)}</h4>
                        <p>${escapeHtml(submission.student_email)}</p>
                    </div>
                </div>
                <span class="status-badge ${statusClass}">${statusLabel}</span>
            </div>

            <!-- BODY: Assignment info + meta -->
            <div class="submission-card-body">
                <h3 class="assignment-title">${escapeHtml(submission.assignment_title)}</h3>
                <span class="subject-tag">${escapeHtml(submission.subject_name)}</span>

                <div class="submission-meta">
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span>${formatDate(submission.submitted_at)}</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Due ${formatDate(submission.due_date)}</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-star"></i>
                        <span>${submission.max_points} pts</span>
                    </div>
                    ${hasAttachments ? `
                    <div class="meta-item">
                        <i class="fas fa-paperclip"></i>
                        <span>${submission.attachment_count} file${submission.attachment_count > 1 ? 's' : ''}</span>
                    </div>` : ''}
                </div>
            </div>

            <!-- FOOTER: Buttons -->
            <div class="submission-card-footer">
                <button class="btn-view" onclick="event.stopPropagation(); openGradeModal(${submission.id})">
                    <i class="fas fa-check-circle"></i> Grade
                </button>
            </div>
        </div>`;
    }).join('');
}

// Open grade modal
async function openGradeModal(submissionId) {
    const modal = document.getElementById('gradeModal');
    const submission = allSubmissions.find(s => s.id === submissionId);

    if (!submission) {
        alert('Submission not found');
        return;
    }

    // Populate modal with submission details
    document.getElementById('modalStudentName').textContent = submission.student_name;
    document.getElementById('modalAssignmentTitle').textContent = submission.assignment_title;
    document.getElementById('modalSubjectName').textContent = submission.subject_name;
    document.getElementById('modalSubmittedAt').textContent = formatDateTime(submission.submitted_at);
    
    const statusBadge = document.getElementById('modalStatus');
    statusBadge.textContent = capitalizeFirst(submission.status);
    statusBadge.className = `status-badge ${submission.status}`;

    document.getElementById('modalSubmissionText').textContent = submission.submission_text || 'No text submitted';
    document.getElementById('submissionId').value = submission.id;
    document.getElementById('maxPoints').textContent = `(Max: ${submission.max_points} points)`;
    document.getElementById('score').max = submission.max_points;

    // Load attachments
    await loadSubmissionAttachments(submissionId);

    // Show modal
    modal.classList.add('active');
}

// Load submission attachments with VIEW option only
async function loadSubmissionAttachments(submissionId) {
    const attachmentsList = document.getElementById('modalAttachmentsList');
    const attachmentsSection = document.getElementById('modalAttachments');

    try {
        const response = await fetch(`get_submission_attachments.php?submission_id=${submissionId}`);
        const data = await response.json();

        if (data.success && data.attachments.length > 0) {
            attachmentsSection.style.display = 'block';
            attachmentsList.innerHTML = data.attachments.map(att => {
                // Database already stores: 'student/upload/submissions/filename.ext'
                // Teacher dashboard is at: ashi/amaya/teacher/teacher_dashb.php
                // So we need: ../student/upload/submissions/filename.ext
                // Since database has 'student/upload/submissions/', we just add '../'
                const filePath = `../${att.file_path}`;
                
                return `
                <div class="attachment-item-container">
                    <div class="attachment-item">
                        <i class="fas fa-file"></i>
                        <span>${escapeHtml(att.file_name)}</span>
                        <span style="margin-left: auto; font-size: 12px; color: #9ca3af;">
                            ${formatFileSize(att.file_size)}
                        </span>
                    </div>
                    <div class="attachment-actions">
                        <button class="btn-view-file" onclick="viewAttachment('${filePath}', '${escapeHtml(att.file_name)}')" title="View File">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                `;
            }).join('');
        } else {
            attachmentsSection.style.display = 'none';
        }
    } catch (error) {
        console.error('Error loading attachments:', error);
        attachmentsSection.style.display = 'none';
    }
}

// View attachment in new tab
function viewAttachment(filePath, fileName) {
    window.open(filePath, '_blank');
}

// Close grade modal
function closeGradeModal() {
    const modal = document.getElementById('gradeModal');
    modal.classList.remove('active');
    document.getElementById('gradeForm').reset();
}

// Submit grade
async function submitGrade(event) {
    event.preventDefault();

    const formData = new FormData(event.target);
    const submitButton = event.target.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;

    // Disable button and show loading state
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const response = await fetch('submit_grade.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Show success message
            alert('Grade submitted successfully!');

            // Close modal
            closeGradeModal();

            // Reload submissions
            await loadSubmissions();

            // Update badge count
            updateSubmissionsBadge();
        } else {
            throw new Error(data.message || 'Failed to submit grade');
        }
    } catch (error) {
        console.error('Error submitting grade:', error);
        alert('Error submitting grade: ' + error.message);
    } finally {
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    }
}

// Update submissions badge count
async function updateSubmissionsBadge() {
    try {
        const response = await fetch('get_ungraded_count.php');
        const data = await response.json();

        if (data.success) {
            const badge = document.getElementById('submissionsBadge');
            if (badge) {
                badge.textContent = data.count;
                if (data.count === 0) {
                    badge.style.display = 'none';
                } else {
                    badge.style.display = 'inline-block';
                }
            }
        }
    } catch (error) {
        console.error('Error updating badge count:', error);
    }
}

// ===================================
// UTILITY FUNCTIONS
// ===================================

// Get initials from name
function getInitials(name) {
    return name
        .split(' ')
        .map(word => word[0])
        .join('')
        .toUpperCase()
        .substring(0, 2);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Capitalize first letter
function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Format date
function formatDate(dateString) {
    const date = new Date(dateString);
    const options = { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric'
    };
    return date.toLocaleDateString('en-US', options);
}

// Format date and time
function formatDateTime(dateString) {
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

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('gradeModal');
    if (event.target === modal) {
        closeGradeModal();
    }
});