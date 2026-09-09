<?php
$baseDir = __DIR__;
$configPath = $baseDir . '/config/database.php';
if (!file_exists($configPath)) {
    $configPath = dirname($baseDir) . '/config/database.php';
}
require_once $configPath;

$sessionPath = $baseDir . '/config/session.php';
if (!file_exists($sessionPath)) {
    $sessionPath = dirname($baseDir) . '/config/session.php';
}
require_once $sessionPath;

require_once __DIR__ . "/../student/notification.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "Teacher") {
    header("Location: ../index.php");
    exit();
}

$userId = $_SESSION['user_id'];
$teacherName = 'Teacher';
$teacherAvatar = '';
$classLabel = 'Teacher';
$subjects = [];
$materials = [];
$message = '';
$messageType = '';
$toastMessage = null;
$editingMaterial = null;
$editTopics = [];
$currentTopicDifficulty = 'Medium';

$formValues = [
    'material_id' => 0,
    'title' => '',
    'subject_id' => 0,
    'topic_id' => '',
    'new_topic_name' => '',
    'video_url' => '',
    'difficulty_level' => 'Medium',
];

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

    if (isset($_GET['action']) && $_GET['action'] === 'getTopicsBySubject' && isset($_GET['subject_id'])) {
        header('Content-Type: application/json');
        $subjectIdParam = (int) $_GET['subject_id'];

        $topicListStmt = $conn->prepare("SELECT topic_id, topic_name, difficulty_level FROM topic WHERE subject_id = ? ORDER BY topic_name ASC");
        $topicListStmt->bind_param('i', $subjectIdParam);
        $topicListStmt->execute();
        $topicListResult = $topicListStmt->get_result();
        $topicList = $topicListResult ? $topicListResult->fetch_all(MYSQLI_ASSOC) : [];
        $topicListStmt->close();

        echo json_encode(['success' => true, 'topics' => $topicList]);
        mysqli_close($conn);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'checkTitle' && isset($_GET['title'])) {
        header('Content-Type: application/json');
        $checkTitle = trim($_GET['title']);
        $excludeId = isset($_GET['exclude_material_id']) ? (int) $_GET['exclude_material_id'] : 0;
        $exists = false;

        if ($checkTitle !== '') {
            if ($excludeId > 0) {
                $dupStmt = $conn->prepare("SELECT material_id FROM material WHERE user_id = ? AND LOWER(title) = LOWER(?) AND material_id != ? LIMIT 1");
                $dupStmt->bind_param('isi', $userId, $checkTitle, $excludeId);
            } else {
                $dupStmt = $conn->prepare("SELECT material_id FROM material WHERE user_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
                $dupStmt->bind_param('is', $userId, $checkTitle);
            }
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            $exists = $dupResult && $dupResult->num_rows > 0;
            $dupStmt->close();
        }

        echo json_encode(['exists' => $exists]);
        mysqli_close($conn);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'getMaterialDetails' && isset($_GET['material_id'])) {
        header('Content-Type: application/json');
        $materialId = (int) $_GET['material_id'];

        $detailStmt = $conn->prepare("SELECT m.material_id, m.title, m.material_type, m.upload_date, m.file_path, m.video_url, t.topic_name, s.subject_name FROM material m LEFT JOIN topic t ON m.topic_id = t.topic_id LEFT JOIN subject s ON t.subject_id = s.subject_id WHERE m.material_id = ? AND m.user_id = ? LIMIT 1");
        $detailStmt->bind_param('ii', $materialId, $userId);
        $detailStmt->execute();
        $detailResult = $detailStmt->get_result();
        $detailRow = $detailResult ? $detailResult->fetch_assoc() : null;
        $detailStmt->close();

        if ($detailRow) {
            $detailRow['pdf_url'] = resourceUrl($detailRow);
            echo json_encode(['success' => true, 'material' => $detailRow]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Material not found']);
        }
        mysqli_close($conn);
        exit;
    }

    $teacherStmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE user_id = ? AND role = 'Teacher' LIMIT 1");
    $teacherStmt->bind_param('i', $userId);
    $teacherStmt->execute();
    $teacherResult = $teacherStmt->get_result();
    if ($teacherResult && $teacherRow = $teacherResult->fetch_assoc()) {
        $teacherName = $teacherRow['name'];
        $teacherAvatar = $teacherRow['profile_picture'] ?? '';
    }
    $teacherStmt->close();

    $classSubjectStmt = $conn->prepare("SELECT DISTINCT s.subject_id, s.subject_name FROM classes c JOIN subject s ON c.subject_id = s.subject_id WHERE c.user_id = ? ORDER BY s.subject_name ASC");
    $classSubjectStmt->bind_param('i', $userId);
    $classSubjectStmt->execute();
    $classSubjectResult = $classSubjectStmt->get_result();
    $classSubjectNames = [];
    $teacherSubjectIds = [];
    while ($classSubjectRow = $classSubjectResult->fetch_assoc()) {
        $classSubjectNames[] = $classSubjectRow['subject_name'];
        $teacherSubjectIds[] = (int) $classSubjectRow['subject_id'];
        $subjects[] = ['subject_id' => $classSubjectRow['subject_id'], 'subject_name' => $classSubjectRow['subject_name']];
    }
    $classSubjectStmt->close();
    if (!empty($classSubjectNames)) {
        $classLabel = implode(', ', $classSubjectNames);
    }

    if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
        $editId = (int) $_GET['edit'];
        $editStmt = $conn->prepare("SELECT m.material_id, m.title, m.material_type, m.file_path, m.video_url, m.topic_id, t.subject_id, s.subject_name FROM material m LEFT JOIN topic t ON m.topic_id = t.topic_id LEFT JOIN subject s ON t.subject_id = s.subject_id WHERE m.material_id = ? AND m.user_id = ? LIMIT 1");
        $editStmt->bind_param('ii', $editId, $userId);
        $editStmt->execute();
        $editResult = $editStmt->get_result();
        if ($editResult) {
            $editingMaterial = $editResult->fetch_assoc();
        }
        $editStmt->close();

        if ($editingMaterial && !empty($editingMaterial['subject_id'])) {
            $editTopicsStmt = $conn->prepare("SELECT topic_id, topic_name, difficulty_level FROM topic WHERE subject_id = ? ORDER BY topic_name ASC");
            $editTopicsStmt->bind_param('i', $editingMaterial['subject_id']);
            $editTopicsStmt->execute();
            $editTopicsResult = $editTopicsStmt->get_result();
            if ($editTopicsResult) {
                $editTopics = $editTopicsResult->fetch_all(MYSQLI_ASSOC);
            }
            $editTopicsStmt->close();

            foreach ($editTopics as $topic) {
                if ((int) $topic['topic_id'] === (int) ($editingMaterial['topic_id'] ?? 0)) {
                    $currentTopicDifficulty = $topic['difficulty_level'];
                    break;
                }
            }
        }

        if ($editingMaterial) {
            $formValues['material_id'] = (int) $editingMaterial['material_id'];
            $formValues['title'] = $editingMaterial['title'];
            $formValues['subject_id'] = (int) ($editingMaterial['subject_id'] ?? 0);
            $formValues['topic_id'] = (string) ($editingMaterial['topic_id'] ?? '');
            $formValues['video_url'] = $editingMaterial['video_url'];
            $formValues['difficulty_level'] = $currentTopicDifficulty;
        }
    }

    $materialsStmt = $conn->prepare("SELECT m.material_id, m.title, m.material_type, m.upload_date, m.file_path, m.video_url, t.topic_name, t.difficulty_level, s.subject_name FROM material m LEFT JOIN topic t ON m.topic_id = t.topic_id LEFT JOIN subject s ON t.subject_id = s.subject_id WHERE m.user_id = ? ORDER BY m.upload_date DESC");
    $materialsStmt->bind_param('i', $userId);
    $materialsStmt->execute();
    $materialsResult = $materialsStmt->get_result();
    if ($materialsResult) {
        $materials = $materialsResult->fetch_all(MYSQLI_ASSOC);
    }
    $materialsStmt->close();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete_material_id'])) {
            $deleteId = (int) $_POST['delete_material_id'];
            $deleteStmt = $conn->prepare("SELECT title, file_path FROM material WHERE material_id = ? AND user_id = ? LIMIT 1");
            $deleteStmt->bind_param('ii', $deleteId, $userId);
            $deleteStmt->execute();
            $deleteRow = $deleteStmt->get_result()->fetch_assoc();
            $deleteStmt->close();

            if ($deleteRow && !empty($deleteRow['file_path']) && !preg_match('/^https?:\/\//i', $deleteRow['file_path'])) {
                $absolutePath = __DIR__ . '/' . ltrim($deleteRow['file_path'], '/');
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            $deleteMaterialStmt = $conn->prepare("DELETE FROM material WHERE material_id = ? AND user_id = ?");
            $deleteMaterialStmt->bind_param('ii', $deleteId, $userId);
            $deleteMaterialStmt->execute();
            $deleteMaterialStmt->close();

            if ($deleteRow) {
                $deleteDescEsc = mysqli_real_escape_string($conn, "Deleted material: " . $deleteRow['title'] . '::ref=' . $deleteId);
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Material Delete', '{$deleteDescEsc}', 'Success', NOW())");
            }

            $message = 'Material deleted successfully.';
            $messageType = 'success';

            $materialsStmt = $conn->prepare("SELECT m.material_id, m.title, m.material_type, m.upload_date, m.file_path, m.video_url, t.topic_name, t.difficulty_level, s.subject_name FROM material m LEFT JOIN topic t ON m.topic_id = t.topic_id LEFT JOIN subject s ON t.subject_id = s.subject_id WHERE m.user_id = ? ORDER BY m.upload_date DESC");
            $materialsStmt->bind_param('i', $userId);
            $materialsStmt->execute();
            $materialsResult = $materialsStmt->get_result();
            if ($materialsResult) {
                $materials = $materialsResult->fetch_all(MYSQLI_ASSOC);
            }
            $materialsStmt->close();
        } else {
            $materialId = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
            $title = trim($_POST['title'] ?? '');
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $videoUrl = trim($_POST['video_url'] ?? '');
            $filePath = '';

            if ($materialId > 0) {
                $existingStmt = $conn->prepare("SELECT file_path FROM material WHERE material_id = ? AND user_id = ? LIMIT 1");
                $existingStmt->bind_param('ii', $materialId, $userId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();
                $filePath = $existingRow['file_path'] ?? '';
            }

            $hasNewUpload = !empty($_FILES['matPdf']['name']) && $_FILES['matPdf']['error'] === UPLOAD_ERR_OK;

            $titleTaken = false;
            if ($title !== '') {
                if ($materialId > 0) {
                    $dupCheckStmt = $conn->prepare("SELECT material_id FROM material WHERE user_id = ? AND LOWER(title) = LOWER(?) AND material_id != ? LIMIT 1");
                    $dupCheckStmt->bind_param('isi', $userId, $title, $materialId);
                } else {
                    $dupCheckStmt = $conn->prepare("SELECT material_id FROM material WHERE user_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
                    $dupCheckStmt->bind_param('is', $userId, $title);
                }
                $dupCheckStmt->execute();
                $dupCheckResult = $dupCheckStmt->get_result();
                $titleTaken = $dupCheckResult && $dupCheckResult->num_rows > 0;
                $dupCheckStmt->close();
            }

            $subjectNotTaught = $subjectId > 0 && !in_array($subjectId, $teacherSubjectIds, true);

            if ($title === '' || $subjectId <= 0 || $subjectNotTaught || (!$hasNewUpload && $filePath === '' && $videoUrl === '')) {
                $message = $subjectNotTaught
                    ? 'You can only upload materials for subjects you teach.'
                    : 'Please provide a title, subject, and a PDF file or video URL.';
                $messageType = 'danger';

                $formValues['material_id'] = $materialId;
                $formValues['title'] = $title;
                $formValues['subject_id'] = $subjectId;
                $formValues['topic_id'] = trim($_POST['topic_id'] ?? '');
                $formValues['new_topic_name'] = trim($_POST['new_topic_name'] ?? '');
                $formValues['video_url'] = $videoUrl;
                $postedDifficulty = trim($_POST['difficulty_level'] ?? '');
                $formValues['difficulty_level'] = in_array($postedDifficulty, ['Easy', 'Medium', 'Hard'], true) ? $postedDifficulty : 'Medium';

                if ($subjectId > 0) {
                    $repopulateTopicsStmt = $conn->prepare("SELECT topic_id, topic_name, difficulty_level FROM topic WHERE subject_id = ? ORDER BY topic_name ASC");
                    $repopulateTopicsStmt->bind_param('i', $subjectId);
                    $repopulateTopicsStmt->execute();
                    $repopulateTopicsResult = $repopulateTopicsStmt->get_result();
                    if ($repopulateTopicsResult) {
                        $editTopics = $repopulateTopicsResult->fetch_all(MYSQLI_ASSOC);
                    }
                    $repopulateTopicsStmt->close();
                }
            } elseif ($titleTaken) {
                $message = "You already have a material named '{$title}'. Please choose a different title.";
                $messageType = 'danger';

                $formValues['material_id'] = $materialId;
                $formValues['title'] = $title;
                $formValues['subject_id'] = $subjectId;
                $formValues['topic_id'] = trim($_POST['topic_id'] ?? '');
                $formValues['new_topic_name'] = trim($_POST['new_topic_name'] ?? '');
                $formValues['video_url'] = $videoUrl;
                $postedDifficulty = trim($_POST['difficulty_level'] ?? '');
                $formValues['difficulty_level'] = in_array($postedDifficulty, ['Easy', 'Medium', 'Hard'], true) ? $postedDifficulty : 'Medium';

                if ($subjectId > 0) {
                    $repopulateTopicsStmt = $conn->prepare("SELECT topic_id, topic_name, difficulty_level FROM topic WHERE subject_id = ? ORDER BY topic_name ASC");
                    $repopulateTopicsStmt->bind_param('i', $subjectId);
                    $repopulateTopicsStmt->execute();
                    $repopulateTopicsResult = $repopulateTopicsStmt->get_result();
                    if ($repopulateTopicsResult) {
                        $editTopics = $repopulateTopicsResult->fetch_all(MYSQLI_ASSOC);
                    }
                    $repopulateTopicsStmt->close();
                }
            } else {
                $topicIdInput = trim($_POST['topic_id'] ?? '');
                $newTopicName = trim($_POST['new_topic_name'] ?? '');
                $difficultyLevel = trim($_POST['difficulty_level'] ?? '');
                if (!in_array($difficultyLevel, ['Easy', 'Medium', 'Hard'], true)) {
                    $difficultyLevel = 'Medium';
                }
                $topicId = 0;

                if ($topicIdInput !== '' && $topicIdInput !== 'new') {
                    $topicCheckStmt = $conn->prepare("SELECT topic_id FROM topic WHERE topic_id = ? AND subject_id = ? LIMIT 1");
                    $topicCheckId = (int) $topicIdInput;
                    $topicCheckStmt->bind_param('ii', $topicCheckId, $subjectId);
                    $topicCheckStmt->execute();
                    $topicCheckResult = $topicCheckStmt->get_result();
                    if ($topicCheckResult && $topicCheckResult->fetch_assoc()) {
                        $topicId = $topicCheckId;
                    }
                    $topicCheckStmt->close();
                }

                if ($topicId === 0) {
                    $subjectStmt = $conn->prepare("SELECT subject_name FROM subject WHERE subject_id = ? LIMIT 1");
                    $subjectStmt->bind_param('i', $subjectId);
                    $subjectStmt->execute();
                    $subjectRow = $subjectStmt->get_result()->fetch_assoc();
                    $subjectStmt->close();

                    $topicName = $newTopicName !== '' ? $newTopicName : ($subjectRow['subject_name'] ?? 'General');
                    $topicStmt = $conn->prepare("SELECT topic_id FROM topic WHERE subject_id = ? AND topic_name = ? LIMIT 1");
                    $topicStmt->bind_param('is', $subjectId, $topicName);
                    $topicStmt->execute();
                    $topicResult = $topicStmt->get_result();
                    if ($topicResult && $topicRow = $topicResult->fetch_assoc()) {
                        $topicId = (int) $topicRow['topic_id'];
                    }
                    $topicStmt->close();

                    if ($topicId === 0) {
                        $insertTopicStmt = $conn->prepare("INSERT INTO topic (subject_id, topic_name, description, difficulty_level) VALUES (?, ?, ?, ?)");
                        $insertTopicStmt->bind_param('isss', $subjectId, $topicName, $topicName, $difficultyLevel);
                        $insertTopicStmt->execute();
                        $topicId = $insertTopicStmt->insert_id;
                        $insertTopicStmt->close();
                    }
                }

                if ($topicId > 0) {
                    $difficultyUpdateStmt = $conn->prepare("UPDATE topic SET difficulty_level = ? WHERE topic_id = ?");
                    $difficultyUpdateStmt->bind_param('si', $difficultyLevel, $topicId);
                    $difficultyUpdateStmt->execute();
                    $difficultyUpdateStmt->close();
                }

                if ($hasNewUpload) {
                    $uploadDir = __DIR__ . '/uploads/materials';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $fileExtension = pathinfo($_FILES['matPdf']['name'], PATHINFO_EXTENSION);
                    $storedName = 'material_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
                    $targetPath = $uploadDir . '/' . $storedName;
                    if (move_uploaded_file($_FILES['matPdf']['tmp_name'], $targetPath)) {
                        $filePath = 'uploads/materials/' . $storedName;
                    }
                }

                if ($filePath !== '' && $videoUrl !== '') {
                    $materialType = 'Both';
                } elseif ($videoUrl !== '') {
                    $materialType = 'Video';
                } else {
                    $materialType = 'PDF';
                }

                if ($materialId > 0) {
                    $updateStmt = $conn->prepare("UPDATE material SET title = ?, topic_id = ?, material_type = ?, file_path = ?, video_url = ? WHERE material_id = ? AND user_id = ?");
                    $updateStmt->bind_param('sisssii', $title, $topicId, $materialType, $filePath, $videoUrl, $materialId, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    $message = 'Material updated successfully.';
                    $toastMessage = "Material '{$title}' updated successfully.";

                    $activityDescEsc = mysqli_real_escape_string($conn, "Updated material: {$title}" . '::ref=' . $materialId);
                    mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Material Update', '{$activityDescEsc}', 'Success', NOW())");

                    $notifySubjectId = getSubjectIdForTopic($conn, $topicId);
                    $notifySubjectName = getSubjectName($conn, $notifySubjectId);
                    $notifyText = $notifySubjectName !== ''
                        ? "Updated learning material for {$notifySubjectName}: {$title}. Check the latest version."
                        : "Updated learning material: {$title}. Check the latest version.";
                    notifyClassStudents($conn, $userId, $notifySubjectId, 'Material Update', $notifyText);
                } else {
                    $insertStmt = $conn->prepare("INSERT INTO material (user_id, topic_id, title, material_type, upload_date, file_path, video_url) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                    $insertStmt->bind_param('iissss', $userId, $topicId, $title, $materialType, $filePath, $videoUrl);
                    $insertStmt->execute();
                    $newMaterialId = $insertStmt->insert_id;
                    $insertStmt->close();
                    $message = 'Material uploaded successfully.';
                    $toastMessage = "Material '{$title}' uploaded successfully.";

                    $activityDescEsc = mysqli_real_escape_string($conn, "Uploaded material: {$title}" . '::ref=' . $newMaterialId);
                    mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Material Upload', '{$activityDescEsc}', 'Success', NOW())");

                    $notifySubjectId = getSubjectIdForTopic($conn, $topicId);
                    $notifySubjectName = getSubjectName($conn, $notifySubjectId);
                    $notifyText = $notifySubjectName !== ''
                        ? "New learning material for {$notifySubjectName}: {$title}. Open it from Learning Materials."
                        : "New learning material: {$title}. Open it from Learning Materials.";
                    notifyClassStudents($conn, $userId, $notifySubjectId, 'New Material', $notifyText);
                }
                $messageType = 'success';

                $materialsStmt = $conn->prepare("SELECT m.material_id, m.title, m.material_type, m.upload_date, m.file_path, m.video_url, t.topic_name, t.difficulty_level, s.subject_name FROM material m LEFT JOIN topic t ON m.topic_id = t.topic_id LEFT JOIN subject s ON t.subject_id = s.subject_id WHERE m.user_id = ? ORDER BY m.upload_date DESC");
                $materialsStmt->bind_param('i', $userId);
                $materialsStmt->execute();
                $materialsResult = $materialsStmt->get_result();
                if ($materialsResult) {
                    $materials = $materialsResult->fetch_all(MYSQLI_ASSOC);
                }
                $materialsStmt->close();
            }
        }
    }

    mysqli_close($conn);
}

if (!$conn) {
    $message = 'Unable to connect to the database.';
    $messageType = 'danger';
}

$filterSubjects = $classSubjectNames;
sort($filterSubjects);

$filterTopics = [];
$seenTopicKeys = [];
foreach ($materials as $materialForFilter) {
    $filterTopicName = $materialForFilter['topic_name'] ?: '';
    if ($filterTopicName === '') {
        continue;
    }
    $filterTopicSubject = $materialForFilter['subject_name'] ?: 'Unassigned';
    $topicKey = $filterTopicSubject . '||' . $filterTopicName;
    if (!isset($seenTopicKeys[$topicKey])) {
        $seenTopicKeys[$topicKey] = true;
        $filterTopics[] = ['topic_name' => $filterTopicName, 'subject_name' => $filterTopicSubject];
    }
}
usort($filterTopics, function ($a, $b) {
    return $a['topic_name'] <=> $b['topic_name'];
});

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function profileAvatarStyle($path) {
    if (empty($path)) {
        return '';
    }
    return "background-image: url('" . e($path) . "'); background-size: cover; background-position: center;";
}

function formatDate($value) {
    if (empty($value)) {
        return '-';
    }
    $date = new DateTime($value);
    return $date->format('Y-m-d');
}

function typeBadge($type) {
    $type = strtolower((string) $type);
    if ($type === 'both') {
        return 'bg-primary';
    }
    if ($type === 'video') {
        return 'bg-info';
    }
    if ($type === 'pdf') {
        return 'bg-success';
    }
    return 'bg-secondary';
}

function difficultyBadge($level) {
    $level = strtolower((string) $level);
    if ($level === 'easy') {
        return 'bg-success text-white';
    }
    if ($level === 'hard') {
        return 'bg-danger text-white';
    }
    if ($level === 'medium') {
        return 'bg-warning text-dark';
    }
    return 'bg-secondary text-white';
}

function resourceUrl($material) {
    $path = $material['file_path'] ?? '';
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }
    return ltrim($path, '/');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Learning Materials - SmartLearn Teacher Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="teacher-shared.css" />
  <style>
    :root {
      --pale: #CADFF2;
      --sky: #96B9D9;
      --mist: #8AA4C7;
      --steel: #6B8AA6;
      --ink-blue: #58608A;
      --text: #17233A;
      --muted: #69768B;
      --line: #D9E5EF;
      --surface: #FFFFFF;
      --bg: #F4F9FD;
      --green: #2DBE78;
      --orange: #F6A313;
      --red: #EE3D59;
      --shadow: 0 8px 22px rgba(88, 96, 138, 0.14);
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: Inter, "Segoe UI", Arial, sans-serif;
    }
    .app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
    .sidebar { background: var(--surface); border-right: 1px solid var(--line); padding: 22px 16px; overflow-y: auto; }
    .brand { display: flex; align-items: center; gap: 12px; margin: 0 8px 20px; }
    .brand-logo { display: block; width: 100%; max-width: 180px; height: auto; }
    .nav { display: grid; gap: 10px; }
    .nav a {
      min-height: 52px;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 18px;
      border-radius: 14px;
      color: #223047;
      font-size: 15px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s;
    }
    .nav a:hover { background: rgba(202, 223, 242, 0.5); }
    .nav a.active { color: var(--ink-blue); background: linear-gradient(90deg, rgba(202, 223, 242, 0.96), rgba(150, 185, 217, 0.42)); }
    .nav svg { width: 20px; height: 20px; stroke-width: 2.1; }
    .sidebar-footer { margin-top: auto; }
    .main { display: grid; grid-template-rows: 76px 1fr; min-width: 0; }
    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 24px; background: var(--surface); border-bottom: 1px solid var(--line); padding: 0 32px; }
    .profile { display: flex; align-items: center; gap: 18px; white-space: nowrap; }
    .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #CADFF2, #58608A); border: 2px solid #E7EEF5; }
    .content { padding: 44px 38px; overflow: auto; }
    .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 32px; }
    .page-head h2 { margin: 0 0 8px 0; color: var(--text); font-size: 32px; line-height: 1.2; }
    .page-head p { margin: 0; color: var(--muted); font-size: 15px; }
    .card { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow); }
    .upload-form-card { padding: 28px 32px; margin-bottom: 26px; background: var(--surface); border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow); }
    .upload-form-card h3 { margin-top: 0; margin-bottom: 18px; font-size: 18px; font-weight: 700; }
    .section-title { margin: 0 0 24px 0; font-size: 18px; font-weight: 700; color: var(--text); }
    .table-search { width: min(420px, 100%); height: 44px; display: flex; align-items: center; gap: 12px; margin: 0 0 20px; border: 1px solid var(--line); border-radius: 10px; color: var(--muted); background: #FBFDFF; padding: 0 16px; }
    .table-search input { width: 100%; border: none; background: transparent; outline: none; color: var(--text); font-size: 15px; }
    .filter-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 20px; }
    .filter-search { flex: 1 1 260px; max-width: 400px; height: 44px; padding: 0 16px; border: 1px solid var(--line); border-radius: 10px; font-size: 15px; background: var(--surface); color: var(--text); }
    .filter-select { flex: 0 0 auto; width: auto; height: 44px; padding: 0 32px 0 14px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; background-color: var(--surface); color: var(--text); }
    .filter-clear-btn { flex: 0 0 auto; height: 44px; padding: 0 16px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; font-weight: 700; background: transparent; color: var(--muted); cursor: pointer; transition: background 0.15s, color 0.15s; }
    .filter-clear-btn:hover { background: rgba(202, 223, 242, 0.4); color: var(--text); }
    .no-results-row td { text-align: center; padding: 40px 20px; color: var(--muted); }
    .table-wrapper { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow); overflow: hidden; }
    .table-scroll { overflow-x: auto; }
    .materials-table { width: 100%; border-collapse: collapse; font-size: 15px; }
    .materials-table thead { background: linear-gradient(90deg, rgba(202, 223, 242, 0.4), rgba(150, 185, 217, 0.2)); border-bottom: 1px solid var(--line); }
    .materials-table th { padding: 16px 20px; text-align: left; font-weight: 700; color: var(--muted); font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; }
    .materials-table tbody tr { border-bottom: 1px solid var(--line); transition: background-color 0.2s; }
    .materials-table tbody tr:last-child { border-bottom: none; }
    .materials-table tbody tr:hover { background-color: rgba(202, 223, 242, 0.15); }
    .materials-table tbody tr.row-highlight { background-color: rgba(45, 190, 120, 0.16); transition: background-color 1.8s ease; }
    .materials-table td { padding: 18px 20px; color: var(--text); vertical-align: middle; }
    .name-cell { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .material-icon { width: 40px; height: 40px; display: grid; place-items: center; flex: 0 0 auto; border-radius: 12px; color: #14633E; background: rgba(45, 190, 120, 0.20); }
    .material-name { overflow: hidden; color: var(--text); font-size: 15px; font-weight: 700; white-space: nowrap; text-overflow: ellipsis; }
    .actions { display: flex; align-items: center; justify-content: center; gap: 12px; }
    .action-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--muted); cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .action-btn:hover { border-color: var(--mist); background: rgba(202, 223, 242, 0.2); color: var(--steel); }
    .action-btn.delete:hover { border-color: #EE3D59; background: rgba(238, 61, 89, 0.1); color: #EE3D59; }
    .action-btn svg { width: 18px; height: 18px; stroke-width: 2; }

    /* Material view modal */
    .quiz-modal-backdrop { position: fixed; inset: 0; background: rgba(23, 35, 58, 0.5); display: none; align-items: center; justify-content: center; padding: 24px; z-index: 1000; }
    .quiz-modal-backdrop.show { display: flex; }
    .quiz-modal { background: var(--surface); border-radius: 18px; box-shadow: 0 20px 60px rgba(23, 35, 58, 0.28); width: min(560px, 100%); max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
    .quiz-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 24px 28px; border-bottom: 1px solid var(--line); }
    .quiz-modal-header h3 { margin: 0 0 6px 0; font-size: 22px; color: var(--text); }
    .quiz-modal-meta { display: flex; flex-direction: column; gap: 6px; color: var(--muted); font-size: 14px; }
    .quiz-modal-close { width: 34px; height: 34px; flex: 0 0 auto; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); color: var(--muted); display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .quiz-modal-close:hover { border-color: var(--mist); color: var(--steel); }
    .quiz-modal-close svg { width: 18px; height: 18px; }
    .quiz-modal-body { padding: 24px 28px; overflow-y: auto; }
    .quiz-modal-body .loading, .quiz-modal-body .error { text-align: center; color: var(--muted); padding: 40px 0; }
    .qv-question { border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; }
    .qv-question-text { font-weight: 700; color: var(--text); margin: 0 0 14px 0; font-size: 15px; }
    .material-link-btn { display: inline-flex; align-items: center; gap: 10px; border: none; border-radius: 10px; padding: 10px 20px; color: white; background: linear-gradient(135deg, #6B8AA6, #58608A); font-size: 15px; font-weight: 700; text-decoration: none; }
    .material-link-btn:hover { color: white; box-shadow: 0 6px 16px rgba(88, 96, 138, 0.24); }
    @media (max-width: 760px) {
      .app { display: block; }
      .sidebar { border-right: 0; border-bottom: 1px solid var(--line); }
      .main { display: block; }
      .topbar { height: auto; align-items: stretch; flex-direction: column; padding: 16px; }
      .content { padding: 22px 18px; }
      .page-head { align-items: flex-start; flex-direction: column; }
      .materials-card { padding: 22px 16px; }
    }
  </style>
</head>
<body data-page="materials">
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg><span>Dashboard</span></a>
        <a href="TProfile.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Profile</span></a>
        <a href="TCreateQuiz.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14"/><path d="M5 12h14"/></svg><span>Create Quiz</span></a>
        <a href="TQuizManagement.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01"/><path d="M9 15h.01"/><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8"/><path d="M12 15a3 3 0 1 0 0-6"/></svg><span>Manage Quiz</span></a>
        <a href="TLearningMaterials.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg><span>Upload Materials</span></a>
        <a href="TClassPerformance.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/></svg><span>Analytics</span></a>
        <a href="TStudentAnalytic.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Student View</span></a>
      </nav>
      <button class="sidebar-logout" id="logoutBtn" type="button">Logout</button>
    </aside>

    <main class="main">
      <header class="topbar" id="topbar">
        <div class="profile">
          <button class="top-icon theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
          </button>
          <button class="top-icon" id="notificationBtn" type="button" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 21h4"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/></svg>
          </button>
          <div class="avatar" aria-hidden="true" style="<?= profileAvatarStyle($teacherAvatar) ?>"></div>
          <div><strong><?= e($teacherName) ?></strong><span><?= e($classLabel) ?></span></div>
        </div>
      </header>

      <section class="content">
        <div class="page-head">
          <div>
            <h2>Learning Materials</h2>
            <p>Upload notes, textbooks, and videos. Organize by subject.</p>
          </div>
        </div>

        <?php if ($message !== ''): ?>
          <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
        <?php endif; ?>

        <section class="upload-form-card" id="uploadFormSection">
          <h3><?= $formValues['material_id'] > 0 ? 'Edit Material' : 'Upload New Material' ?></h3>
          <form method="post" enctype="multipart/form-data" class="row g-3" id="materialUploadForm">
            <?php if ($formValues['material_id'] > 0): ?>
              <input type="hidden" name="material_id" id="editMaterialIdInput" value="<?= (int) $formValues['material_id'] ?>" />
            <?php endif; ?>
            <div class="col-12">
              <label class="form-label fw-bold">Material Title</label>
              <input type="text" class="form-control" id="materialTitleInput" name="title" value="<?= e($formValues['title']) ?>" placeholder="e.g. SQL Joins Handbook" required />
              <div id="materialTitleFeedback" class="form-text text-danger" style="display:none;">You already have a material with this title.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Subject</label>
              <select class="form-select" name="subject_id" id="subjectSelect" required>
                <option value="">Select subject</option>
                <?php foreach ($subjects as $subject): ?>
                  <option value="<?= (int) $subject['subject_id'] ?>" <?= ($formValues['subject_id'] == (int) $subject['subject_id']) ? 'selected' : '' ?>><?= e($subject['subject_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Topic</label>
              <select class="form-select" name="topic_id" id="topicSelect">
                <?php if (empty($formValues['subject_id'])): ?>
                  <option value="">Select a subject first</option>
                <?php else: ?>
                  <?php foreach ($editTopics as $topic): ?>
                    <option value="<?= (int) $topic['topic_id'] ?>" data-difficulty="<?= e($topic['difficulty_level']) ?>" <?= ((string) $topic['topic_id'] === $formValues['topic_id']) ? 'selected' : '' ?>><?= e($topic['topic_name']) ?></option>
                  <?php endforeach; ?>
                  <option value="new" <?= $formValues['topic_id'] === 'new' ? 'selected' : '' ?>>+ Add new topic</option>
                <?php endif; ?>
              </select>
              <input type="text" class="form-control mt-2" name="new_topic_name" id="newTopicName" placeholder="New topic name" value="<?= e($formValues['new_topic_name']) ?>" style="display:<?= $formValues['topic_id'] === 'new' ? 'block' : 'none' ?>;" />
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Difficulty Level</label>
              <select class="form-select" name="difficulty_level" id="difficultySelect">
                <option value="Easy" <?= $formValues['difficulty_level'] === 'Easy' ? 'selected' : '' ?>>Easy</option>
                <option value="Medium" <?= $formValues['difficulty_level'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                <option value="Hard" <?= $formValues['difficulty_level'] === 'Hard' ? 'selected' : '' ?>>Hard</option>
              </select>
              <small class="text-muted d-block mt-1">Changing this updates every material under this topic.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Upload PDF</label>
              <input type="file" class="form-control" name="matPdf" accept=".pdf" />
              <?php if (!empty($editingMaterial['file_path'])): ?>
                <small class="text-muted d-block mt-1">Current file: <?= e(basename($editingMaterial['file_path'])) ?> (choose a new file to replace it)</small>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Video URL (optional)</label>
              <input type="url" class="form-control" name="video_url" value="<?= e($formValues['video_url']) ?>" placeholder="https://..." />
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#6B8AA6,#58608A);border:0;font-weight:700;">
                <?= $formValues['material_id'] > 0 ? 'Update Material' : 'Upload Material' ?>
              </button>
              <?php if ($formValues['material_id'] > 0): ?>
                <a href="TLearningMaterials.php" class="btn btn-outline-secondary ms-2">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <div class="filter-toolbar">
          <input type="search" id="materialSearch" placeholder="Search materials by title or subject..." class="filter-search" />

          <select id="filterSubject" class="form-select filter-select">
            <option value="">All subjects</option>
            <?php foreach ($filterSubjects as $filterSubjectName): ?>
              <option value="<?= e($filterSubjectName) ?>"><?= e($filterSubjectName) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="filterTopic" class="form-select filter-select">
            <option value="">All topics</option>
            <?php foreach ($filterTopics as $filterTopicOption): ?>
              <option value="<?= e($filterTopicOption['topic_name']) ?>" data-subject="<?= e($filterTopicOption['subject_name']) ?>"><?= e($filterTopicOption['topic_name']) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="filterDifficulty" class="form-select filter-select">
            <option value="">All difficulties</option>
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>

          <button type="button" id="clearFiltersBtn" class="filter-clear-btn">Clear filters</button>
        </div>

        <h3 class="section-title">All Materials</h3>
        <p style="color: var(--muted); margin: 0 0 16px 0; font-size: 14px;"><span id="materialCountLabel"><?= count($materials) ?></span> resources</p>

        <div class="table-wrapper">
          <div class="table-scroll">
            <table class="materials-table" id="materialTable">
              <thead>
                <tr>
                  <th style="width: 21%">Title</th>
                  <th style="width: 13%">Subject</th>
                  <th style="width: 13%">Topic</th>
                  <th style="width: 14%">Difficulty</th>
                  <th style="width: 10%">Uploaded</th>
                  <th style="width: 20%; text-align: center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($materials)): ?>
                  <tr>
                    <td colspan="6" style="text-align:center; padding: 40px 20px;">No materials yet. Upload your first resource above.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($materials as $material): ?>
                    <tr id="material-row-<?= (int) $material['material_id'] ?>"
                      data-title="<?= e(strtolower($material['title'])) ?>"
                      data-subject="<?= e($material['subject_name'] ?: 'Unassigned') ?>"
                      data-topic="<?= e($material['topic_name'] ?: '') ?>"
                      data-difficulty="<?= e(strtolower($material['difficulty_level'] ?: '')) ?>"
                    >
                      <td>
                        <div class="name-cell">
                          <div class="material-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z" /><path d="M14 2v5h5" /><path d="M10 13h4" /><path d="M12 11v6" /></svg>
                          </div>
                          <div>
                            <div class="material-name"><?= e($material['title']) ?></div>
                          </div>
                        </div>
                      </td>
                      <td class="subject"><?= e($material['subject_name'] ?? 'Unassigned') ?></td>
                      <td><?= e($material['topic_name'] ?? '—') ?></td>
                      <td><?php if (!empty($material['difficulty_level'])): ?><span class="badge <?= difficultyBadge($material['difficulty_level']) ?>"><?= e($material['difficulty_level']) ?></span><?php else: ?>—<?php endif; ?></td>
                      <td><?= e(formatDate($material['upload_date'])) ?></td>
                      <td>
                        <div class="actions">
                          <button class="action-btn view-material-btn" title="View" type="button" data-material-id="<?= (int) $material['material_id'] ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                          </button>
                          <a class="action-btn" href="TLearningMaterials.php?edit=<?= (int) $material['material_id'] ?>" title="Edit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                          </a>
                          <form method="post" style="display:inline;" onsubmit="return confirm('Delete this material?');">
                            <input type="hidden" name="delete_material_id" value="<?= (int) $material['material_id'] ?>" />
                            <button class="action-btn delete" title="Delete" type="submit">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18" /><path d="M8 6V4h8v2" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /></svg>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                <tr class="no-results-row" id="noResultsRow" style="display:none;">
                  <td colspan="6">No materials match your filters.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <div class="quiz-modal-backdrop" id="materialModalBackdrop">
    <div class="quiz-modal" role="dialog" aria-modal="true" aria-labelledby="materialModalTitle">
      <div class="quiz-modal-header">
        <div>
          <h3 id="materialModalTitle">Material Details</h3>
          <div class="quiz-modal-meta" id="materialModalMeta"></div>
        </div>
        <button type="button" class="quiz-modal-close" id="materialModalClose" aria-label="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
        </button>
      </div>
      <div class="quiz-modal-body" id="materialModalBody">
        <div class="loading">Loading material…</div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="teacher-shared.js"></script>
  <?php if ($toastMessage !== null): ?>
  <script>
    if (typeof window.showToast === 'function') {
      window.showToast(<?= json_encode($toastMessage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    }
  </script>
  <?php endif; ?>
  <script>
    const materialTitleInput = document.getElementById('materialTitleInput');
    const materialTitleFeedback = document.getElementById('materialTitleFeedback');
    const editMaterialIdInput = document.getElementById('editMaterialIdInput');
    const materialUploadForm = document.getElementById('materialUploadForm');
    let materialTitleCheckTimer = null;
    let materialTitleIsDuplicate = false;

    function checkMaterialTitleDuplicate() {
      const title = materialTitleInput.value.trim();
      if (!title) {
        materialTitleIsDuplicate = false;
        materialTitleFeedback.style.display = 'none';
        return;
      }
      const excludeId = editMaterialIdInput ? editMaterialIdInput.value : '';
      const params = new URLSearchParams({ action: 'checkTitle', title: title });
      if (excludeId) params.set('exclude_material_id', excludeId);
      fetch(`?${params.toString()}`)
        .then((res) => res.json())
        .then((data) => {
          materialTitleIsDuplicate = !!data.exists;
          materialTitleFeedback.style.display = materialTitleIsDuplicate ? '' : 'none';
        })
        .catch(() => {
        });
    }

    if (materialTitleInput) {
      materialTitleInput.addEventListener('input', () => {
        clearTimeout(materialTitleCheckTimer);
        materialTitleCheckTimer = setTimeout(checkMaterialTitleDuplicate, 400);
      });
    }

    if (materialUploadForm) {
      materialUploadForm.addEventListener('submit', (e) => {
        if (materialTitleIsDuplicate) {
          e.preventDefault();
          materialTitleFeedback.style.display = '';
          materialTitleInput.focus();
        }
      });
    }

    const subjectSelect = document.getElementById('subjectSelect');
    const topicSelect = document.getElementById('topicSelect');
    const newTopicName = document.getElementById('newTopicName');
    const difficultySelect = document.getElementById('difficultySelect');

    function toggleNewTopicField() {
      if (topicSelect.value === 'new') {
        newTopicName.style.display = '';
      } else {
        newTopicName.style.display = 'none';
        newTopicName.value = '';
      }
    }

    function syncDifficultyWithTopic() {
      const selectedOption = topicSelect.options[topicSelect.selectedIndex];
      const difficulty = selectedOption ? selectedOption.getAttribute('data-difficulty') : null;
      if (difficulty && ['Easy', 'Medium', 'Hard'].includes(difficulty)) {
        difficultySelect.value = difficulty;
      }
    }

    function onTopicChange() {
      toggleNewTopicField();
      syncDifficultyWithTopic();
    }

    async function loadTopicsForSubject(subjectId, selectedTopicId) {
      if (!subjectId) {
        topicSelect.innerHTML = '<option value="">Select a subject first</option>';
        onTopicChange();
        return;
      }
      topicSelect.innerHTML = '<option value="">Loading topics…</option>';
      try {
        const response = await fetch(`?action=getTopicsBySubject&subject_id=${encodeURIComponent(subjectId)}`);
        const data = await response.json();
        const topics = (data && data.success) ? data.topics : [];

        let optionsHtml = topics.map((t) => {
          const selected = String(t.topic_id) === String(selectedTopicId) ? 'selected' : '';
          return `<option value="${t.topic_id}" data-difficulty="${escapeHtmlMat(t.difficulty_level)}" ${selected}>${escapeHtmlMat(t.topic_name)}</option>`;
        }).join('');

        if (!topics.length) {
          optionsHtml += '<option value="" disabled>No topics yet for this subject</option>';
        }
        optionsHtml += '<option value="new">+ Add new topic</option>';

        topicSelect.innerHTML = optionsHtml;
      } catch (error) {
        console.error('Error loading topics:', error);
        topicSelect.innerHTML = '<option value="new">+ Add new topic</option>';
      }
      onTopicChange();
    }

    if (subjectSelect && topicSelect) {
      subjectSelect.addEventListener('change', () => loadTopicsForSubject(subjectSelect.value, null));
      topicSelect.addEventListener('change', onTopicChange);
      toggleNewTopicField();
      syncDifficultyWithTopic();
    }

    const materialModalBackdrop = document.getElementById('materialModalBackdrop');
    const materialModalBody = document.getElementById('materialModalBody');
    const materialModalMeta = document.getElementById('materialModalMeta');
    const materialModalTitle = document.getElementById('materialModalTitle');

    function escapeHtmlMat(str) {
      const div = document.createElement('div');
      div.textContent = str ?? '';
      return div.innerHTML;
    }

    function openMaterialModal() {
      materialModalBackdrop.classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeMaterialModal() {
      materialModalBackdrop.classList.remove('show');
      document.body.style.overflow = '';
    }

    materialModalBackdrop.addEventListener('click', (e) => {
      if (e.target === materialModalBackdrop) closeMaterialModal();
    });
    document.getElementById('materialModalClose').addEventListener('click', closeMaterialModal);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && materialModalBackdrop.classList.contains('show')) closeMaterialModal();
    });

    async function viewMaterial(materialId) {
      materialModalTitle.textContent = 'Material Details';
      materialModalMeta.innerHTML = '';
      materialModalBody.innerHTML = '<div class="loading">Loading material…</div>';
      openMaterialModal();

      try {
        const response = await fetch(`?action=getMaterialDetails&material_id=${encodeURIComponent(materialId)}`);
        const data = await response.json();

        if (!data.success) {
          materialModalBody.innerHTML = `<div class="error">${escapeHtmlMat(data.message || 'Material not found.')}</div>`;
          return;
        }

        const m = data.material;
        materialModalTitle.textContent = m.title || 'Untitled Material';
        const uploadedDate = m.upload_date ? new Date(m.upload_date.replace(' ', 'T')).toLocaleDateString() : '—';
        materialModalMeta.innerHTML = `
          <span>Type: <strong>${escapeHtmlMat(m.material_type || '—')}</strong></span>
          <span>Subject: <strong>${escapeHtmlMat(m.subject_name || 'Unassigned')}</strong></span>
          <span>Topic: <strong>${escapeHtmlMat(m.topic_name || '—')}</strong></span>
          <span>Uploaded: <strong>${escapeHtmlMat(uploadedDate)}</strong></span>
        `;

        const hasPdf = m.pdf_url && m.pdf_url.trim() !== '';
        const hasVideo = m.video_url && m.video_url.trim() !== '';

        let resourcesHtml = '';
        if (hasPdf) {
          resourcesHtml += `
            <div class="qv-question" style="margin-bottom: 12px;">
              <p class="qv-question-text">Uploaded file</p>
              <a class="material-link-btn" href="${escapeHtmlMat(m.pdf_url)}" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="18" height="18"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z" /><path d="M14 2v5h5" /></svg>
                Open PDF file
              </a>
            </div>`;
        }
        if (hasVideo) {
          resourcesHtml += `
            <div class="qv-question">
              <p class="qv-question-text">Video link</p>
              <a class="material-link-btn" href="${escapeHtmlMat(m.video_url)}" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                Open video link
              </a>
            </div>`;
        }
        if (!hasPdf && !hasVideo) {
          resourcesHtml = '<div class="qv-question"><p style="color: var(--muted); margin: 0;">No file or link was attached to this material.</p></div>';
        }

        materialModalBody.innerHTML = resourcesHtml;
      } catch (error) {
        console.error('Error loading material details:', error);
        materialModalBody.innerHTML = '<div class="error">Something went wrong while loading this material.</div>';
      }
    }

    document.querySelectorAll('.view-material-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const materialId = btn.getAttribute('data-material-id');
        if (materialId) viewMaterial(materialId);
      });
    });

    const urlParams = new URLSearchParams(window.location.search);
    const targetMaterialId = urlParams.get('view');
    if (targetMaterialId) {
      const targetRow = document.getElementById('material-row-' + targetMaterialId);
      if (targetRow) {
        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        targetRow.classList.add('row-highlight');
        setTimeout(() => targetRow.classList.remove('row-highlight'), 2200);
      }
      viewMaterial(targetMaterialId);
    }

    (function setupMaterialFilters() {
      const dataRows = Array.from(document.querySelectorAll('#materialTable tbody tr[data-title]'));
      if (!dataRows.length) return; 

      const searchInput = document.getElementById('materialSearch');
      const subjectSelect = document.getElementById('filterSubject');
      const topicSelect = document.getElementById('filterTopic');
      const difficultySelect = document.getElementById('filterDifficulty');
      const clearBtn = document.getElementById('clearFiltersBtn');
      const noResultsRow = document.getElementById('noResultsRow');
      const materialCountLabel = document.getElementById('materialCountLabel');
      const topicOptions = Array.from(topicSelect.options).filter((opt) => opt.value !== '');

      function syncTopicOptions() {
        const subject = subjectSelect.value;
        let selectedStillValid = topicSelect.value === '';

        topicOptions.forEach((opt) => {
          const belongs = !subject || opt.dataset.subject === subject;
          opt.hidden = !belongs;
          opt.disabled = !belongs;
          if (belongs && opt.value === topicSelect.value) {
            selectedStillValid = true;
          }
        });

        if (!selectedStillValid) {
          topicSelect.value = '';
        }
      }

      function applyFilters() {
        syncTopicOptions();
        const searchTerm = searchInput.value.trim().toLowerCase();
        const subject = subjectSelect.value;
        const topic = topicSelect.value;
        const difficulty = difficultySelect.value;

        let visibleCount = 0;

        dataRows.forEach((row) => {
          const title = row.dataset.title || '';
          const rowSubject = row.dataset.subject || '';
          const rowTopic = row.dataset.topic || '';
          const rowDifficulty = row.dataset.difficulty || '';

          let matches = true;

          if (searchTerm && !title.includes(searchTerm) && !rowSubject.toLowerCase().includes(searchTerm)) {
            matches = false;
          }
          if (matches && subject && rowSubject !== subject) {
            matches = false;
          }
          if (matches && topic && rowTopic !== topic) {
            matches = false;
          }
          if (matches && difficulty && rowDifficulty !== difficulty) {
            matches = false;
          }

          row.style.display = matches ? '' : 'none';
          if (matches) visibleCount++;
        });

        noResultsRow.style.display = visibleCount === 0 ? '' : 'none';
        if (materialCountLabel) materialCountLabel.textContent = visibleCount;
      }

      [searchInput, subjectSelect, topicSelect, difficultySelect].forEach((el) => {
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
      });

      clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        subjectSelect.value = '';
        topicSelect.value = '';
        difficultySelect.value = '';
        applyFilters();
      });
    })();
  </script>
</body>
</html>