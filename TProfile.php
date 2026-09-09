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

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "Teacher") {
    header("Location: ../index.php");
    exit();
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    header('Content-Type: application/json');

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please select an image to upload.']);
        exit();
    }

    $avatarFile = $_FILES['avatar'];
    $extension = strtolower(pathinfo($avatarFile['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    $allowedMimeTypes = ['image/jpeg', 'image/png'];

    if (!in_array($extension, $allowedExtensions, true)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, and PNG images are allowed.']);
        exit();
    }

    if ($avatarFile['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image size cannot exceed 2MB.']);
        exit();
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $avatarFile['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, $allowedMimeTypes, true) || !is_uploaded_file($avatarFile['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid image file.']);
        exit();
    }

    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }

    $uploadDirectory = $baseDir . '/uploads/avatars/';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Unable to create upload directory.']);
        exit();
    }

    $fileName = 'avatar_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
    $relativePath = 'uploads/avatars/' . $fileName;
    $targetFile = $uploadDirectory . $fileName;

    $oldPictureStmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $oldPictureStmt->bind_param('i', $userId);
    $oldPictureStmt->execute();
    $oldRow = $oldPictureStmt->get_result()->fetch_assoc();
    $oldPictureStmt->close();
    $oldPicture = $oldRow['profile_picture'] ?? '';

    if (!move_uploaded_file($avatarFile['tmp_name'], $targetFile)) {
        echo json_encode(['success' => false, 'message' => 'Avatar upload failed.']);
        exit();
    }

    $avatarUpdateStmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
    $avatarUpdateStmt->bind_param('si', $relativePath, $userId);

    if ($avatarUpdateStmt->execute()) {
        $_SESSION['profile_picture'] = $relativePath;

        if ($oldPicture && strpos($oldPicture, 'uploads/avatars/') === 0) {
            $oldFilePath = $baseDir . '/' . $oldPicture;
            if (is_file($oldFilePath)) {
                unlink($oldFilePath);
            }
        }

        mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Profile Update', 'Updated profile picture.', 'Success', NOW())");

        echo json_encode(['success' => true, 'message' => 'Avatar updated successfully.', 'path' => $relativePath]);
    } else {
        unlink($targetFile);
        echo json_encode(['success' => false, 'message' => 'Avatar update failed.']);
    }
    $avatarUpdateStmt->close();
    exit();
}

$profile = [
    'username' => 'Dr. Anita Sharma',
    'email' => 'anita.sharma@smartlearn.edu',
    'profile_picture' => '',
    'subject' => '',
    'classes' => '',
];
$message = '';
$messageType = '';
$passwordMessage = '';
$passwordMessageType = '';

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

    $userStmt = $conn->prepare("SELECT name, email, profile_picture FROM users WHERE user_id = ? LIMIT 1");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    if ($userResult && $userRow = $userResult->fetch_assoc()) {
        $profile['username'] = $userRow['name'] ?: $profile['username'];
        $profile['email'] = $userRow['email'] ?: $profile['email'];
        $profile['profile_picture'] = $userRow['profile_picture'] ?? '';
    }
    $userStmt->close();

    $subjectStmt = $conn->prepare("SELECT DISTINCT s.subject_name FROM classes c INNER JOIN subject s ON c.subject_id = s.subject_id WHERE c.user_id = ? ORDER BY s.subject_name ASC");
    $subjectStmt->bind_param('i', $userId);
    $subjectStmt->execute();
    $subjectResult = $subjectStmt->get_result();
    $subjectNames = [];
    if ($subjectResult) {
        while ($subjectRow = $subjectResult->fetch_assoc()) {
            $subjectNames[] = $subjectRow['subject_name'];
        }
    }
    $subjectStmt->close();
    $profile['subject'] = implode(', ', $subjectNames);

    $classStmt = $conn->prepare("SELECT DISTINCT cl.class_name FROM classes c INNER JOIN class cl ON c.class_id = cl.class_id WHERE c.user_id = ? ORDER BY cl.class_name ASC");
    $classStmt->bind_param('i', $userId);
    $classStmt->execute();
    $classResult = $classStmt->get_result();
    $classNames = [];
    if ($classResult) {
        while ($classRow = $classResult->fetch_assoc()) {
            $classNames[] = $classRow['class_name'];
        }
    }
    $classStmt->close();
    $profile['classes'] = implode(', ', $classNames);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_submit'])) {
        $fullName = trim($_POST['full_name'] ?? $profile['username']);

        if ($fullName === '') {
            $message = 'Full name cannot be empty.';
            $messageType = 'danger';
        } else {
            $originalName = $profile['username'];

            $updateStmt = $conn->prepare("UPDATE users SET name = ? WHERE user_id = ?");
            $updateStmt->bind_param('si', $fullName, $userId);
            if ($updateStmt->execute()) {
                $message = 'Profile updated successfully.';
                $messageType = 'success';
                $profile['username'] = $fullName;
                $_SESSION['name'] = $fullName;

                if ($fullName !== $originalName) {
                    $activityDesc = 'Updated name to "' . $fullName . '".';
                    $activityDescEsc = mysqli_real_escape_string($conn, $activityDesc);
                    mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Profile Update', '{$activityDescEsc}', 'Success', NOW())");
                }
            } else {
                $message = 'Unable to update profile.';
                $messageType = 'danger';
            }
            $updateStmt->close();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password_submit'])) {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $pwStmt = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
        $pwStmt->bind_param('i', $userId);
        $pwStmt->execute();
        $pwRow = $pwStmt->get_result()->fetch_assoc();
        $pwStmt->close();
        $storedPassword = $pwRow['password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $passwordMessage = 'Please fill in all password fields.';
            $passwordMessageType = 'danger';
        } elseif ($currentPassword !== $storedPassword) {
            $passwordMessage = 'Current password is incorrect.';
            $passwordMessageType = 'danger';
        } elseif (strlen($newPassword) < 6) {
            $passwordMessage = 'New password must be at least 6 characters.';
            $passwordMessageType = 'danger';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordMessage = 'New password and confirmation do not match.';
            $passwordMessageType = 'danger';
        } else {
            $pwUpdateStmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $pwUpdateStmt->bind_param('si', $newPassword, $userId);
            if ($pwUpdateStmt->execute()) {
                $passwordMessage = 'Password updated successfully.';
                $passwordMessageType = 'success';
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, 'Password Change', 'Changed account password.', 'Success', NOW())");
            } else {
                $passwordMessage = 'Unable to update password.';
                $passwordMessageType = 'danger';
            }
            $pwUpdateStmt->close();
        }
    }

    mysqli_close($conn);
} else {
    $message = 'Unable to connect to the database.';
    $messageType = 'danger';
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function profileAvatarStyle($path) {
    if (empty($path)) {
        return '';
    }
    return "background-image: url('" . e($path) . "'); background-size: cover; background-position: center;";
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profile Settings - SmartLearn Teacher Portal</title>
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
      --blue: #0E80D7;
      --shadow: 0 8px 22px rgba(88, 96, 138, 0.14);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: Inter, "Segoe UI", Arial, sans-serif;
      letter-spacing: 0;
    }

    .app {
      display: grid;
      grid-template-columns: 280px 1fr;
      min-height: 100vh;
    }

    .sidebar {
      background: var(--surface);
      border-right: 1px solid var(--line);
      padding: 22px 16px;
      overflow-y: auto;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 0 8px 20px;
    }

    .brand-logo {
      display: block;
      width: 100%;
      max-width: 180px;
      height: auto;
    }

    .nav {
      display: grid;
      gap: 10px;
    }

    .nav a {
      min-height: 50px;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 16px;
      border-radius: 13px;
      color: #223047;
      font-size: 16px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s;
    }

    .nav a:hover {
      background: rgba(202, 223, 242, 0.5);
    }

    .nav a.active {
      color: var(--ink-blue);
      background: linear-gradient(90deg, rgba(202, 223, 242, 0.96), rgba(150, 185, 217, 0.42));
    }

    .nav svg {
      width: 20px;
      height: 20px;
      stroke-width: 2.1;
    }

    .main {
      display: grid;
      grid-template-rows: 76px 1fr;
      min-width: 0;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      background: var(--surface);
      border-bottom: 1px solid var(--line);
      padding: 0 32px;
    }

    .search {
      width: min(540px, 100%);
      height: 44px;
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1px solid var(--line);
      border-radius: 13px;
      background: #FBFDFF;
      padding: 0 16px;
      color: var(--muted);
      box-shadow: inset 0 0 0 1px rgba(150, 185, 217, 0.12), 0 2px 7px rgba(88, 96, 138, 0.08);
    }

    .search span {
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
      font-size: 15px;
    }

    .search svg {
      width: 19px;
      height: 19px;
      stroke-width: 2;
      flex: 0 0 auto;
    }

    .profile {
      display: flex;
      align-items: center;
      gap: 18px;
      white-space: nowrap;
    }

    .top-icon {
      position: relative;
      width: 32px;
      height: 32px;
      display: grid;
      place-items: center;
      color: #25324A;
      cursor: pointer;
    }

    .top-icon svg {
      width: 20px;
      height: 20px;
      stroke-width: 2;
    }

    .avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 45% 34%, #F4D8C9 0 18%, transparent 19%),
        radial-gradient(circle at 50% 48%, #332B37 0 34%, transparent 35%),
        linear-gradient(135deg, #CADFF2, #58608A);
      border: 2px solid #E7EEF5;
    }

    .profile strong {
      display: block;
      font-size: 15px;
    }

    .profile span {
      color: var(--muted);
      font-size: 13px;
    }

    .content {
      padding: 38px;
      overflow: auto;
    }

    .header {
      margin-bottom: 32px;
    }

    .header h2 {
      margin: 0 0 8px 0;
      font-size: 32px;
      line-height: 1.2;
      color: var(--text);
    }

    .header p {
      margin: 0;
      color: var(--muted);
      font-size: 15px;
    }

    .profile-layout {
      display: grid;
      grid-template-columns: 300px 1fr;
      gap: 24px;
      align-items: start;
      max-width: 1180px;
    }

    .settings-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 32px;
      box-shadow: var(--shadow);
    }

    .settings-card + .settings-card {
      margin-top: 24px;
    }

    .side-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 4px;
    }

    .side-card .avatar-large {
      margin-bottom: 12px;
    }

    .side-card h3 {
      margin: 0;
      font-size: 19px;
      color: var(--text);
    }

    .side-card .side-role {
      margin: 2px 0 20px;
      color: var(--muted);
      font-size: 14px;
    }

    .side-divider {
      width: 100%;
      height: 1px;
      background: var(--line);
      margin: 4px 0 18px;
    }

    .side-info {
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-bottom: 22px;
    }

    .side-info-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      font-size: 13px;
      text-align: left;
    }

    .side-info-row span {
      color: var(--muted);
    }

    .side-info-row strong {
      color: var(--text);
      font-weight: 700;
      text-align: right;
    }

    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
    }

    .card-header h3 {
      margin: 0;
      font-size: 19px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .card-header h3 svg {
      width: 19px;
      height: 19px;
      stroke-width: 2.2;
      color: var(--blue);
    }

    .save-btn-sm {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      height: 38px;
      padding: 0 18px;
      border: none;
      border-radius: 9px;
      background: linear-gradient(135deg, #0E80D7, #3298D5);
      color: white;
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 6px 16px rgba(14, 128, 215, 0.24);
      flex: 0 0 auto;
    }

    .save-btn-sm:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(14, 128, 215, 0.32);
    }

    .save-btn-sm svg {
      width: 16px;
      height: 16px;
      stroke-width: 2.2;
    }

    .form-section {
      display: grid;
      gap: 28px;
    }

    .avatar-large {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 30% 30%, #F4D8C9 0 20%, transparent 21%),
        radial-gradient(circle at 50% 50%, #4A3A42 0 40%, transparent 41%),
        linear-gradient(135deg, #CADFF2, #58608A);
      border: 3px solid #E7EEF5;
      box-shadow: 0 8px 20px rgba(88, 96, 138, 0.16);
    }

    .change-photo-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      height: 40px;
      padding: 0 16px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--surface);
      color: var(--text);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
    }

    .change-photo-btn:hover {
      border-color: var(--sky);
      background: rgba(150, 185, 217, 0.08);
      color: var(--steel);
    }

    .change-photo-btn svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
    }

    .form-row {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 24px;
    }

    .form-row.full {
      grid-template-columns: 1fr;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-label {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
    }

    .form-input,
    .form-textarea {
      padding: 12px 16px;
      border: 1px solid var(--line);
      border-radius: 10px;
      font-size: 14px;
      font-family: inherit;
      color: var(--text);
      background: #FBFDFF;
      transition: all 0.2s;
    }

    .form-input:focus,
    .form-textarea:focus {
      outline: none;
      border-color: var(--sky);
      background: white;
      box-shadow: 0 0 0 3px rgba(150, 185, 217, 0.1);
    }

    .form-textarea {
      resize: vertical;
      min-height: 100px;
    }

    .form-row.actions {
      margin-top: 8px;
    }

    .save-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-height: 44px;
      padding: 0 28px;
      border: none;
      border-radius: 10px;
      background: linear-gradient(135deg, #0E80D7, #3298D5);
      color: white;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 6px 16px rgba(14, 128, 215, 0.24);
    }

    .save-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(14, 128, 215, 0.32);
    }

    .save-btn:active {
      transform: translateY(0);
    }

    .save-btn svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
    }

    .password-section {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .password-info {
      padding: 16px;
      background: rgba(14, 128, 215, 0.08);
      border: 1px solid rgba(14, 128, 215, 0.2);
      border-radius: 10px;
      color: var(--text);
      font-size: 14px;
      line-height: 1.5;
    }

    .password-info svg {
      width: 20px;
      height: 20px;
      stroke-width: 2;
      margin-right: 8px;
      vertical-align: middle;
      color: var(--blue);
    }

    @media (max-width: 1180px) {
      .app {
        grid-template-columns: 88px 1fr;
      }

      .brand {
        justify-content: center;
        margin-left: 0;
        margin-right: 0;
      }

      .nav a span {
        display: none;
      }

      .nav a {
        justify-content: center;
        padding: 0;
      }
    }

    @media (max-width: 860px) {
      .app {
        display: block;
      }

      .sidebar {
        position: sticky;
        top: 0;
        z-index: 5;
        display: flex;
        align-items: center;
        gap: 12px;
        overflow-x: auto;
        border-right: 0;
        border-bottom: 1px solid var(--line);
        padding: 12px;
      }

      .brand {
        margin: 0;
        flex: 0 0 auto;
      }

      .nav {
        display: flex;
        gap: 8px;
      }

      .nav a {
        width: 50px;
        flex: 0 0 auto;
      }

      .main {
        display: block;
      }

      .topbar {
        height: auto;
        align-items: stretch;
        flex-direction: column;
        padding: 16px;
      }

      .profile {
        justify-content: space-between;
      }

      .content {
        padding: 18px;
      }

      .header h2 {
        font-size: 24px;
      }

      .settings-card {
        padding: 20px;
      }

      .form-row {
        grid-template-columns: 1fr;
      }

      .profile-layout {
        grid-template-columns: 1fr;
      }

      .card-header {
        flex-wrap: wrap;
      }
    }

    @media (max-width: 640px) {
      .content {
        padding: 16px;
      }

      .header h2 {
        font-size: 20px;
      }

      .settings-card {
        padding: 16px;
        border-radius: 14px;
      }

      .avatar-large {
        width: 100px;
        height: 100px;
      }

      .form-row {
        gap: 16px;
      }

      .save-btn-sm {
        width: 100%;
      }
    }
  </style>
</head>
<body data-page="profile">
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg><span>Dashboard</span></a>
        <a href="TProfile.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Profile</span></a>
        <a href="TCreateQuiz.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14"/><path d="M5 12h14"/></svg><span>Create Quiz</span></a>
        <a href="TQuizManagement.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01"/><path d="M9 15h.01"/><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8"/><path d="M12 15a3 3 0 1 0 0-6"/></svg><span>Manage Quiz</span></a>
        <a href="TLearningMaterials.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg><span>Upload Materials</span></a>
        <a href="TClassPerformance.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/></svg><span>Analytics</span></a>
        <a href="TStudentAnalytic.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Student View</span></a>
      </nav>

      <button class="sidebar-logout" id="logoutBtn" type="button">Logout</button>
    </aside>

    <main class="main">
      <header class="topbar" id="topbar">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
          <input type="search" id="globalSearch" placeholder="Search students, quizzes, materials..." autocomplete="off" />
        </div>

        <div class="profile">
          <button class="top-icon theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
          </button>
          <button class="top-icon" id="notificationBtn" type="button" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 21h4"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/></svg>
          </button>
          <div class="avatar" aria-hidden="true" style="<?= profileAvatarStyle($profile['profile_picture']) ?>"></div>
          <div><strong><?= e($profile['username']) ?></strong><span><?= e($profile['subject'] !== '' ? $profile['subject'] : 'Teacher') ?></span></div>
        </div>
      </header>

      <section class="content">
        <div class="header">
          <h2>Profile Settings</h2>
          <p>Manage your account information.</p>
        </div>

        <div class="profile-layout">
          <div class="settings-card side-card">
            <div class="avatar-large" id="avatarPreview" style="<?= profileAvatarStyle($profile['profile_picture']) ?>"></div>
            <h3><?= e($profile['username']) ?></h3>
            <p class="side-role"><?= e($profile['subject'] !== '' ? $profile['subject'] : 'Teacher') ?></p>

            <div class="side-divider"></div>

            <div class="side-info">
              <div class="side-info-row">
                <span>Subject</span>
                <strong><?= e($profile['subject'] !== '' ? $profile['subject'] : 'Not assigned') ?></strong>
              </div>
              <div class="side-info-row">
                <span>Class</span>
                <strong><?= e($profile['classes'] !== '' ? $profile['classes'] : 'Not assigned') ?></strong>
              </div>
            </div>

            <input type="file" id="avatarUpload" name="avatar" accept="image/jpeg,image/png,.jpg,.jpeg,.png" hidden />
            <button class="change-photo-btn" type="button" id="changePhotoBtn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
              Upload Photo
            </button>
          </div>

          <div class="profile-main">
            <form class="settings-card form-section" id="accountForm" method="post">
              <input type="hidden" name="profile_submit" value="1" />
              <div class="card-header">
                <h3>Account Information</h3>
                <button type="submit" class="save-btn-sm">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" /><polyline points="17 21 17 13 7 13 7 21" /><polyline points="7 3 7 8 15 8" /></svg>
                  Save
                </button>
              </div>

              <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
              <?php endif; ?>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Full Name</label>
                  <input type="text" class="form-input" name="full_name" value="<?= e($profile['username']) ?>" placeholder="Enter your full name">
                </div>
                <div class="form-group">
                  <label class="form-label">Email</label>
                  <input type="email" class="form-input" value="<?= e($profile['email']) ?>" readonly>
                </div>
              </div>
            </form>

            <form class="settings-card form-section" method="post">
              <input type="hidden" name="password_submit" value="1" />
              <div class="card-header">
                <h3>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                  Security
                </h3>
              </div>

              <div class="password-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="display: inline;"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="16" /><line x1="8" y1="12" x2="16" y2="12" /></svg>
                Keep your account secure by using a strong password. Use a mix of uppercase, lowercase, numbers, and special characters.
              </div>

              <?php if ($passwordMessage !== ''): ?>
                <div class="alert alert-<?= e($passwordMessageType) ?>" role="alert"><?= e($passwordMessage) ?></div>
              <?php endif; ?>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Current Password</label>
                  <input type="password" class="form-input" name="current_password" placeholder="Enter current password" autocomplete="current-password">
                </div>
                <div class="form-group">
                  <label class="form-label">New Password</label>
                  <input type="password" class="form-input" name="new_password" placeholder="Enter new password" autocomplete="new-password">
                </div>
              </div>

              <div class="form-row full">
                <div class="form-group">
                  <label class="form-label">Confirm Password</label>
                  <input type="password" class="form-input" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password">
                </div>
              </div>

              <div class="form-row actions">
                <button type="submit" class="save-btn" id="passwordSaveBtn">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" /><polyline points="17 21 17 13 7 13 7 21" /><polyline points="7 3 7 8 15 8" /></svg>
                  Update Password
                </button>
              </div>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="teacher-shared.js"></script>
  <script>
    const avatarUpload = document.getElementById('avatarUpload');
    const avatarPreview = document.getElementById('avatarPreview');
    document.getElementById('changePhotoBtn')?.addEventListener('click', () => avatarUpload?.click());
    avatarUpload?.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) {
        return;
      }

      const previewUrl = URL.createObjectURL(file);
      avatarPreview.style.backgroundImage = `url(${previewUrl})`;
      avatarPreview.style.backgroundSize = 'cover';

      const formData = new FormData();
      formData.append('action', 'upload_avatar');
      formData.append('avatar', file);

      try {
        const res = await fetch(window.location.pathname, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const result = await res.json();
        showToast(result.message || (result.success ? 'Avatar updated successfully.' : 'Avatar upload failed.'));
        if (result.success && result.path) {
          avatarPreview.style.backgroundImage = `url(${result.path}?v=${Date.now()})`;
        }
      } catch (err) {
        showToast('Avatar upload failed.');
      } finally {
        avatarUpload.value = '';
      }
    });

    const passwordForm = document.querySelector('input[name="password_submit"]')?.closest('form');
    if (passwordForm) {
      passwordForm.addEventListener('submit', (e) => {
        const newPassword = passwordForm.querySelector('[name="new_password"]').value;
        const confirmPassword = passwordForm.querySelector('[name="confirm_password"]').value;
        if (newPassword !== confirmPassword) {
          e.preventDefault();
          showToast('New password and confirmation do not match.');
        }
      });
    }
  </script>
</body>
</html>