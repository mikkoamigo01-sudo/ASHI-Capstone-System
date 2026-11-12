var StudentSubmissionsModule = (function() {
    'use strict';
    function loadStudentSubmissions() {
        const loadingState = document.getElementById('student-submissions-loading');
        const submissionsContainer = document.getElementById('student-submissions-container');
        const emptyState = document.getElementById('student-submissions-empty');
        const errorState = document.getElementById('student-submissions-error');
        const tbody = document.getElementById('student-submissions-tbody');

        // Check if elements exist
        if (!loadingState || !submissionsContainer || !emptyState || !errorState || !tbody) {
            console.error('Student submissions elements not found');
            return;
        }

        // Show loading state
        loadingState.style.display = 'flex';
        submissionsContainer.style.display = 'none';
        emptyState.style.display = 'none';
        errorState.style.display = 'none';

        // Fetch submissions from server
        fetch('fetch_submissions.php')
            .then(response => response.json())
            .then(data => {
                loadingState.style.display = 'none';

                if (data.error) {
                    errorState.style.display = 'flex';
                    console.error('Error:', data.error);
                    return;
                }

                if (!data.submissions || data.submissions.length === 0) {
                    emptyState.style.display = 'flex';
                    return;
                }

                // Display submissions
                tbody.innerHTML = '';
                data.submissions.forEach(submission => {
                    const row = createStudentSubmissionRow(submission);
                    tbody.appendChild(row);
                });

                submissionsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Error fetching submissions:', error);
                loadingState.style.display = 'none';
                errorState.style.display = 'flex';
            });
    }

    /**
     * Create a table row for a submission
     */
    function createStudentSubmissionRow(submission) {
        const tr = document.createElement('tr');

        // Task Information Column
        const taskInfoTd = document.createElement('td');
        taskInfoTd.innerHTML = `
            <div class="student-task-info">
                <div class="student-task-title">${escapeSubmissionHtml(submission.assignment_title)}</div>
                <div class="student-task-meta">
                    <span class="student-task-type">
                        <i class="fas ${getStudentAssignmentIcon(submission.assignment_type)}"></i>
                        ${capitalizeSubmissionFirst(submission.assignment_type)}
                    </span>
                    <span class="student-task-points">
                        <i class="fas fa-star"></i>
                        ${submission.max_points} points
                    </span>
                </div>
            </div>
        `;

        // Subject Column
        const subjectTd = document.createElement('td');
        subjectTd.innerHTML = `
            <div class="student-subject-info">
                <div class="student-subject-badge" style="background: ${hexToRgbaSubmission(submission.theme_color, 0.1)}; color: ${submission.theme_color}">
                    <span class="student-subject-color-dot" style="background: ${submission.theme_color}"></span>
                    ${escapeSubmissionHtml(submission.subject_name)}
                </div>
            </div>
        `;

        // Submitted Column
        const submittedTd = document.createElement('td');
        const submittedDate = new Date(submission.submitted_at);
        submittedTd.innerHTML = `
            <div class="student-submitted-info">
                <div class="student-submitted-date">${formatSubmissionDate(submittedDate)}</div>
                <div class="student-submitted-time">${formatSubmissionTime(submittedDate)}</div>
            </div>
        `;

        // Status Column
        const statusTd = document.createElement('td');
        statusTd.innerHTML = getStudentStatusHTML(submission);

        // Action Column
        const actionTd = document.createElement('td');
        actionTd.innerHTML = `
            <a href="view_assignment.php?id=${submission.assignment_id}" class="student-action-btn">
                <i class="fas fa-eye"></i>
                View
            </a>
        `;

        tr.appendChild(taskInfoTd);
        tr.appendChild(subjectTd);
        tr.appendChild(submittedTd);
        tr.appendChild(statusTd);
        tr.appendChild(actionTd);

        return tr;
    }

    /**
     * Get status HTML based on submission status and score
     */
    function getStudentStatusHTML(submission) {
        if (submission.score !== null) {
            // Graded
            return `
                <div class="student-grade-display">
                    <div class="student-status-badge student-status-graded">
                        <i class="fas fa-check-circle"></i>
                        Graded
                    </div>
                    <div class="student-grade-score">${submission.score}/${submission.max_points}</div>
                </div>
            `;
        } else if (submission.status === 'late') {
            // Late submission, pending review
            return `
                <div class="student-status-badge student-status-late">
                    <i class="fas fa-clock"></i>
                    Late - Pending Review
                </div>
            `;
        } else {
            // Pending review
            return `
                <div class="student-status-badge student-status-pending">
                    <i class="fas fa-hourglass-half"></i>
                    Pending Review
                </div>
            `;
        }
    }

    function getStudentAssignmentIcon(type) {
        const icons = {
            'homework': 'fa-book',
            'quiz': 'fa-question-circle',
            'project': 'fa-project-diagram',
            'exam': 'fa-file-alt',
            'classwork': 'fa-clipboard'
        };
        return icons[type] || 'fa-file';
    }

    function formatSubmissionDate(date) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
    }

    function formatSubmissionTime(date) {
        let hours = date.getHours();
        const minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        const minutesStr = minutes < 10 ? '0' + minutes : minutes;
        return `${hours}:${minutesStr} ${ampm}`;
    }


    function hexToRgbaSubmission(hex, alpha) {
        alpha = alpha || 1;
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }


    function capitalizeSubmissionFirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }


    function escapeSubmissionHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function initStudentSubmissions() {
        // Check if we're on the submissions section
        const hash = window.location.hash;
        if (hash === '#submissions') {
            loadStudentSubmissions();
        }

        // Load submissions when clicking on the submissions nav link
        const submissionsNavLink = document.querySelector('a[href="#submissions"]');
        if (submissionsNavLink) {
            submissionsNavLink.addEventListener('click', function(e) {
                setTimeout(function() {
                    loadStudentSubmissions();
                }, 100);
            });
        }

        // Add event listener to retry button
        const retryBtn = document.querySelector('.student-btn-retry');
        if (retryBtn) {
            retryBtn.addEventListener('click', function() {
                loadStudentSubmissions();
            });
        }
    }

    // Public API
    return {
        init: initStudentSubmissions,
        load: loadStudentSubmissions
    };

})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', StudentSubmissionsModule.init);
} else {
    StudentSubmissionsModule.init();
}

// Expose to global scope for manual calls
window.loadStudentSubmissions = StudentSubmissionsModule.load;