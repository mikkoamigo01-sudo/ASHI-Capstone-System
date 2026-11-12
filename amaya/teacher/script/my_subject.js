(function() {
    'use strict';
    
    const teacherSubjectModal = document.getElementById("teacherCreateModal");
    const teacherOpenBtn = document.getElementById("teacherOpenCreateModal");
    const teacherCancelBtn = document.getElementById("teacherCancelCreate");
    const teacherCreateBtn = document.getElementById("teacherCreateSubject");
    const teacherSubjectContainer = document.getElementById("teacherSubjectContainer");
    const teacherEmptyState = document.getElementById("teacherSubjectsEmptyState");

    const teacherSubjectName = document.getElementById("teacherSubjectName");
    const teacherSubjectGrade = document.getElementById("teacherSubjectGrade");
    const teacherSubjectSection = document.getElementById("teacherSubjectSection");
    const teacherLogoInput = document.getElementById("teacherSubjectLogo");
    const teacherLogoPreview = document.getElementById("teacherLogoPreview");
    const teacherColorOptions = document.querySelectorAll(".teacher-subject-color-option");

    const teacherSubjectColors = ["#1a73e8","#34a853","#9c27b0","#fb8c00","#e53935","#d81b60","#00897b","#546e7a"];
    let teacherLogoData = "";
    let teacherSelectedColor = "";

    let globalDropdown = null;
    let currentDropdownSubjectId = null;

    function checkTeacherEmptyState() {
        const hasCards = teacherSubjectContainer.querySelectorAll('.teacher-subject-card').length > 0;
        teacherEmptyState.style.display = hasCards ? 'none' : 'block';
    }

    function resetTeacherForm() {
        teacherSubjectName.value = "";
        teacherSubjectGrade.value = "";
        teacherSubjectSection.innerHTML = '<option value="">Select grade first</option>';
        teacherSubjectSection.disabled = true;

        teacherLogoInput.value = "";
        teacherLogoPreview.src = "";
        teacherLogoPreview.classList.add("hidden");
        teacherLogoData = "";

        teacherSelectedColor = "";
        teacherColorOptions.forEach(opt => opt.classList.remove("color-selected"));

        teacherCreateBtn.disabled = true;
    }

    teacherColorOptions.forEach(option => {
        option.addEventListener("click", () => {
            teacherColorOptions.forEach(opt => opt.classList.remove("color-selected"));
            option.classList.add("color-selected");
            teacherSelectedColor = option.dataset.color;
        });
    });

    teacherOpenBtn.onclick = () => {
        resetTeacherForm();
        teacherSubjectModal.classList.remove("hidden");
    };

    teacherCancelBtn.onclick = () => {
        resetTeacherForm();
        teacherSubjectModal.classList.add("hidden");
    };

    teacherSubjectGrade.onchange = async () => {
        const grade = teacherSubjectGrade.value;
        teacherSubjectSection.innerHTML = '<option value="">Loading...</option>';
        teacherSubjectSection.disabled = true;

        if (!grade) {
            teacherSubjectSection.innerHTML = '<option value="">Select grade first</option>';
            validateTeacherForm();
            return;
        }

        try {
            const res = await fetch(`php/teacher_subject_operations.php?action=get_sections&grade=${encodeURIComponent(grade)}`);
            const data = await res.json();

            if (data.success && data.sections.length > 0) {
                teacherSubjectSection.innerHTML = '<option value="">Select section</option>';
                data.sections.forEach(sec => {
                    const opt = document.createElement('option');
                    opt.value = sec.section_name;
                    opt.textContent = sec.section_name;
                    teacherSubjectSection.appendChild(opt);
                });
                teacherSubjectSection.disabled = false;
            } else {
                teacherSubjectSection.innerHTML = '<option value="">No sections available</option>';
            }
        } catch (err) {
            console.error('Error fetching sections:', err);
            teacherSubjectSection.innerHTML = '<option value="">Failed to load sections</option>';
        }

        validateTeacherForm();
    };

    teacherSubjectSection.onchange = validateTeacherForm;
    teacherSubjectName.oninput = validateTeacherForm;

    function validateTeacherForm() {
        teacherCreateBtn.disabled = !teacherSubjectName.value || !teacherSubjectGrade.value || !teacherSubjectSection.value || !teacherLogoData;
    }

    teacherLogoInput.onchange = () => {
        const file = teacherLogoInput.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = () => {
            teacherLogoData = reader.result;
            teacherLogoPreview.src = teacherLogoData;
            teacherLogoPreview.classList.remove("hidden");
            validateTeacherForm();
        };
        reader.readAsDataURL(file);
    };

    function loadTeacherSubjects() {
        fetch('php/teacher_subject_operations.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    teacherSubjectContainer.innerHTML = '';
                    data.subjects.forEach(subject => {
                        createTeacherSubjectCard(subject);
                    });
                    checkTeacherEmptyState();
                } else {
                    console.error('Failed to load subjects:', data.message);
                }
            })
            .catch(err => {
                console.error('Error loading subjects:', err);
            });
    }

    function createTeacherSubjectCard(subject) {
        const card = document.createElement("div");
        card.className = "teacher-subject-card";
        card.dataset.status = subject.status;
        card.dataset.subjectId = subject.id;

        if (subject.status === 'disabled') {
            card.classList.add('subject-disabled');
        }

        const color = subject.theme_color;

        card.innerHTML = `
            <div class="teacher-subject-card-banner" style="background: linear-gradient(135deg, ${color} 0%, ${adjustTeacherColor(color, -20)} 100%);">
                <img src="php/${subject.logo_path}">
                <div class="teacher-subject-banner-info">
                    <strong>${subject.subject_name}</strong>
                    <small>Class Code: ${subject.class_code}</small>
                </div>
                <div class="teacher-subject-menu" data-subject-id="${subject.id}" data-status="${subject.status}">
                    ⋮
                </div>
            </div>

            <div class="teacher-subject-card-body">
                <div class="teacher-subject-info-cards">
                    <div class="teacher-subject-info-item"><i class="fas fa-users"></i>${subject.student_count || 0} Students</div>
                    <div class="teacher-subject-info-item"><i class="fas fa-layer-group"></i>${subject.section_count || 0} Sections</div>
                    <div class="teacher-subject-info-item"><i class="fas fa-tasks"></i>Tasks</div>
                </div>

                <div class="teacher-subject-section-area">
                    <strong>Assigned Sections</strong>
                    <p>${subject.section || 'No sections assigned'}</p>
                </div>

                <div class="teacher-subject-card-actions">
                    <button class="teacher-subject-btn teacher-subject-btn-primary" onclick="viewTeacherSubjectDetails(${subject.id})">View Details</button>
                    <button class="teacher-subject-btn teacher-subject-btn-text" onclick="addTeacherSubjectTask(${subject.id})">Add Task</button>
                </div>
            </div>
        `;

        teacherSubjectContainer.appendChild(card);
    }

    function createGlobalDropdown(subjectId, status, menuButton) {
        if (globalDropdown) {
            globalDropdown.remove();
        }

        const isDisabled = status === 'disabled';

        globalDropdown = document.createElement('div');
        globalDropdown.className = 'teacher-subject-menu-dropdown';
        globalDropdown.innerHTML = `
            <div class="teacher-subject-menu-item toggle-status ${isDisabled ? 'menu-highlight' : ''}" data-subject-id="${subjectId}" data-status="${status}">
                <i class="fas fa-${isDisabled ? 'check-circle' : 'ban'}"></i> 
                ${isDisabled ? 'Enable' : 'Disable'}
            </div>
            <div class="teacher-subject-menu-item menu-danger ${isDisabled ? 'menu-disabled' : ''}" data-subject-id="${subjectId}">
                <i class="fas fa-trash"></i> Remove
            </div>
        `;

        const rect = menuButton.getBoundingClientRect();
        globalDropdown.style.position = 'fixed';
        globalDropdown.style.top = `${rect.bottom + 8}px`;
        globalDropdown.style.left = `${rect.right - 180}px`;
        globalDropdown.style.zIndex = '9999';

        document.body.appendChild(globalDropdown);
        currentDropdownSubjectId = subjectId;
    }

    function closeGlobalDropdown() {
        if (globalDropdown) {
            globalDropdown.remove();
            globalDropdown = null;
            currentDropdownSubjectId = null;
        }
    }

    window.viewTeacherSubjectDetails = function(subjectId) {
        window.location.href = 'php/teacher_view_subject.php?id=' + subjectId;
    };

    window.addTeacherSubjectTask = function(subjectId) {
        window.location.href = '../quiz/quiz_creator.php?subject_id=' + subjectId;
    };

    teacherCreateBtn.onclick = async () => {
        const gradeValue = teacherSubjectGrade.value;
        const color = teacherSelectedColor || teacherSubjectColors[Math.floor(Math.random() * teacherSubjectColors.length)];

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('subject_name', teacherSubjectName.value);
        formData.append('section', teacherSubjectSection.value);
        formData.append('grade', gradeValue);
        formData.append('theme_color', color);
        formData.append('logo_data', teacherLogoData);

        teacherCreateBtn.disabled = true;
        teacherCreateBtn.textContent = 'Creating...';

        try {
            const response = await fetch('php/teacher_subject_operations.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                alert('Subject created successfully! Class Code: ' + data.class_code);
                teacherSubjectModal.classList.add("hidden");
                resetTeacherForm();
                loadTeacherSubjects();
            } else {
                alert('Error: ' + data.message);
            }
        } catch (error) {
            console.error('Error creating subject:', error);
            alert('Failed to create subject. Please try again.');
        } finally {
            teacherCreateBtn.disabled = false;
            teacherCreateBtn.textContent = 'Create Subject';
        }
    };

    function adjustTeacherColor(color, amount) {
        const num = parseInt(color.replace("#", ""), 16);
        const r = Math.max(0, Math.min(255, (num >> 16) + amount));
        const g = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amount));
        const b = Math.max(0, Math.min(255, (num & 0x0000FF) + amount));
        return "#" + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
    }

    document.addEventListener("click", (e) => {
        if (!e.target.closest(".teacher-subject-menu") && !e.target.closest(".teacher-subject-menu-dropdown")) {
            closeGlobalDropdown();
        }
    });

    document.addEventListener("click", (e) => {
        const menu = e.target.closest(".teacher-subject-menu");
        if (menu) {
            e.stopPropagation();
            const subjectId = menu.dataset.subjectId;
            const status = menu.dataset.status;

            if (currentDropdownSubjectId === subjectId && globalDropdown) {
                closeGlobalDropdown();
            } else {
                createGlobalDropdown(subjectId, status, menu);
            }
        }
    });

    document.addEventListener("click", async (e) => {
        if (e.target.closest(".toggle-status")) {
            const item = e.target.closest(".toggle-status");
            const subjectId = item.dataset.subjectId;
            const currentStatus = item.dataset.status;
            const newStatus = currentStatus === "active" ? "disabled" : "active";
            const confirmMsg = newStatus === "disabled" ? "Disable this subject?" : "Enable this subject?";

            if (!confirm(confirmMsg)) return;

            closeGlobalDropdown();

            try {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('subject_id', subjectId);
                formData.append('status', newStatus);

                const response = await fetch('php/teacher_subject_operations.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    loadTeacherSubjects();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error updating status:', error);
                alert('Failed to update status. Please try again.');
            }
        }

        if (e.target.closest(".menu-danger")) {
            const item = e.target.closest(".menu-danger");
            const subjectId = item.dataset.subjectId;

            if (confirm("Remove this subject permanently?")) {
                closeGlobalDropdown();

                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('subject_id', subjectId);

                    const response = await fetch('php/teacher_subject_operations.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        loadTeacherSubjects();
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (error) {
                    console.error('Error deleting subject:', error);
                    alert('Failed to delete subject. Please try again.');
                }
            }
        }
    });

    window.addEventListener('scroll', closeGlobalDropdown, true);

    loadTeacherSubjects();
})();