<?php
// ============================================================
// ASHI AMAYA — get_teacher.php
// ?action=list                        Active teachers (search/filter/paginate)
// ?action=sections&grade=X            Sections for a given advisory grade
// ?action=info&id=X                   Full teacher detail
// ?action=progress&id=X               Subjects, students, grading stats
// ?action=archive&id=X                Set status = 'archived'
// ============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';

$db     = getDB();
$action = isset($_GET['action']) ? trim($_GET['action']) : 'list';
$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {

    $search  = $db->real_escape_string(trim($_GET['search']  ?? ''));
    $grade   = $db->real_escape_string(trim($_GET['grade']   ?? ''));
    $section = $db->real_escape_string(trim($_GET['section'] ?? ''));

    // Pagination
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 10;
    $offset   = ($page - 1) * $per_page;

    $where = ["t.status = 'active'"];
    if ($search !== '') {
        $s = $db->real_escape_string($search);
        $where[] = "(
            t.fullname LIKE '%{$s}%'
            OR t.email LIKE '%{$s}%'
            OR t.specialization LIKE '%{$s}%'
            OR t.education LIKE '%{$s}%'
            OR t.phone LIKE '%{$s}%'
        )";
    }
    if ($grade   !== '') $where[] = "t.advisory_grade = '{$grade}'";
    if ($section !== '') $where[] = "t.advisory_section = '{$section}'";

    $where_sql = implode(' AND ', $where);

    // Total count for pagination
    $count_r     = $db->query("SELECT COUNT(*) AS total FROM teachers t WHERE {$where_sql}");
    $total       = $count_r ? (int)$count_r->fetch_assoc()['total'] : 0;
    $total_pages = max(1, (int)ceil($total / $per_page));

    $teachers = [];
    $r = $db->query("
        SELECT t.id, t.fullname, t.email, t.phone, t.gender, t.specialization,
               t.education, t.advisory_grade, t.advisory_section,
               t.profile_picture, t.status, t.created_at, t.birthdate, t.address, t.bio,
               (SELECT COUNT(*) FROM subjects s WHERE s.teacher_id = t.id AND s.status = 'active') AS subject_count,
               (SELECT COUNT(DISTINCT ss.student_id) FROM student_subjects ss
                    JOIN subjects s ON ss.subject_id = s.id
                    WHERE s.teacher_id = t.id AND ss.status = 'active') AS total_students
        FROM teachers t
        WHERE {$where_sql}
        ORDER BY t.fullname ASC
        LIMIT {$per_page} OFFSET {$offset}
    ");
    if ($r) while ($row = $r->fetch_assoc()) $teachers[] = $row;

    // Advisory grades (unfiltered, for grade dropdown)
    $grades = [];
    $r2 = $db->query("
        SELECT DISTINCT advisory_grade FROM teachers
        WHERE status = 'active' AND advisory_grade IS NOT NULL AND advisory_grade != ''
        ORDER BY advisory_grade ASC
    ");
    if ($r2) while ($row = $r2->fetch_assoc()) $grades[] = $row['advisory_grade'];

    echo json_encode([
        'teachers'    => $teachers,
        'filters'     => ['grades' => $grades],
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ]);

// ── SECTIONS (dependent on advisory grade) ────────────────────
} elseif ($action === 'sections') {

    $grade    = $db->real_escape_string(trim($_GET['grade'] ?? ''));
    $sections = [];
    if ($grade !== '') {
        $r = $db->query("
            SELECT DISTINCT advisory_section FROM teachers
            WHERE status = 'active'
              AND advisory_grade = '{$grade}'
              AND advisory_section IS NOT NULL
              AND advisory_section != ''
            ORDER BY advisory_section ASC
        ");
        if ($r) while ($row = $r->fetch_assoc()) $sections[] = $row['advisory_section'];
    }
    echo json_encode(['sections' => $sections]);

// ── INFO ─────────────────────────────────────────────────────
} elseif ($action === 'info' && $id > 0) {

    $r = $db->query("
        SELECT id, fullname, email, phone, gender, birthdate, address,
               education, specialization, advisory_grade, advisory_section,
               bio, profile_picture, status, created_at
        FROM teachers WHERE id = {$id} LIMIT 1
    ");
    if ($r && $r->num_rows > 0) {
        echo json_encode(['teacher' => $r->fetch_assoc()]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Teacher not found']);
    }

// ── PROGRESS ─────────────────────────────────────────────────
} elseif ($action === 'progress' && $id > 0) {

    $overview = [];
    $r = $db->query("SELECT
        (SELECT COUNT(*) FROM subjects WHERE teacher_id={$id} AND status='active') AS subjects_handled,
        (SELECT COUNT(DISTINCT ss.student_id) FROM student_subjects ss
            JOIN subjects s ON ss.subject_id=s.id
            WHERE s.teacher_id={$id} AND ss.status='active') AS total_students,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id={$id} AND status != 'draft') AS assignments_created,
        (SELECT COUNT(*) FROM quizzes WHERE teacher_id={$id} AND status != 'draft') AS quizzes_created,
        (SELECT COUNT(*) FROM submissions sm WHERE sm.graded_by={$id} AND sm.status IN ('graded','returned')) AS graded_submissions,
        (SELECT COUNT(*) FROM materials WHERE teacher_id={$id}) AS materials_posted
    ");
    if ($r) $overview = $r->fetch_assoc();

    $subjects = [];
    $r2 = $db->query("
        SELECT sub.id, sub.subject_name, sub.grade, sub.section, sub.class_code, sub.status,
               (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = sub.id AND ss.status = 'active') AS enrolled_students,
               (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = sub.id AND a.status != 'draft') AS assignment_count,
               (SELECT COUNT(*) FROM quizzes q WHERE q.subject_id = sub.id AND q.status != 'draft') AS quiz_count,
               (SELECT COUNT(*) FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
                   WHERE a.subject_id = sub.id AND sm.graded_by = {$id} AND sm.status IN ('graded','returned')) AS graded_count,
               (SELECT COUNT(*) FROM materials m WHERE m.subject_id = sub.id AND m.teacher_id = {$id}) AS material_count
        FROM subjects sub
        WHERE sub.teacher_id = {$id} AND sub.status = 'active'
        ORDER BY sub.subject_name ASC
    ");
    if ($r2) while ($row = $r2->fetch_assoc()) $subjects[] = $row;

    $activity = [];
    $r3 = $db->query("
        SELECT sm.score, sm.graded_at, sm.status,
               CONCAT(st.first_name,' ',st.last_name) AS student_name,
               a.title AS assignment_title, a.max_points, sub.subject_name
        FROM submissions sm
        JOIN assignments a ON sm.assignment_id = a.id
        JOIN subjects sub ON a.subject_id = sub.id
        JOIN students st ON sm.student_id = st.id
        WHERE sm.graded_by = {$id} AND sm.status IN ('graded','returned')
        ORDER BY sm.graded_at DESC LIMIT 15
    ");
    if ($r3) while ($row = $r3->fetch_assoc()) $activity[] = $row;

    echo json_encode([
        'overview' => $overview ?: (object)[],
        'subjects' => $subjects,
        'activity' => $activity,
    ]);

// ── ARCHIVE ──────────────────────────────────────────────────
} elseif ($action === 'archive' && $id > 0) {

    $db->query("UPDATE teachers SET status = 'archived' WHERE id = {$id} AND status = 'active'");
    if ($db->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Teacher archived successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not archive. Already archived or not found.']);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or missing id parameter.']);
}