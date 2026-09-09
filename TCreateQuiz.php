<?php
require_once "../config/session.php";
require_once "../config/paths.php";
require_once __DIR__ . "/../student/notification.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "Teacher") {
    header("Location: ../index.php");
    exit();
}

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'smartlearn';

$conn = mysqli_connect($host, $user, $pass, $dbname);
$userId = $_SESSION['user_id'];
$teacherName = 'Teacher';
$teacherAvatar = '';
$classLabel = 'Teacher';
$subjects = [];
$message = '';
$messageType = '';
$toastMessage = null;
$isEditMode = false;
$editQuizData = null;
$editQuestions = [];
$editQuizHasSubmissions = false;

if (isset($_GET['action']) && $_GET['action'] === 'getTopics' && isset($_GET['subject_name'])) {
  header('Content-Type: application/json');
  if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $subjectName = mysqli_real_escape_string($conn, $_GET['subject_name']);
    $topicsResult = mysqli_query($conn, "SELECT topic_id, topic_name FROM topic WHERE subject_id = (SELECT subject_id FROM subject WHERE subject_name = '{$subjectName}' LIMIT 1) ORDER BY topic_name");
    $topics = [];
    if ($topicsResult) {
      $topics = mysqli_fetch_all($topicsResult, MYSQLI_ASSOC);
    }
    echo json_encode($topics);
    mysqli_close($conn);
  }
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'checkTitle' && isset($_GET['quiz_title'])) {
  header('Content-Type: application/json');
  if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $checkTitle = trim($_GET['quiz_title']);
    $excludeId = isset($_GET['exclude_quiz_id']) ? (int) $_GET['exclude_quiz_id'] : 0;
    $exists = false;
    if ($checkTitle !== '') {
      $checkTitleEsc = mysqli_real_escape_string($conn, $checkTitle);
      $dupQuery = "SELECT quiz_id FROM quiz WHERE user_id = {$userId} AND LOWER(quiz_title) = LOWER('{$checkTitleEsc}')";
      if ($excludeId > 0) {
        $dupQuery .= " AND quiz_id != {$excludeId}";
      }
      $dupQuery .= " LIMIT 1";
      $dupResult = mysqli_query($conn, $dupQuery);
      $exists = $dupResult && mysqli_num_rows($dupResult) > 0;
    }
    echo json_encode(['exists' => $exists]);
    mysqli_close($conn);
  }
  exit;
}

if ($conn) {
  mysqli_set_charset($conn, 'utf8mb4');

  $teacherResult = mysqli_query($conn, "SELECT name, profile_picture FROM users WHERE user_id = {$userId} AND role = 'Teacher' LIMIT 1");
  if ($teacherResult && $teacherRow = mysqli_fetch_assoc($teacherResult)) {
    $teacherName = $teacherRow['name'];
    $teacherAvatar = $teacherRow['profile_picture'] ?? '';
  }

  $classSubjectResult = mysqli_query($conn, "SELECT DISTINCT s.subject_name FROM classes c JOIN subject s ON c.subject_id = s.subject_id WHERE c.user_id = {$userId} ORDER BY s.subject_name ASC");
  if ($classSubjectResult) {
    $classSubjectNames = [];
    while ($classSubjectRow = mysqli_fetch_assoc($classSubjectResult)) {
      $classSubjectNames[] = $classSubjectRow['subject_name'];
    }
    if (!empty($classSubjectNames)) {
      $classLabel = implode(', ', $classSubjectNames);
    }
  }

  $subjectsResult = mysqli_query($conn, 'SELECT subject_id, subject_name FROM subject ORDER BY subject_name');
  if ($subjectsResult) {
    $subjects = mysqli_fetch_all($subjectsResult, MYSQLI_ASSOC);
  }

  if (isset($_GET['quiz_id']) && !isset($_POST['quizTitle'])) {
    $quizId = (int) $_GET['quiz_id'];
    $quizEditResult = mysqli_query($conn, "SELECT q.quiz_id, q.quiz_title, s.subject_name, q.time_limit, q.level, q.subject_id, q.total_marks FROM quiz q LEFT JOIN subject s ON q.subject_id = s.subject_id WHERE q.quiz_id = {$quizId} AND q.user_id = {$userId} LIMIT 1");
    
    if ($quizEditResult && $quizEditRow = mysqli_fetch_assoc($quizEditResult)) {
      $isEditMode = true;
      $editQuizData = $quizEditRow;
      $editQuizHasSubmissions = quizHasSubmissions($conn, $quizId);
      
      $questionsEditResult = mysqli_query($conn, "SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_answer, topic_id, mark, image FROM question WHERE quiz_id = {$quizId} ORDER BY question_id");
      if ($questionsEditResult) {
        $editQuestions = mysqli_fetch_all($questionsEditResult, MYSQLI_ASSOC);
      }
    }
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quizTitle = trim($_POST['quizTitle'] ?? '');
    $subjectName = trim($_POST['quizSubject'] ?? '');
    $quizLevel = trim($_POST['quizLevel'] ?? 'Medium');
    $timeLimit = (int) ($_POST['timeLimit'] ?? 30);
    $questions = $_POST['questions'] ?? [];
    $editingQuizId = isset($_POST['edit_quiz_id']) ? (int) $_POST['edit_quiz_id'] : null;

    $titleTaken = false;
    if ($quizTitle !== '') {
      $quizTitleDupEsc = mysqli_real_escape_string($conn, $quizTitle);
      $dupCheckQuery = "SELECT quiz_id FROM quiz WHERE user_id = {$userId} AND LOWER(quiz_title) = LOWER('{$quizTitleDupEsc}')";
      if ($editingQuizId) {
        $dupCheckQuery .= " AND quiz_id != {$editingQuizId}";
      }
      $dupCheckQuery .= " LIMIT 1";
      $dupCheckResult = mysqli_query($conn, $dupCheckQuery);
      $titleTaken = $dupCheckResult && mysqli_num_rows($dupCheckResult) > 0;
    }

    if ($titleTaken) {
      $message = "You already have a quiz named '{$quizTitle}'. Please choose a different title.";
      $messageType = 'danger';
    } elseif ($editingQuizId && quizHasSubmissions($conn, $editingQuizId)) {
      $message = 'This quiz already has student submissions and can no longer be edited. Create a new quiz instead.';
      $messageType = 'danger';
    } elseif ($quizTitle !== '' && $subjectName !== '' && !empty($questions)) {
      $subjectNameEsc = mysqli_real_escape_string($conn, $subjectName);
      $subjectResult = mysqli_query($conn, "SELECT subject_id FROM subject WHERE subject_name = '{$subjectNameEsc}' LIMIT 1");
      $subjectId = null;

      if ($subjectResult && $subjectRow = mysqli_fetch_assoc($subjectResult)) {
        $subjectId = (int) $subjectRow['subject_id'];
      }

      if ($subjectId === null) {
        mysqli_query($conn, "INSERT INTO subject (subject_name, description) VALUES ('{$subjectNameEsc}', 'Added from quiz form')");
        $subjectId = mysqli_insert_id($conn);
      }

      $quizLevelEsc = mysqli_real_escape_string($conn, $quizLevel);
      $quizTitleEsc = mysqli_real_escape_string($conn, $quizTitle);
      
      $quizId = null;
      if ($editingQuizId) {

        $updateQuery = "UPDATE quiz SET quiz_title = '{$quizTitleEsc}', subject_id = {$subjectId}, time_limit = {$timeLimit}, level = '{$quizLevelEsc}' WHERE quiz_id = {$editingQuizId} AND user_id = {$userId}";
        $quizUpdate = mysqli_query($conn, $updateQuery);
        
        if ($quizUpdate) {
          $quizId = $editingQuizId;
          mysqli_query($conn, "DELETE FROM question WHERE quiz_id = {$quizId}");
        } else {
          $message = 'Failed to update quiz. Please try again.';
          $messageType = 'danger';
          $quizId = null;
        }
      } else {

        $quizInsert = mysqli_query($conn, "INSERT INTO quiz (user_id, subject_id, quiz_title, time_limit, level, created_date) VALUES ({$userId}, {$subjectId}, '{$quizTitleEsc}', {$timeLimit}, '{$quizLevelEsc}', NOW())");
        if ($quizInsert) {
          $quizId = mysqli_insert_id($conn);
        } else {
          $message = 'Failed to create quiz. Please try again.';
          $messageType = 'danger';
        }
      }

      if ($quizId !== null) {
        $insertedQuestions = 0;
        $totalMarks = 0;
        $missingTopicQuestions = [];

        foreach ($questions as $qIndex => $question) {
          $questionText = trim($question['question_text'] ?? '');
          $topicId = (int) ($question['topic_id'] ?? 0);
          $optionA = trim($question['option_a'] ?? '');
          $optionB = trim($question['option_b'] ?? '');
          $optionC = trim($question['option_c'] ?? '');
          $optionD = trim($question['option_d'] ?? '');
          $correctAnswer = strtoupper(trim($question['correct_answer'] ?? ''));
          $mark = (int) ($question['mark'] ?? 1);
          if ($mark < 0) {
            $mark = 0;
          }

          if ($questionText === '' || $optionA === '' || $optionB === '' || $optionC === '' || $optionD === '' || !in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
            continue;
          }

          if ($topicId === 0) {
            $missingTopicQuestions[] = $qIndex + 1;
            continue;
          }

          $imagePath = trim($question['existing_image'] ?? '');
          $uploadedImage = $_FILES['questions']['name'][$qIndex]['image'] ?? '';
          if ($uploadedImage !== '' && ($_FILES['questions']['error'][$qIndex]['image'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/questions';
            if (!is_dir($uploadDir)) {
              mkdir($uploadDir, 0777, true);
            }
            $fileExtension = pathinfo($uploadedImage, PATHINFO_EXTENSION);
            $storedName = 'question_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
            $targetPath = $uploadDir . '/' . $storedName;
            if (move_uploaded_file($_FILES['questions']['tmp_name'][$qIndex]['image'], $targetPath)) {
              $imagePath = 'uploads/questions/' . $storedName;
            }
          }

          $questionTextEsc = mysqli_real_escape_string($conn, $questionText);
          $optionAEsc = mysqli_real_escape_string($conn, $optionA);
          $optionBEsc = mysqli_real_escape_string($conn, $optionB);
          $optionCEsc = mysqli_real_escape_string($conn, $optionC);
          $optionDEsc = mysqli_real_escape_string($conn, $optionD);
          $correctAnswerEsc = mysqli_real_escape_string($conn, $correctAnswer);
          $imagePathEsc = mysqli_real_escape_string($conn, $imagePath);
          $imageValueSql = $imagePath === '' ? 'NULL' : "'{$imagePathEsc}'";

          $questionInsert = mysqli_query($conn, "INSERT INTO question (quiz_id, topic_id, question_text, image, option_a, option_b, option_c, option_d, correct_answer, mark) VALUES ({$quizId}, {$topicId}, '{$questionTextEsc}', {$imageValueSql}, '{$optionAEsc}', '{$optionBEsc}', '{$optionCEsc}', '{$optionDEsc}', '{$correctAnswerEsc}', {$mark})");
          if ($questionInsert) {
            $insertedQuestions++;
            $totalMarks += $mark;
          }
        }

        if ($insertedQuestions > 0 && empty($missingTopicQuestions)) {
          mysqli_query($conn, "UPDATE quiz SET total_marks = {$totalMarks} WHERE quiz_id = {$quizId} AND user_id = {$userId}");

          if (!$editingQuizId) {
            $classIdStmt = $conn->prepare("SELECT class_id FROM classes WHERE user_id = ? AND subject_id = ? AND classes_status = 'Active'");
            $classIdStmt->bind_param('ii', $userId, $subjectId);
            $classIdStmt->execute();
            $classIdResult = $classIdStmt->get_result();
            $matchedClassIds = [];
            while ($classIdRow = $classIdResult->fetch_assoc()) {
              $matchedClassIds[] = (int) $classIdRow['class_id'];
            }
            $classIdStmt->close();

            if (!empty($matchedClassIds)) {
              $classPlaceholders = implode(',', array_fill(0, count($matchedClassIds), '?'));
              $classTypes = str_repeat('i', count($matchedClassIds));
              $studentStmt = $conn->prepare("SELECT DISTINCT c.user_id
                FROM classes c
                JOIN users u ON c.user_id = u.user_id
                WHERE c.subject_id IS NULL
                  AND c.class_id IN ($classPlaceholders)
                  AND c.classes_status = 'Active'
                  AND u.role = 'Student'
                  AND u.status = 'Active'");
              $studentStmt->bind_param($classTypes, ...$matchedClassIds);
              $studentStmt->execute();
              $studentResult = $studentStmt->get_result();
              $matchedStudentIds = [];
              while ($studentRow = $studentResult->fetch_assoc()) {
                $matchedStudentIds[] = (int) $studentRow['user_id'];
              }
              $studentStmt->close();

              if (!empty($matchedStudentIds)) {
                $planStmt = $conn->prepare("INSERT INTO plan (user_id, quiz_id, plan_status) VALUES (?, ?, 'Pending')");
                foreach ($matchedStudentIds as $matchedStudentId) {
                  $planStmt->bind_param('ii', $matchedStudentId, $quizId);
                  $planStmt->execute();
                }
                $planStmt->close();
              }
            }
          }

          $message = ($editingQuizId ? 'Quiz updated' : 'Quiz created') . ' successfully.';
          $messageType = 'success';
          $isEditMode = false;
          $editQuizData = null;
          $editQuestions = [];

          $quizActivityType = $editingQuizId ? 'Quiz Update' : 'Quiz Creation';
          $quizActivityVerb = $editingQuizId ? 'Updated quiz: ' : 'Created quiz: ';
          $activityDescEsc = mysqli_real_escape_string($conn, $quizActivityVerb . $quizTitle . '::ref=' . $quizId);
          mysqli_query($conn, "INSERT INTO activity_log (user_id, activity_type, activity_description, log_status, activity_date) VALUES ({$userId}, '{$quizActivityType}', '{$activityDescEsc}', 'Success', NOW())");

          $notifySubjectName = getSubjectName($conn, $subjectId);
          $notifySubjectLabel = $notifySubjectName !== '' ? " for {$notifySubjectName}" : '';
          if ($editingQuizId) {
              $notifyType = 'Quiz Update';
              $notifyText = "Updated quiz{$notifySubjectLabel}: {$quizTitle}. Check the changes before you attempt it.";
          } else {
              $notifyType = 'New Quiz';
              $notifyText = "New quiz assigned{$notifySubjectLabel}: {$quizTitle} ({$timeLimit} min, {$quizLevel}). Complete it in the Quiz page.";
          }
          notifyClassStudents($conn, $userId, $subjectId, $notifyType, $notifyText);
          $toastMessage = ($editingQuizId ? "Quiz '{$quizTitle}' updated successfully." : "Quiz '{$quizTitle}' created successfully.");
        } elseif (!empty($missingTopicQuestions)) {
          if ($insertedQuestions > 0) {
                mysqli_query($conn, "UPDATE quiz SET total_marks = {$totalMarks} WHERE quiz_id = {$quizId} AND user_id = {$userId}");
          }
          $message = 'Please select a topic for question' . (count($missingTopicQuestions) > 1 ? 's' : '') . ' ' . implode(', ', $missingTopicQuestions) . '. Those question(s) were not saved — the rest of the quiz was.';
          $messageType = 'danger';
        } else {
          $message = 'Quiz was ' . ($editingQuizId ? 'updated' : 'created') . ' but no valid questions were saved.';
          $messageType = 'warning';
        }
      }
    } else {
      $message = 'Please fill in the quiz title, subject, and at least one complete question.';
      $messageType = 'danger';
    }
  }

  mysqli_close($conn);
}

if (!$conn) {
  $message = 'Unable to connect to the database.';
  $messageType = 'danger';
}

function e($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quizHasSubmissions($conn, $quizId) {
  $quizId = (int) $quizId;
  $result = mysqli_query($conn, "SELECT 1 FROM result WHERE quiz_id = {$quizId} LIMIT 1");
  if ($result && mysqli_num_rows($result) > 0) {
    return true;
  }
  $result = mysqli_query($conn, "SELECT 1 FROM student_answer sa INNER JOIN question q ON sa.question_id = q.question_id WHERE q.quiz_id = {$quizId} LIMIT 1");
  return $result && mysqli_num_rows($result) > 0;
}

function profileAvatarStyle($path) {
  if (empty($path)) {
    return '';
  }
  return "background-image: url('" . e($path) . "'); background-size: cover; background-position: center;";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Quiz - SmartLearn Teacher Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="teacher-shared.css" />
  <style>
    :root {
      --pale: #CADFF2; --sky: #96B9D9; --mist: #8AA4C7; --steel: #6B8AA6;
      --ink-blue: #58608A; --text: #17233A; --muted: #69768B; --line: #D9E5EF;
      --surface: #FFFFFF; --bg: #F4F9FD; --green: #2DBE78; --blue: #0E80D7;
      --shadow: 0 8px 22px rgba(88, 96, 138, 0.14);
    }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: Inter, "Segoe UI", Arial, sans-serif; }
    .app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
    .sidebar { background: var(--surface); border-right: 1px solid var(--line); padding: 22px 16px; overflow-y: auto; }
    .brand { display: flex; align-items: center; gap: 12px; margin: 0 8px 20px; }
    .brand-logo { display: block; width: 100%; max-width: 180px; height: auto; }
    .nav { display: grid; gap: 10px; }
    .nav a { min-height: 50px; display: flex; align-items: center; gap: 14px; padding: 0 16px; border-radius: 13px; color: #223047; font-size: 16px; font-weight: 700; text-decoration: none; transition: background 0.2s; }
    .nav a:hover { background: rgba(202, 223, 242, 0.5); }
    .nav a.active { color: var(--ink-blue); background: linear-gradient(90deg, rgba(202, 223, 242, 0.96), rgba(150, 185, 217, 0.42)); }
    .nav svg { width: 20px; height: 20px; stroke-width: 2.1; }
    .main { display: grid; grid-template-rows: 76px 1fr; min-width: 0; }
    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 24px; background: var(--surface); border-bottom: 1px solid var(--line); padding: 0 32px; position: sticky; top: 0; z-index: 20; }
    .profile { display: flex; align-items: center; gap: 14px; }
    .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #CADFF2, #58608A); border: 2px solid #E7EEF5; cursor: pointer; }
    .profile strong { display: block; font-size: 15px; }
    .profile span { color: var(--muted); font-size: 13px; }
    .content { padding: 38px; overflow: auto; }
    .page-header { margin-bottom: 28px; }
    .page-header h2 { margin: 0 0 8px; font-size: 32px; }
    .page-header p { margin: 0; color: var(--muted); }
    .quiz-form-card { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 28px; box-shadow: var(--shadow); margin-bottom: 24px; }
    .form-label { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--text); }
    .form-control, .form-select { border-radius: 10px; border-color: var(--line); padding: 10px 14px; }
    .form-control:focus, .form-select:focus { border-color: var(--sky); box-shadow: 0 0 0 4px rgba(150, 185, 217, 0.18); }
    .btn-primary-custom { background: linear-gradient(135deg, #6B8AA6, #58608A); border: 0; font-weight: 700; padding: 10px 22px; border-radius: 10px; }
    .btn-primary-custom:hover { background: linear-gradient(135deg, #58608A, #4A5278); }
    .btn-outline-custom { border: 1px solid var(--line); color: var(--text); font-weight: 700; padding: 10px 22px; border-radius: 10px; }
    .btn-outline-custom:hover { background: rgba(202, 223, 242, 0.4); }
    .btn-danger-soft { color: #EE3D59; border-color: #EE3D59; }
    .section-label { font-size: 18px; font-weight: 700; margin: 0 0 16px; }
    .total-marks-badge { background: rgba(45, 190, 120, 0.14); color: #1E9C64; font-weight: 700; font-size: 14px; padding: 8px 16px; border-radius: 10px; }
    html[data-theme="dark"] .total-marks-badge { background: rgba(61, 214, 140, 0.20); color: #6FE3AC; }
    @media (max-width: 860px) {
      .app { display: block; }
      .sidebar { display: flex; overflow-x: auto; padding: 12px; border-bottom: 1px solid var(--line); }
      .brand { margin: 0; flex: 0 0 auto; }
      .nav { display: flex; gap: 8px; }
      .nav a span { display: none; }
      .content { padding: 18px; }
    }
  </style>
</head>
<body data-page="create-quiz">
  <div class="app">
    
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg><span>Dashboard</span></a>
        <a href="TProfile.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Profile</span></a>
        <a href="TCreateQuiz.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14"/><path d="M5 12h14"/></svg><span>Create Quiz</span></a>
        <a href="TQuizManagement.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01"/><path d="M9 15h.01"/><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8"/><path d="M12 15a3 3 0 1 0 0-6"/></svg><span>Manage Quiz</span></a>
        <a href="TLearningMaterials.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg><span>Upload Materials</span></a>
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
        <div class="page-header">
          <h2><?= $isEditMode ? 'Edit Quiz' : 'Create Quiz' ?></h2>
          <p><?= $isEditMode ? 'Update your quiz information and questions.' : 'Build a new quiz with multiple choice questions for your students.' ?></p>
        </div>

        <?php if ($message !== ''): ?>
          <div class="alert alert-<?= e($messageType) ?> mb-3" role="alert">
            <?= e($message) ?>
          </div>
        <?php endif; ?>

        <?php if ($isEditMode && $editQuizHasSubmissions): ?>
          <div class="alert alert-warning mb-3" role="alert">
            Students have already submitted answers for this quiz, so it can no longer be edited. You're viewing it in read-only mode — go to <a href="TQuizManagement.php">Manage Quiz</a> to create a new quiz instead.
          </div>
        <?php endif; ?>

        <form id="createQuizForm" method="post" enctype="multipart/form-data">
          <?php if ($isEditMode && $editQuizData): ?>
            <input type="hidden" name="edit_quiz_id" value="<?= e($editQuizData['quiz_id']) ?>">
          <?php endif; ?>
          <?php if ($isEditMode && $editQuizHasSubmissions): ?>
            <fieldset disabled>
          <?php endif; ?>
          
          <div class="quiz-form-card">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="quizTitle">Quiz Title</label>
                <input type="text" class="form-control" id="quizTitle" name="quizTitle" placeholder="e.g. Data Structures Mid-Term" value="<?= $isEditMode && $editQuizData ? e($editQuizData['quiz_title']) : '' ?>" required />
                <div id="quizTitleFeedback" class="form-text text-danger" style="display:none;">You already have a quiz with this title.</div>
              </div>
              <div class="col-md-2">
                <label class="form-label" for="quizSubject">Subject</label>
                <select class="form-select" id="quizSubject" name="quizSubject" required>
                  <option value="">Select subject</option>
                  <?php foreach ($subjects as $subject): ?>
                    <option value="<?= e($subject['subject_name']) ?>" <?= ($isEditMode && $editQuizData && $editQuizData['subject_name'] === $subject['subject_name']) ? 'selected' : '' ?>><?= e($subject['subject_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label" for="quizLevel">Level</label>
                <select class="form-select" id="quizLevel" name="quizLevel" required>
                  <option value="Easy" <?= ($isEditMode && $editQuizData && $editQuizData['level'] === 'Easy') ? 'selected' : '' ?>>Easy</option>
                  <option value="Medium" <?= (!$isEditMode || !$editQuizData || $editQuizData['level'] === 'Medium') ? 'selected' : '' ?>>Medium</option>
                  <option value="Hard" <?= ($isEditMode && $editQuizData && $editQuizData['level'] === 'Hard') ? 'selected' : '' ?>>Hard</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label" for="timeLimit">Time Limit (minutes)</label>
                <input type="number" class="form-control" id="timeLimit" name="timeLimit" min="5" max="180" value="<?= $isEditMode && $editQuizData ? e($editQuizData['time_limit']) : 30 ?>" required />
              </div>
            </div>
          </div>

          <div class="quiz-form-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
              <h3 class="section-label mb-0">Questions</h3>
              <div class="total-marks-badge">Total Marks: <strong id="totalMarksDisplay">0</strong></div>
            </div>
            <div id="questionsContainer"></div>
            <button type="button" class="btn btn-outline-custom mt-2" id="addQuestionBtn">
              + Add More Question
            </button>
          </div>
          <?php if ($isEditMode && $editQuizHasSubmissions): ?>
            </fieldset>
          <?php endif; ?>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary btn-primary-custom text-white" <?= ($isEditMode && $editQuizHasSubmissions) ? 'disabled' : '' ?>>Save Quiz</button>
            <a href="TQuizManagement.php" class="btn btn-outline-custom">Cancel</a>
          </div>
        </form>
      </section>
    </main>
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
    let questionCount = 0;
    let currentTopics = [];

    async function loadTopics(subjectName) {
      if (!subjectName) {
        currentTopics = [];
        updateAllTopicSelects();
        return;
      }

      try {
        const response = await fetch(`?action=getTopics&subject_name=${encodeURIComponent(subjectName)}`);
        currentTopics = await response.json();
        updateAllTopicSelects();
      } catch (error) {
        console.error('Error loading topics:', error);
        currentTopics = [];
        updateAllTopicSelects();
      }
    }

    function updateAllTopicSelects() {
      document.querySelectorAll('.q-topic').forEach((select) => {
        const currentValue = select.value;
        select.innerHTML = '<option value="0">Select topic</option>';
        currentTopics.forEach((topic) => {
          const option = document.createElement('option');
          option.value = topic.topic_id;
          option.textContent = topic.topic_name;
          select.appendChild(option);
        });
        select.value = currentValue;
      });
    }

    document.getElementById('quizSubject').addEventListener('change', (e) => {
      loadTopics(e.target.value);
    });

    function createQuestionCard(index) {
      const card = document.createElement('div');
      card.className = 'question-card open';
      card.dataset.index = index;
      card.innerHTML = `
        <div class="question-header" type="button">
          <span>Question ${index}</span>
          <button type="button" class="btn btn-sm btn-outline-danger btn-danger-soft remove-question" aria-label="Remove question">Remove</button>
        </div>
        <div class="question-body">
          <div class="row g-3 mb-3">
            <div class="col-md-8">
              <label class="form-label">Topic</label>
              <select class="form-select q-topic" name="questions[${index}][topic_id]">
                <option value="0">Select topic</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Marks</label>
              <input type="number" class="form-control q-mark" name="questions[${index}][mark]" min="0" step="1" value="1" required />
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Question Text</label>
            <textarea class="form-control q-text" rows="2" name="questions[${index}][question_text]" placeholder="Enter your question..." required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Question Image (optional)</label>
            <input type="hidden" class="q-existing-image" name="questions[${index}][existing_image]" value="" />
            <div class="q-image-preview mb-2" style="display:none;"></div>
            <input type="file" class="form-control q-image" name="questions[${index}][image]" accept="image/*" />
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Option A</label><input type="text" class="form-control q-opt-a" name="questions[${index}][option_a]" required /></div>
            <div class="col-md-6"><label class="form-label">Option B</label><input type="text" class="form-control q-opt-b" name="questions[${index}][option_b]" required /></div>
            <div class="col-md-6"><label class="form-label">Option C</label><input type="text" class="form-control q-opt-c" name="questions[${index}][option_c]" required /></div>
            <div class="col-md-6"><label class="form-label">Option D</label><input type="text" class="form-control q-opt-d" name="questions[${index}][option_d]" required /></div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Correct Answer</label>
            <select class="form-select q-correct" name="questions[${index}][correct_answer]" required>
              <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
            </select>
          </div>
        </div>`;

      const topicSelect = card.querySelector('.q-topic');
      currentTopics.forEach((topic) => {
        const option = document.createElement('option');
        option.value = topic.topic_id;
        option.textContent = topic.topic_name;
        topicSelect.appendChild(option);
      });

      card.querySelector('.question-header').addEventListener('click', (e) => {
        if (e.target.closest('.remove-question')) return;
        card.classList.toggle('open');
      });

      card.querySelector('.remove-question').addEventListener('click', () => {
        if (document.querySelectorAll('.question-card').length <= 1) {
          alert('At least one question is required');
          return;
        }
        card.remove();
        renumberQuestions();
        updateTotalMarks();
      });

      card.querySelector('.q-mark').addEventListener('input', updateTotalMarks);

      return card;
    }

    function updateTotalMarks() {
      let total = 0;
      document.querySelectorAll('.q-mark').forEach((input) => {
        total += parseInt(input.value, 10) || 0;
      });
      const totalMarksEl = document.getElementById('totalMarksDisplay');
      if (totalMarksEl) {
        totalMarksEl.textContent = total;
      }
    }

    function renumberQuestions() {
      document.querySelectorAll('.question-card').forEach((card, i) => {
        card.querySelector('.question-header span').textContent = `Question ${i + 1}`;
        const inputs = card.querySelectorAll('[name]');
        inputs.forEach((input) => {
          const match = input.name.match(/questions\[(\d+)\]\[(.+)\]/);
          if (match) {
            input.name = `questions[${i}][${match[2]}]`;
          }
        });
      });
      questionCount = document.querySelectorAll('.question-card').length;
    }

    function addQuestion() {
      questionCount++;
      document.getElementById('questionsContainer').appendChild(createQuestionCard(questionCount));
      updateTotalMarks();
    }

    document.getElementById('addQuestionBtn').addEventListener('click', addQuestion);

    const quizTitleInput = document.getElementById('quizTitle');
    const quizTitleFeedback = document.getElementById('quizTitleFeedback');
    const editQuizIdInput = document.querySelector('input[name="edit_quiz_id"]');
    let titleCheckTimer = null;
    let titleIsDuplicate = false;

    function checkTitleDuplicate() {
      const title = quizTitleInput.value.trim();
      if (!title) {
        titleIsDuplicate = false;
        quizTitleFeedback.style.display = 'none';
        return;
      }
      const excludeId = editQuizIdInput ? editQuizIdInput.value : '';
      const params = new URLSearchParams({ action: 'checkTitle', quiz_title: title });
      if (excludeId) params.set('exclude_quiz_id', excludeId);
      fetch(`?${params.toString()}`)
        .then((res) => res.json())
        .then((data) => {
          titleIsDuplicate = !!data.exists;
          quizTitleFeedback.style.display = titleIsDuplicate ? '' : 'none';
        })
        .catch(() => {
        });
    }

    quizTitleInput.addEventListener('input', () => {
      clearTimeout(titleCheckTimer);
      titleCheckTimer = setTimeout(checkTitleDuplicate, 400);
    });

    document.getElementById('createQuizForm').addEventListener('submit', (e) => {
      const title = document.getElementById('quizTitle').value.trim();
      const subject = document.getElementById('quizSubject').value;
      const cards = document.querySelectorAll('.question-card');
      if (!title || !subject || !cards.length) {
        e.preventDefault();
        alert('Please fill in all required fields');
        return;
      }

      if (titleIsDuplicate) {
        e.preventDefault();
        quizTitleFeedback.style.display = '';
        quizTitleInput.focus();
        return;
      }

      const missingTopicCards = Array.from(cards).filter((card) => card.querySelector('.q-topic').value === '0');
      if (missingTopicCards.length) {
        e.preventDefault();
        missingTopicCards.forEach((card) => card.classList.add('open'));
        missingTopicCards[0].querySelector('.q-topic').focus();
        missingTopicCards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        const questionNumbers = missingTopicCards.map((card) => card.querySelector('.question-header span').textContent).join(', ');
        alert(`Please select a topic for: ${questionNumbers}`);
      }
    });

    (async function initQuestions() {
      <?php if ($isEditMode && $editQuizData): ?>
        const subjectName = <?= json_encode($editQuizData['subject_name']) ?>;
        if (subjectName) {
          try {
            const response = await fetch(`?action=getTopics&subject_name=${encodeURIComponent(subjectName)}`);
            currentTopics = await response.json();
          } catch (error) {
            console.error('Error loading topics:', error);
            currentTopics = [];
          }
        }
      <?php endif; ?>

      <?php if ($isEditMode && !empty($editQuestions)): ?>
        <?php foreach ($editQuestions as $idx => $q): ?>
          (function() {
            questionCount++;
            const card = createQuestionCard(questionCount);
            const body = card.querySelector('.question-body');
            body.querySelector('.q-topic').value = <?= $q['topic_id'] ?>;
            body.querySelector('.q-text').value = <?= json_encode($q['question_text']) ?>;
            body.querySelector('.q-mark').value = <?= json_encode((int) ($q['mark'] ?? 1)) ?>;
            body.querySelector('.q-opt-a').value = <?= json_encode($q['option_a']) ?>;
            body.querySelector('.q-opt-b').value = <?= json_encode($q['option_b']) ?>;
            body.querySelector('.q-opt-c').value = <?= json_encode($q['option_c']) ?>;
            body.querySelector('.q-opt-d').value = <?= json_encode($q['option_d']) ?>;
            body.querySelector('.q-correct').value = <?= json_encode($q['correct_answer']) ?>;
            <?php if (!empty($q['image'])): ?>
              body.querySelector('.q-existing-image').value = <?= json_encode($q['image']) ?>;
              const preview = body.querySelector('.q-image-preview');
              preview.style.display = '';
              preview.innerHTML = '<img src="<?= e(questionImageUrl($q['image'])) ?>" alt="Current question image" style="max-height:120px;border-radius:8px;border:1px solid var(--line);" /><div class="form-text">Current image — choose a new file above to replace it.</div>';
            <?php endif; ?>
            document.getElementById('questionsContainer').appendChild(card);
          })();
        <?php endforeach; ?>
        updateTotalMarks();
      <?php else: ?>
        addQuestion();
      <?php endif; ?>
    })();
  </script>
</body>
</html>