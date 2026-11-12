<?php
header('Content-Type: application/json');
require_once 'db.php';
session_start();

$input      = json_decode(file_get_contents('php://input'), true);
$action     = $input['action']     ?? $_POST['action']     ?? '';
$subject_id = intval($input['subject_id'] ?? $_POST['subject_id'] ?? 0);

$teacher_id = $_SESSION['teacher_id'] ?? 0;
if (!$teacher_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

if (!$subject_id && !in_array($action, ['purge_expired', 'get_subjects'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid subject ID.']);
    exit;
}

switch ($action) {

    // ============================================================
    // DEACTIVATE
    // Marks subject + all children deactivated.
    // Titles/names are NEVER modified here — only status + archived_at.
    // ============================================================
    case 'deactivate':
        try {
            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s');

            $own = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
            $own->execute([$subject_id, $teacher_id]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or access denied.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE subjects
                SET status = 'deactivated', archived_at = ?
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$now, $subject_id]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or already deactivated.']);
                exit;
            }

            $pdo->prepare("UPDATE assignments   SET status = 'deactivated', archived_at = ? WHERE subject_id = ? AND status != 'deactivated'")->execute([$now, $subject_id]);
            $pdo->prepare("UPDATE quizzes       SET status = 'deactivated', archived_at = ? WHERE subject_id = ? AND status != 'deactivated'")->execute([$now, $subject_id]);
            $pdo->prepare("UPDATE materials     SET status = 'deactivated', archived_at = ? WHERE subject_id = ? AND status != 'deactivated'")->execute([$now, $subject_id]);
            $pdo->prepare("UPDATE announcements SET status = 'deactivated', archived_at = ? WHERE subject_id = ? AND status != 'deactivated'")->execute([$now, $subject_id]);
            $pdo->prepare("UPDATE student_subjects SET status = 'deactivated' WHERE subject_id = ? AND status = 'active'")->execute([$subject_id]);
            $pdo->prepare("
                UPDATE submissions s
                JOIN assignments a ON s.assignment_id = a.id
                SET s.status = 'deactivated'
                WHERE a.subject_id = ? AND s.status != 'deactivated'
            ")->execute([$subject_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Subject deactivated. Content will be purged in 30 days. Titles are preserved.']);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // REACTIVATE
    // Restores subject to active. Children restored only if not purged.
    // ============================================================
    case 'reactivate':
        try {
            $pdo->beginTransaction();

            $own = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
            $own->execute([$subject_id, $teacher_id]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or access denied.']);
                exit;
            }

            // Check if any content was already purged
            $check = $pdo->prepare("SELECT content_purged FROM assignments WHERE subject_id = ? AND content_purged = 1 LIMIT 1");
            $check->execute([$subject_id]);
            $hasPurged = (bool) $check->fetch();

            $stmt = $pdo->prepare("
                UPDATE subjects SET status = 'active', archived_at = NULL
                WHERE id = ? AND status = 'deactivated'
            ");
            $stmt->execute([$subject_id]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or not currently deactivated.']);
                exit;
            }

            if (!$hasPurged) {
                $pdo->prepare("UPDATE assignments   SET status = 'published', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE quizzes       SET status = 'published', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE materials     SET status = 'active',    archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE announcements SET status = 'active',    archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE student_subjects SET status = 'active' WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("
                    UPDATE submissions s
                    JOIN assignments a ON s.assignment_id = a.id
                    SET s.status = 'submitted'
                    WHERE a.subject_id = ? AND s.status = 'deactivated'
                ")->execute([$subject_id]);
            }

            $pdo->commit();
            $msg = $hasPurged
                ? 'Subject reactivated. Note: some content was already purged after 30 days and cannot be restored. Titles are intact.'
                : 'Subject reactivated successfully.';
            echo json_encode(['success' => true, 'message' => $msg]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // FORCE DELETE
    // Admin-triggered immediate purge — skips the 30-day window.
    // KEEPS: subject title, class_code, teacher_id, grade, section (all subject row columns).
    // KEEPS: assignment/quiz/material/announcement titles and names.
    // DELETES: all files, submissions, responses, comments, content bodies.
    // ============================================================
    case 'force_delete':
        try {
            $pdo->beginTransaction();

            // Verify subject belongs to this teacher and is deactivated
            $own = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ? AND status = 'deactivated'");
            $own->execute([$subject_id, $teacher_id]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found, not deactivated, or access denied.']);
                exit;
            }

            purgeSubjectContent($pdo, $subject_id);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Content permanently deleted. Subject title and metadata are preserved.']);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // PURGE EXPIRED (cron target)
    // Same logic as force_delete but runs for ALL expired subjects.
    // KEEPS titles. DELETES content bodies, files, responses.
    // ============================================================
    case 'purge_expired':
        try {
            $pdo->beginTransaction();

            $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
            $purged_subjects = 0;

            $stmt = $pdo->prepare("
                SELECT DISTINCT s.subject_id
                FROM (
                    SELECT subject_id FROM assignments   WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
                    UNION
                    SELECT subject_id FROM quizzes       WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
                    UNION
                    SELECT subject_id FROM materials     WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
                    UNION
                    SELECT subject_id FROM announcements WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
                ) s
            ");
            $stmt->execute([$cutoff, $cutoff, $cutoff, $cutoff]);
            $subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($subject_ids as $sid) {
                purgeSubjectContent($pdo, (int)$sid);
                $purged_subjects++;
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Purge complete. $purged_subjects subject(s) purged.",
                'purged'  => $purged_subjects
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Purge error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // GET SUBJECTS (teacher's own subjects for teacher dashboard)
    // ============================================================
    case 'get_subjects':
        try {
            $sy   = $pdo->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1")->fetch();
            $stmt = $pdo->prepare("
                SELECT
                    s.*,
                    t.fullname AS teacher_name,
                    COUNT(DISTINCT CASE WHEN ss.status = 'active' THEN ss.student_id END) AS student_count,
                    COUNT(DISTINCT CASE WHEN a.status NOT IN ('deactivated') THEN a.id END) AS assignment_count,
                    COUNT(DISTINCT CASE WHEN q.status NOT IN ('deactivated') THEN q.id END) AS quiz_count,
                    CASE
                        WHEN s.status = 'deactivated' AND s.archived_at IS NOT NULL
                        THEN GREATEST(0, 30 - DATEDIFF(NOW(), s.archived_at))
                        ELSE NULL
                    END AS days_until_purge
                FROM subjects s
                LEFT JOIN teachers t ON s.teacher_id = t.id
                LEFT JOIN student_subjects ss ON ss.subject_id = s.id
                LEFT JOIN assignments a ON a.subject_id = s.id
                LEFT JOIN quizzes q ON q.subject_id = s.id
                WHERE s.teacher_id = ?
                GROUP BY s.id
                ORDER BY FIELD(s.status, 'active', 'disabled', 'deactivated'), s.created_at DESC
            ");
            $stmt->execute([$teacher_id]);
            $subjects = $stmt->fetchAll();

            echo json_encode([
                'success'         => true,
                'subjects'        => $subjects,
                'school_year'     => $sy['school_year']     ?? '—',
                'current_quarter' => $sy['current_quarter'] ?? 1,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}

// ============================================================
// HELPER: purgeSubjectContent($pdo, $subject_id)
//
// KEEPS (never touched):
//   - subjects row (title, class_code, teacher_id, grade, section, theme_color, logo_path)
//   - assignments.title, assignments.assignment_type, assignments.max_points, assignments.due_date
//   - quizzes.title, quizzes.total_points, quizzes.passing_score, quizzes.due_date
//   - materials.title, materials.material_type
//   - announcements.title
//
// DELETES:
//   - Physical files (submissions + assignment attachments + material files)
//   - submission_attachments, submissions
//   - assignment_attachments
//   - comments (assignment, announcement, material, submission types)
//   - quiz_responses, quiz_question_options, quiz_question_answers, quiz_questions, quiz_attempts
//   - assignments.description, assignments.instructions  → set NULL, content_purged = 1
//   - quizzes.description                               → set NULL, content_purged = 1
//   - materials.description, file_path, url             → set NULL, content_purged = 1
//   - announcements.content                             → set '[Content removed]', content_purged = 1
// ============================================================
function purgeSubjectContent(PDO $pdo, int $subject_id): void {

    // ── ASSIGNMENTS ──────────────────────────────────────────
    $aStmt = $pdo->prepare("SELECT id FROM assignments WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0");
    $aStmt->execute([$subject_id]);
    $assignment_ids = $aStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($assignment_ids)) {
        $in = implode(',', array_fill(0, count($assignment_ids), '?'));

        // Delete physical submission attachment files
        $sfStmt = $pdo->prepare("
            SELECT sa.file_path
            FROM submission_attachments sa
            JOIN submissions s ON sa.submission_id = s.id
            WHERE s.assignment_id IN ($in)
        ");
        $sfStmt->execute($assignment_ids);
        foreach ($sfStmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
            if ($file && file_exists($file)) @unlink($file);
        }

        // Delete physical assignment attachment files
        $afStmt = $pdo->prepare("SELECT file_path FROM assignment_attachments WHERE assignment_id IN ($in)");
        $afStmt->execute($assignment_ids);
        foreach ($afStmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
            if ($file && file_exists($file)) @unlink($file);
        }

        $pdo->prepare("DELETE sa FROM submission_attachments sa JOIN submissions s ON sa.submission_id = s.id WHERE s.assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM submissions WHERE assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM assignment_attachments WHERE assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'assignment' AND commentable_id IN ($in)")->execute($assignment_ids);

        // Keep title, type, due_date, max_points — only null content
        $pdo->prepare("
            UPDATE assignments
            SET description = NULL, instructions = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($assignment_ids);
    }

    // ── QUIZZES ──────────────────────────────────────────────
    $qStmt = $pdo->prepare("SELECT id FROM quizzes WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0");
    $qStmt->execute([$subject_id]);
    $quiz_ids = $qStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($quiz_ids)) {
        $in = implode(',', array_fill(0, count($quiz_ids), '?'));

        $qqStmt = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id IN ($in)");
        $qqStmt->execute($quiz_ids);
        $question_ids = $qqStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($question_ids)) {
            $inQ = implode(',', array_fill(0, count($question_ids), '?'));
            $pdo->prepare("DELETE FROM quiz_responses WHERE question_id IN ($inQ)")->execute($question_ids);
            $pdo->prepare("DELETE FROM quiz_question_options WHERE question_id IN ($inQ)")->execute($question_ids);
            $pdo->prepare("DELETE FROM quiz_question_answers WHERE question_id IN ($inQ)")->execute($question_ids);
        }

        $pdo->prepare("DELETE FROM quiz_attempts WHERE quiz_id IN ($in)")->execute($quiz_ids);
        $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id IN ($in)")->execute($quiz_ids);

        // Keep title, total_points, passing_score, due_date — only null description
        $pdo->prepare("
            UPDATE quizzes
            SET description = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($quiz_ids);
    }

    // ── MATERIALS ─────────────────────────────────────────────
    $mStmt = $pdo->prepare("SELECT id, file_path FROM materials WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0");
    $mStmt->execute([$subject_id]);
    $material_rows = $mStmt->fetchAll();

    if (!empty($material_rows)) {
        $material_ids = array_column($material_rows, 'id');
        $in = implode(',', array_fill(0, count($material_ids), '?'));

        foreach ($material_rows as $mat) {
            if (!empty($mat['file_path']) && file_exists($mat['file_path'])) @unlink($mat['file_path']);
        }

        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'material' AND commentable_id IN ($in)")->execute($material_ids);

        // Keep title, material_type — null content
        $pdo->prepare("
            UPDATE materials
            SET description = NULL, file_path = NULL, url = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($material_ids);
    }

    // ── ANNOUNCEMENTS ─────────────────────────────────────────
    $anStmt = $pdo->prepare("SELECT id FROM announcements WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0");
    $anStmt->execute([$subject_id]);
    $announcement_ids = $anStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($announcement_ids)) {
        $in = implode(',', array_fill(0, count($announcement_ids), '?'));

        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'announcement' AND commentable_id IN ($in)")->execute($announcement_ids);

        // Keep title — replace content body only
        $pdo->prepare("
            UPDATE announcements
            SET content = '[Content removed]', content_purged = 1
            WHERE id IN ($in)
        ")->execute($announcement_ids);
    }
}
?>