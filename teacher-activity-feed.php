<?php
require_once "../config/session.php";

header('Content-Type: application/json');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "Teacher") {
    http_response_code(401);
    echo json_encode(['items' => []]);
    exit();
}

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'smartlearn';

$conn = mysqli_connect($host, $user, $pass, $dbname);
$userId = $_SESSION['user_id'];
$items = [];

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

    $quizResult = mysqli_query($conn, "SELECT quiz_id, quiz_title, created_date
        FROM quiz
        WHERE user_id = {$userId}
        ORDER BY created_date DESC
        LIMIT 10");
    if ($quizResult) {
        while ($row = mysqli_fetch_assoc($quizResult)) {
            $items[] = [
                'id' => 'quiz-' . $row['quiz_id'],
                'title' => 'Quiz created',
                'message' => $row['quiz_title'] . ' was created successfully.',
                'date' => $row['created_date'],
                'url' => 'TCreateQuiz.php?quiz_id=' . (int) $row['quiz_id'],
            ];
        }
    }

    $materialResult = mysqli_query($conn, "SELECT material_id, title, upload_date
        FROM material
        WHERE user_id = {$userId}
        ORDER BY upload_date DESC
        LIMIT 10");
    if ($materialResult) {
        while ($row = mysqli_fetch_assoc($materialResult)) {
            $items[] = [
                'id' => 'material-' . $row['material_id'],
                'title' => 'Material uploaded',
                'message' => $row['title'] . ' was uploaded successfully.',
                'date' => $row['upload_date'],
                'url' => 'TLearningMaterials.php?edit=' . (int) $row['material_id'],
            ];
        }
    }

    $materialUpdateResult = mysqli_query($conn, "SELECT activity_id, activity_description, activity_date
        FROM activity_log
        WHERE user_id = {$userId}
          AND activity_type = 'Material Update'
          AND log_status = 'Success'
        ORDER BY activity_date DESC
        LIMIT 10");
    if ($materialUpdateResult) {
        while ($row = mysqli_fetch_assoc($materialUpdateResult)) {
            $refId = 0;
            $title = $row['activity_description'];
            if (preg_match('/^Updated material: (.*)::ref=(\d+)$/', $row['activity_description'], $matches)) {
                $title = $matches[1];
                $refId = (int) $matches[2];
            }
            $items[] = [
                'id' => 'activity-' . $row['activity_id'],
                'title' => 'Material updated',
                'message' => $title . ' was updated successfully.',
                'date' => $row['activity_date'],
                'url' => $refId > 0 ? 'TLearningMaterials.php?edit=' . $refId : 'TLearningMaterials.php',
            ];
        }
    }

    $profileActivityResult = mysqli_query($conn, "SELECT activity_id, activity_type, activity_description, activity_date
        FROM activity_log
        WHERE user_id = {$userId}
          AND activity_type IN ('Profile Update', 'Password Change')
          AND log_status = 'Success'
        ORDER BY activity_date DESC
        LIMIT 10");
    if ($profileActivityResult) {
        while ($row = mysqli_fetch_assoc($profileActivityResult)) {
            $items[] = [
                'id' => 'activity-' . $row['activity_id'],
                'title' => $row['activity_type'] === 'Password Change' ? 'Password changed' : 'Profile updated',
                'message' => $row['activity_description'],
                'date' => $row['activity_date'],
                'url' => 'TProfile.php',
            ];
        }
    }

    mysqli_close($conn);
}

usort($items, function ($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});

$items = array_slice($items, 0, 8);

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);