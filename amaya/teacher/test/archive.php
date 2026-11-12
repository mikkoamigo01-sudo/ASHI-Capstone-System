<?php
session_start();

/* ================= DB ================= */
$conn = mysqli_connect("localhost", "root", "", "users");
if (!$conn) die("DB Error");

/* ================= SESSION ================= */
if (!isset($_SESSION['teacher_id'])) {
    echo "Not logged in";
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

/* ================= SUBJECTS ================= */
$subjects = mysqli_query($conn, "
    SELECT * FROM subjects 
    WHERE teacher_id = '$teacher_id' 
    AND status = 'deactivated'
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Deactivated Content</title>

    <style>
        body {
            font-family: Arial;
            background: #f5f5f5;
            padding: 20px;
        }

        .card {
            background: #fff;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 6px;
            cursor: pointer;
        }

        .card:hover {
            background: #f0f0f0;
        }

        .content {
            display: none;
            margin-top: 10px;
            padding-left: 15px;
        }

        .item {
            background: #fafafa;
            padding: 8px;
            margin-bottom: 5px;
            border-left: 4px solid #999;
            border-radius: 4px;
        }

        .empty {
            color: gray;
            font-size: 13px;
        }
    </style>

    <script>
        function toggle(id) {
            var el = document.getElementById(id);
            el.style.display = (el.style.display === "block") ? "none" : "block";
        }
    </script>

</head>
<body>

<h1>📦 Deactivated Subjects</h1>

<?php if (mysqli_num_rows($subjects) > 0): ?>
    <?php while($sub = mysqli_fetch_assoc($subjects)): ?>

        <?php
        $subject_id = $sub['id'];

        // ASSIGNMENTS
        $assignments = mysqli_query($conn, "
            SELECT * FROM assignments
            WHERE subject_id = '$subject_id'
            AND status = 'deactivated'
        ");

        // QUIZZES
        $quizzes = mysqli_query($conn, "
            SELECT * FROM quizzes
            WHERE subject_id = '$subject_id'
            AND status = 'deactivated'
        ");

        // MATERIALS
        $materials = mysqli_query($conn, "
            SELECT * FROM materials
            WHERE subject_id = '$subject_id'
            AND status = 'deactivated'
        ");

        // ANNOUNCEMENTS
        $announcements = mysqli_query($conn, "
            SELECT * FROM announcements
            WHERE subject_id = '$subject_id'
            AND status = 'deactivated'
        ");

        $content_id = "content_" . $subject_id;
        ?>

        <!-- SUBJECT CARD -->
        <div class="card" onclick="toggle('<?php echo $content_id; ?>')">
            <b><?php echo $sub['subject_name']; ?></b><br>
            Grade: <?php echo $sub['grade']; ?> | Section: <?php echo $sub['section']; ?>
        </div>

        <!-- DROPDOWN CONTENT -->
        <div class="content" id="<?php echo $content_id; ?>">

            <!-- ASSIGNMENTS -->
            <h4>Assignments</h4>
            <?php if (mysqli_num_rows($assignments) > 0): ?>
                <?php while($a = mysqli_fetch_assoc($assignments)): ?>
                    <div class="item"><?php echo $a['title']; ?></div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">No assignments</div>
            <?php endif; ?>

            <!-- QUIZZES -->
            <h4>Quizzes</h4>
            <?php if (mysqli_num_rows($quizzes) > 0): ?>
                <?php while($q = mysqli_fetch_assoc($quizzes)): ?>
                    <div class="item"><?php echo $q['title']; ?></div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">No quizzes</div>
            <?php endif; ?>

            <!-- MATERIALS -->
            <h4>Materials</h4>
            <?php if (mysqli_num_rows($materials) > 0): ?>
                <?php while($m = mysqli_fetch_assoc($materials)): ?>
                    <div class="item"><?php echo $m['title']; ?></div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">No materials</div>
            <?php endif; ?>

            <!-- ANNOUNCEMENTS -->
            <h4>Announcements</h4>
            <?php if (mysqli_num_rows($announcements) > 0): ?>
                <?php while($an = mysqli_fetch_assoc($announcements)): ?>
                    <div class="item"><?php echo $an['title']; ?></div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">No announcements</div>
            <?php endif; ?>

        </div>

    <?php endwhile; ?>
<?php else: ?>
    <p class="empty">No deactivated subjects found</p>
<?php endif; ?>

</body>
</html>