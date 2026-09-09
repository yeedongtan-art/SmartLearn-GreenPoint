<?php
require_once "../config/session.php";

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
$quizzes = [];
$subjectNames = [];
$message = '';
$messageType = '';

if ($conn) {
  mysqli_set_charset($conn, 'utf8mb4');

  $teacherResult = mysqli_query($conn, "SELECT name, profile_picture FROM users WHERE user_id = {$userId} AND role = 'Teacher' LIMIT 1");
  if ($teacherResult && $teacherRow = mysqli_fetch_assoc($teacherResult)) {
    $teacherName = $teacherRow['name'];
    $teacherAvatar = $teacherRow['profile_picture'] ?? '';
  }

  if (isset($_GET['action']) && $_GET['action'] === 'getQuizDetails' && isset($_GET['quiz_id'])) {
    header('Content-Type: application/json');
    $quizId = (int) $_GET['quiz_id'];
    
    $quizResult = mysqli_query($conn, "SELECT q.quiz_id, q.quiz_title, s.subject_name, q.time_limit, q.level, q.created_date, q.total_marks, COUNT(qn.question_id) as question_count FROM quiz q LEFT JOIN subject s ON q.subject_id = s.subject_id LEFT JOIN question qn ON q.quiz_id = qn.quiz_id WHERE q.quiz_id = {$quizId} AND q.user_id = {$userId} GROUP BY q.quiz_id");
    
    if ($quizResult && $quizRow = mysqli_fetch_assoc($quizResult)) {
      $questionsResult = mysqli_query($conn, "SELECT question_id, question_text, image, option_a, option_b, option_c, option_d, correct_answer, mark, t.topic_name FROM question qn LEFT JOIN topic t ON qn.topic_id = t.topic_id WHERE quiz_id = {$quizId} ORDER BY question_id");
      $questions = [];
      if ($questionsResult) {
        $questions = mysqli_fetch_all($questionsResult, MYSQLI_ASSOC);
      }
      
      echo json_encode([
        'success' => true,
        'quiz' => $quizRow,
        'questions' => $questions
      ]);
    } else {
      echo json_encode(['success' => false, 'message' => 'Quiz not found']);
    }
    mysqli_close($conn);
    exit;
  }

  $subjectNames = [];
  $subjectsResult = mysqli_query($conn, "SELECT DISTINCT s.subject_name FROM classes cl JOIN subject s ON cl.subject_id = s.subject_id WHERE cl.user_id = {$userId} ORDER BY s.subject_name ASC");
  if ($subjectsResult) {
    while ($subjectRow = mysqli_fetch_assoc($subjectsResult)) {
      $subjectNames[] = $subjectRow['subject_name'];
    }
    if (!empty($subjectNames)) {
      $classLabel = implode(', ', $subjectNames);
    }
  }

  $quizzesResult = mysqli_query($conn, "SELECT q.quiz_id, q.quiz_title, s.subject_name, q.time_limit, q.level, q.created_date, q.total_marks,
    (EXISTS(SELECT 1 FROM result r WHERE r.quiz_id = q.quiz_id)
     OR EXISTS(SELECT 1 FROM student_answer sa INNER JOIN question qn ON sa.question_id = qn.question_id WHERE qn.quiz_id = q.quiz_id)) AS has_submissions
    FROM quiz q LEFT JOIN subject s ON q.subject_id = s.subject_id WHERE q.user_id = {$userId} ORDER BY q.created_date DESC");
  if ($quizzesResult) {
    $quizzes = mysqli_fetch_all($quizzesResult, MYSQLI_ASSOC);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quiz_id'])) {
    $deleteId = (int) $_POST['delete_quiz_id'];

    if (quizHasSubmissions($conn, $deleteId)) {
      $message = 'This quiz already has student submissions and cannot be deleted.';
      $messageType = 'danger';
    } else {
      mysqli_begin_transaction($conn);
      try {
        mysqli_query($conn, "DELETE FROM plan WHERE quiz_id = {$deleteId}");
        mysqli_query($conn, "DELETE FROM result WHERE quiz_id = {$deleteId}");
        mysqli_query($conn, "DELETE FROM question WHERE quiz_id = {$deleteId}");
        mysqli_query($conn, "DELETE FROM quiz WHERE quiz_id = {$deleteId} AND user_id = {$userId}");

        mysqli_commit($conn);
        $message = 'Quiz deleted successfully.';
        $messageType = 'success';
      } catch (mysqli_sql_exception $e) {
        mysqli_rollback($conn);
        $message = 'Unable to delete quiz.';
        $messageType = 'danger';
      }
    }

    $quizzesResult = mysqli_query($conn, "SELECT q.quiz_id, q.quiz_title, s.subject_name, q.time_limit, q.level, q.created_date, q.total_marks,
      (EXISTS(SELECT 1 FROM result r WHERE r.quiz_id = q.quiz_id)
       OR EXISTS(SELECT 1 FROM student_answer sa INNER JOIN question qn ON sa.question_id = qn.question_id WHERE qn.quiz_id = q.quiz_id)) AS has_submissions
      FROM quiz q LEFT JOIN subject s ON q.subject_id = s.subject_id WHERE q.user_id = {$userId} ORDER BY q.created_date DESC");
    if ($quizzesResult) {
      $quizzes = mysqli_fetch_all($quizzesResult, MYSQLI_ASSOC);
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

function difficultyBadge($level) {
  $level = strtolower((string) $level);
  if ($level === 'hard') {
    return 'difficulty-hard';
  }
  if ($level === 'medium') {
    return 'difficulty-medium';
  }
  return 'difficulty-easy';
}

$filterSubjects = $subjectNames;
sort($filterSubjects);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Quiz Management - SmartLearn Teacher Portal</title>
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

    .profile {
      display: flex;
      align-items: center;
      gap: 18px;
      white-space: nowrap;
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
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 24px;
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

    .button-primary {
      min-height: 44px;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      border: none;
      border-radius: 10px;
      padding: 0 22px;
      color: white;
      background: linear-gradient(135deg, #6B8AA6, #58608A);
      font-size: 16px;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 6px 16px rgba(88, 96, 138, 0.16);
      transition: all 0.3s;
    }

    .button-primary:hover {
      box-shadow: 0 8px 20px rgba(88, 96, 138, 0.24);
      transform: translateY(-2px);
    }

    .button-primary svg {
      width: 20px;
      height: 20px;
      stroke-width: 2;
    }

    .section-title {
      margin: 0 0 24px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .filter-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
    }

    .filter-search {
      flex: 1 1 260px;
      max-width: 400px;
      height: 44px;
      padding: 0 16px;
      border: 1px solid var(--line);
      border-radius: 10px;
      font-size: 15px;
      background: var(--surface);
      color: var(--text);
    }

    .filter-select {
      flex: 0 0 auto;
      width: auto;
      height: 44px;
      padding: 0 32px 0 14px;
      border: 1px solid var(--line);
      border-radius: 10px;
      font-size: 14px;
      background-color: var(--surface);
      color: var(--text);
    }

    .filter-clear-btn {
      flex: 0 0 auto;
      height: 44px;
      padding: 0 16px;
      border: 1px solid var(--line);
      border-radius: 10px;
      font-size: 14px;
      font-weight: 700;
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      transition: background 0.15s, color 0.15s;
    }

    .filter-clear-btn:hover {
      background: rgba(202, 223, 242, 0.4);
      color: var(--text);
    }

    .no-results-row td {
      text-align: center;
      padding: 40px 20px;
      color: var(--muted);
    }

    .table-wrapper {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 15px;
    }

    thead {
      background: linear-gradient(90deg, rgba(202, 223, 242, 0.4), rgba(150, 185, 217, 0.2));
      border-bottom: 1px solid var(--line);
    }

    th {
      padding: 16px 20px;
      text-align: left;
      font-weight: 700;
      color: var(--muted);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    tbody tr {
      border-bottom: 1px solid var(--line);
      transition: background-color 0.2s;
    }

    tbody tr:last-child {
      border-bottom: none;
    }

    tbody tr:hover {
      background-color: rgba(202, 223, 242, 0.15);
    }

    td {
      padding: 18px 20px;
      color: var(--text);
    }

    .quiz-name {
      font-weight: 700;
      color: var(--text);
    }

    .subject {
      color: var(--muted);
      font-size: 14px;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 80px;
      height: 28px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 700;
      text-transform: capitalize;
    }

    .difficulty-easy {
      background: rgba(45, 190, 120, 0.18);
      color: #1E9C64;
    }

    .difficulty-medium {
      background: rgba(246, 163, 19, 0.18);
      color: #B4740A;
    }

    .difficulty-hard {
      background: rgba(238, 61, 89, 0.16);
      color: #C41E3A;
    }

    .actions {
      display: flex;
      align-items: center;
      gap: 12px;
      justify-content: center;
    }

    .action-btn {
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--surface);
      color: var(--muted);
      cursor: pointer;
      transition: all 0.2s;
    }

    .action-btn:hover {
      border-color: var(--mist);
      background: rgba(202, 223, 242, 0.2);
      color: var(--steel);
    }

    .action-btn.delete:hover {
      border-color: #EE3D59;
      background: rgba(238, 61, 89, 0.1);
      color: #EE3D59;
    }

    .action-btn svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
    }

    .empty-state {
      min-height: 400px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 60px 40px;
    }

    .empty-icon {
      width: 120px;
      height: 120px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: rgba(202, 223, 242, 0.3);
      color: var(--steel);
      margin-bottom: 24px;
    }

    .empty-icon svg {
      width: 60px;
      height: 60px;
      stroke-width: 1.5;
    }

    .empty-state h3 {
      margin: 0 0 12px 0;
      font-size: 20px;
      color: var(--text);
    }

    .empty-state p {
      margin: 0 0 24px 0;
      color: var(--muted);
      font-size: 15px;
      max-width: 400px;
    }

    .empty-state .button-primary {
      background: linear-gradient(135deg, var(--steel), var(--ink-blue));
    }

    /* Quiz view modal */
    .quiz-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(23, 35, 58, 0.5);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      z-index: 1000;
    }

    .quiz-modal-backdrop.show {
      display: flex;
    }

    .quiz-modal {
      background: var(--surface);
      border-radius: 18px;
      box-shadow: 0 20px 60px rgba(23, 35, 58, 0.28);
      width: min(720px, 100%);
      max-height: 85vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .quiz-modal-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      padding: 24px 28px;
      border-bottom: 1px solid var(--line);
    }

    .quiz-modal-header h3 {
      margin: 0 0 6px 0;
      font-size: 22px;
      color: var(--text);
    }

    .quiz-modal-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 18px;
      color: var(--muted);
      font-size: 14px;
    }

    .quiz-modal-close {
      width: 34px;
      height: 34px;
      flex: 0 0 auto;
      border: 1px solid var(--line);
      border-radius: 9px;
      background: var(--surface);
      color: var(--muted);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .quiz-modal-close:hover {
      border-color: var(--mist);
      color: var(--steel);
    }

    .quiz-modal-close svg {
      width: 18px;
      height: 18px;
    }

    .quiz-modal-body {
      padding: 24px 28px;
      overflow-y: auto;
    }

    .quiz-modal-body .loading,
    .quiz-modal-body .error {
      text-align: center;
      color: var(--muted);
      padding: 40px 0;
    }

    .qv-question {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 18px 20px;
      margin-bottom: 16px;
    }

    .qv-question:last-child {
      margin-bottom: 0;
    }

    .qv-question-top {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 12px;
      margin-bottom: 10px;
    }

    .qv-question-top span.qv-topic {
      font-size: 12px;
      color: var(--steel);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .qv-question-top span.qv-marks {
      font-size: 12px;
      color: #1E9C64;
      background: rgba(45, 190, 120, 0.14);
      font-weight: 700;
      padding: 3px 10px;
      border-radius: 999px;
      white-space: nowrap;
    }

    html[data-theme="dark"] .qv-question-top span.qv-marks {
      color: #6FE3AC;
      background: rgba(61, 214, 140, 0.20);
    }

    .qv-question-image {
      display: block;
      max-width: 100%;
      max-height: 220px;
      border-radius: 10px;
      border: 1px solid var(--line);
      margin: 0 0 14px 0;
      object-fit: contain;
    }

    .qv-question-text {
      font-weight: 700;
      color: var(--text);
      margin: 0 0 14px 0;
      font-size: 15px;
    }

    .qv-options {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .qv-option {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      color: var(--text);
      background: #FBFDFF;
    }

    .qv-option .qv-letter {
      flex: 0 0 auto;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: rgba(150, 185, 217, 0.3);
      color: var(--ink-blue);
      font-size: 12px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .qv-option.correct {
      background: rgba(45, 190, 120, 0.16);
      border-color: var(--green);
      color: #16693F;
      font-weight: 700;
    }

    .qv-option.correct .qv-letter {
      background: var(--green);
      color: white;
    }

    html[data-theme="dark"] .qv-option {
      background: #141C28;
      border-color: var(--line);
    }

    html[data-theme="dark"] .qv-option .qv-letter {
      background: rgba(150, 185, 217, 0.2);
      color: var(--text);
    }

    html[data-theme="dark"] .qv-option.correct {
      background: rgba(61, 214, 140, 0.18);
      border-color: var(--green);
      color: #6FE3AC;
    }

    html[data-theme="dark"] .qv-option.correct .qv-letter {
      background: var(--green);
      color: #0B1F14;
    }

    @media (max-width: 640px) {
      .qv-options {
        grid-template-columns: 1fr;
      }
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

      .header {
        flex-direction: column;
        align-items: stretch;
      }

      .header h2 {
        font-size: 24px;
      }

      table {
        font-size: 14px;
      }

      th,
      td {
        padding: 12px 15px;
      }

      .actions {
        gap: 8px;
      }

      .action-btn {
        width: 32px;
        height: 32px;
      }
    }

    @media (max-width: 640px) {
      .table-wrapper {
        overflow-x: auto;
      }

      table {
        min-width: 500px;
        font-size: 13px;
      }

      th,
      td {
        padding: 10px 12px;
      }

      .header h2 {
        font-size: 20px;
      }

      .button-primary {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
</head>
<body data-page="manage-quiz">
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" /></svg><span>Dashboard</span></a>
        <a href="TProfile.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg><span>Profile</span></a>
        <a href="TCreateQuiz.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14" /><path d="M5 12h14" /></svg><span>Create Quiz</span></a>
        <a href="TQuizManagement.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" /></svg><span>Manage Quiz</span></a>
        <a href="TLearningMaterials.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z" /></svg><span>Upload Materials</span></a>
        <a href="TClassPerformance.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M7 16V9" /><path d="M12 16V5" /><path d="M17 16v-3" /></svg><span>Analytics</span></a>
        <a href="TStudentAnalytic.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg><span>Student View</span></a>
      </nav>

      <button class="sidebar-logout" id="logoutBtn" type="button">Logout</button>
    </aside>

    <main class="main">
      <header class="topbar" id="topbar">
        <div class="profile">
          <button class="top-icon theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" /></svg>
          </button>
          <button class="top-icon" id="notificationBtn" type="button" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 21h4" /><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /></svg>
          </button>
          <div class="avatar" aria-hidden="true" style="<?= profileAvatarStyle($teacherAvatar) ?>"></div>
          <div><strong><?= e($teacherName) ?></strong><span><?= e($classLabel) ?></span></div>
        </div>
      </header>

      <section class="content">
        <div class="header">
          <div>
            <h2>Quiz Management</h2>
            <p>Create, edit, preview, and publish quizzes for your classes.</p>
          </div>
          <a href="TCreateQuiz.php" class="button-primary" style="text-decoration:none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14" /><path d="M5 12h14" /></svg>
            Create New Quiz
          </a>
        </div>

        <div class="filter-toolbar">
          <input type="search" id="quizSearch" placeholder="Search quizzes by title or subject..." class="filter-search" />

          <select id="filterSubject" class="form-select filter-select">
            <option value="">All subjects</option>
            <?php foreach ($filterSubjects as $filterSubjectName): ?>
              <option value="<?= e($filterSubjectName) ?>"><?= e($filterSubjectName) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="filterDifficulty" class="form-select filter-select">
            <option value="">All difficulties</option>
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>

          <select id="filterTimeLimit" class="form-select filter-select">
            <option value="">Any time limit</option>
            <option value="0-15">Up to 15 min</option>
            <option value="16-30">16–30 min</option>
            <option value="31-45">31–45 min</option>
            <option value="46-9999">46+ min</option>
          </select>

          <select id="filterTotalMarks" class="form-select filter-select">
            <option value="">Any total marks</option>
            <option value="0-20">0–20</option>
            <option value="21-40">21–40</option>
            <option value="41-60">41–60</option>
            <option value="61-999999">60+</option>
            <option value="unset">Not set yet</option>
          </select>

          <button type="button" id="clearFiltersBtn" class="filter-clear-btn">Clear filters</button>
        </div>

        <?php if ($message !== ''): ?>
          <div class="alert alert-<?= e($messageType) ?> mb-3" role="alert">
            <?= e($message) ?>
          </div>
        <?php endif; ?>

        <h3 class="section-title">Existing Quizzes</h3>
        <p style="color: var(--muted); margin: 0 0 16px 0; font-size: 14px;"><span id="quizCountLabel"><?= count($quizzes) ?></span> quizzes in your library</p>

        <div class="table-wrapper">
          <table id="quizTable">
            <thead>
              <tr>
                <th>Quiz ID</th>
                <th>Title</th>
                <th>Subject</th>
                <th style="text-align: center;">Time Limit</th>
                <th style="text-align: center;">Total Marks</th>
                <th style="text-align: center;">Difficulty</th>
                <th style="text-align: center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($quizzes)): ?>
                <?php foreach ($quizzes as $quiz): ?>
                  <?php
                    $rowSubject = $quiz['subject_name'] ?: 'General';
                    $rowDifficulty = strtolower($quiz['level'] ?: 'medium');
                    $rowTimeLimit = (int) ($quiz['time_limit'] ?? 0);
                    $rowTotalMarks = $quiz['total_marks'] !== null ? (int) $quiz['total_marks'] : '';
                  ?>
                  <tr
                    data-title="<?= e(strtolower($quiz['quiz_title'])) ?>"
                    data-subject="<?= e($rowSubject) ?>"
                    data-difficulty="<?= e($rowDifficulty) ?>"
                    data-time-limit="<?= e($rowTimeLimit) ?>"
                    data-total-marks="<?= e($rowTotalMarks) ?>"
                  >
                    <td><?= e($quiz['quiz_id']) ?></td>
                    <td class="quiz-name"><?= e($quiz['quiz_title']) ?></td>
                    <td class="subject"><?= e($quiz['subject_name'] ?: 'General') ?></td>
                    <td style="text-align: center;"><?= e($quiz['time_limit'] ? $quiz['time_limit'] . ' min' : '—') ?></td>
                    <td style="text-align: center;"><?= e($quiz['total_marks'] !== null ? $quiz['total_marks'] : '—') ?></td>
                    <td style="text-align: center;">
                      <span class="status-badge <?= e(difficultyBadge($quiz['level'])) ?>"><?= e(ucfirst($quiz['level'] ?: 'Medium')) ?></span>
                    </td>
                    <td>
                      <div class="actions">
                        <button class="action-btn view-btn" title="View" type="button" data-quiz-id="<?= e($quiz['quiz_id']) ?>">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                        </button>
                        <?php if (!empty($quiz['has_submissions'])): ?>
                          <button class="action-btn" type="button" disabled title="Students have already submitted answers — this quiz can no longer be edited" style="opacity:.4; cursor:not-allowed;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11z" /></svg>
                          </button>
                          <button class="action-btn delete" type="button" disabled title="Students have already submitted answers — this quiz can no longer be deleted" style="opacity:.4; cursor:not-allowed;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M10 11v6" /><path d="M14 11v6" /></svg>
                          </button>
                        <?php else: ?>
                          <a class="action-btn" href="TCreateQuiz.php?quiz_id=<?= e($quiz['quiz_id']) ?>" title="Edit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11z" /></svg>
                          </a>
                          <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quiz?');">
                            <input type="hidden" name="delete_quiz_id" value="<?= e($quiz['quiz_id']) ?>">
                            <button class="action-btn delete" title="Delete" type="submit">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M10 11v6" /><path d="M14 11v6" /></svg>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" style="text-align:center; padding: 40px 20px;">No quizzes found. Create a new quiz to get started.</td>
                </tr>
              <?php endif; ?>
              <tr class="no-results-row" id="noResultsRow" style="display:none;">
                <td colspan="7">No quizzes match your filters.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <div class="quiz-modal-backdrop" id="quizModalBackdrop">
    <div class="quiz-modal" role="dialog" aria-modal="true" aria-labelledby="quizModalTitle">
      <div class="quiz-modal-header">
        <div>
          <h3 id="quizModalTitle">Quiz Details</h3>
          <div class="quiz-modal-meta" id="quizModalMeta"></div>
        </div>
        <button type="button" class="quiz-modal-close" id="quizModalClose" aria-label="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
        </button>
      </div>
      <div class="quiz-modal-body" id="quizModalBody">
        <div class="loading">Loading quiz…</div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="teacher-shared.js"></script>
  <script>
    const quizModalBackdrop = document.getElementById('quizModalBackdrop');
    const quizModalBody = document.getElementById('quizModalBody');
    const quizModalMeta = document.getElementById('quizModalMeta');
    const quizModalTitle = document.getElementById('quizModalTitle');

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str ?? '';
      return div.innerHTML;
    }

    function openQuizModal() {
      quizModalBackdrop.classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeQuizModal() {
      quizModalBackdrop.classList.remove('show');
      document.body.style.overflow = '';
    }

    quizModalBackdrop.addEventListener('click', (e) => {
      if (e.target === quizModalBackdrop) closeQuizModal();
    });
    document.getElementById('quizModalClose').addEventListener('click', closeQuizModal);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && quizModalBackdrop.classList.contains('show')) closeQuizModal();
    });

    async function viewQuiz(quizId) {
      quizModalTitle.textContent = 'Quiz Details';
      quizModalMeta.innerHTML = '';
      quizModalBody.innerHTML = '<div class="loading">Loading quiz…</div>';
      openQuizModal();

      try {
        const response = await fetch(`?action=getQuizDetails&quiz_id=${encodeURIComponent(quizId)}`);
        const data = await response.json();

        if (!data.success) {
          quizModalBody.innerHTML = `<div class="error">${escapeHtml(data.message || 'Quiz not found.')}</div>`;
          return;
        }

        const quiz = data.quiz;
        const questions = data.questions || [];

        quizModalTitle.textContent = quiz.quiz_title || 'Untitled Quiz';
        const createdDate = quiz.created_date ? new Date(quiz.created_date.replace(' ', 'T')).toLocaleDateString() : '—';
        quizModalMeta.innerHTML = `
          <span>Subject: <strong>${escapeHtml(quiz.subject_name || 'General')}</strong></span>
          <span>Level: <strong>${escapeHtml(quiz.level || '—')}</strong></span>
          <span>Time limit: <strong>${quiz.time_limit ? escapeHtml(quiz.time_limit) + ' min' : '—'}</strong></span>
          <span>Questions: <strong>${escapeHtml(quiz.question_count ?? questions.length)}</strong></span>
          <span>Total marks: <strong>${quiz.total_marks !== null && quiz.total_marks !== undefined ? escapeHtml(quiz.total_marks) : '—'}</strong></span>
          <span>Created: <strong>${escapeHtml(createdDate)}</strong></span>
        `;

        if (!questions.length) {
          quizModalBody.innerHTML = '<div class="error">This quiz has no questions yet.</div>';
          return;
        }

        const letters = ['A', 'B', 'C', 'D'];
        const optionKeys = ['option_a', 'option_b', 'option_c', 'option_d'];

        quizModalBody.innerHTML = questions.map((q, i) => {
          const correct = (q.correct_answer || '').trim().toUpperCase();
          const optionsHtml = optionKeys.map((key, idx) => {
            const letter = letters[idx];
            const isCorrect = letter === correct;
            return `
              <div class="qv-option ${isCorrect ? 'correct' : ''}">
                <span class="qv-letter">${letter}</span>
                <span>${escapeHtml(q[key])}</span>
              </div>
            `;
          }).join('');

          return `
            <div class="qv-question">
              <div class="qv-question-top">
                <span>Question ${i + 1}</span>
                <div style="display:flex; align-items:center; gap:8px;">
                  ${q.topic_name ? `<span class="qv-topic">${escapeHtml(q.topic_name)}</span>` : ''}
                  <span class="qv-marks">${escapeHtml(q.mark ?? 0)} mark${(q.mark ?? 0) === 1 ? '' : 's'}</span>
                </div>
              </div>
              <p class="qv-question-text">${escapeHtml(q.question_text)}</p>
              ${q.image ? `<img src="${escapeHtml(q.image)}" alt="Question image" class="qv-question-image" />` : ''}
              <div class="qv-options">${optionsHtml}</div>
            </div>
          `;
        }).join('');
      } catch (error) {
        console.error('Error loading quiz details:', error);
        quizModalBody.innerHTML = '<div class="error">Something went wrong while loading this quiz.</div>';
      }
    }

    document.querySelectorAll('.view-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const quizId = btn.getAttribute('data-quiz-id');
        if (quizId) viewQuiz(quizId);
      });
    });

    (function setupQuizFilters() {
      const dataRows = Array.from(document.querySelectorAll('#quizTable tbody tr[data-title]'));
      if (!dataRows.length) return; 

      const searchInput = document.getElementById('quizSearch');
      const subjectSelect = document.getElementById('filterSubject');
      const difficultySelect = document.getElementById('filterDifficulty');
      const timeLimitSelect = document.getElementById('filterTimeLimit');
      const totalMarksSelect = document.getElementById('filterTotalMarks');
      const clearBtn = document.getElementById('clearFiltersBtn');
      const noResultsRow = document.getElementById('noResultsRow');
      const quizCountLabel = document.getElementById('quizCountLabel');

      function parseRange(value) {
        if (!value) return null;
        const [min, max] = value.split('-').map(Number);
        return { min, max };
      }

      function applyFilters() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const subject = subjectSelect.value;
        const difficulty = difficultySelect.value;
        const timeRange = parseRange(timeLimitSelect.value);
        const marksValue = totalMarksSelect.value;
        const marksRange = marksValue && marksValue !== 'unset' ? parseRange(marksValue) : null;

        let visibleCount = 0;

        dataRows.forEach((row) => {
          const title = row.dataset.title || '';
          const rowSubject = row.dataset.subject || '';
          const rowDifficulty = row.dataset.difficulty || '';
          const rowTimeLimit = Number(row.dataset.timeLimit || 0);
          const rowTotalMarksRaw = row.dataset.totalMarks;
          const rowTotalMarks = rowTotalMarksRaw === '' ? null : Number(rowTotalMarksRaw);

          let matches = true;

          if (searchTerm && !title.includes(searchTerm) && !rowSubject.toLowerCase().includes(searchTerm)) {
            matches = false;
          }
          if (matches && subject && rowSubject !== subject) {
            matches = false;
          }
          if (matches && difficulty && rowDifficulty !== difficulty) {
            matches = false;
          }
          if (matches && timeRange && (rowTimeLimit < timeRange.min || rowTimeLimit > timeRange.max)) {
            matches = false;
          }
          if (matches && marksValue === 'unset' && rowTotalMarks !== null) {
            matches = false;
          }
          if (matches && marksRange && (rowTotalMarks === null || rowTotalMarks < marksRange.min || rowTotalMarks > marksRange.max)) {
            matches = false;
          }

          row.style.display = matches ? '' : 'none';
          if (matches) visibleCount++;
        });

        noResultsRow.style.display = visibleCount === 0 ? '' : 'none';
        if (quizCountLabel) quizCountLabel.textContent = visibleCount;
      }

      [searchInput, subjectSelect, difficultySelect, timeLimitSelect, totalMarksSelect].forEach((el) => {
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
      });

      clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        subjectSelect.value = '';
        difficultySelect.value = '';
        timeLimitSelect.value = '';
        totalMarksSelect.value = '';
        applyFilters();
      });
    })();
  </script>
</body>
</html>