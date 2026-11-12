<?php
/**
 * ASHI AMAYA — 30-Day Purge Cron Job
 *
 * Run daily via cron:
 *   0 2 * * * /usr/bin/php /path/to/your/project/cron_purge.php >> /var/log/amaya_purge.log 2>&1
 *
 * What this does:
 *   - Finds all deactivated content older than 30 days (archived_at <= cutoff)
 *   - Deletes files, submissions, responses, attachments, and comments
 *   - Sets content_purged = 1 on each affected row
 *
 * What this KEEPS (Google Classroom archive style):
 *   - subjects.subject_name, class_code, grade, section, teacher_id, theme_color
 *   - assignments.title, assignment_type, due_date, max_points
 *   - quizzes.title, total_points, passing_score, due_date
 *   - materials.title, material_type
 *   - announcements.title
 */

require_once __DIR__ . '/db.php';   // Fixed: was missing the leading slash

$log   = [];
$log[] = "[" . date('Y-m-d H:i:s') . "] ASHI AMAYA Purge Job Started";

try {
    $pdo->beginTransaction();

    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $log[]  = "Cutoff date: $cutoff";

    $total_purged = 0;

    // ============================================================
    // 1. ASSIGNMENTS
    //    DELETE: files (submission + assignment attachments),
    //            submissions, submission_attachments,
    //            assignment_attachments, comments
    //    NULL:   description, instructions
    //    KEEP:   title, assignment_type, due_date, max_points
    // ============================================================
    $aStmt = $pdo->prepare("
        SELECT id FROM assignments
        WHERE status = 'deactivated'
          AND archived_at <= ?
          AND content_purged = 0
    ");
    $aStmt->execute([$cutoff]);
    $assignment_ids = $aStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($assignment_ids)) {
        $in = implode(',', array_fill(0, count($assignment_ids), '?'));

        // Physical files — submission attachments
        $sfStmt = $pdo->prepare("
            SELECT sa.file_path
            FROM submission_attachments sa
            JOIN submissions s ON sa.submission_id = s.id
            WHERE s.assignment_id IN ($in)
        ");
        $sfStmt->execute($assignment_ids);
        $deleted_files = 0;
        foreach ($sfStmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
            if ($file && file_exists($file)) { @unlink($file); $deleted_files++; }
        }

        // Physical files — assignment attachments
        $afStmt = $pdo->prepare("SELECT file_path FROM assignment_attachments WHERE assignment_id IN ($in)");
        $afStmt->execute($assignment_ids);
        foreach ($afStmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
            if ($file && file_exists($file)) { @unlink($file); $deleted_files++; }
        }

        $pdo->prepare("DELETE sa FROM submission_attachments sa JOIN submissions s ON sa.submission_id = s.id WHERE s.assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM submissions WHERE assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM assignment_attachments WHERE assignment_id IN ($in)")->execute($assignment_ids);
        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'assignment' AND commentable_id IN ($in)")->execute($assignment_ids);

        // Keep title — null content only
        $pdo->prepare("
            UPDATE assignments
            SET description = NULL, instructions = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($assignment_ids);

        $c = count($assignment_ids);
        $total_purged += $c;
        $log[] = "Assignments purged: $c ({$deleted_files} files deleted)";

    } else {
        $log[] = "Assignments: nothing to purge.";
    }

    // ============================================================
    // 2. QUIZZES
    //    DELETE: quiz_responses, quiz_question_options,
    //            quiz_question_answers, quiz_questions, quiz_attempts
    //    NULL:   description
    //    KEEP:   title, total_points, passing_score, due_date
    // ============================================================
    $qStmt = $pdo->prepare("
        SELECT id FROM quizzes
        WHERE status = 'deactivated'
          AND archived_at <= ?
          AND content_purged = 0
    ");
    $qStmt->execute([$cutoff]);
    $quiz_ids = $qStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($quiz_ids)) {
        $in = implode(',', array_fill(0, count($quiz_ids), '?'));

        $qqStmt = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id IN ($in)");
        $qqStmt->execute($quiz_ids);
        $question_ids = $qqStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($question_ids)) {
            $inQ = implode(',', array_fill(0, count($question_ids), '?'));
            $pdo->prepare("DELETE FROM quiz_responses        WHERE question_id IN ($inQ)")->execute($question_ids);
            $pdo->prepare("DELETE FROM quiz_question_options WHERE question_id IN ($inQ)")->execute($question_ids);
            $pdo->prepare("DELETE FROM quiz_question_answers WHERE question_id IN ($inQ)")->execute($question_ids);
        }

        $pdo->prepare("DELETE FROM quiz_attempts  WHERE quiz_id IN ($in)")->execute($quiz_ids);
        $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id IN ($in)")->execute($quiz_ids);

        // Keep title — null description only
        $pdo->prepare("
            UPDATE quizzes
            SET description = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($quiz_ids);

        $c = count($quiz_ids);
        $total_purged += $c;
        $log[] = "Quizzes purged: $c (questions + responses deleted)";

    } else {
        $log[] = "Quizzes: nothing to purge.";
    }

    // ============================================================
    // 3. MATERIALS
    //    DELETE: physical files, comments
    //    NULL:   description, file_path, url
    //    KEEP:   title, material_type
    // ============================================================
    $mStmt = $pdo->prepare("
        SELECT id, file_path FROM materials
        WHERE status = 'deactivated'
          AND archived_at <= ?
          AND content_purged = 0
    ");
    $mStmt->execute([$cutoff]);
    $material_rows = $mStmt->fetchAll();

    if (!empty($material_rows)) {
        $material_ids = array_column($material_rows, 'id');
        $in = implode(',', array_fill(0, count($material_ids), '?'));

        $deleted_files = 0;
        foreach ($material_rows as $mat) {
            if (!empty($mat['file_path']) && file_exists($mat['file_path'])) {
                @unlink($mat['file_path']);
                $deleted_files++;
            }
        }

        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'material' AND commentable_id IN ($in)")->execute($material_ids);

        // Keep title — null content
        $pdo->prepare("
            UPDATE materials
            SET description = NULL, file_path = NULL, url = NULL, content_purged = 1
            WHERE id IN ($in)
        ")->execute($material_ids);

        $c = count($material_ids);
        $total_purged += $c;
        $log[] = "Materials purged: $c ({$deleted_files} files deleted)";

    } else {
        $log[] = "Materials: nothing to purge.";
    }

    // ============================================================
    // 4. ANNOUNCEMENTS
    //    DELETE: comments
    //    REPLACE: content → '[Content removed]'
    //    KEEP:   title
    // ============================================================
    $anStmt = $pdo->prepare("
        SELECT id FROM announcements
        WHERE status = 'deactivated'
          AND archived_at <= ?
          AND content_purged = 0
    ");
    $anStmt->execute([$cutoff]);
    $announcement_ids = $anStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($announcement_ids)) {
        $in = implode(',', array_fill(0, count($announcement_ids), '?'));

        $pdo->prepare("DELETE FROM comments WHERE commentable_type = 'announcement' AND commentable_id IN ($in)")->execute($announcement_ids);

        // Keep title — replace body only
        $pdo->prepare("
            UPDATE announcements
            SET content = '[Content removed]', content_purged = 1
            WHERE id IN ($in)
        ")->execute($announcement_ids);

        $c = count($announcement_ids);
        $total_purged += $c;
        $log[] = "Announcements purged: $c";

    } else {
        $log[] = "Announcements: nothing to purge.";
    }

    $pdo->commit();
    $log[] = "Total items purged: $total_purged";
    $log[] = "[" . date('Y-m-d H:i:s') . "] Purge Job Completed Successfully.";

} catch (Exception $e) {
    $pdo->rollBack();
    $log[] = "[ERROR] " . $e->getMessage();
    $log[] = "[" . date('Y-m-d H:i:s') . "] Purge Job FAILED.";
}

echo implode(PHP_EOL, $log) . PHP_EOL;
?>