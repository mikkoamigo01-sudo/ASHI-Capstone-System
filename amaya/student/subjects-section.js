// Load subjects when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadSubjects();
});

// Function to load subjects
function loadSubjects() {
    showLoadingState();

    fetch('fetch_student_subjects.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                if (data.subjects.length === 0) {
                    showEmptyState();
                } else {
                    displaySubjects(data.subjects);
                }
            } else {
                showErrorState(data.error || 'Failed to load subjects');
            }
        })
        .catch(error => {
            console.error('Error loading subjects:', error);
            showErrorState('Unable to connect to the server. Please check your connection.');
        });
}

// Show loading state
function showLoadingState() {
    document.getElementById('subjects-loading').style.display = 'block';
    document.getElementById('subjects-error').style.display = 'none';
    document.getElementById('subjects-empty').style.display = 'none';
    document.getElementById('subjects-grid').style.display = 'none';
}

// Show error state
function showErrorState(message) {
    document.getElementById('subjects-loading').style.display = 'none';
    document.getElementById('subjects-error').style.display = 'block';
    document.getElementById('subjects-empty').style.display = 'none';
    document.getElementById('subjects-grid').style.display = 'none';
    document.getElementById('subjects-error-message').textContent = message;
}

// Show empty state
function showEmptyState() {
    document.getElementById('subjects-loading').style.display = 'none';
    document.getElementById('subjects-error').style.display = 'none';
    document.getElementById('subjects-empty').style.display = 'block';
    document.getElementById('subjects-grid').style.display = 'none';
}

// Display subjects in grid
function displaySubjects(subjects) {
    document.getElementById('subjects-loading').style.display = 'none';
    document.getElementById('subjects-error').style.display = 'none';
    document.getElementById('subjects-empty').style.display = 'none';
    document.getElementById('subjects-grid').style.display = 'grid';

    const grid = document.getElementById('subjects-grid');
    grid.innerHTML = '';

    subjects.forEach(subject => {
        const card = createSubjectCard(subject);
        grid.appendChild(card);
    });
}

// Create subject card element
// Create subject card element
function createSubjectCard(subject) {
    const card = document.createElement('div');
    card.className = 'subject-card' + (subject.is_adviser == 1 ? ' adviser-subject' : '');

    // Format enrollment date
    const enrollDate = new Date(subject.enrollment_date);
    const formattedDate = enrollDate.toLocaleDateString('en-US', { 
        month: 'short', 
        day: 'numeric', 
        year: 'numeric' 
    });

    card.innerHTML = `
        <div class="subject-card-header" style="background: ${escapeHtml(subject.theme_color)};">
            <img src="../teacher/php/${escapeHtml(subject.logo_path)}" 
                 class="subject-logo" 
                 alt="Subject Logo" 
                 onerror="this.src='../teacher/img/default-subject.gif'">
            <div>
                <h3 class="subject-name">${escapeHtml(subject.subject_name)}</h3>
                <div class="subject-teacher">
                    <i class="fas fa-user-tie"></i>
                    ${escapeHtml(subject.teacher_name)}
                </div>
                ${subject.is_adviser == 1 ? `
                    <div class="adviser-badge">
                        <i class="fas fa-star"></i> My Adviser
                    </div>
                ` : ''}
            </div>
        </div>
        <div class="subject-card-body">
            <div class="subject-meta">
                <div class="meta-item">
                    <i class="fas fa-layer-group"></i>
                    Grade ${escapeHtml(subject.grade)}
                </div>
                ${subject.section ? `
                    <div class="meta-item">
                        <i class="fas fa-users"></i>
                        ${escapeHtml(subject.section)}
                    </div>
                ` : ''}
            </div>
            ${subject.description ? `
                <div class="subject-description">
                    ${escapeHtml(subject.description)}
                </div>
            ` : ''}
            <div class="subject-footer">
                <div class="class-code-badge">
                    <i class="fas fa-key"></i>
                    ${escapeHtml(subject.class_code)}
                </div>
                <div class="enrolled-date">
                    Enrolled ${formattedDate}
                </div>
            </div>
            <div class="subject-actions">
                <button class="btn-view-subject" onclick="viewSubject(${subject.id})">
                    <i class="fas fa-eye"></i>
                    Open Class
                </button>
                <button class="btn-leave-subject" onclick="leaveSubject(${subject.id}, '${escapeHtml(subject.subject_name)}')">
                    <i class="fas fa-sign-out-alt"></i>
                    Leave
                </button>
            </div>
        </div>
    `;

    return card;
}

// View subject function
function viewSubject(subjectId) {
    window.location.href = `view_subject.php?id=${subjectId}`;
}

// Leave subject function
function leaveSubject(subjectId, subjectName) {
    if (confirm(`Are you sure you want to leave "${subjectName}"?\n\nYou will lose access to all materials and assignments for this class.`)) {
        window.location.href = `unenroll_subject.php?id=${subjectId}`;
    }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Refresh subjects
function refreshSubjects() {
    loadSubjects();
}

// Open join class modal
function openJoinModal() {
    const modal = document.getElementById('joinClassModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    setTimeout(() => {
        document.getElementById('classCode').focus();
    }, 100);
}

// Close join class modal
function closeJoinModal() {
    const modal = document.getElementById('joinClassModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    
    document.getElementById('joinClassForm').reset();
    
    const messageDiv = document.getElementById('modalMessage');
    messageDiv.style.display = 'none';
    messageDiv.className = 'modal-message';
}

// Handle join class form submission
function handleJoinClass(event) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = form.querySelector('.btn-modal-submit');
    const classCodeInput = document.getElementById('classCode');
    const classCode = classCodeInput.value.trim().toUpperCase();
    const messageDiv = document.getElementById('modalMessage');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Joining...';
    
    messageDiv.style.display = 'none';
    
    const formData = new FormData();
    formData.append('class_code', classCode);
    
    fetch('join_class.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageDiv.className = 'modal-message success';
            messageDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            messageDiv.style.display = 'block';
            
            classCodeInput.value = '';
            
            setTimeout(() => {
                closeJoinModal();
                loadSubjects();
            }, 1500);
        } else {
            messageDiv.className = 'modal-message error';
            messageDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            messageDiv.style.display = 'block';
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Join Class';
        }
    })
    .catch(error => {
        console.error('Error joining class:', error);
        
        messageDiv.className = 'modal-message error';
        messageDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to join class. Please try again.';
        messageDiv.style.display = 'block';
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Join Class';
    });
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('joinClassModal');
        if (modal && modal.classList.contains('active')) {
            closeJoinModal();
        }
    }
});

// Auto-capitalize class code input
document.addEventListener('DOMContentLoaded', function() {
    const classCodeInput = document.getElementById('classCode');
    if (classCodeInput) {
        classCodeInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });
    }
});