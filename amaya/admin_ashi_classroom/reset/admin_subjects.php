<?php
header('Content-Type: application/json');
require_once 'db.php';
session_start();

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

// Admin auth check
$admin_id = $_SESSION['admin_id'] ?? 0;
if (!$admin_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin login required.']);
    exit;
}

switch ($action) {

    // ============================================================
    // GET ALL ACTIVE SUBJECTS
    // Returns all active subjects across all teachers,
    // plus stats and the count of deactivated subjects.
    // ============================================================
    case 'get_all_subjects':
        try {
            $sy = $pdo->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1")->fetch();

            $stmt = $pdo->query("
                SELECT
                    s.id,
                    s.subject_name,
                    s.class_code,
                    s.grade,
                    s.section,
                    s.theme_color,
                    s.status,
                    s.created_at,
                    t.fullname AS teacher_name,
                    COUNT(DISTINCT CASE WHEN ss.status = 'active' THEN ss.student_id END)       AS student_count,
                    COUNT(DISTINCT CASE WHEN a.status NOT IN ('deactivated') THEN a.id END)     AS assignment_count,
                    COUNT(DISTINCT CASE WHEN q.status NOT IN ('deactivated') THEN q.id END)     AS quiz_count
                FROM subjects s
                LEFT JOIN teachers         t  ON t.id         = s.teacher_id
                LEFT JOIN student_subjects ss ON ss.subject_id = s.id
                LEFT JOIN assignments      a  ON a.subject_id  = s.id
                LEFT JOIN quizzes          q  ON q.subject_id  = s.id
                WHERE s.status = 'active'
                GROUP BY s.id
                ORDER BY s.created_at DESC
            ");
            $subjects = $stmt->fetchAll();

            $stats = $pdo->query("
                SELECT
                    COUNT(DISTINCT s.id)                                                        AS total_subjects,
                    COUNT(DISTINCT ss.student_id)                                               AS total_students,
                    COUNT(DISTINCT s.teacher_id)                                                AS total_teachers,
                    COUNT(DISTINCT CASE WHEN a.status NOT IN ('deactivated') THEN a.id END)    AS total_assignments
                FROM subjects s
                LEFT JOIN student_subjects ss ON ss.subject_id = s.id AND ss.status = 'active'
                LEFT JOIN assignments      a  ON a.subject_id  = s.id
                WHERE s.status = 'active'
            ")->fetch();

            $deactivatedCount = (int) $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'deactivated'")->fetchColumn();

            echo json_encode([
                'success'           => true,
                'subjects'          => $subjects,
                'stats'             => $stats,
                'school_year'       => $sy['school_year']     ?? '—',
                'current_quarter'   => $sy['current_quarter'] ?? 1,
                'deactivated_count' => $deactivatedCount,
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ============================================================
    // GET DEACTIVATED SUBJECTS
    // Returns all deactivated subjects with purge timer info,
    // content counts, and content_purged flag.
    // ============================================================
    case 'get_deactivated_subjects':
        try {
            $stmt = $pdo->query("
                SELECT
                    s.id,
                    s.subject_name,
                    s.class_code,
                    s.grade,
                    s.section,
                    s.theme_color,
                    s.archived_at,
                    s.status,
                    t.fullname AS teacher_name,

                    GREATEST(0, 30 - DATEDIFF(NOW(), s.archived_at)) AS days_until_purge,

                    COUNT(DISTINCT ss.student_id)  AS student_count,
                    COUNT(DISTINCT a.id)            AS assignment_count,
                    COUNT(DISTINCT q.id)            AS quiz_count,
                    COUNT(DISTINCT m.id)            AS material_count,
                    COUNT(DISTINCT an.id)           AS announcement_count,

                    GREATEST(
                        COALESCE(MAX(CASE WHEN a.content_purged  = 1 THEN 1 ELSE 0 END), 0),
                        COALESCE(MAX(CASE WHEN q.content_purged  = 1 THEN 1 ELSE 0 END), 0),
                        COALESCE(MAX(CASE WHEN m.content_purged  = 1 THEN 1 ELSE 0 END), 0),
                        COALESCE(MAX(CASE WHEN an.content_purged = 1 THEN 1 ELSE 0 END), 0)
                    ) AS content_purged

                FROM subjects s
                LEFT JOIN teachers         t  ON t.id          = s.teacher_id
                LEFT JOIN student_subjects ss ON ss.subject_id  = s.id
                LEFT JOIN assignments      a  ON a.subject_id   = s.id AND a.status  = 'deactivated'
                LEFT JOIN quizzes          q  ON q.subject_id   = s.id AND q.status  = 'deactivated'
                LEFT JOIN materials        m  ON m.subject_id   = s.id AND m.status  = 'deactivated'
                LEFT JOIN announcements    an ON an.subject_id  = s.id AND an.status = 'deactivated'

                WHERE s.status = 'deactivated'
                GROUP BY s.id
                ORDER BY s.archived_at ASC
            ");
            $subjects = $stmt->fetchAll();

            echo json_encode([
                'success'  => true,
                'subjects' => $subjects,
                'count'    => count($subjects),
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ============================================================
    // BULK DEACTIVATE
    // Deactivates all subject IDs passed in.
    // Cascades to assignments, quizzes, materials, announcements,
    // enrollments, and submissions.
    // NEVER touches any title or name column.
    // ============================================================
    case 'bulk_deactivate':
        $subject_ids = $input['subject_ids'] ?? [];

        if (empty($subject_ids) || !is_array($subject_ids)) {
            echo json_encode(['success' => false, 'message' => 'No subject IDs provided.']);
            exit;
        }

        $subject_ids = array_values(array_filter(array_map('intval', $subject_ids)));

        if (empty($subject_ids)) {
            echo json_encode(['success' => false, 'message' => 'No valid subject IDs.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s');
            $in  = implode(',', array_fill(0, count($subject_ids), '?'));

            // Deactivate subjects — set archived_at to start the 30-day purge timer
            $pdo->prepare("
                UPDATE subjects
                SET status = 'deactivated', archived_at = ?
                WHERE id IN ($in) AND status = 'active'
            ")->execute(array_merge([$now], $subject_ids));

            // Cascade to all content — titles/names are NEVER modified
            $pdo->prepare("UPDATE assignments   SET status = 'deactivated', archived_at = ? WHERE subject_id IN ($in) AND status != 'deactivated'")->execute(array_merge([$now], $subject_ids));
            $pdo->prepare("UPDATE quizzes       SET status = 'deactivated', archived_at = ? WHERE subject_id IN ($in) AND status != 'deactivated'")->execute(array_merge([$now], $subject_ids));
            $pdo->prepare("UPDATE materials     SET status = 'deactivated', archived_at = ? WHERE subject_id IN ($in) AND status != 'deactivated'")->execute(array_merge([$now], $subject_ids));
            $pdo->prepare("UPDATE announcements SET status = 'deactivated', archived_at = ? WHERE subject_id IN ($in) AND status != 'deactivated'")->execute(array_merge([$now], $subject_ids));
            $pdo->prepare("UPDATE student_subjects SET status = 'deactivated' WHERE subject_id IN ($in) AND status = 'active'")->execute($subject_ids);
            $pdo->prepare("
                UPDATE submissions sub
                JOIN assignments a ON sub.assignment_id = a.id
                SET sub.status = 'deactivated'
                WHERE a.subject_id IN ($in) AND sub.status != 'deactivated'
            ")->execute($subject_ids);

            $pdo->commit();

            $count = count($subject_ids);
            echo json_encode([
                'success' => true,
                'message' => "$count subject(s) deactivated. Content will be purged after 30 days. Titles are preserved forever.",
                'count'   => $count,
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // REACTIVATE SINGLE SUBJECT
    // Restores one deactivated subject back to active.
    // Cancels the 30-day purge timer by clearing archived_at.
    // Only restores content that has NOT been purged yet.
    // Titles are always intact regardless.
    // ============================================================
    case 'reactivate':
        $subject_id = intval($input['subject_id'] ?? 0);

        if (!$subject_id) {
            echo json_encode(['success' => false, 'message' => 'No subject ID provided.']);
            exit;
        }

        try {
            // Verify subject exists and is deactivated
            $subject = $pdo->prepare("SELECT id, subject_name, content_purged FROM subjects WHERE id = ? AND status = 'deactivated'");
            $subject->execute([$subject_id]);
            $subject = $subject->fetch();

            if (!$subject) {
                echo json_encode(['success' => false, 'message' => 'Subject not found or is not deactivated.']);
                exit;
            }

            $pdo->beginTransaction();

            // Reactivate the subject — clear archived_at to cancel purge timer
            $pdo->prepare("
                UPDATE subjects
                SET status = 'active', archived_at = NULL
                WHERE id = ?
            ")->execute([$subject_id]);

            // Restore assignments that haven't been purged
            $pdo->prepare("
                UPDATE assignments
                SET status = 'published', archived_at = NULL
                WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0
            ")->execute([$subject_id]);

            // Restore quizzes that haven't been purged
            $pdo->prepare("
                UPDATE quizzes
                SET status = 'published', archived_at = NULL
                WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0
            ")->execute([$subject_id]);

            // Restore materials that haven't been purged
            $pdo->prepare("
                UPDATE materials
                SET status = 'active', archived_at = NULL
                WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0
            ")->execute([$subject_id]);

            // Restore announcements that haven't been purged
            $pdo->prepare("
                UPDATE announcements
                SET status = 'active', archived_at = NULL
                WHERE subject_id = ? AND status = 'deactivated' AND content_purged = 0
            ")->execute([$subject_id]);

            // Restore student enrollments
            $pdo->prepare("
                UPDATE student_subjects
                SET status = 'active'
                WHERE subject_id = ? AND status = 'deactivated'
            ")->execute([$subject_id]);

            // Restore submissions linked to this subject's assignments
            $pdo->prepare("
                UPDATE submissions sub
                JOIN assignments a ON sub.assignment_id = a.id
                SET sub.status = 'submitted'
                WHERE a.subject_id = ? AND sub.status = 'deactivated' AND sub.content_purged = 0
            ")->execute([$subject_id]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "\"{$subject['subject_name']}\" has been reactivated. Students and teachers now have full access.",
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // BULK REACTIVATE
    // Reactivates ALL currently deactivated subjects at once.
    // Same logic as single reactivate — only restores
    // content that has not yet been purged.
    // ============================================================
    case 'bulk_reactivate':
        try {
            // Fetch all deactivated subject IDs
            $ids = $pdo->query("SELECT id FROM subjects WHERE status = 'deactivated'")->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'No deactivated subjects found.']);
                exit;
            }

            $pdo->beginTransaction();
            $in = implode(',', array_fill(0, count($ids), '?'));

            // Reactivate subjects — cancel purge timers
            $pdo->prepare("
                UPDATE subjects
                SET status = 'active', archived_at = NULL
                WHERE id IN ($in)
            ")->execute($ids);

            // Restore unpurged assignments
            $pdo->prepare("
                UPDATE assignments
                SET status = 'published', archived_at = NULL
                WHERE subject_id IN ($in) AND status = 'deactivated' AND content_purged = 0
            ")->execute($ids);

            // Restore unpurged quizzes
            $pdo->prepare("
                UPDATE quizzes
                SET status = 'published', archived_at = NULL
                WHERE subject_id IN ($in) AND status = 'deactivated' AND content_purged = 0
            ")->execute($ids);

            // Restore unpurged materials
            $pdo->prepare("
                UPDATE materials
                SET status = 'active', archived_at = NULL
                WHERE subject_id IN ($in) AND status = 'deactivated' AND content_purged = 0
            ")->execute($ids);

            // Restore unpurged announcements
            $pdo->prepare("
                UPDATE announcements
                SET status = 'active', archived_at = NULL
                WHERE subject_id IN ($in) AND status = 'deactivated' AND content_purged = 0
            ")->execute($ids);

            // Restore student enrollments
            $pdo->prepare("
                UPDATE student_subjects
                SET status = 'active'
                WHERE subject_id IN ($in) AND status = 'deactivated'
            ")->execute($ids);

            // Restore unpurged submissions
            $pdo->prepare("
                UPDATE submissions sub
                JOIN assignments a ON sub.assignment_id = a.id
                SET sub.status = 'submitted'
                WHERE a.subject_id IN ($in) AND sub.status = 'deactivated' AND sub.content_purged = 0
            ")->execute($ids);

            $pdo->commit();

            $count = count($ids);
            echo json_encode([
                'success' => true,
                'message' => "$count subject(s) reactivated. Students and teachers have been restored access.",
                'count'   => $count,
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // FORCE DELETE (single subject)
    // Immediately purges all content for a deactivated subject —
    // skips the 30-day window entirely.
    //
    // DELETED:
    //   - Assignment files (assignment_attachments)
    //   - Submission files (submission_attachments) & text
    //   - Quiz responses (quiz_responses) & attempts (quiz_attempts)
    //   - Quiz questions & options & answers
    //   - Material files
    //   - Announcement content body
    //   - Comments on all content
    //
    // KEPT FOREVER (titles/metadata):
    //   - subjects.subject_name, class_code, grade, section, teacher_id
    //   - assignments.title
    //   - quizzes.title
    //   - materials.title
    //   - announcements.title
    // ============================================================
    case 'force_delete':
        $subject_id = intval($input['subject_id'] ?? 0);

        if (!$subject_id) {
            echo json_encode(['success' => false, 'message' => 'No subject ID provided.']);
            exit;
        }

        try {
            // Confirm subject is deactivated
            $subject = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id = ? AND status = 'deactivated'");
            $subject->execute([$subject_id]);
            $subject = $subject->fetch();

            if (!$subject) {
                echo json_encode(['success' => false, 'message' => 'Subject not found or is not deactivated.']);
                exit;
            }

            $pdo->beginTransaction();

            // ── 1. Delete physical attachment files (assignment attachments) ──
            // Fetch file paths so we can delete from disk too
            $attachFiles = $pdo->prepare("
                SELECT aa.file_path
                FROM assignment_attachments aa
                JOIN assignments a ON aa.assignment_id = a.id
                WHERE a.subject_id = ?
            ");
            $attachFiles->execute([$subject_id]);
            $attachPaths = $attachFiles->fetchAll(PDO::FETCH_COLUMN);

            // ── 2. Delete physical submission attachment files ──
            $subAttachFiles = $pdo->prepare("
                SELECT sa.file_path
                FROM submission_attachments sa
                JOIN submissions sub ON sa.submission_id = sub.id
                JOIN assignments a   ON sub.assignment_id = a.id
                WHERE a.subject_id = ?
            ");
            $subAttachFiles->execute([$subject_id]);
            $subAttachPaths = $subAttachFiles->fetchAll(PDO::FETCH_COLUMN);

            // ── 3. Delete physical material files ──
            $matFiles = $pdo->prepare("
                SELECT file_path FROM materials
                WHERE subject_id = ? AND file_path IS NOT NULL
            ");
            $matFiles->execute([$subject_id]);
            $matPaths = $matFiles->fetchAll(PDO::FETCH_COLUMN);

            // ── Delete DB records ──

            // Delete submission attachments
            $pdo->prepare("
                DELETE sa FROM submission_attachments sa
                JOIN submissions sub ON sa.submission_id = sub.id
                JOIN assignments a   ON sub.assignment_id = a.id
                WHERE a.subject_id = ?
            ")->execute([$subject_id]);

            // Delete submissions (content only — wipe score, text, feedback but keep row title via assignment)
            // Actually we remove submissions entirely; assignment title is kept on assignments table
            $pdo->prepare("
                DELETE sub FROM submissions sub
                JOIN assignments a ON sub.assignment_id = a.id
                WHERE a.subject_id = ?
            ")->execute([$subject_id]);

            // Delete assignment attachments
            $pdo->prepare("
                DELETE aa FROM assignment_attachments aa
                JOIN assignments a ON aa.assignment_id = a.id
                WHERE a.subject_id = ?
            ")->execute([$subject_id]);

            // Mark assignments as purged (keep title, wipe description & instructions)
            $pdo->prepare("
                UPDATE assignments
                SET description = NULL, instructions = NULL, content_purged = 1
                WHERE subject_id = ?
            ")->execute([$subject_id]);

            // Delete quiz responses
            $pdo->prepare("
                DELETE qr FROM quiz_responses qr
                JOIN quiz_attempts qa ON qr.attempt_id = qa.id
                JOIN quizzes q        ON qa.quiz_id    = q.id
                WHERE q.subject_id = ?
            ")->execute([$subject_id]);

            // Delete quiz attempts
            $pdo->prepare("
                DELETE qa FROM quiz_attempts qa
                JOIN quizzes q ON qa.quiz_id = q.id
                WHERE q.subject_id = ?
            ")->execute([$subject_id]);

            // Delete quiz question options
            $pdo->prepare("
                DELETE qo FROM quiz_question_options qo
                JOIN quiz_questions qq ON qo.question_id = qq.id
                JOIN quizzes q        ON qq.quiz_id      = q.id
                WHERE q.subject_id = ?
            ")->execute([$subject_id]);

            // Delete quiz question answers
            $pdo->prepare("
                DELETE qa FROM quiz_question_answers qa
                JOIN quiz_questions qq ON qa.question_id = qq.id
                JOIN quizzes q         ON qq.quiz_id     = q.id
                WHERE q.subject_id = ?
            ")->execute([$subject_id]);

            // Delete quiz questions
            $pdo->prepare("
                DELETE qq FROM quiz_questions qq
                JOIN quizzes q ON qq.quiz_id = q.id
                WHERE q.subject_id = ?
            ")->execute([$subject_id]);

            // Mark quizzes as purged (keep title, wipe description)
            $pdo->prepare("
                UPDATE quizzes
                SET description = NULL, content_purged = 1
                WHERE subject_id = ?
            ")->execute([$subject_id]);

            // Delete material files from DB (keep title)
            $pdo->prepare("
                UPDATE materials
                SET file_path = NULL, url = NULL, description = NULL, content_purged = 1
                WHERE subject_id = ?
            ")->execute([$subject_id]);

            // Wipe announcement content body (keep title)
            $pdo->prepare("
                UPDATE announcements
                SET content = '', content_purged = 1
                WHERE subject_id = ?
            ")->execute([$subject_id]);

            // Delete all comments on this subject's content
            // assignments
            $pdo->prepare("
                DELETE c FROM comments c
                JOIN assignments a ON c.commentable_id = a.id
                WHERE c.commentable_type = 'assignment' AND a.subject_id = ?
            ")->execute([$subject_id]);

            // announcements
            $pdo->prepare("
                DELETE c FROM comments c
                JOIN announcements an ON c.commentable_id = an.id
                WHERE c.commentable_type = 'announcement' AND an.subject_id = ?
            ")->execute([$subject_id]);

            // materials
            $pdo->prepare("
                DELETE c FROM comments c
                JOIN materials m ON c.commentable_id = m.id
                WHERE c.commentable_type = 'material' AND m.subject_id = ?
            ")->execute([$subject_id]);

            // Mark subject itself as purged
            $pdo->prepare("
                UPDATE subjects SET content_purged = 1 WHERE id = ?
            ")->execute([$subject_id]);

            $pdo->commit();

            // ── Delete physical files from disk ──
            $allPaths = array_merge($attachPaths, $subAttachPaths, $matPaths);
            $deleted  = 0;
            foreach ($allPaths as $path) {
                if ($path && file_exists($path)) {
                    unlink($path);
                    $deleted++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Content for \"{$subject['subject_name']}\" has been permanently deleted. Title and metadata are preserved.",
                'files_deleted' => $deleted,
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // CRON-STYLE: AUTO PURGE SUBJECTS PAST 30 DAYS
    // Call this from a cron job or trigger it manually.
    // Purges content for all deactivated subjects whose
    // archived_at is older than 30 days.
    // ============================================================
    case 'auto_purge':
        try {
            // Find subjects past the 30-day window that haven't been purged yet
            $overdue = $pdo->query("
                SELECT id, subject_name FROM subjects
                WHERE status = 'deactivated'
                  AND content_purged = 0
                  AND archived_at IS NOT NULL
                  AND archived_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ")->fetchAll();

            if (empty($overdue)) {
                echo json_encode(['success' => true, 'message' => 'No subjects due for purge.', 'purged' => 0]);
                exit;
            }

            $purged = 0;
            foreach ($overdue as $s) {
                // Re-use force_delete logic per subject by calling internally
                $id = $s['id'];

                // Collect file paths
                $attachPaths = $pdo->prepare("
                    SELECT aa.file_path FROM assignment_attachments aa
                    JOIN assignments a ON aa.assignment_id = a.id WHERE a.subject_id = ?
                ");
                $attachPaths->execute([$id]);
                $attachPaths = $attachPaths->fetchAll(PDO::FETCH_COLUMN);

                $subAttachPaths = $pdo->prepare("
                    SELECT sa.file_path FROM submission_attachments sa
                    JOIN submissions sub ON sa.submission_id = sub.id
                    JOIN assignments a   ON sub.assignment_id = a.id WHERE a.subject_id = ?
                ");
                $subAttachPaths->execute([$id]);
                $subAttachPaths = $subAttachPaths->fetchAll(PDO::FETCH_COLUMN);

                $matPaths = $pdo->prepare("
                    SELECT file_path FROM materials WHERE subject_id = ? AND file_path IS NOT NULL
                ");
                $matPaths->execute([$id]);
                $matPaths = $matPaths->fetchAll(PDO::FETCH_COLUMN);

                $pdo->beginTransaction();

                $pdo->prepare("DELETE sa FROM submission_attachments sa JOIN submissions sub ON sa.submission_id = sub.id JOIN assignments a ON sub.assignment_id = a.id WHERE a.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE sub FROM submissions sub JOIN assignments a ON sub.assignment_id = a.id WHERE a.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE aa FROM assignment_attachments aa JOIN assignments a ON aa.assignment_id = a.id WHERE a.subject_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE assignments SET description = NULL, instructions = NULL, content_purged = 1 WHERE subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE qr FROM quiz_responses qr JOIN quiz_attempts qa ON qr.attempt_id = qa.id JOIN quizzes q ON qa.quiz_id = q.id WHERE q.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE qa FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE q.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE qo FROM quiz_question_options qo JOIN quiz_questions qq ON qo.question_id = qq.id JOIN quizzes q ON qq.quiz_id = q.id WHERE q.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE qa FROM quiz_question_answers qa JOIN quiz_questions qq ON qa.question_id = qq.id JOIN quizzes q ON qq.quiz_id = q.id WHERE q.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE qq FROM quiz_questions qq JOIN quizzes q ON qq.quiz_id = q.id WHERE q.subject_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE quizzes SET description = NULL, content_purged = 1 WHERE subject_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE materials SET file_path = NULL, url = NULL, description = NULL, content_purged = 1 WHERE subject_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE announcements SET content = '', content_purged = 1 WHERE subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE c FROM comments c JOIN assignments a ON c.commentable_id = a.id WHERE c.commentable_type = 'assignment' AND a.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE c FROM comments c JOIN announcements an ON c.commentable_id = an.id WHERE c.commentable_type = 'announcement' AND an.subject_id = ?")->execute([$id]);
                $pdo->prepare("DELETE c FROM comments c JOIN materials m ON c.commentable_id = m.id WHERE c.commentable_type = 'material' AND m.subject_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE subjects SET content_purged = 1 WHERE id = ?")->execute([$id]);

                $pdo->commit();

                // Delete physical files
                foreach (array_merge($attachPaths, $subAttachPaths, $matPaths) as $path) {
                    if ($path && file_exists($path)) unlink($path);
                }

                $purged++;
            }

            echo json_encode([
                'success' => true,
                'message' => "$purged subject(s) auto-purged successfully.",
                'purged'  => $purged,
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Auto-purge error: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
?>