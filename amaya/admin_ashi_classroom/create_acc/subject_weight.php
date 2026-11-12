<?php
session_start();
require_once '../../db_connection/ashi_dbcnnt.php';

// ── Auto-create tables ────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS subject_weight_groups (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        grade_level  VARCHAR(50)   NOT NULL,
        group_number INT           NOT NULL,
        group_weight DECIMAL(5,2)  NOT NULL DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_group (grade_level, group_number),
        INDEX idx_grade (grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS subject_weight_group_items (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        group_id       INT           NOT NULL,
        subject_name   VARCHAR(150)  NOT NULL,
        grade_level    VARCHAR(50)   NOT NULL,
        custom_percent DECIMAL(5,2)  DEFAULT NULL,
        FOREIGN KEY (group_id) REFERENCES subject_weight_groups(id) ON DELETE CASCADE,
        INDEX idx_group (group_id),
        INDEX idx_grade (grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // Get distinct subject names for a grade
    if ($action === 'get_subjects') {
        $grade = trim($_POST['grade_level'] ?? '');
        $stmt  = $conn->prepare("
            SELECT DISTINCT subject_name
            FROM subjects
            WHERE grade = ? AND status = 'active'
            ORDER BY subject_name
        ");
        $stmt->bind_param("s", $grade);
        $stmt->execute();
        $subjects = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_name');
        $stmt->close();
        echo json_encode(['ok' => true, 'subjects' => $subjects]);
        exit;
    }

    // Load saved config for a grade
    if ($action === 'load_config') {
        $grade = trim($_POST['grade_level'] ?? '');
        $stmt  = $conn->prepare("SELECT * FROM subject_weight_groups WHERE grade_level = ? ORDER BY group_number");
        $stmt->bind_param("s", $grade);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $row) {
            $si = $conn->prepare("SELECT subject_name, custom_percent FROM subject_weight_group_items WHERE group_id = ?");
            $si->bind_param("i", $row['id']);
            $si->execute();
            $items = $si->get_result()->fetch_all(MYSQLI_ASSOC);
            $si->close();
            $out[] = [
                'weight'   => floatval($row['group_weight']),
                'subjects' => array_map(fn($r) => [
                    'name'    => $r['subject_name'],
                    'percent' => $r['custom_percent'] !== null ? floatval($r['custom_percent']) : null
                ], $items)
            ];
        }
        echo json_encode(['ok' => true, 'groups' => $out]);
        exit;
    }

    // Save config
    if ($action === 'save_config') {
        $grade  = trim($_POST['grade_level'] ?? '');
        $groups = json_decode($_POST['groups'] ?? '[]', true);

        if (!$grade || !is_array($groups)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid data.']);
            exit;
        }
        $total = array_sum(array_column($groups, 'weight'));
        if (round($total, 2) != 100.00) {
            echo json_encode(['ok' => false, 'msg' => "Batch weights must total 100%. Current: {$total}%"]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM subject_weight_groups WHERE grade_level = ?");
            $del->bind_param("s", $grade);
            $del->execute();
            $del->close();

            foreach ($groups as $i => $grp) {
                $num    = $i + 1;
                $weight = floatval($grp['weight']);
                $ins    = $conn->prepare("INSERT INTO subject_weight_groups (grade_level, group_number, group_weight) VALUES (?, ?, ?)");
                $ins->bind_param("sid", $grade, $num, $weight);
                $ins->execute();
                $gid = $conn->insert_id;
                $ins->close();

                foreach ($grp['subjects'] as $subj) {
                    $name    = trim($subj['name'] ?? '');
                    $pct     = isset($subj['percent']) && $subj['percent'] !== null ? floatval($subj['percent']) : null;
                    if (!$name) continue;
                    $insS = $conn->prepare("INSERT INTO subject_weight_group_items (group_id, subject_name, grade_level, custom_percent) VALUES (?, ?, ?, ?)");
                    $insS->bind_param("issd", $gid, $name, $grade, $pct);
                    $insS->execute();
                    $insS->close();
                }
            }
            $conn->commit();
            echo json_encode(['ok' => true, 'msg' => 'Configuration saved successfully.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'msg' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

// ── Grade levels — always from subjects.grade ─────────────────────────────────
$gradeRes    = $conn->query("SELECT DISTINCT grade FROM subjects WHERE status = 'active' ORDER BY grade");
$gradeLevels = $gradeRes ? array_column($gradeRes->fetch_all(MYSQLI_ASSOC), 'grade') : [];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subject Weight Groups — Ashi Amaya</title>
<link rel="icon" href="../teacher/img/ashi.jpg" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600&family=Geist:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>
<body>

<div class="loader-overlay" id="loader">
    <div class="loader-box">
        <div class="spinner"></div>
        Saving configuration…
    </div>
</div>

<!-- Topbar -->
<div class="topbar">
    <a href="../admin_dash.php" class="topbar-back">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13L5 8l5-5"/></svg>
        Dashboard
    </a>
    <div class="topbar-sep"></div>
    <div class="topbar-crumb">
        <span>Admin</span>
        <div class="crumb-dot"></div>
        <span>Grading</span>
        <div class="crumb-dot"></div>
        <span class="crumb-active">Subject Weight Groups</span>
    </div>
    <div class="topbar-badge">Ashi Amaya</div>
</div>

<!-- Alert -->
<div class="alert-strip" id="alertStrip">
    <span id="alertIco"></span>
    <span id="alertMsg"></span>
</div>

<div class="page-wrap">

    <!-- Header -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-tag">Grading Configuration</div>
            <div class="page-title">Subject Weight Groups</div>
            <div class="page-sub">Group subjects per grade level and assign each group a weight. All batch weights must total 100%.</div>
        </div>
        <div class="grade-select-wrap">
            <label for="gradeSelect">Grade Level</label>
            <select class="grade-select" id="gradeSelect" onchange="onGradeChange()">
                <option value="">— Select Grade —</option>
                <?php foreach ($gradeLevels as $gl): ?>
                <option value="<?= htmlspecialchars($gl) ?>"><?= htmlspecialchars($gl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- No grade selected -->
    <div id="noGradeMsg" class="no-grade-msg">
        <div class="no-grade-icon">◈</div>
        Select a grade level above to begin configuring subject weight groups.
    </div>

    <!-- Main content -->
    <div id="mainContent" style="display:none;">
        <div class="two-col">

            <!-- LEFT: Subject Pool + Add Batch -->
            <div>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-head">
                        <div class="card-title">
                            Subjects
                            <span class="card-title-badge" id="poolBadge">0 subjects</span>
                        </div>
                        <span style="font-size:11px;color:var(--text3);">Check to select for a batch</span>
                    </div>
                    <div class="card-body">
                        <div id="subjectPool"><div class="pool-empty">Loading…</div></div>

                        <!-- Add batch controls -->
                        <div class="add-batch-row">
                            <div class="batch-weight-wrap">
                                <input type="number" class="batch-weight-input" id="newBatchWeight"
                                       min="1" max="100" step="1" value="" placeholder="wt">
                                <span class="batch-weight-pct">%</span>
                            </div>
                            <button class="btn-add-batch" id="btnAddBatch" onclick="addBatch()" disabled>
                                <span style="font-size:16px;line-height:1;">+</span>
                                Add as Batch
                            </button>
                            <span class="sel-count" id="selCount">0 selected</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Batches + Summary -->
            <div>
                <!-- Batches -->
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-head">
                        <div class="card-title">
                            Batches
                            <span class="card-title-badge" id="batchBadge">0 batches</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="batches-list" id="batchesList">
                            <div class="pool-empty">No batches yet. Select subjects and add a batch.</div>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">Summary</div>
                    </div>
                    <div class="card-body">
                        <div id="sumEmpty" class="sum-empty">Add batches to see summary.</div>
                        <div id="sumActive" style="display:none;">
                            <div class="sum-total-row">
                                <span class="sum-total-label">Total batch weight</span>
                                <span class="sum-total-val" id="sumTotalVal">0%</span>
                            </div>
                            <div class="sum-bar-track" id="sumBar"></div>
                            <div class="sum-rows" id="sumRows"></div>
                            <div class="sum-note" id="sumNote"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- .two-col -->

        <!-- Save footer -->
        <div class="save-footer">
            <span class="save-status" id="saveStatus">Add batches to continue.</span>
            <button class="btn-save" id="btnSave" onclick="saveConfig()" disabled>Save Configuration</button>
        </div>
    </div><!-- #mainContent -->

</div><!-- .page-wrap -->

<script>
const COLORS = ['#6c7bff','#3ecf8e','#f5c842','#ff7eb3','#7dd3fc','#fb923c'];
const PAGE   = location.pathname + location.search;

let currentGrade  = '';
let allSubjects   = [];   // string[]
let checkedSet    = new Set();
let batches       = [];   // [{weight, subjects:[{name,percent}]}]
// percent=null means auto (equal split of batch weight)

// ── Grade change ──────────────────────────────────────────────────────────────
function onGradeChange() {
    currentGrade = document.getElementById('gradeSelect').value;
    if (!currentGrade) {
        document.getElementById('noGradeMsg').style.display = 'block';
        document.getElementById('mainContent').style.display = 'none';
        return;
    }
    document.getElementById('noGradeMsg').style.display = 'none';
    document.getElementById('mainContent').style.display = 'block';

    allSubjects = [];
    checkedSet  = new Set();
    batches     = [];

    // Show spinner immediately
    document.getElementById('subjectPool').innerHTML =
        '<div class="pool-empty"><span style="width:13px;height:13px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;display:inline-block;animation:spin .7s linear infinite;margin-right:8px;"></span>Loading subjects for ' + currentGrade + '…</div>';
    document.getElementById('poolBadge').textContent = '…';
    renderBatches();

    post('get_subjects', { grade_level: currentGrade })
        .then(d => {
            allSubjects = d.subjects || [];
            if (!allSubjects.length) {
                document.getElementById('subjectPool').innerHTML =
                    '<div class="pool-empty">No active subjects found in <strong style="color:var(--text2);">' + currentGrade + '</strong>.<br><span style="font-size:11px;">Check that subjects for this grade exist and are active.</span></div>';
                document.getElementById('poolBadge').textContent = '0 subjects';
            } else {
                renderPool();
            }
            return post('load_config', { grade_level: currentGrade });
        })
        .then(d => {
            if (d.ok && d.groups && d.groups.length) {
                batches = d.groups.map(g => ({
                    weight:   parseFloat(g.weight),
                    subjects: g.subjects.map(s => ({ name: s.name, percent: s.percent }))
                }));
                renderBatches();
            }
        });
}

// ── Pool ──────────────────────────────────────────────────────────────────────
function renderPool() {
    const pool = document.getElementById('subjectPool');
    document.getElementById('poolBadge').textContent = allSubjects.length + ' subject' + (allSubjects.length !== 1 ? 's' : '');

    if (!allSubjects.length) {
        pool.innerHTML = '<div class="pool-empty">No active subjects found for this grade.</div>';
        return;
    }

    pool.innerHTML = '';
    const grid = document.createElement('div');
    grid.className = 'subjects-grid';

    allSubjects.forEach(s => {
        const inBatch = batchesContaining(s);
        const isChecked = checkedSet.has(s);
        const row = document.createElement('div');
        row.className = 'subj-row' + (isChecked ? ' checked' : '');
        row.dataset.name = s;
        row.onclick = () => toggleSubject(s, row);

        const colorTags = inBatch.map(i =>
            `<span class="subj-in-batch" style="background:color-mix(in srgb,${COLORS[i%COLORS.length]} 15%,transparent);color:${COLORS[i%COLORS.length]};">B${i+1}</span>`
        ).join('');

        row.innerHTML = `
            <div class="subj-checkbox">
                <svg class="subj-check-icon" width="10" height="8" viewBox="0 0 10 8" fill="none">
                    <path d="M1 4l3 3 5-6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <span class="subj-name">${s}</span>
            ${colorTags}
        `;
        grid.appendChild(row);
    });

    pool.appendChild(grid);
    updateSelCount();
}

function batchesContaining(name) {
    return batches.map((b,i) => b.subjects.some(s=>s.name===name) ? i : -1).filter(i=>i>=0);
}

function toggleSubject(name, el) {
    if (checkedSet.has(name)) { checkedSet.delete(name); el.classList.remove('checked'); }
    else                      { checkedSet.add(name);    el.classList.add('checked');    }
    updateSelCount();
}

function updateSelCount() {
    const n = checkedSet.size;
    document.getElementById('selCount').textContent = n + ' selected';
    document.getElementById('btnAddBatch').disabled = n === 0;
}

// ── Add batch ─────────────────────────────────────────────────────────────────
function addBatch() {
    if (!checkedSet.size) return;
    const wt = parseFloat(document.getElementById('newBatchWeight').value);
    if (!wt || wt <= 0 || wt > 100) { showAlert('error', 'Enter a valid batch weight (1–100).'); return; }

    const subjects = [...checkedSet].map(name => ({ name, percent: null })); // null = auto
    batches.push({ weight: wt, subjects });

    checkedSet = new Set();
    document.getElementById('newBatchWeight').value = '';
    renderPool();
    renderBatches();
}

// ── Render batches ────────────────────────────────────────────────────────────
function renderBatches() {
    const list = document.getElementById('batchesList');
    document.getElementById('batchBadge').textContent = batches.length + ' batch' + (batches.length !== 1 ? 'es' : '');

    if (!batches.length) {
        list.innerHTML = '<div class="pool-empty">No batches yet. Select subjects and add a batch.</div>';
        updateSummary();
        return;
    }

    list.innerHTML = '';
    batches.forEach((b, bi) => {
        const c    = COLORS[bi % COLORS.length];
        const auto = b.weight / Math.max(b.subjects.length, 1);

        const card = document.createElement('div');
        card.className = 'batch-card';
        card.innerHTML = `
            <div class="batch-head">
                <div class="batch-num" style="background:color-mix(in srgb,${c} 18%,transparent);color:${c};">B${bi+1}</div>
                <div class="batch-label" style="color:${c};">Batch ${bi+1}</div>
                <div class="batch-weight-edit-wrap">
                    <input type="number" class="batch-weight-edit" value="${b.weight}"
                           min="0" max="100" step="0.5"
                           onchange="updateBatchWeight(${bi}, this.value)"
                           style="border-color:color-mix(in srgb,${c} 35%,var(--border2));">
                    <span class="batch-weight-edit-pct">%</span>
                </div>
                <button class="batch-del" onclick="deleteBatch(${bi})">×</button>
            </div>
            <div class="batch-subjects" id="batchSubjects_${bi}">
                ${b.subjects.length
                    ? b.subjects.map((s, si) => `
                        <div class="batch-subj-row">
                            <span class="batch-subj-name">${s.name}</span>
                            ${s.percent !== null
                                ? `<div class="batch-subj-pct-wrap">
                                    <input type="number" class="batch-subj-pct" value="${s.percent}"
                                           min="0" max="100" step="0.5"
                                           onchange="updateSubjPercent(${bi},${si},this.value)"
                                           style="border-color:color-mix(in srgb,${c} 30%,var(--border2));">
                                    <span class="batch-subj-pct-label">%</span>
                                   </div>`
                                : `<span class="batch-subj-auto" title="Auto: batch weight ÷ subjects">≈${auto.toFixed(1)}%</span>`
                            }
                            <button onclick="toggleSubjOverride(${bi},${si})"
                                style="font-size:10px;padding:3px 7px;border-radius:5px;cursor:pointer;
                                       background:color-mix(in srgb,${c} 12%,transparent);
                                       border:1px solid color-mix(in srgb,${c} 25%,transparent);
                                       color:${c}; font-family:'Geist Mono',monospace;">
                                ${s.percent !== null ? 'auto' : 'edit'}
                            </button>
                        </div>`)
                    .join('')
                    : '<span class="batch-empty-subj">No subjects in this batch.</span>'
                }
            </div>
        `;
        list.appendChild(card);
    });

    updateSummary();
}

function deleteBatch(bi) {
    batches.splice(bi, 1);
    renderPool();
    renderBatches();
}

function updateBatchWeight(bi, val) {
    batches[bi].weight = parseFloat(val) || 0;
    updateSummary();
}

function updateSubjPercent(bi, si, val) {
    batches[bi].subjects[si].percent = parseFloat(val) || 0;
    updateSummary();
}

function toggleSubjOverride(bi, si) {
    const s = batches[bi].subjects[si];
    if (s.percent !== null) {
        s.percent = null; // back to auto
    } else {
        const auto = batches[bi].weight / Math.max(batches[bi].subjects.length, 1);
        s.percent = parseFloat(auto.toFixed(1));
    }
    renderBatches();
}

// ── Summary ───────────────────────────────────────────────────────────────────
function updateSummary() {
    const total = batches.reduce((a,b) => a + b.weight, 0);
    const isOk  = Math.round(total * 100) === 10000; // exactly 100.00

    const hasB = batches.length > 0;
    document.getElementById('sumEmpty').style.display  = hasB ? 'none'  : 'block';
    document.getElementById('sumActive').style.display = hasB ? 'block' : 'none';

    if (!hasB) { updateSaveFooter(total); return; }

    const tv = document.getElementById('sumTotalVal');
    tv.textContent = total.toFixed(1) + '%';
    tv.className   = 'sum-total-val ' + (isOk ? 'ok' : 'err');

    // bar
    document.getElementById('sumBar').innerHTML = batches.map((b,i) =>
        `<div class="sum-bar-seg" style="width:${Math.min(b.weight,100)}%;background:${COLORS[i%COLORS.length]};"></div>`
    ).join('');

    // rows
    document.getElementById('sumRows').innerHTML = batches.map((b,i) => {
        const auto = b.weight / Math.max(b.subjects.length, 1);
        const subjLine = b.subjects.map(s =>
            `${s.name} (${s.percent !== null ? s.percent + '%' : '≈' + auto.toFixed(1) + '%'})`
        ).join(', ');
        return `
            <div class="sum-row">
                <div class="sum-dot" style="background:${COLORS[i%COLORS.length]};"></div>
                <div class="sum-info">
                    <div class="sum-batch-name" style="color:${COLORS[i%COLORS.length]};">Batch ${i+1}</div>
                    <div class="sum-batch-subjs">${subjLine || '—'}</div>
                </div>
                <div class="sum-batch-pct" style="color:${COLORS[i%COLORS.length]};">${b.weight}%</div>
            </div>`;
    }).join('');

    // note
    document.getElementById('sumNote').innerHTML = isOk
        ? `<span style="color:var(--green);">✓ All batch weights total 100%. Ready to save.</span><br>
           <span style="color:var(--text3);">GWA = Σ [ effective_subject_% × subject_quarterly_grade ]</span>`
        : `<span style="color:var(--red);">⚠ Total is ${total.toFixed(1)}% — must be exactly 100% to save.</span>`;

    updateSaveFooter(total);
}

function updateSaveFooter(total) {
    const isOk = Math.round(total * 100) === 10000;
    const btn  = document.getElementById('btnSave');
    const st   = document.getElementById('saveStatus');
    btn.disabled = !isOk || !currentGrade;
    if (!currentGrade) { st.textContent = 'Select a grade level first.'; st.className = 'save-status'; return; }
    if (!batches.length) { st.textContent = 'Add at least one batch.'; st.className = 'save-status'; return; }
    st.textContent = isOk ? `Ready to save for ${currentGrade}.` : `Total is ${total.toFixed(1)}% — must be 100%.`;
    st.className = 'save-status ' + (isOk ? 'ok' : 'err');
}

// ── Save ──────────────────────────────────────────────────────────────────────
function saveConfig() {
    if (!currentGrade || !batches.length) return;
    document.getElementById('loader').classList.add('show');

    post('save_config', {
        grade_level: currentGrade,
        groups:      JSON.stringify(batches)
    }).then(d => {
        document.getElementById('loader').classList.remove('show');
        showAlert(d.ok ? 'success' : 'error', d.msg);
    }).catch(() => {
        document.getElementById('loader').classList.remove('show');
        showAlert('error', 'Network error. Please try again.');
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function post(action, data) {
    const body = 'ajax_action=' + encodeURIComponent(action) +
        Object.entries(data).map(([k,v]) => '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('');
    return fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
        .then(r => r.json());
}

function showAlert(type, msg) {
    const s = document.getElementById('alertStrip');
    document.getElementById('alertIco').textContent = type === 'success' ? '✓ ' : '✕ ';
    document.getElementById('alertMsg').textContent = msg;
    s.className = 'alert-strip show ' + type;
    clearTimeout(s._t);
    s._t = setTimeout(() => { s.className = 'alert-strip'; }, 4500);
}
</script>
</body>
</html>