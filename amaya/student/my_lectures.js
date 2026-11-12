// Lectures & Materials JavaScript
// ================================

class LecturesManager {
    constructor() {
        this.materials = [];
        this.filteredMaterials = [];
        this.currentFilter = 'all';
        this.currentSort = 'recent';
        this.init();
    }

    init() {
        this.attachEventListeners();
        this.loadMaterials();
    }

    attachEventListeners() {
        // Filter change
        const typeFilter = document.getElementById('materialTypeFilter');
        if (typeFilter) {
            typeFilter.addEventListener('change', (e) => {
                this.currentFilter = e.target.value;
                this.filterAndRenderMaterials();
            });
        }

        // Sort change
        const sortFilter = document.getElementById('materialSortFilter');
        if (sortFilter) {
            sortFilter.addEventListener('change', (e) => {
                this.currentSort = e.target.value;
                this.filterAndRenderMaterials();
            });
        }
    }

    async loadMaterials() {
        const container = document.getElementById('lectures');
        
        // Show loading state
        this.showLoading(container);

        try {
            const response = await fetch('fetch_materials.php');
            const data = await response.json();

            if (data.success) {
                this.materials = data.materials;
                this.filteredMaterials = [...this.materials];
                this.filterAndRenderMaterials();
            } else {
                this.showError(container, data.message || 'Failed to load materials');
            }
        } catch (error) {
            console.error('Error loading materials:', error);
            this.showError(container, 'Failed to load materials. Please try again.');
        }
    }

    filterAndRenderMaterials() {
        // Apply filters
        this.filteredMaterials = this.materials.filter(material => {
            if (this.currentFilter === 'all') return true;
            return material.material_type === this.currentFilter;
        });

        // Apply sorting
        this.filteredMaterials.sort((a, b) => {
            switch (this.currentSort) {
                case 'recent':
                    return new Date(b.created_at) - new Date(a.created_at);
                case 'oldest':
                    return new Date(a.created_at) - new Date(b.created_at);
                case 'title':
                    return a.title.localeCompare(b.title);
                default:
                    return 0;
            }
        });

        this.renderMaterials();
    }

    renderMaterials() {
        const container = document.getElementById('lectures');
        
        if (this.filteredMaterials.length === 0) {
            this.showEmpty(container);
            return;
        }

        const html = `

            <div class="lectures-controls">
                <div class="lectures-filter">
                    <div class="filter-group">
                        <label class="filter-label" for="materialTypeFilter">Filter by Type</label>
                        <select id="materialTypeFilter" class="filter-select">
                            <option value="all" ${this.currentFilter === 'all' ? 'selected' : ''}>All Materials</option>
                            <option value="file" ${this.currentFilter === 'file' ? 'selected' : ''}>Files Only</option>
                            <option value="link" ${this.currentFilter === 'link' ? 'selected' : ''}>Links Only</option>
                            <option value="video" ${this.currentFilter === 'video' ? 'selected' : ''}>Videos Only</option>
                            <option value="document" ${this.currentFilter === 'document' ? 'selected' : ''}>Documents Only</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="materialSortFilter">Sort By</label>
                        <select id="materialSortFilter" class="filter-select">
                            <option value="recent" ${this.currentSort === 'recent' ? 'selected' : ''}>Most Recent First</option>
                            <option value="oldest" ${this.currentSort === 'oldest' ? 'selected' : ''}>Oldest First</option>
                            <option value="title" ${this.currentSort === 'title' ? 'selected' : ''}>Title (A-Z)</option>
                        </select>
                    </div>
                </div>
                <div class="lectures-count">
                    Showing <strong>${this.filteredMaterials.length}</strong> of <strong>${this.materials.length}</strong>
                </div>
            </div>

            <div class="lectures-grid">
                ${this.filteredMaterials.map((material, index) => this.createMaterialCard(material, index)).join('')}
            </div>
        `;

        container.innerHTML = html;
        
        // Re-attach event listeners after render
        this.attachEventListeners();
        this.attachCardClickListeners();
    }

    createMaterialCard(material, index) {
        const iconClass = this.getIconClass(material.material_type, material.file_extension);
        const teacherInitial = material.teacher_name ? material.teacher_name.charAt(0).toUpperCase() : '?';
        
        return `
            <div class="material-card" data-material-id="${material.id}" style="animation-delay: ${index * 0.05}s">
                <div class="material-card-header">
                    <div class="material-icon ${material.material_type}">
                        <i class="fas ${iconClass}"></i>
                    </div>
                    <div class="material-header-info">
                        <span class="material-type-badge ${material.material_type}">
                            ${this.formatMaterialType(material.material_type)}
                        </span>
                        <h3 class="material-title">${this.escapeHtml(material.title)}</h3>
                    </div>
                </div>

                <div class="material-card-body">
                    ${material.description ? `
                        <p class="material-description">${this.escapeHtml(material.description)}</p>
                    ` : ''}
                    
                    <div class="material-subject" style="background: ${material.theme_color}15; color: ${material.theme_color}">
                        <span class="material-subject-dot" style="background: ${material.theme_color}"></span>
                        <span>${this.escapeHtml(material.subject_name)}${material.section ? ' - ' + this.escapeHtml(material.section) : ''}</span>
                    </div>

                    ${material.material_type === 'file' && material.file_size ? `
                        <div class="material-file-info">
                            <i class="fas fa-file"></i>
                            <span>${material.file_name || 'Download file'}</span>
                            <span>•</span>
                            <span>${this.formatFileSize(material.file_size)}</span>
                        </div>
                    ` : ''}
                </div>

                <div class="material-card-footer">
                    <div class="material-teacher">
                        <div class="material-teacher-avatar">
                            ${material.teacher_picture ? 
                                `<img src="../teacher/${this.escapeHtml(material.teacher_picture)}" alt="${this.escapeHtml(material.teacher_name)}" onerror="this.style.display='none'; this.parentElement.textContent='${teacherInitial}';">` 
                                : teacherInitial
                            }
                        </div>
                        <div class="material-teacher-info">
                            <span class="material-teacher-name">${this.escapeHtml(material.teacher_name)}</span>
                            <span class="material-date">${material.time_ago}</span>
                        </div>
                    </div>
                    <div class="material-action">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
        `;
    }

    attachCardClickListeners() {
        const cards = document.querySelectorAll('.material-card');
        cards.forEach(card => {
            card.addEventListener('click', () => {
                const materialId = card.dataset.materialId;
                this.openMaterial(materialId);
            });
        });
    }

    openMaterial(materialId) {
        // Redirect to material view page
        window.location.href = `view_student_material.php?id=${materialId}`;
    }

    showLoading(container) {
        container.innerHTML = `
            <div class="lectures-loading">
                <div class="loading-spinner"></div>
                <p class="loading-text">Loading materials...</p>
            </div>
        `;
    }

    showEmpty(container) {
        const filterMessage = this.currentFilter === 'all' 
            ? 'No materials have been shared yet'
            : `No ${this.currentFilter} materials found`;

        container.innerHTML = `

            <div class="lectures-controls">
                <div class="lectures-filter">
                    <div class="filter-group">
                        <label class="filter-label" for="materialTypeFilter">Filter by Type</label>
                        <select id="materialTypeFilter" class="filter-select">
                            <option value="all" ${this.currentFilter === 'all' ? 'selected' : ''}>All Materials</option>
                            <option value="file" ${this.currentFilter === 'file' ? 'selected' : ''}>Files Only</option>
                            <option value="link" ${this.currentFilter === 'link' ? 'selected' : ''}>Links Only</option>
                            <option value="video" ${this.currentFilter === 'video' ? 'selected' : ''}>Videos Only</option>
                            <option value="document" ${this.currentFilter === 'document' ? 'selected' : ''}>Documents Only</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="materialSortFilter">Sort By</label>
                        <select id="materialSortFilter" class="filter-select">
                            <option value="recent" ${this.currentSort === 'recent' ? 'selected' : ''}>Most Recent First</option>
                            <option value="oldest" ${this.currentSort === 'oldest' ? 'selected' : ''}>Oldest First</option>
                            <option value="title" ${this.currentSort === 'title' ? 'selected' : ''}>Title (A-Z)</option>
                        </select>
                    </div>
                </div>
                <div class="lectures-count">
                    Showing <strong>0</strong> of <strong>${this.materials.length}</strong>
                </div>
            </div>

            <div class="lectures-empty">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3>${filterMessage}</h3>
                <p>Materials shared by your teachers will appear here</p>
            </div>
        `;

        // Re-attach event listeners
        this.attachEventListeners();
    }

    showError(container, message) {
        container.innerHTML = `
            <div class="lectures-error">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Error Loading Materials</h3>
                <p>${this.escapeHtml(message)}</p>
                <button class="btn-retry" onclick="lecturesManager.loadMaterials()">
                    <i class="fas fa-redo"></i> Try Again
                </button>
            </div>
        `;
    }

    // Helper functions
    getIconClass(type, extension) {
        if (type === 'video') return 'fa-play-circle';
        if (type === 'link') return 'fa-link';
        if (type === 'document') return 'fa-file-alt';
        
        // File type icons based on extension
        const iconMap = {
            'pdf': 'fa-file-pdf',
            'doc': 'fa-file-word',
            'docx': 'fa-file-word',
            'xls': 'fa-file-excel',
            'xlsx': 'fa-file-excel',
            'ppt': 'fa-file-powerpoint',
            'pptx': 'fa-file-powerpoint',
            'jpg': 'fa-file-image',
            'jpeg': 'fa-file-image',
            'png': 'fa-file-image',
            'gif': 'fa-file-image',
            'zip': 'fa-file-archive',
            'rar': 'fa-file-archive',
            'txt': 'fa-file-alt',
            'mp4': 'fa-file-video',
            'mp3': 'fa-file-audio',
        };

        return iconMap[extension] || 'fa-file';
    }

    formatMaterialType(type) {
        const typeMap = {
            'file': 'File',
            'link': 'Link',
            'video': 'Video',
            'document': 'Document'
        };
        return typeMap[type] || type;
    }

    formatFileSize(bytes) {
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2) + ' GB';
        } else if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        } else {
            return bytes + ' bytes';
        }
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
    }
}

// Initialize lectures manager when DOM is ready
let lecturesManager;

document.addEventListener('DOMContentLoaded', () => {
    const lecturesSection = document.getElementById('lectures');
    if (lecturesSection) {
        lecturesManager = new LecturesManager();
    }
});

// Export for global access
window.LecturesManager = LecturesManager;