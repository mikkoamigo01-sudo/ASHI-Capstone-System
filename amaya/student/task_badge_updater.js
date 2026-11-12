// Function to fetch and update the task badge count
async function updateTaskBadge() {
    try {
        const response = await fetch('get_task_count.php');
        const data = await response.json();
        
        if (data.success) {
            const badge = document.querySelector('[data-section="tasks"] .badge-notification');
            
            if (badge) {
                const count = data.count;
                
                // Update badge text
                badge.textContent = count;
                
                // Hide badge if count is 0, show if count > 0
                if (count === 0) {
                    badge.style.display = 'none';
                } else {
                    badge.style.display = 'flex';
                }
                
                // Optional: Add pulse animation for new tasks
                if (count > 0) {
                    badge.classList.add('badge-pulse');
                    setTimeout(() => {
                        badge.classList.remove('badge-pulse');
                    }, 1000);
                }
            }
        }
    } catch (error) {
        console.error('Error updating task badge:', error);
    }
}

// Function to be called after task submission
function onTaskSubmitted() {
    // Update the badge immediately after submission
    updateTaskBadge();
    
    // Optional: Refresh the tasks list if on tasks page
    if (typeof refreshTasks === 'function') {
        refreshTasks();
    }
}

// Initialize badge on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update badge immediately
    updateTaskBadge();
    
    // Update badge every 2 minutes (120000 ms) for real-time sync
    setInterval(updateTaskBadge, 120000);
    
    // Update badge when tasks section is opened
    const tasksMenuLink = document.querySelector('[data-section="tasks"]');
    if (tasksMenuLink) {
        tasksMenuLink.addEventListener('click', function() {
            setTimeout(updateTaskBadge, 500);
        });
    }
});

// Export for use in other scripts
if (typeof window !== 'undefined') {
    window.updateTaskBadge = updateTaskBadge;
    window.onTaskSubmitted = onTaskSubmitted;
}