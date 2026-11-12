// ============================================
// TAKE QUIZ - JAVASCRIPT
// ============================================

let quizTimer = null;
let timeRemaining = 0;
let totalTime = 0;
let attemptId = null;
let autoSaveInterval = null;
let isSubmitting = false; // tracks submission state to suppress beforeunload

// ============================================
// START QUIZ
// ============================================

function startQuiz() {
    const startBtn = document.querySelector('.btn-start');
    const originalText = startBtn.innerHTML;

    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';
    startBtn.disabled = true;

    fetch('create_quiz_attempt.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ quiz_id: quizData.quizId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            attemptId = data.attempt_id;
            document.getElementById('attemptId').value = attemptId;

            document.getElementById('quizInstructions').style.display = 'none';
            document.getElementById('quizForm').style.display = 'block';

            if (quizData.timeLimit) {
                const tc = document.getElementById('timerContainer');
                if (tc) tc.style.display = 'block';
                startTimer(quizData.timeLimit * 60);
            }

            startAutoSave();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            alert(data.message || 'Failed to start quiz. Please try again.');
            startBtn.innerHTML = originalText;
            startBtn.disabled = false;
        }
    })
    .catch(() => {
        alert('An error occurred. Please try again.');
        startBtn.innerHTML = originalText;
        startBtn.disabled = false;
    });
}

// ============================================
// TIMER
// ============================================

function startTimer(seconds) {
    timeRemaining = seconds;
    totalTime = seconds;
    updateTimerDisplay();
    updateTimerProgress();

    quizTimer = setInterval(() => {
        timeRemaining--;
        updateTimerDisplay();
        updateTimerProgress();

        if (timeRemaining === 300) showTimeWarning('5 minutes remaining!');
        else if (timeRemaining === 60) showTimeWarning('1 minute remaining!');

        if (timeRemaining <= 0) {
            clearInterval(quizTimer);
            showTimeWarning('Time is up! Submitting your quiz...');
            submitQuiz(true);
        }
    }, 1000);
}

function updateTimerDisplay() {
    const minutes = Math.floor(timeRemaining / 60);
    const seconds = timeRemaining % 60;
    const el = document.getElementById('timerDisplay');
    if (el) {
        el.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        if (timeRemaining <= 60) el.style.color = '#ea4335';
        else if (timeRemaining <= 300) el.style.color = '#fbbc04';
        else el.style.color = '';
    }
}

function updateTimerProgress() {
    const bar = document.getElementById('timerProgress');
    if (bar && totalTime > 0) {
        bar.style.width = ((timeRemaining / totalTime) * 100) + '%';
        if (timeRemaining <= 60) bar.style.background = '#ea4335';
        else if (timeRemaining <= 300) bar.style.background = '#fbbc04';
        else bar.style.background = '#fff';
    }
}

function showTimeWarning(message) {
    document.querySelectorAll('.time-warning-toast').forEach(el => el.remove());
    const w = document.createElement('div');
    w.className = 'time-warning-toast';
    w.style.cssText = `
        position:fixed;top:90px;left:50%;transform:translateX(-50%);
        background:#fbbc04;color:#202124;padding:12px 24px;
        border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.2);
        z-index:10000;font-size:14px;font-weight:500;`;
    w.innerHTML = '<i class="fas fa-clock"></i> ' + message;
    document.body.appendChild(w);
    setTimeout(() => w.remove(), 3000);
}

// ============================================
// SUBMIT QUIZ
// ============================================

function submitQuiz(autoSubmit = false) {
    if (!attemptId) {
        alert('Quiz attempt not found. Please refresh and try again.');
        return;
    }
    if (isSubmitting) return; // prevent double-submit

    // Collect answers
    const form = document.getElementById('quizAnswerForm');
    const formData = new FormData(form);
    const answers = {};
    for (let [key, value] of formData.entries()) {
        if (key !== 'quiz_id' && key !== 'attempt_id') {
            answers[key] = value;
        }
    }

    const timeTaken = quizData.timeLimit
        ? (quizData.timeLimit * 60) - timeRemaining
        : null;

    // Set isSubmitting BEFORE touching DOM so beforeunload won't fire
    isSubmitting = true;

    if (quizTimer) clearInterval(quizTimer);
    if (autoSaveInterval) clearInterval(autoSaveInterval);

    showSubmittingOverlay();

    fetch('submit_quiz.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            quiz_id: quizData.quizId,
            attempt_id: attemptId,
            answers: answers,
            time_taken: timeTaken
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'quiz_result.php?attempt=' + data.attempt_id;
        } else {
            hideSubmittingOverlay();
            isSubmitting = false;
            alert(data.message || 'Failed to submit quiz. Please try again.');
        }
    })
    .catch(() => {
        hideSubmittingOverlay();
        isSubmitting = false;
        alert('An error occurred while submitting. Please try again.');
    });
}

// ============================================
// SUBMITTING OVERLAY
// ============================================

function showSubmittingOverlay() {
    let overlay = document.getElementById('submittingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'submittingOverlay';
        overlay.innerHTML = `
            <style>@keyframes quiz-spin{to{transform:rotate(360deg)}}</style>
            <div style="width:56px;height:56px;border:5px solid #e8eaed;
                border-top-color:${quizData.themeColor};border-radius:50%;
                animation:quiz-spin 0.8s linear infinite;"></div>
            <p style="font-size:18px;font-weight:600;color:#202124;margin:0;">Submitting your quiz...</p>
            <p style="font-size:14px;color:#5f6368;margin:0;">Please don't close this page</p>`;
        overlay.style.cssText = `
            position:fixed;inset:0;background:rgba(255,255,255,0.93);
            display:flex;flex-direction:column;align-items:center;
            justify-content:center;z-index:99999;gap:16px;`;
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}

function hideSubmittingOverlay() {
    const o = document.getElementById('submittingOverlay');
    if (o) o.style.display = 'none';
}

// ============================================
// AUTO-SAVE
// ============================================

function startAutoSave() {
    autoSaveInterval = setInterval(() => {
        if (!attemptId || isSubmitting) return;
        const formData = new FormData(document.getElementById('quizAnswerForm'));
        const answers = {};
        for (let [k, v] of formData.entries()) {
            if (k !== 'quiz_id' && k !== 'attempt_id') answers[k] = v;
        }
        fetch('save_quiz_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ attempt_id: attemptId, answers })
        }).catch(() => {});
    }, 30000);
}

// ============================================
// DOM READY
// ============================================

document.addEventListener('DOMContentLoaded', function () {

    const questionCards = document.querySelectorAll('.question-card');

    // ── Modified True-or-False: show/hide correction field ───────────────
    questionCards.forEach(card => {
        const tfRadios = card.querySelectorAll('input[type="radio"][name$="_tf"]');
        if (!tfRadios.length) return;

        // Extract question ID from the name (e.g. "question_42_tf" → "42")
        const qid = tfRadios[0].name.replace('question_', '').replace('_tf', '');
        const correctionBox   = document.getElementById('correction_' + qid);
        const correctionInput = correctionBox ? correctionBox.querySelector('input[type="text"]') : null;

        tfRadios.forEach(radio => {
            radio.addEventListener('change', function () {
                const selectedText = this.closest('label')
                    .querySelector('.option-text')
                    .textContent.trim().toLowerCase();

                const isFalse = selectedText === 'false';

                if (correctionBox) {
                    correctionBox.style.display = isFalse ? 'block' : 'none';
                }

                // Clear value and toggle required when hidden/shown
                if (correctionInput) {
                    if (!isFalse) correctionInput.value = '';
                    correctionInput.required = isFalse;
                }
            });
        });
    });

    // ── Visual answered-badge on each question card ───────────────────────
    questionCards.forEach(card => {
        const markAnswered = () => {
            const header = card.querySelector('.question-header');
            if (header && !header.querySelector('.answered-badge')) {
                const badge = document.createElement('span');
                badge.className = 'answered-badge';
                badge.style.cssText = 'color:#1e8e3e;font-size:16px;margin-left:8px;';
                badge.innerHTML = '<i class="fas fa-check-circle"></i>';
                header.appendChild(badge);
            }
        };

        card.querySelectorAll('input[type="radio"]').forEach(r => r.addEventListener('change', markAnswered));
        card.querySelectorAll('textarea, input[type="text"]').forEach(i => i.addEventListener('input', markAnswered));
    });

    // ── Form submit ───────────────────────────────────────────────────────
    const quizForm = document.getElementById('quizAnswerForm');
    if (!quizForm) return;

    quizForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (isSubmitting) return;

        const unanswered = [];

        questionCards.forEach((card, idx) => {
            const radios = card.querySelectorAll('input[type="radio"]');

            if (radios.length > 0) {
                // Group radios by name within THIS card only
                const groups = {};
                radios.forEach(r => {
                    if (!groups[r.name]) groups[r.name] = false;
                    if (r.checked) groups[r.name] = true;
                });

                // For modified-TF: require the _tf group; correction text is optional
                for (let name in groups) {
                    if (name.endsWith('_text')) continue; // skip the text input name if somehow captured
                    if (!groups[name]) {
                        unanswered.push(idx + 1);
                        break;
                    }
                }
            } else {
                // Short answer – require non-empty textarea
                const ta = card.querySelector('textarea[required]');
                if (ta && !ta.value.trim()) unanswered.push(idx + 1);
            }
        });

        const uniqueUnanswered = [...new Set(unanswered)];

        if (uniqueUnanswered.length > 0) {
            alert(`Please answer question(s): ${uniqueUnanswered.join(', ')} before submitting.`);
            const firstCard = questionCards[uniqueUnanswered[0] - 1];
            if (firstCard) firstCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (confirm('Are you sure you want to submit your quiz?\n\nYou cannot change your answers after submission.')) {
            submitQuiz();
        }
    });

    document.documentElement.style.scrollBehavior = 'smooth';
});

// ============================================
// PREVENT ACCIDENTAL PAGE LEAVE
// Suppressed during submission redirect
// ============================================

window.addEventListener('beforeunload', function (e) {
    if (isSubmitting) return; // allow redirect
    const form = document.getElementById('quizForm');
    if (attemptId && form && form.style.display !== 'none') {
        e.preventDefault();
        e.returnValue = '';
    }
});