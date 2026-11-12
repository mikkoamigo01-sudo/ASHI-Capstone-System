let myStudentsData = [];
let myStudentsSortColumn = '';
let myStudentsSortDirection = 'asc';

// Pagination variables
let myStudentsCurrentPage = 1;
let myStudentsPerPage = 10;
let myStudentsFilteredData = [];

// Load students on page load
document.addEventListener('DOMContentLoaded', function() {
    loadMyStudents();
    
    // Search functionality
    document.getElementById('myStudentsSearchInput').addEventListener('input', filterMyStudents);
    
    // Filter functionality
    document.getElementById('myStudentsGradeFilter').addEventListener('change', filterMyStudents);
    document.getElementById('myStudentsSectionFilter').addEventListener('change', filterMyStudents);
    document.getElementById('myStudentsSubjectFilter').addEventListener('change', filterMyStudents);
    
    // Reset filters
    document.getElementById('myStudentsResetFiltersBtn').addEventListener('click', resetMyStudentsFilters);
    
    // Per page selector
    document.getElementById('myStudentsPerPageSelect').addEventListener('change', function() {
        myStudentsPerPage = parseInt(this.value);
        myStudentsCurrentPage = 1;
        displayMyStudents(myStudentsFilteredData);
    });
    
    // Sort functionality - entire header is clickable
    document.querySelectorAll('.my-students-sortable-header').forEach(header => {
        header.addEventListener('click', function() {
            const column = this.getAttribute('data-sort-column');
            sortMyStudents(column);
        });
    });
    
    // Modal close
    document.querySelector('.my-students-close-modal').addEventListener('click', function() {
        document.getElementById('myStudentDetailsModal').style.display = 'none';
    });
    
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('myStudentDetailsModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});

function loadMyStudents() {
    fetch('php/get_students.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                myStudentsData = data.students;
                myStudentsFilteredData = data.students;
                populateMyStudentsFilters(data.students);
                displayMyStudents(data.students);
                updateMyStudentsTotalCount(data.students.length);
            } else {
                showMyStudentsError(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMyStudentsError('Failed to load students');
        });
}

function populateMyStudentsFilters(students) {
    // Get unique grade levels from the teacher's SUBJECTS (sub.grade)
    const grades = [...new Set(students.flatMap(s => s.subjects.map(sub => sub.grade)))].sort();
    const gradeFilter = document.getElementById('myStudentsGradeFilter');
    gradeFilter.innerHTML = '<option value="">All Grade Levels</option>';
    grades.forEach(grade => {
        const option = document.createElement('option');
        option.value = grade;
        option.textContent = grade;
        gradeFilter.appendChild(option);
    });

    // Get unique sections
    const sections = [...new Set(students.map(s => s.section))].sort();
    const sectionFilter = document.getElementById('myStudentsSectionFilter');
    sectionFilter.innerHTML = '<option value="">All Sections</option>';
    sections.forEach(section => {
        const option = document.createElement('option');
        option.value = section;
        option.textContent = section;
        sectionFilter.appendChild(option);
    });

    // Get unique subjects
    const subjects = [...new Set(students.flatMap(s => s.subjects.map(sub => sub.subject_name)))].sort();
    const subjectFilter = document.getElementById('myStudentsSubjectFilter');
    subjectFilter.innerHTML = '<option value="">All Subjects</option>';
    subjects.forEach(subject => {
        const option = document.createElement('option');
        option.value = subject;
        option.textContent = subject;
        subjectFilter.appendChild(option);
    });
}

function displayMyStudents(students) {
    const tbody = document.getElementById('myStudentsTableBody');
    
    if (students.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="my-students-no-data-cell"><i class="fas fa-user-slash"></i><br>No students found</td></tr>';
        document.getElementById('myStudentsPaginationWrapper').style.display = 'none';
        return;
    }
    
    // Calculate pagination
    const totalPages = Math.ceil(students.length / myStudentsPerPage);
    const startIndex = (myStudentsCurrentPage - 1) * myStudentsPerPage;
    const endIndex = startIndex + myStudentsPerPage;
    const paginatedStudents = students.slice(startIndex, endIndex);
    
    // Display students
    tbody.innerHTML = paginatedStudents.map(student => `
        <tr>
            <td>
                <div class="my-students-student-name">
                    <div class="my-students-student-avatar">
                        ${student.profile_picture ? 
                            `<img src="${student.profile_picture}" alt="${student.first_name}">` : 
                            `${student.first_name.charAt(0)}${student.last_name.charAt(0)}`
                        }
                    </div>
                    <div>
                        <span>${student.first_name} ${student.last_name}</span>
                        <br><small style="color: #94a3b8;">${student.username}</small>
                    </div>
                </div>
            </td>
            <td data-label="Email">${student.email}</td>
            <td data-label="Section">${student.section}</td>
            <td data-label="Subjects Enrolled">
                <div class="my-students-subject-pills">
                    ${student.subjects.map(sub => 
                        `<span class="my-students-subject-pill">${sub.subject_name}</span>`
                    ).join('')}
                </div>
            </td>
            <td>
                <button class="my-students-btn-view" onclick="viewMyStudent(${student.id})">
                    View
                </button>
            </td>
        </tr>
    `).join('');
    
    // Update pagination controls
    updatePaginationControls(students.length, totalPages);
    
    // Show pagination wrapper
    document.getElementById('myStudentsPaginationWrapper').style.display = 'flex';
}

function updatePaginationControls(totalStudents, totalPages) {
    const startIndex = (myStudentsCurrentPage - 1) * myStudentsPerPage + 1;
    const endIndex = Math.min(myStudentsCurrentPage * myStudentsPerPage, totalStudents);
    
    // Update info text
    document.getElementById('myStudentsPaginationInfo').textContent = 
        `Showing ${startIndex} to ${endIndex} of ${totalStudents} students`;
    
    // Update previous button
    const prevBtn = document.getElementById('myStudentsPrevBtn');
    prevBtn.disabled = myStudentsCurrentPage === 1;
    
    // Update next button
    const nextBtn = document.getElementById('myStudentsNextBtn');
    nextBtn.disabled = myStudentsCurrentPage === totalPages;
    
    // Update page numbers
    renderPaginationNumbers(totalPages);
}

function renderPaginationNumbers(totalPages) {
    const container = document.getElementById('myStudentsPaginationNumbers');
    container.innerHTML = '';
    
    if (totalPages <= 1) return;
    
    const maxVisible = 7; // Maximum number of page buttons to show
    let startPage, endPage;
    
    if (totalPages <= maxVisible) {
        // Show all pages
        startPage = 1;
        endPage = totalPages;
    } else {
        // Calculate range
        const halfVisible = Math.floor(maxVisible / 2);
        
        if (myStudentsCurrentPage <= halfVisible + 1) {
            startPage = 1;
            endPage = maxVisible - 1;
        } else if (myStudentsCurrentPage >= totalPages - halfVisible) {
            startPage = totalPages - maxVisible + 2;
            endPage = totalPages;
        } else {
            startPage = myStudentsCurrentPage - halfVisible;
            endPage = myStudentsCurrentPage + halfVisible;
        }
    }
    
    // Always show first page
    if (startPage > 1) {
        container.appendChild(createPageButton(1));
        if (startPage > 2) {
            container.appendChild(createDotsElement());
        }
    }
    
    // Show page range
    for (let i = startPage; i <= endPage; i++) {
        container.appendChild(createPageButton(i));
    }
    
    // Always show last page
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            container.appendChild(createDotsElement());
        }
        container.appendChild(createPageButton(totalPages));
    }
}

function createPageButton(pageNumber) {
    const button = document.createElement('button');
    button.className = 'my-students-pagination-number';
    button.textContent = pageNumber;
    
    if (pageNumber === myStudentsCurrentPage) {
        button.classList.add('active');
    }
    
    button.addEventListener('click', function() {
        goToPage(pageNumber);
    });
    
    return button;
}

function createDotsElement() {
    const dots = document.createElement('span');
    dots.className = 'my-students-pagination-number dots';
    dots.textContent = '...';
    return dots;
}

function goToPage(pageNumber) {
    myStudentsCurrentPage = pageNumber;
    displayMyStudents(myStudentsFilteredData);
}

function previousPage() {
    if (myStudentsCurrentPage > 1) {
        myStudentsCurrentPage--;
        displayMyStudents(myStudentsFilteredData);
    }
}

function nextPage() {
    const totalPages = Math.ceil(myStudentsFilteredData.length / myStudentsPerPage);
    if (myStudentsCurrentPage < totalPages) {
        myStudentsCurrentPage++;
        displayMyStudents(myStudentsFilteredData);
    }
}

function filterMyStudents() {
    const searchTerm = document.getElementById('myStudentsSearchInput').value.toLowerCase();
    const gradeFilter = document.getElementById('myStudentsGradeFilter').value;
    const sectionFilter = document.getElementById('myStudentsSectionFilter').value;
    const subjectFilter = document.getElementById('myStudentsSubjectFilter').value;

    let filtered = myStudentsData.filter(student => {
        const matchesSearch =
            student.first_name.toLowerCase().includes(searchTerm) ||
            student.last_name.toLowerCase().includes(searchTerm) ||
            student.email.toLowerCase().includes(searchTerm);

        // Match grade against subjects.grade (not student.grade_level)
        const matchesGrade = !gradeFilter ||
            student.subjects.some(sub => sub.grade === gradeFilter);

        const matchesSection = !sectionFilter || student.section === sectionFilter;

        const matchesSubject = !subjectFilter ||
            student.subjects.some(sub => sub.subject_name === subjectFilter);

        return matchesSearch && matchesGrade && matchesSection && matchesSubject;
    });

    myStudentsFilteredData = filtered;
    myStudentsCurrentPage = 1;
    displayMyStudents(filtered);
    updateMyStudentsTotalCount(filtered.length);
}

function resetMyStudentsFilters() {
    document.getElementById('myStudentsSearchInput').value = '';
    document.getElementById('myStudentsGradeFilter').value = '';
    document.getElementById('myStudentsSectionFilter').value = '';
    document.getElementById('myStudentsSubjectFilter').value = '';
    
    // Reset sort
    myStudentsSortColumn = '';
    myStudentsSortDirection = 'asc';
    document.querySelectorAll('.my-students-sort-arrows').forEach(arrow => {
        arrow.classList.remove('my-students-active-asc', 'my-students-active-desc');
    });
    
    // Reset pagination
    myStudentsCurrentPage = 1;
    myStudentsFilteredData = myStudentsData;
    
    displayMyStudents(myStudentsData);
    updateMyStudentsTotalCount(myStudentsData.length);
}

function sortMyStudents(column) {
    // Toggle direction if same column, otherwise reset to asc
    if (myStudentsSortColumn === column) {
        myStudentsSortDirection = myStudentsSortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        myStudentsSortColumn = column;
        myStudentsSortDirection = 'asc';
    }
    
    // Update arrow indicators
    document.querySelectorAll('.my-students-sort-arrows').forEach(arrow => {
        arrow.classList.remove('my-students-active-asc', 'my-students-active-desc');
    });
    
    const activeHeader = document.querySelector(`[data-sort-column="${column}"]`);
    const activeArrow = activeHeader.querySelector('.my-students-sort-arrows');
    if (activeArrow) {
        activeArrow.classList.add(myStudentsSortDirection === 'asc' ? 'my-students-active-asc' : 'my-students-active-desc');
    }
    
    // Sort the filtered data
    const sortedData = [...myStudentsFilteredData].sort((a, b) => {
        let aVal, bVal;
        
        switch(column) {
            case 'name':
                aVal = `${a.first_name} ${a.last_name}`;
                bVal = `${b.first_name} ${b.last_name}`;
                break;
            case 'email':
                aVal = a.email;
                bVal = b.email;
                break;
            case 'grade':
                aVal = a.grade_level;
                bVal = b.grade_level;
                break;
            case 'section':
                aVal = a.section;
                bVal = b.section;
                break;
            case 'subjects':
                aVal = a.subjects.length;
                bVal = b.subjects.length;
                break;
        }
        
        if (myStudentsSortDirection === 'asc') {
            return aVal > bVal ? 1 : -1;
        } else {
            return aVal < bVal ? 1 : -1;
        }
    });
    
    myStudentsFilteredData = sortedData;
    myStudentsCurrentPage = 1; // Reset to first page when sorting
    displayMyStudents(sortedData);
}

function updateMyStudentsTotalCount(count) {
    document.getElementById('myStudentsTotalCount').textContent = count;
}

function viewMyStudent(studentId) {
    const student = myStudentsData.find(s => s.id === studentId);
    if (!student) return;
    
    const detailsHtml = `
        <div class="my-students-profile-header">
            <div class="my-students-profile-avatar">
                ${student.profile_picture ? 
                    `<img src="${student.profile_picture}" alt="${student.first_name}">` : 
                    `${student.first_name.charAt(0)}${student.last_name.charAt(0)}`
                }
            </div>
            <h2>${student.first_name} ${student.last_name}</h2>
            <p>${student.username}</p>
        </div>
        
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Email:</div>
            <div class="my-students-detail-value">${student.email}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Grade Level:</div>
            <div class="my-students-detail-value">${student.grade_level}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Section:</div>
            <div class="my-students-detail-value">${student.section}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Birthdate:</div>
            <div class="my-students-detail-value">${student.birthdate}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Contact Number:</div>
            <div class="my-students-detail-value">${student.contact_number || 'N/A'}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Guardian Name:</div>
            <div class="my-students-detail-value">${student.guardian_name}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Guardian Relationship:</div>
            <div class="my-students-detail-value">${student.relationship}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Guardian Contact:</div>
            <div class="my-students-detail-value">${student.guardian_contact || 'N/A'}</div>
        </div>
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Enrolled Subjects:</div>
            <div class="my-students-detail-value">
                <div class="my-students-subject-pills">
                    ${student.subjects.map(sub => 
                        `<span class="my-students-subject-pill">${sub.subject_name} (${sub.section})</span>`
                    ).join('')}
                </div>
            </div>
        </div>
        ${student.bio ? `
        <div class="my-students-detail-row">
            <div class="my-students-detail-label">Bio:</div>
            <div class="my-students-detail-value">${student.bio}</div>
        </div>
        ` : ''}
    `;
    
    document.getElementById('myStudentDetailsContent').innerHTML = detailsHtml;
    document.getElementById('myStudentDetailsModal').style.display = 'flex';
}

function showMyStudentsError(message) {
    const tbody = document.getElementById('myStudentsTableBody');
    tbody.innerHTML = `<tr><td colspan="5" class="my-students-no-data-cell"><i class="fas fa-exclamation-triangle"></i><br>${message}</td></tr>`;
    document.getElementById('myStudentsPaginationWrapper').style.display = 'none';
}