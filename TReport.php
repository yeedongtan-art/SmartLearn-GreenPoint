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
$teacherName = 'Teacher';
$teacherAvatar = '';
$classLabel = 'Teacher';
$studentCount = 0;
$quizCount = 0;
$materialCount = 0;
$classCount = 0;
$studentReportRows = [];
$quizReportRows = [];
$materialReportRows = [];
$classSummaryRows = [];
$subjectOptions = [];
$classOptions = [];

function getStudentReportRows(mysqli $conn, int $userId, ?int $subjectId, ?int $classId): array {
    $subjectClause = $subjectId !== null ? " AND q.subject_id = {$subjectId}" : '';
    $classClause = $classId !== null ? " AND r.user_id IN (SELECT user_id FROM classes WHERE class_id = {$classId} AND subject_id IS NULL)" : '';

    $query = "SELECT u.user_id, u.name AS student_name, s.subject_name, q.quiz_title, (r.score / r.total_questions * 100) AS score, r.attempt_date
        FROM result r
        JOIN users u ON r.user_id = u.user_id
        JOIN quiz q ON r.quiz_id = q.quiz_id
        LEFT JOIN subject s ON q.subject_id = s.subject_id
        WHERE u.role = 'Student' AND q.user_id = {$userId}{$subjectClause}{$classClause}
        ORDER BY r.attempt_date DESC";

    $rows = [];
    $seenStudentIds = [];
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'student' => trim($row['student_name'] ?? 'Unknown Student'),
                'subject' => trim($row['subject_name'] ?? '') ?: 'General',
                'quiz' => trim($row['quiz_title'] ?? '') ?: 'Untitled Quiz',
                'score' => (int) round((float) $row['score']),
                'date' => substr($row['attempt_date'] ?? '', 0, 10),
            ];
            $seenStudentIds[(int) $row['user_id']] = true;
        }
    }
    return ['rows' => $rows, 'student_count' => count($seenStudentIds)];
}

function getQuizReportRows(mysqli $conn, int $userId, ?int $subjectId, ?int $classId): array {
    $subjectClause = $subjectId !== null ? " AND q.subject_id = {$subjectId}" : '';
    $classJoinClause = $classId !== null ? " AND r.user_id IN (SELECT user_id FROM classes WHERE class_id = {$classId} AND subject_id IS NULL)" : '';

    $query = "SELECT q.quiz_id, q.quiz_title, q.level, s.subject_name,
            COUNT(r.result_id) AS attempts,
            COALESCE(AVG(r.score / r.total_questions * 100), 0) AS avg_score,
            COALESCE(MAX(r.score / r.total_questions * 100), 0) AS top_score,
            COALESCE(MIN(r.score / r.total_questions * 100), 0) AS lowest_score
        FROM quiz q
        LEFT JOIN result r ON r.quiz_id = q.quiz_id{$classJoinClause}
        LEFT JOIN subject s ON q.subject_id = s.subject_id
        WHERE q.user_id = {$userId}{$subjectClause}
        GROUP BY q.quiz_id, q.quiz_title, q.level, s.subject_name
        ORDER BY q.created_date DESC";

    $rows = [];
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attempts = (int) $row['attempts'];
            $rows[] = [
                'quiz' => trim($row['quiz_title'] ?? '') ?: 'Untitled Quiz',
                'subject' => trim($row['subject_name'] ?? '') ?: 'General',
                'level' => $row['level'] ?: 'N/A',
                'attempts' => $attempts,
                'avg_score' => $attempts > 0 ? (int) round((float) $row['avg_score']) : 0,
                'top_score' => $attempts > 0 ? (int) round((float) $row['top_score']) : 0,
                'lowest_score' => $attempts > 0 ? (int) round((float) $row['lowest_score']) : 0,
            ];
        }
    }
    return ['rows' => $rows, 'quiz_count' => count($rows)];
}

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

    if (isset($_GET['feed']) && $_GET['feed'] === 'report_filter') {
        header('Content-Type: application/json');
        $filterType = $_GET['type'] ?? '';
        $filterSubjectId = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int) $_GET['subject_id'] : null;
        $filterClassId = (isset($_GET['class_id']) && $_GET['class_id'] !== '') ? (int) $_GET['class_id'] : null;

        if ($filterType === 'student') {
            echo json_encode(getStudentReportRows($conn, (int) $userId, $filterSubjectId, $filterClassId), JSON_UNESCAPED_UNICODE);
        } elseif ($filterType === 'quiz') {
            echo json_encode(getQuizReportRows($conn, (int) $userId, $filterSubjectId, $filterClassId), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'Unknown report type']);
        }
        exit();
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

    $classSubjectStmt = $conn->prepare("SELECT DISTINCT s.subject_name FROM classes c JOIN subject s ON c.subject_id = s.subject_id WHERE c.user_id = ? ORDER BY s.subject_name ASC");
    $classSubjectStmt->bind_param('i', $userId);
    $classSubjectStmt->execute();
    $classSubjectResult = $classSubjectStmt->get_result();
    $classSubjectNames = [];
    while ($classSubjectRow = $classSubjectResult->fetch_assoc()) {
        $classSubjectNames[] = $classSubjectRow['subject_name'];
    }
    $classSubjectStmt->close();
    if (!empty($classSubjectNames)) {
        $classLabel = implode(', ', $classSubjectNames);
    }

    $subjectOptionsResult = mysqli_query($conn, "SELECT DISTINCT s.subject_id, s.subject_name FROM classes cl JOIN subject s ON cl.subject_id = s.subject_id WHERE cl.user_id = {$userId} ORDER BY s.subject_name ASC");
    if ($subjectOptionsResult) {
        $subjectOptions = mysqli_fetch_all($subjectOptionsResult, MYSQLI_ASSOC);
    }
    $classOptionsResult = mysqli_query($conn, "SELECT DISTINCT c.class_id, c.class_name, c.year FROM classes cl JOIN class c ON cl.class_id = c.class_id WHERE cl.user_id = {$userId} AND cl.subject_id IS NOT NULL ORDER BY c.class_name ASC, c.year ASC");
    if ($classOptionsResult) {
        $classOptions = mysqli_fetch_all($classOptionsResult, MYSQLI_ASSOC);
    }

    $studentReportData = getStudentReportRows($conn, (int) $userId, null, null);
    $studentReportRows = $studentReportData['rows'];
    $studentCount = $studentReportData['student_count'];

    $quizReportData = getQuizReportRows($conn, (int) $userId, null, null);
    $quizReportRows = $quizReportData['rows'];
    $quizCount = $quizReportData['quiz_count'];

    $materialReportQuery = "SELECT m.title, m.material_type, t.topic_name, m.upload_date
        FROM material m
        LEFT JOIN topic t ON m.topic_id = t.topic_id
        WHERE m.user_id = $userId
        ORDER BY m.upload_date DESC";
    $materialReportResult = mysqli_query($conn, $materialReportQuery);
    if ($materialReportResult) {
        while ($row = mysqli_fetch_assoc($materialReportResult)) {
            $materialReportRows[] = [
                'title' => trim($row['title'] ?? '') ?: 'Untitled',
                'type' => $row['material_type'] ?: 'N/A',
                'topic' => trim($row['topic_name'] ?? '') ?: 'General',
                'date' => substr($row['upload_date'] ?? '', 0, 10),
            ];
        }
    }
    $materialCount = count($materialReportRows);

    $teacherClassIds = [];
    $classIdResult = mysqli_query($conn, "SELECT DISTINCT class_id FROM classes WHERE user_id = $userId");
    if ($classIdResult) {
        while ($row = mysqli_fetch_assoc($classIdResult)) {
            $teacherClassIds[] = (int) $row['class_id'];
        }
    }

    foreach ($teacherClassIds as $classId) {
        $classInfoResult = mysqli_query($conn, "SELECT class_name, year FROM class WHERE class_id = $classId LIMIT 1");
        $classInfo = $classInfoResult ? mysqli_fetch_assoc($classInfoResult) : null;
        if (!$classInfo) {
            continue;
        }

        $studentIds = [];
        $studentsInClassResult = mysqli_query($conn, "SELECT DISTINCT c.user_id
            FROM classes c
            JOIN users u ON c.user_id = u.user_id
            WHERE c.class_id = $classId AND u.role = 'Student'");
        if ($studentsInClassResult) {
            while ($row = mysqli_fetch_assoc($studentsInClassResult)) {
                $studentIds[] = (int) $row['user_id'];
            }
        }

        $classAttempts = 0;
        $classAvgScore = 0;
        if (!empty($studentIds)) {
            $idList = implode(',', $studentIds);
            $classScoreResult = mysqli_query($conn, "SELECT COUNT(*) AS attempts, COALESCE(AVG(r.score / r.total_questions * 100), 0) AS avg_score
                FROM result r
                JOIN quiz q ON r.quiz_id = q.quiz_id
                WHERE r.user_id IN ($idList) AND q.user_id = $userId");
            if ($classScoreResult && $scoreRow = mysqli_fetch_assoc($classScoreResult)) {
                $classAttempts = (int) $scoreRow['attempts'];
                $classAvgScore = $classAttempts > 0 ? (int) round((float) $scoreRow['avg_score']) : 0;
            }
        }

        $classSummaryRows[] = [
            'class' => $classInfo['class_name'],
            'year' => (int) $classInfo['year'],
            'students' => count($studentIds),
            'attempts' => $classAttempts,
            'avg_score' => $classAvgScore,
        ];
    }
    $classCount = count($classSummaryRows);

    mysqli_close($conn);
}

$reportDataJson = json_encode([
    'student' => $studentReportRows,
    'quiz' => $quizReportRows,
    'material' => $materialReportRows,
    'class' => $classSummaryRows,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

function e($value)
{
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
  <title>Reports - SmartLearn Teacher Portal</title>
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

    .search svg {
      width: 19px;
      height: 19px;
      stroke-width: 2;
      flex: 0 0 auto;
    }

    .search input {
      width: 100%;
      border: none;
      background: transparent;
      outline: none;
      font-size: 15px;
      color: var(--text);
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
      margin-bottom: 40px;
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

    .reports-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 24px;
    }

    .report-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 28px 30px;
      box-shadow: var(--shadow);
      transition: all 0.3s;
      display: flex;
      align-items: flex-start;
      gap: 24px;
    }

    .report-card:hover {
      border-color: var(--sky);
      box-shadow: 0 12px 28px rgba(88, 96, 138, 0.18);
      transform: translateY(-4px);
    }

    .report-icon {
      width: 60px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 16px;
      flex: 0 0 auto;
      font-size: 28px;
    }

    .report-icon.student {
      background: rgba(14, 128, 215, 0.15);
      color: var(--blue);
    }

    .report-icon.quiz {
      background: rgba(45, 190, 120, 0.15);
      color: #1E9C64;
    }

    .report-icon.material {
      background: rgba(45, 190, 120, 0.15);
      color: #1E9C64;
    }

    .report-icon.summary {
      background: rgba(14, 128, 215, 0.15);
      color: var(--blue);
    }

    .report-icon svg {
      width: 32px;
      height: 32px;
      stroke-width: 2;
    }

    .report-content {
      flex: 1;
    }

    .report-title {
      margin: 0 0 6px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .report-description {
      margin: 0 0 20px 0;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }

    .report-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: -8px 0 18px;
    }

    .report-filter-select {
      height: 36px;
      padding: 0 30px 0 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background-color: var(--surface);
      color: var(--text);
      font-size: 13px;
      font-weight: 600;
    }

    .report-filter-select:focus {
      border-color: var(--sky);
      box-shadow: 0 0 0 3px rgba(150, 185, 217, 0.18);
    }

    .report-filter-hint {
      margin: -12px 0 16px;
      color: var(--steel);
      font-size: 12px;
      line-height: 1.5;
    }

    .report-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .export-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 36px;
      padding: 0 16px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--surface);
      color: var(--text);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }

    .export-btn:hover {
      border-color: var(--sky);
      background: rgba(150, 185, 217, 0.08);
      color: var(--steel);
    }

    .export-btn:disabled {
      opacity: 0.6;
      cursor: wait;
      pointer-events: none;
    }

    .export-btn svg {
      width: 16px;
      height: 16px;
      stroke-width: 2;
    }

    .export-btn.pdf {
      background: rgba(238, 61, 89, 0.1);
      border-color: rgba(238, 61, 89, 0.3);
      color: #C41E3A;
    }

    .export-btn.pdf:hover {
      background: rgba(238, 61, 89, 0.15);
      border-color: #EE3D59;
    }

    .export-btn.excel {
      background: rgba(45, 190, 120, 0.1);
      border-color: rgba(45, 190, 120, 0.3);
      color: #1E9C64;
    }

    .export-btn.excel:hover {
      background: rgba(45, 190, 120, 0.15);
      border-color: #2DBE78;
    }

    @media (max-width: 1200px) {
      .reports-grid {
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

      .header h2 {
        font-size: 24px;
      }

      .reports-grid {
        grid-template-columns: 1fr;
      }

      .report-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 16px;
      }

      .report-actions {
        justify-content: center;
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

      .reports-grid {
        gap: 16px;
      }

      .report-card {
        padding: 20px 16px;
      }

      .report-icon {
        width: 48px;
        height: 48px;
        font-size: 24px;
      }

      .report-icon svg {
        width: 24px;
      }

      .report-title {
        font-size: 16px;
      }
    }
  </style>
</head>
<body data-page="report">
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" /></svg>
          <span>Dashboard</span>
        </a>
        <a href="TQuizManagement.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" /></svg>
          <span>Quiz Management</span>
        </a>
        <a href="TLearningMaterials.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z" /></svg>
          <span>Learning Materials</span>
        </a>
        <a href="TStudentAnalytic.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
          <span>Student Analytics</span>
        </a>
        <a href="TClassPerformance.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M7 16V9" /><path d="M12 16V5" /><path d="M17 16v-3" /></svg>
          <span>Class Performance</span>
        </a>
        <a href="TReport.php" class="active">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /><path d="M16 13H8" /><path d="M16 17H8" /><path d="M10 9H8" /></svg>
          <span>Reports</span>
        </a>
        <a href="TProfile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.74v-.51a2 2 0 0 1 1-1.72l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z" /><circle cx="12" cy="12" r="3" /></svg>
          <span>Profile</span>
        </a>
      </nav>

      <button class="sidebar-logout" id="logoutBtn" type="button">Logout</button>
    </aside>

    <main class="main">
      <header class="topbar" id="topbar">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
          <input type="search" id="globalSearch" placeholder="Search students, quizzes, materials..." />
        </div>

        <div class="profile">
          <button class="top-icon theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" /></svg>
          </button>
          <button class="top-icon" id="notificationBtn" type="button" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 21h4" /><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /></svg>
          </button>
          <div class="avatar" aria-hidden="true" style="<?= profileAvatarStyle($teacherAvatar) ?>"></div>
          <div>
            <strong><?= e($teacherName) ?></strong>
            <span><?= e($classLabel) ?></span>
          </div>
        </div>
      </header>

      <section class="content">
        <div class="header">
          <h2>Reports</h2>
          <p>Generate and export detailed academic reports.</p>
        </div>

        <section class="reports-grid" aria-label="Available reports">
          <article class="report-card" id="report-student" data-report="student">
            <div class="report-icon student">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /></svg>
            </div>
            <div class="report-content">
              <h3 class="report-title">Student Performance Report</h3>
              <p class="report-description" id="report-student-desc">Detailed per-student scores and progress for <?= e($studentCount) ?> learners.</p>
              <div class="report-filter-row">
                <select class="report-filter-select" id="report-student-subject" aria-label="Filter student report by subject">
                  <option value="">All Subjects</option>
                  <?php foreach ($subjectOptions as $subjectOption): ?>
                    <option value="<?= (int) $subjectOption['subject_id'] ?>"><?= e($subjectOption['subject_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="report-filter-select" id="report-student-class" aria-label="Filter student report by class">
                  <option value="">All Classes</option>
                  <?php foreach ($classOptions as $classOption): ?>
                    <option value="<?= (int) $classOption['class_id'] ?>"><?= e($classOption['class_name']) ?> (Year <?= (int) $classOption['year'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="report-actions">
                <button class="export-btn pdf" type="button" data-report="student" data-format="pdf">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  PDF
                </button>
                <button class="export-btn excel" type="button" data-report="student" data-format="excel">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  Excel
                </button>
              </div>
            </div>
          </article>

          <article class="report-card" id="report-quiz" data-report="quiz">
            <div class="report-icon quiz">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" /></svg>
            </div>
            <div class="report-content">
              <h3 class="report-title">Quiz Performance Report</h3>
              <p class="report-description" id="report-quiz-desc">Attempts, average, top and lowest score from <?= e($quizCount) ?> quizzes.</p>
              <div class="report-filter-row">
                <select class="report-filter-select" id="report-quiz-subject" aria-label="Filter quiz report by subject">
                  <option value="">All Subjects</option>
                  <?php foreach ($subjectOptions as $subjectOption): ?>
                    <option value="<?= (int) $subjectOption['subject_id'] ?>"><?= e($subjectOption['subject_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="report-filter-select" id="report-quiz-class" aria-label="Filter quiz report by class">
                  <option value="">All Classes</option>
                  <?php foreach ($classOptions as $classOption): ?>
                    <option value="<?= (int) $classOption['class_id'] ?>"><?= e($classOption['class_name']) ?> (Year <?= (int) $classOption['year'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <p class="report-filter-hint" id="report-quiz-hint" style="display:none;">Class filter shows each quiz's attempts from just that class — quizzes with none show as 0.</p>
              <div class="report-actions">
                <button class="export-btn pdf" type="button" data-report="quiz" data-format="pdf">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  PDF
                </button>
                <button class="export-btn excel" type="button" data-report="quiz" data-format="excel">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  Excel
                </button>
              </div>
            </div>
          </article>

          <article class="report-card" id="report-material" data-report="material">
            <div class="report-icon material">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z" /></svg>
            </div>
            <div class="report-content">
              <h3 class="report-title">Learning Material Usage Report</h3>
              <p class="report-description">Type, topic and upload date for <?= e($materialCount) ?> uploaded resources.</p>
              <div class="report-actions">
                <button class="export-btn pdf" type="button" data-report="material" data-format="pdf">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  PDF
                </button>
                <button class="export-btn excel" type="button" data-report="material" data-format="excel">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  Excel
                </button>
              </div>
            </div>
          </article>

          <article class="report-card" id="report-class" data-report="class">
            <div class="report-icon summary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M7 16V9" /><path d="M12 16V5" /><path d="M17 16v-3" /></svg>
            </div>
            <div class="report-content">
              <h3 class="report-title">Class Summary Report</h3>
              <p class="report-description">Enrollment and average quiz performance across <?= e($classCount) ?> classes.</p>
              <div class="report-actions">
                <button class="export-btn pdf" type="button" data-report="class" data-format="pdf">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  PDF
                </button>
                <button class="export-btn excel" type="button" data-report="class" data-format="excel">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                  Excel
                </button>
              </div>
            </div>
          </article>
        </section>
      </section>
    </main>
  </div>

  <script>

    window.reportData = <?= $reportDataJson !== false ? $reportDataJson : '{}' ?>;
    window.reportTeacherName = <?= json_encode($teacherName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <script src="teacher-shared.js"></script>
  <script>
    (function () {
      const REPORT_CONFIG = {
        student: {
          title: 'Student Performance Report',
          filename: 'student_performance_report',
          columns: [
            { key: 'student', label: 'Student' },
            { key: 'subject', label: 'Subject' },
            { key: 'quiz', label: 'Quiz' },
            { key: 'score', label: 'Score (%)' },
            { key: 'date', label: 'Date' },
          ],
        },
        quiz: {
          title: 'Quiz Performance Report',
          filename: 'quiz_performance_report',
          columns: [
            { key: 'quiz', label: 'Quiz' },
            { key: 'subject', label: 'Subject' },
            { key: 'level', label: 'Level' },
            { key: 'attempts', label: 'Attempts' },
            { key: 'avg_score', label: 'Avg Score (%)' },
            { key: 'top_score', label: 'Top Score (%)' },
            { key: 'lowest_score', label: 'Lowest Score (%)' },
          ],
        },
        material: {
          title: 'Learning Material Usage Report',
          filename: 'material_usage_report',
          columns: [
            { key: 'title', label: 'Title' },
            { key: 'type', label: 'Type' },
            { key: 'topic', label: 'Topic' },
            { key: 'date', label: 'Uploaded' },
          ],
        },
        class: {
          title: 'Class Summary Report',
          filename: 'class_summary_report',
          columns: [
            { key: 'class', label: 'Class' },
            { key: 'year', label: 'Year' },
            { key: 'students', label: 'Students' },
            { key: 'attempts', label: 'Quiz Attempts' },
            { key: 'avg_score', label: 'Avg Score (%)' },
          ],
        },
      };

      function notify(message) {
        if (typeof window.showToast === 'function') window.showToast(message);
      }

      function getRows(key) {
        return (window.reportData && window.reportData[key]) || [];
      }

      const INITIAL_STUDENT_ROWS = getRows('student');
      const INITIAL_QUIZ_ROWS = getRows('quiz');
      const INITIAL_STUDENT_COUNT = <?= (int) $studentCount ?>;
      const INITIAL_QUIZ_COUNT = <?= (int) $quizCount ?>;

      function wireReportFilter(type, { subjectSelectId, classSelectId, descId, describe, hintId }) {
        const subjectSelect = document.getElementById(subjectSelectId);
        const classSelect = document.getElementById(classSelectId);
        const descEl = document.getElementById(descId);
        const hintEl = hintId ? document.getElementById(hintId) : null;
        if (!subjectSelect || !classSelect) return;

        let requestId = 0;

        async function applyFilter() {
          const subjectId = subjectSelect.value;
          const classId = classSelect.value;
          if (hintEl) hintEl.style.display = classId ? '' : 'none';

          if (!subjectId && !classId) {
            window.reportData[type] = type === 'student' ? INITIAL_STUDENT_ROWS : INITIAL_QUIZ_ROWS;
            if (descEl) descEl.textContent = describe(window.reportData[type], type === 'student' ? INITIAL_STUDENT_COUNT : INITIAL_QUIZ_COUNT);
            return;
          }

          const thisRequest = ++requestId;
          const params = new URLSearchParams({ feed: 'report_filter', type });
          if (subjectId) params.set('subject_id', subjectId);
          if (classId) params.set('class_id', classId);

          let data;
          try {
            const res = await fetch(`TReport.php?${params.toString()}`, { credentials: 'same-origin' });
            data = await res.json();
          } catch (err) {
            notify('Unable to load filtered report data.');
            return;
          }
          if (thisRequest !== requestId) return; 
          if (data.error) {
            notify(data.error);
            return;
          }

          window.reportData[type] = data.rows || [];
          const count = type === 'student' ? data.student_count : data.quiz_count;
          if (descEl) descEl.textContent = describe(window.reportData[type], count);
        }

        subjectSelect.addEventListener('change', applyFilter);
        classSelect.addEventListener('change', applyFilter);
      }

      wireReportFilter('student', {
        subjectSelectId: 'report-student-subject',
        classSelectId: 'report-student-class',
        descId: 'report-student-desc',
        describe: (rows, count) => `Detailed per-student scores and progress for ${count} learner${count === 1 ? '' : 's'}.`,
      });

      wireReportFilter('quiz', {
        subjectSelectId: 'report-quiz-subject',
        classSelectId: 'report-quiz-class',
        descId: 'report-quiz-desc',
        hintId: 'report-quiz-hint',
        describe: (rows, count) => `Attempts, average, top and lowest score from ${count} quiz${count === 1 ? '' : 'zes'}.`,
      });

      function exportExcel(key) {
        const config = REPORT_CONFIG[key];
        const rows = getRows(key);
        if (!config || !rows.length) {
          notify('No data to export yet');
          return;
        }
        const data = rows.map((row) => {
          const record = {};
          config.columns.forEach((col) => { record[col.label] = row[col.key]; });
          return record;
        });
        const worksheet = XLSX.utils.json_to_sheet(data);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, config.title.slice(0, 31));
        XLSX.writeFile(workbook, `${config.filename}.xlsx`);
        notify(`${config.title} exported as Excel`);
      }


      function exportPdf(key) {
        const config = REPORT_CONFIG[key];
        const rows = getRows(key);
        if (!config || !rows.length) {
          notify('No data to export yet');
          return;
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        doc.setFontSize(16);
        doc.text(config.title, 14, 18);
        doc.setFontSize(10);
        doc.setTextColor(120, 120, 120);
        doc.text(`SmartLearn · ${window.reportTeacherName} · Generated ${new Date().toLocaleDateString()}`, 14, 25);
        doc.autoTable({
          startY: 32,
          head: [config.columns.map((c) => c.label)],
          body: rows.map((row) => config.columns.map((c) => String(row[c.key] ?? ''))),
          styles: { fontSize: 9 },
          headStyles: { fillColor: [14, 128, 215] },
        });
        doc.save(`${config.filename}.pdf`);
        notify(`${config.title} exported as PDF`);
      }

      function runExport(btn, key, format) {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = original.replace(/PDF|Excel/, 'Exporting…');

        setTimeout(() => {
          try {
            if (format === 'pdf') exportPdf(key); else exportExcel(key);
          } catch (err) {
            console.error(err);
            notify('Export failed — please try again');
          } finally {
            btn.disabled = false;
            btn.innerHTML = original;
          }
        }, 150);
      }

      document.querySelectorAll('.export-btn[data-report]').forEach((btn) => {
        btn.addEventListener('click', () => {
          runExport(btn, btn.dataset.report, btn.dataset.format);
        });
      });
    })();
  </script>
</body>
</html>