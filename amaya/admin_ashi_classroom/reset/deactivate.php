<?php
header('Content-Type: application/json');
require_once 'db.php';
session_start();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';
$subject_id = intval($input['subject_id'] ?? $_POST['subject_id'] ?? 0);

// ── Get teacher ID from session ──────────────────────────────
$teacher_id = $_SESSION['teacher_id'] ?? 0;
if (!$teacher_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

if (!$subject_id && $action !== 'purge_expired' && $action !== 'get_subjects') {
    echo json_encode(['success' => false, 'message' => 'Invalid subject ID.']);
    exit;
}

switch ($action) {

    // ============================================================
    // DEACTIVATE: mark subject + all children as deactivated
    // ============================================================
    case 'deactivate':
        try {
            $pdo->beginTransaction();

            $now = date('Y-m-d H:i:s');

            // Verify subject belongs to this teacher
            $own = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
            $own->execute([$subject_id, $teacher_id]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or access denied.']);
                exit;
            }

            // 1. Deactivate subject
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

            // 2. Deactivate assignments
            $pdo->prepare("
                UPDATE assignments
                SET status = 'deactivated', archived_at = ?
                WHERE subject_id = ? AND status != 'deactivated'
            ")->execute([$now, $subject_id]);

            // 3. Deactivate quizzes
            $pdo->prepare("
                UPDATE quizzes
                SET status = 'deactivated', archived_at = ?
                WHERE subject_id = ? AND status != 'deactivated'
            ")->execute([$now, $subject_id]);

            // 4. Deactivate materials
            $pdo->prepare("
                UPDATE materials
                SET status = 'deactivated', archived_at = ?
                WHERE subject_id = ? AND status != 'deactivated'
            ")->execute([$now, $subject_id]);

            // 5. Deactivate announcements
            $pdo->prepare("
                UPDATE announcements
                SET status = 'deactivated', archived_at = ?
                WHERE subject_id = ? AND status != 'deactivated'
            ")->execute([$now, $subject_id]);

            // 6. Deactivate student enrollments
            $pdo->prepare("
                UPDATE student_subjects
                SET status = 'deactivated'
                WHERE subject_id = ? AND status = 'active'
            ")->execute([$subject_id]);

            // 7. Deactivate submissions linked to this subject's assignments
            $pdo->prepare("
                UPDATE submissions s
                JOIN assignments a ON s.assignment_id = a.id
                SET s.status = 'deactivated'
                WHERE a.subject_id = ? AND s.status != 'deactivated'
            ")->execute([$subject_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Subject deactivated successfully. Content will be purged in 30 days.']);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // REACTIVATE: restore subject back to active
    // ============================================================
    case 'reactivate':
        try {
            $pdo->beginTransaction();

            // Verify ownership
            $own = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
            $own->execute([$subject_id, $teacher_id]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or access denied.']);
                exit;
            }

            // Check if content is already purged
            $check = $pdo->prepare("SELECT content_purged FROM assignments WHERE subject_id = ? AND content_purged = 1 LIMIT 1");
            $check->execute([$subject_id]);
            $purged = $check->fetch();

            // Reactivate subject
            $stmt = $pdo->prepare("
                UPDATE subjects
                SET status = 'active', archived_at = NULL
                WHERE id = ? AND status = 'deactivated'
            ");
            $stmt->execute([$subject_id]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject not found or not deactivated.']);
                exit;
            }

            // Only reactivate children if content NOT purged yet
            if (!$purged) {
                $pdo->prepare("UPDATE assignments SET status = 'published', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE quizzes SET status = 'published', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE materials SET status = 'active', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE announcements SET status = 'active', archived_at = NULL WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("UPDATE student_subjects SET status = 'active' WHERE subject_id = ? AND status = 'deactivated'")->execute([$subject_id]);
                $pdo->prepare("
                    UPDATE submissions s
                    JOIN assignments a ON s.assignment_id = a.id
                    SET s.status = 'submitted'
                    WHERE a.subject_id = ? AND s.status = 'deactivated'
                ")->execute([$subject_id]);
            }

            $pdo->commit();
            $msg = $purged
                ? 'Subject reactivated. Note: some content was already purged after 30 days and cannot be restored.'
                : 'Subject reactivated successfully.';
            echo json_encode(['success' => true, 'message' => $msg]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // PURGE EXPIRED: cron job target — silent delete
    // ============================================================
    case 'purge_expired':
        try {
            $pdo->beginTransaction();

            $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
            $purged_count = 0;

            // --- ASSIGNMENTS ---
            $assignments = $pdo->prepare("
                SELECT id FROM assignments
                WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
            ");
            $assignments->execute([$cutoff]);
            $assignment_ids = $assignments->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($assignment_ids)) {
                $in = implode(',', array_fill(0, count($assignment_ids), '?'));
                $files = $pdo->prepare("SELECT sa.file_path FROM submission_attachments sa JOIN submissions s ON sa.submission_id = s.id WHERE s.assignment_id IN ($in)");
                $files->execute($assignment_ids);
                foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $file) {
                    if ($file && file_exists($file)) unlink($file);
                }
                $afiles = $pdo->prepare("SELECT file_path FROM assignment_attachments WHERE assignment_id IN ($in)");
                $afiles->execute($assignment_ids);
                foreach ($afiles->fetchAll(PDO::FETCH_COLUMN) as $file) {
                    if ($file && file_exists($file)) unlink($file);
                }
                $pdo->prepare("DELETE sa FROM submission_attachments sa JOIN submissions s ON sa.submission_id = s.id WHERE s.assignment_id IN ($in)")->execute($assignment_ids);
                $pdo->prepare("DELETE FROM submissions WHERE assignment_id IN ($in)")->execute($assignment_ids);
                $pdo->prepare("DELETE FROM assignment_attachments WHERE assignment_id IN ($in)")->execute($assignment_ids);
                $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'assignment' AND commentable_id IN ($in)")->execute($assignment_ids);
                $pdo->prepare("UPDATE assignments SET description = NULL, instructions = NULL, content_purged = 1 WHERE id IN ($in)")->execute($assignment_ids);
                $purged_count += count($assignment_ids);
            }

            // --- QUIZZES ---
            $quizzes = $pdo->prepare("
                SELECT id FROM quizzes
                WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
            ");
            $quizzes->execute([$cutoff]);
            $quiz_ids = $quizzes->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($quiz_ids)) {
                $in = implode(',', array_fill(0, count($quiz_ids), '?'));
                $questions = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id IN ($in)");
                $questions->execute($quiz_ids);
                $question_ids = $questions->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($question_ids)) {
                    $inQ = implode(',', array_fill(0, count($question_ids), '?'));
                    $pdo->prepare("DELETE FROM quiz_responses WHERE question_id IN ($inQ)")->execute($question_ids);
                    $pdo->prepare("DELETE FROM quiz_question_options WHERE question_id IN ($inQ)")->execute($question_ids);
                    $pdo->prepare("DELETE FROM quiz_question_answers WHERE question_id IN ($inQ)")->execute($question_ids);
                }
                $pdo->prepare("DELETE FROM quiz_attempts WHERE quiz_id IN ($in)")->execute($quiz_ids);
                $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id IN ($in)")->execute($quiz_ids);
                $pdo->prepare("UPDATE quizzes SET description = NULL, content_purged = 1 WHERE id IN ($in)")->execute($quiz_ids);
                $purged_count += count($quiz_ids);
            }

            // --- MATERIALS ---
            $materials = $pdo->prepare("
                SELECT id, file_path FROM materials
                WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
            ");
            $materials->execute([$cutoff]);
            $material_rows = $materials->fetchAll();
            if (!empty($material_rows)) {
                $material_ids = array_column($material_rows, 'id');
                $in = implode(',', array_fill(0, count($material_ids), '?'));
                foreach ($material_rows as $mat) {
                    if (!empty($mat['file_path']) && file_exists($mat['file_path'])) unlink($mat['file_path']);
                }
                $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'material' AND commentable_id IN ($in)")->execute($material_ids);
                $pdo->prepare("UPDATE materials SET description = NULL, file_path = NULL, url = NULL, content_purged = 1 WHERE id IN ($in)")->execute($material_ids);
                $purged_count += count($material_ids);
            }

            // --- ANNOUNCEMENTS ---
            $announcements = $pdo->prepare("
                SELECT id FROM announcements
                WHERE status = 'deactivated' AND archived_at <= ? AND content_purged = 0
            ");
            $announcements->execute([$cutoff]);
            $announcement_ids = $announcements->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($announcement_ids)) {
                $in = implode(',', array_fill(0, count($announcement_ids), '?'));
                $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'announcement' AND commentable_id IN ($in)")->execute($announcement_ids);
                $pdo->prepare("UPDATE announcements SET content = '[Content removed]', content_purged = 1 WHERE id IN ($in)")->execute($announcement_ids);
                $purged_count += count($announcement_ids);
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Purge complete. $purged_count item(s) purged.",
                'purged'  => $purged_count
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Purge error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // GET SUBJECTS: fetch subjects + school year + quarter
    // ============================================================
    case 'get_subjects':
        try {
            // Fetch current school year settings
            $sy = $pdo->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1")->fetch();

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
                ORDER BY
                    FIELD(s.status, 'active', 'disabled', 'deactivated'),
                    s.created_at DESC
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
?>