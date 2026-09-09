<?php
require_once "../config/session.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "Teacher") {
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['feed']) && $_GET['feed'] === 'activity') {
    header('Content-Type: application/json');

    $feedHost = '127.0.0.1';
    $feedUser = 'root';
    $feedPass = '';
    $feedDbname = 'smartlearn';

    $feedConn = mysqli_connect($feedHost, $feedUser, $feedPass, $feedDbname);
    $feedUserId = $_SESSION['user_id'];
    $feedItems = [];


    $feedTypeMap = [
        'Quiz Creation' => ['title' => 'Quiz created', 'url' => 'TCreateQuiz.php?quiz_id=%d'],
        'Quiz Update' => ['title' => 'Quiz updated', 'url' => 'TCreateQuiz.php?quiz_id=%d'],
        'Material Upload' => ['title' => 'Material uploaded', 'url' => 'TLearningMaterials.php?edit=%d'],
        'Material Update' => ['title' => 'Material updated', 'url' => 'TLearningMaterials.php?edit=%d'],
        'Material Delete' => ['title' => 'Material deleted', 'url' => 'TLearningMaterials.php'],
        'Profile Update' => ['title' => 'Profile updated', 'url' => 'TProfile.php'],
        'Password Change' => ['title' => 'Password changed', 'url' => 'TProfile.php'],
    ];

    if ($feedConn) {
        mysqli_set_charset($feedConn, 'utf8mb4');

        $feedTypesEsc = array_map(function ($t) use ($feedConn) {
            return "'" . mysqli_real_escape_string($feedConn, $t) . "'";
        }, array_keys($feedTypeMap));
        $feedTypesIn = implode(', ', $feedTypesEsc);

        $logFeedResult = mysqli_query($feedConn, "SELECT activity_id, activity_type, activity_description, activity_date
            FROM activity_log
            WHERE user_id = {$feedUserId} AND activity_type IN ({$feedTypesIn})
            ORDER BY activity_date DESC
            LIMIT 20");
        if ($logFeedResult) {
            while ($row = mysqli_fetch_assoc($logFeedResult)) {
                $typeInfo = $feedTypeMap[$row['activity_type']];

                $description = $row['activity_description'];
                $refId = null;
                if (preg_match('/::ref=(\d+)$/', $description, $matches)) {
                    $refId = (int) $matches[1];
                    $description = substr($description, 0, -strlen($matches[0]));
                }

                $url = ($refId !== null && strpos($typeInfo['url'], '%d') !== false)
                    ? sprintf($typeInfo['url'], $refId)
                    : preg_replace('/\?.*$/', '', $typeInfo['url']);

                $feedItems[] = [
                    'id' => 'log-' . $row['activity_id'],
                    'title' => $typeInfo['title'],
                    'message' => $description . '.',
                    'date' => $row['activity_date'],
                    'url' => $url,
                ];
            }
        }

        mysqli_close($feedConn);
    }

    $feedItems = array_slice($feedItems, 0, 8);

    echo json_encode(['items' => $feedItems], JSON_UNESCAPED_UNICODE);
    exit();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'smartlearn';

/**
 * Shared query logic for the four filterable dashboard widgets (Quiz Performance,
 * Quiz Completion, Recent Activity, Top Performing Students). Used both for the
 * initial page render (below, with $subjectId = $classId = null) and for the
 * ?feed=dashboard_filter AJAX endpoint, so the two never drift apart.
 *
 * $subjectId filters to quizzes in that subject (q.subject_id). $classId filters
 * to students on that class's roster — students are linked to a class via rows in
 * `classes` where subject_id IS NULL (see classes table: teacher-assignment rows
 * have a subject_id, student-roster rows don't), so it's applied as a subquery
 * against result.user_id rather than a direct join.
 */
function getDashboardFilteredData(mysqli $conn, int $userId, ?int $subjectId, ?int $classId): array {
  $subjectClause = $subjectId !== null ? " AND q.subject_id = {$subjectId}" : '';
  $classSubquery = $classId !== null ? "(SELECT user_id FROM classes WHERE class_id = {$classId} AND subject_id IS NULL)" : null;
  $classClauseR = $classSubquery !== null ? " AND r.user_id IN {$classSubquery}" : '';

  $latestResultSource = "(SELECT r1.* FROM result r1 WHERE r1.result_id = (
      SELECT r2.result_id FROM result r2
      WHERE r2.user_id = r1.user_id AND r2.quiz_id = r1.quiz_id
      ORDER BY r2.attempt_date DESC, r2.result_id DESC LIMIT 1
    )) r";

  $data = [
    'hasCompletionData' => false,
    'completionPercent' => 0,
    'quizPerformance' => [],
    'recentActivity' => [],
    'topStudents' => [],
    'totalStudents' => 0,
    'totalQuizzes' => 0,
    'avgScore' => 0,
    'studentsChangeText' => 'No new students yet',
    'studentsChangeDirection' => 'neutral',
    'quizzesChangeText' => 'No new quizzes yet',
    'quizzesChangeDirection' => 'neutral',
    'avgScoreChangeText' => 'Not enough data yet',
    'avgScoreChangeDirection' => 'neutral',
    'materialsCount' => 0,
    'materials' => [],
  ];

  $quizCountResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM quiz q WHERE q.user_id = {$userId}{$subjectClause}");
  $filteredQuizCount = $quizCountResult ? (int) mysqli_fetch_assoc($quizCountResult)['total'] : 0;
  $data['totalQuizzes'] = $filteredQuizCount;

  $studentCountResult = mysqli_query($conn, "SELECT COUNT(DISTINCT r.user_id) AS total FROM result r JOIN quiz q ON r.quiz_id = q.quiz_id WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}");
  $filteredStudentCount = $studentCountResult ? (int) mysqli_fetch_assoc($studentCountResult)['total'] : 0;
  $data['totalStudents'] = $filteredStudentCount;

  $avgScoreResult = mysqli_query($conn, "SELECT COALESCE(AVG(r.score / r.total_questions * 100), 0) AS avg_score FROM {$latestResultSource} JOIN quiz q ON r.quiz_id = q.quiz_id WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}");
  $data['avgScore'] = $avgScoreResult ? (int) round((float) mysqli_fetch_assoc($avgScoreResult)['avg_score']) : 0;

  $newStudentsResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM (
      SELECT r.user_id, MIN(r.attempt_date) AS first_attempt
      FROM result r JOIN quiz q ON r.quiz_id = q.quiz_id
      WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}
      GROUP BY r.user_id
    ) first_seen
    WHERE first_seen.first_attempt >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
  $newStudents = $newStudentsResult ? (int) mysqli_fetch_assoc($newStudentsResult)['total'] : 0;
  if ($newStudents > 0) {
    $data['studentsChangeText'] = "+{$newStudents} this month";
    $data['studentsChangeDirection'] = 'positive';
  }

  $newQuizzesResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM quiz q WHERE q.user_id = {$userId}{$subjectClause} AND q.created_date >= (NOW() - INTERVAL 7 DAY)");
  $newQuizzes = $newQuizzesResult ? (int) mysqli_fetch_assoc($newQuizzesResult)['total'] : 0;
  if ($newQuizzes > 0) {
    $data['quizzesChangeText'] = "+{$newQuizzes} this week";
    $data['quizzesChangeDirection'] = 'positive';
  }

  $curMonthResult = mysqli_query($conn, "SELECT AVG(r.score / r.total_questions * 100) AS avg_score, COUNT(*) AS total
    FROM {$latestResultSource} JOIN quiz q ON r.quiz_id = q.quiz_id
    WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}
      AND r.attempt_date >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
  $curMonthRow = $curMonthResult ? mysqli_fetch_assoc($curMonthResult) : null;

  $prevMonthResult = mysqli_query($conn, "SELECT AVG(r.score / r.total_questions * 100) AS avg_score, COUNT(*) AS total
    FROM {$latestResultSource} JOIN quiz q ON r.quiz_id = q.quiz_id
    WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}
      AND r.attempt_date >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01 00:00:00')
      AND r.attempt_date < DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
  $prevMonthRow = $prevMonthResult ? mysqli_fetch_assoc($prevMonthResult) : null;

  if ($curMonthRow && (int) $curMonthRow['total'] > 0 && $prevMonthRow && (int) $prevMonthRow['total'] > 0) {
    $delta = (int) round((float) $curMonthRow['avg_score'] - (float) $prevMonthRow['avg_score']);
    if ($delta > 0) {
      $data['avgScoreChangeText'] = "+{$delta}% vs last month";
      $data['avgScoreChangeDirection'] = 'positive';
    } elseif ($delta < 0) {
      $data['avgScoreChangeText'] = "{$delta}% vs last month";
      $data['avgScoreChangeDirection'] = 'negative';
    } else {
      $data['avgScoreChangeText'] = 'No change vs last month';
    }
  } elseif ($curMonthRow && (int) $curMonthRow['total'] > 0) {
    $data['avgScoreChangeText'] = 'No data from last month to compare';
  }

  $possibleAttempts = $filteredStudentCount * $filteredQuizCount;
  if ($possibleAttempts > 0) {
    $completedAttemptsResult = mysqli_query($conn, "SELECT COUNT(DISTINCT CONCAT(r.user_id, '-', r.quiz_id)) AS total FROM result r JOIN quiz q ON r.quiz_id = q.quiz_id WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}");
    $completedAttempts = $completedAttemptsResult ? (int) mysqli_fetch_assoc($completedAttemptsResult)['total'] : 0;
    $data['completionPercent'] = (int) round(($completedAttempts / $possibleAttempts) * 100);
    $data['hasCompletionData'] = true;
  }

  $resultJoinClause = $classSubquery !== null ? "LEFT JOIN {$latestResultSource} ON r.quiz_id = q.quiz_id AND r.user_id IN {$classSubquery}" : "LEFT JOIN {$latestResultSource} ON r.quiz_id = q.quiz_id";
  $quizPerfResult = mysqli_query($conn, "SELECT q.quiz_id, q.quiz_title, COUNT(r.result_id) AS attempts, COALESCE(AVG(r.score / r.total_questions * 100), 0) AS avg_percentage
    FROM quiz q
    {$resultJoinClause}
    WHERE q.user_id = {$userId}{$subjectClause}
    GROUP BY q.quiz_id, q.quiz_title, q.created_date
    ORDER BY q.created_date DESC LIMIT 8");
  if ($quizPerfResult) {
    $rawQuizPerf = array_reverse(mysqli_fetch_all($quizPerfResult, MYSQLI_ASSOC));
    $data['quizPerformance'] = array_map(function ($q) {
      return [
        'title' => $q['quiz_title'],
        'avg' => (int) round((float) $q['avg_percentage']),
        'attempts' => (int) $q['attempts'],
      ];
    }, $rawQuizPerf);
  }

  $recentActivityResult = mysqli_query($conn, "SELECT u.name AS student_name, q.quiz_title, ROUND(r.score / r.total_questions * 100) AS percentage, r.attempt_date
    FROM result r JOIN users u ON r.user_id = u.user_id JOIN quiz q ON r.quiz_id = q.quiz_id
    WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}
    ORDER BY r.attempt_date DESC LIMIT 5");
  if ($recentActivityResult) {
    $data['recentActivity'] = mysqli_fetch_all($recentActivityResult, MYSQLI_ASSOC);
  }

  $topStudentsResult = mysqli_query($conn, "SELECT u.name AS student_name, ROUND(r.score / r.total_questions * 100) AS percentage, q.quiz_title
    FROM {$latestResultSource} JOIN users u ON r.user_id = u.user_id JOIN quiz q ON r.quiz_id = q.quiz_id
    WHERE q.user_id = {$userId}{$subjectClause}{$classClauseR}
    ORDER BY (r.score / r.total_questions) DESC, r.attempt_date DESC LIMIT 5");
  if ($topStudentsResult) {
    $data['topStudents'] = mysqli_fetch_all($topStudentsResult, MYSQLI_ASSOC);
  }

  $materialSubjectClause = $subjectId !== null ? " AND t.subject_id = {$subjectId}" : '';

  $materialsCountResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM material m JOIN topic t ON m.topic_id = t.topic_id WHERE m.user_id = {$userId}{$materialSubjectClause}");
  $data['materialsCount'] = $materialsCountResult ? (int) mysqli_fetch_assoc($materialsCountResult)['total'] : 0;

  $materialsListResult = mysqli_query($conn, "SELECT m.material_id, m.title, m.material_type, m.upload_date
    FROM material m JOIN topic t ON m.topic_id = t.topic_id
    WHERE m.user_id = {$userId}{$materialSubjectClause}
    ORDER BY m.upload_date DESC LIMIT 4");
  if ($materialsListResult) {
    $data['materials'] = mysqli_fetch_all($materialsListResult, MYSQLI_ASSOC);
  }

  return $data;
}

$conn = mysqli_connect($host, $user, $pass, $dbname);
$teacher = null;
$classes = [];
$subjectOptions = [];
$classOptions = [];
$quizCount = 0;
$materialCount = 0;
$studentCount = 0;
$averageScore = 0;
$hasCompletionData = false;
$completionPercent = 0;
$recentActivity = [];
$topStudents = [];
$materials = [];
$classLabel = 'Teacher Portal';
$statTotalQuizzes = 0;
$statMaterialsCount = 0;
$statsChange = [
  'studentsChangeText' => 'No new students yet',
  'studentsChangeDirection' => 'neutral',
  'quizzesChangeText' => 'No new quizzes yet',
  'quizzesChangeDirection' => 'neutral',
  'avgScoreChangeText' => 'Not enough data yet',
  'avgScoreChangeDirection' => 'neutral',
];

if ($conn) {
  mysqli_set_charset($conn, 'utf8mb4');
  $userId = $_SESSION['user_id'];

  if (isset($_GET['feed']) && $_GET['feed'] === 'dashboard_filter') {
    header('Content-Type: application/json');
    $filterSubjectId = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int) $_GET['subject_id'] : null;
    $filterClassId = (isset($_GET['class_id']) && $_GET['class_id'] !== '') ? (int) $_GET['class_id'] : null;
    $filtered = getDashboardFilteredData($conn, (int) $userId, $filterSubjectId, $filterClassId);
    echo json_encode($filtered, JSON_UNESCAPED_UNICODE);
    exit();
  }

  $teacherResult = mysqli_query($conn, "SELECT user_id, name AS username, email, role, profile_picture FROM users WHERE user_id = {$userId} AND role = 'Teacher' LIMIT 1");
  if ($teacherResult) {
    $teacher = mysqli_fetch_assoc($teacherResult);
  }

  if (!$teacher) {
    $teacher = ['username' => 'Teacher'];
  }

  $classesResult = mysqli_query($conn, "SELECT DISTINCT s.subject_name FROM classes cl JOIN subject s ON cl.subject_id = s.subject_id WHERE cl.user_id = {$userId} ORDER BY s.subject_name ASC");
  if ($classesResult) {
    $classes = mysqli_fetch_all($classesResult, MYSQLI_ASSOC);
  }

  if (!empty($classes)) {
    $classNames = array_column($classes, 'subject_name');
    $classLabel = implode(', ', $classNames);
  }

  $subjectOptionsResult = mysqli_query($conn, "SELECT DISTINCT s.subject_id, s.subject_name FROM classes cl JOIN subject s ON cl.subject_id = s.subject_id WHERE cl.user_id = {$userId} ORDER BY s.subject_name ASC");
  if ($subjectOptionsResult) {
    $subjectOptions = mysqli_fetch_all($subjectOptionsResult, MYSQLI_ASSOC);
  }

  $classOptionsResult = mysqli_query($conn, "SELECT DISTINCT c.class_id, c.class_name, c.year FROM classes cl JOIN class c ON cl.class_id = c.class_id WHERE cl.user_id = {$userId} AND cl.subject_id IS NOT NULL ORDER BY c.class_name ASC, c.year ASC");
  if ($classOptionsResult) {
    $classOptions = mysqli_fetch_all($classOptionsResult, MYSQLI_ASSOC);
  }

  $quizCountResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM quiz WHERE user_id = {$userId}");
  if ($quizCountResult) {
    $quizCount = (int) mysqli_fetch_assoc($quizCountResult)['total'];
  }

  $materialCountResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM material WHERE user_id = {$userId}");
  if ($materialCountResult) {
    $materialCount = (int) mysqli_fetch_assoc($materialCountResult)['total'];
  }

  $initialDashboardData = getDashboardFilteredData($conn, (int) $userId, null, null);
  $hasCompletionData = $initialDashboardData['hasCompletionData'];
  $completionPercent = $initialDashboardData['completionPercent'];
  $quizPerformance = $initialDashboardData['quizPerformance'];
  $recentActivity = $initialDashboardData['recentActivity'];
  $topStudents = $initialDashboardData['topStudents'];
  $studentCount = $initialDashboardData['totalStudents'];
  $averageScore = $initialDashboardData['avgScore'];
  $statTotalQuizzes = $initialDashboardData['totalQuizzes'];
  $statsChange = $initialDashboardData;
  $materials = $initialDashboardData['materials'];
  $statMaterialsCount = $initialDashboardData['materialsCount'];

  mysqli_close($conn);
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

function statChangeArrow($direction) {
  if ($direction === 'positive') return '↗ ';
  if ($direction === 'negative') return '↘ ';
  return '';
}

function statChangeClass($direction) {
  if ($direction === 'positive') return '';
  if ($direction === 'negative') return ' negative';
  return ' neutral';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SmartLearn Teacher Portal</title>
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
      position: relative;
      overflow: hidden;
      transition: background 0.25s ease, color 0.25s ease, transform 0.25s ease;
    }

    .nav a:hover {
      background: rgba(202, 223, 242, 0.45);
      transform: translateX(3px);
    }

    .nav a.active {
      color: var(--ink-blue);
      background: linear-gradient(90deg, rgba(202, 223, 242, 0.96), rgba(150, 185, 217, 0.42));
    }

    .nav svg,
    .top-icon svg,
    .button svg {
      width: 20px;
      height: 20px;
      stroke-width: 2.1;
      flex: 0 0 auto;
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
      position: sticky;
      top: 0;
      z-index: 20;
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
      cursor: pointer;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .avatar:hover {
      transform: scale(1.06);
      box-shadow: 0 0 0 4px rgba(150, 185, 217, 0.35);
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

    .hero {
      min-height: 246px;
      display: flex;
      align-items: center;
      border-radius: 18px;
      padding: 34px 38px;
      color: white;
      background: linear-gradient(100deg, #58608A 0%, #6B8AA6 34%, #8AA4C7 70%, #96B9D9 100%);
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
      transform-style: preserve-3d;
      transition: transform 0.4s ease;
    }

    .hero::before {
      content: "";
      position: absolute;
      inset: -10%;
      background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.32), transparent 18%),
                  radial-gradient(circle at 80% 30%, rgba(255,255,255,0.18), transparent 16%);
      opacity: 0.7;
      pointer-events: none;
      transform: translate3d(var(--hero-x, 0px), var(--hero-y, 0px), 0);
      transition: transform 0.25s ease;
    }

    .hero .hero-visual {
      position: absolute;
      right: -24px;
      bottom: -26px;
      width: 420px;
      height: 420px;
      pointer-events: none;
      filter: drop-shadow(0 28px 55px rgba(24, 40, 76, 0.18));
      transform: translate3d(var(--hero-x, 0px), var(--hero-y, 0px), 0);
      animation: floatCard 8s ease-in-out infinite;
    }

    .hero .hero-visual svg {
      width: 100%;
      height: 100%;
      display: block;
    }

    .hero .hero-content {
      position: relative;
      z-index: 1;
      max-width: 54%;
    }

    .hero p {
      width: min(760px, 100%);
      margin: 0 0 18px;
      color: rgba(255, 255, 255, 0.88);
      font-size: 16px;
      line-height: 1.5;
    }

    .hero h2 {
      margin: 8px 0 8px;
      font-size: clamp(28px, 3vw, 40px);
      line-height: 1.1;
      color: white;
    }

    .hero h2 .wave {
      display: inline-block;
      transform-origin: 70% 70%;
      animation: wave 2.4s ease-in-out infinite;
    }

    @keyframes wave {
      0%, 100% { transform: rotate(0deg); }
      12% { transform: rotate(16deg); }
      24% { transform: rotate(-8deg); }
      36% { transform: rotate(16deg); }
      48% { transform: rotate(0deg); }
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 8px;
    }

    .button {
      min-height: 42px;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      border: 1px solid rgba(255, 255, 255, 0.28);
      border-radius: 10px;
      padding: 0 18px;
      color: white;
      background: rgba(255, 255, 255, 0.12);
      font-size: 16px;
      font-weight: 800;
      text-decoration: none;
      box-shadow: 0 6px 16px rgba(42, 56, 91, 0.16);
      position: relative;
      overflow: hidden;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      cursor: pointer;
    }

    .button.primary {
      color: #163047;
      background: #D8F7E2;
      border-color: transparent;
    }

    .button::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle 140px at var(--mx, 50%) var(--my, 50%), rgba(255,255,255,0.42), transparent 42%);
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
    }

    .button:hover::before {
      opacity: 1;
    }

    .button:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 22px rgba(40, 56, 92, 0.18);
    }

    .button:active {
      transform: translateY(0) scale(0.98);
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(190px, 1fr));
      gap: 18px;
      margin-top: 28px;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card:hover {
      transform: translateY(-6px);
      box-shadow: 0 22px 40px rgba(88, 96, 138, 0.16);
    }

    .stat-card {
      min-height: 126px;
      display: flex;
      align-items: center;
      gap: 18px;
      padding: 22px 24px;
      cursor: default;
    }

    .stat-icon {
      width: 58px;
      height: 58px;
      display: grid;
      place-items: center;
      flex: 0 0 auto;
      border-radius: 17px;
      color: var(--ink-blue);
      background: rgba(202, 223, 242, 0.75);
      transition: transform 0.35s ease;
    }

    .stat-card:hover .stat-icon {
      transform: rotate(-8deg) scale(1.08);
    }

    .stat-icon.green {
      color: #1E9C64;
      background: rgba(45, 190, 120, 0.18);
    }

    .stat-icon.orange {
      color: #7C560A;
      background: rgba(246, 163, 19, 0.20);
    }

    .stat-label {
      color: #5C6780;
      font-size: 13px;
      font-weight: 900;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .stat-value {
      margin-top: 5px;
      font-size: 28px;
      line-height: 1;
      font-weight: 900;
      color: var(--text);
    }

    .stat-change {
      margin-top: 8px;
      color: var(--green);
      font-size: 13px;
      font-weight: 700;
    }

    .stat-change.negative {
      color: var(--red);
    }

    .stat-change.neutral {
      color: var(--muted);
    }

    .dash-filter-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-top: 24px;
      padding: 16px 22px;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 16px;
      box-shadow: var(--shadow);
    }

    .dash-filter-label {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
    }

    .dash-filter-label svg {
      flex: 0 0 auto;
      color: var(--steel);
      stroke-width: 2;
    }

    .dash-filter-controls {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }

    .dash-filter-select.form-select {
      width: auto;
      min-width: 168px;
      padding: 9px 34px 9px 14px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background-color: #FBFDFF;
      color: var(--text);
      font-size: 14px;
      font-weight: 600;
    }

    .dash-filter-select.form-select:focus {
      border-color: var(--sky);
      box-shadow: 0 0 0 3px rgba(150, 185, 217, 0.18);
    }

    .dash-filter-clear {
      border: 1px solid var(--line);
      border-radius: 10px;
      background: transparent;
      color: var(--steel);
      font-size: 13px;
      font-weight: 700;
      padding: 9px 14px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .dash-filter-clear:hover {
      background: rgba(150, 185, 217, 0.12);
      border-color: var(--sky);
    }

    @media (max-width: 640px) {
      .dash-filter-bar {
        flex-direction: column;
        align-items: stretch;
      }

      .dash-filter-select.form-select {
        width: 100%;
      }
    }

    .charts {
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(310px, 1fr);
      gap: 20px;
      margin-top: 28px;
    }

    .bottom-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 20px;
      margin-top: 28px;
    }

    .panel {
      padding: 28px 30px;
      position: relative;
    }

    .panel h3 {
      margin: 0;
      font-size: 18px;
      line-height: 1.25;
      color: var(--text);
    }

    .panel p {
      margin: 8px 0 22px;
      color: var(--muted);
      font-size: 15px;
    }

    /* ---------- Line chart + tooltip ---------- */

    .chart-wrap {
      width: 100%;
      overflow: hidden;
      position: relative;
    }

    .line-chart {
      width: 100%;
      min-height: 290px;
      display: block;
    }

    .bar-chart {
      width: 100%;
      min-height: 290px;
      display: block;
    }

    .perf-bar {
      cursor: pointer;
      transition: opacity 0.2s ease, filter 0.2s ease;
    }

    .perf-bar:hover,
    .perf-bar.active {
      filter: brightness(1.08);
      opacity: 0.95;
    }

    .empty-chart-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      min-height: 290px;
      color: var(--muted);
      text-align: center;
      padding: 0 24px;
    }

    .empty-chart-state svg {
      opacity: 0.5;
    }

    .empty-chart-state p {
      margin: 0;
      font-size: 15px;
    }

    .axis-text {
      fill: #6D7890;
      font-size: 13px;
    }

    .grid-line {
      stroke: #CADFF2;
      stroke-dasharray: 4 5;
      opacity: 0.95;
    }

    .hover-col {
      opacity: 0;
      transition: opacity 0.15s ease;
      pointer-events: none;
    }

    .hover-col.show {
      opacity: 1;
    }

    .hover-line {
      stroke: #8795A8;
      stroke-width: 1.4;
      stroke-dasharray: 3 4;
    }

    .hover-dot {
      stroke: white;
      stroke-width: 2.5;
    }

    .chart-tooltip {
      position: absolute;
      top: 0;
      left: 0;
      min-width: 168px;
      transform: translate(-50%, -100%);
      background: #1B2840;
      color: white;
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 13px;
      line-height: 1.55;
      box-shadow: 0 14px 30px rgba(16, 24, 46, 0.32);
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.12s ease;
      z-index: 30;
      white-space: nowrap;
    }

    .chart-tooltip.show {
      opacity: 1;
    }

    .chart-tooltip.align-right {
      transform: translate(calc(-100% + 14px), -100%);
    }

    .chart-tooltip.align-left {
      transform: translate(-14px, -100%);
    }

    .chart-tooltip.align-right::after {
      left: auto;
      right: 14px;
      transform: translateX(0) rotate(45deg);
    }

    .chart-tooltip.align-left::after {
      left: 14px;
      transform: translateX(0) rotate(45deg);
    }

    .chart-tooltip.align-below {
      transform: translate(-50%, 0);
    }

    .chart-tooltip.align-below.align-right {
      transform: translate(calc(-100% + 14px), 0);
    }

    .chart-tooltip.align-below.align-left {
      transform: translate(-14px, 0);
    }

    .chart-tooltip.align-below::after {
      bottom: auto;
      top: -5px;
      transform: translateX(-50%) rotate(45deg);
    }

    .chart-tooltip.align-below.align-right::after {
      left: auto;
      right: 14px;
      top: -5px;
      transform: translateX(0) rotate(45deg);
    }

    .chart-tooltip.align-below.align-left::after {
      left: 14px;
      top: -5px;
      transform: translateX(0) rotate(45deg);
    }

    .chart-tooltip .tt-title {
      font-weight: 900;
      font-size: 13px;
      letter-spacing: 0.02em;
      color: #CFE0F4;
      margin-bottom: 6px;
      text-transform: uppercase;
    }

    .chart-tooltip .tt-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
    }

    .chart-tooltip .tt-row + .tt-row {
      margin-top: 3px;
    }

    .chart-tooltip .tt-key {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      color: #E7EEF8;
    }

    .chart-tooltip .tt-key i {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      display: inline-block;
      flex: 0 0 auto;
    }

    .chart-tooltip .tt-val {
      font-weight: 900;
    }

    .chart-tooltip::after {
      content: "";
      position: absolute;
      left: 50%;
      bottom: -5px;
      width: 10px;
      height: 10px;
      background: #1B2840;
      transform: translateX(-50%) rotate(45deg);
    }

    /* ---------- Donut + tooltip ---------- */

    .donut-area {
      min-height: 310px;
      display: grid;
      place-items: center;
      padding-top: 8px;
      position: relative;
    }

    .donut-svg {
      width: min(250px, 82%);
      height: min(250px, 82%);
      aspect-ratio: 1;
      overflow: visible;
    }

    .donut-seg {
      cursor: pointer;
      transition: filter 0.2s ease, opacity 0.2s ease;
      transform-origin: 125px 125px;
    }

    .donut-seg:hover,
    .donut-seg.active {
      filter: brightness(1.07);
    }

    .donut-seg-outline {
      fill: none;
      stroke: white;
      stroke-width: 3;
    }

    .donut-center-label {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
      pointer-events: none;
    }

    .donut-center-label .dc-value {
      font-size: 26px;
      font-weight: 900;
      color: var(--text);
      line-height: 1;
    }

    .donut-center-label .dc-label {
      margin-top: 3px;
      font-size: 12px;
      color: var(--muted);
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .legend {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 14px;
      margin-top: 4px;
      color: var(--muted);
      font-size: 15px;
      font-weight: 700;
    }

    .legend span {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      cursor: pointer;
      padding: 4px 10px;
      border-radius: 999px;
      transition: background 0.2s ease;
    }

    .legend span:hover,
    .legend span.active {
      background: rgba(202, 223, 242, 0.4);
    }

    .dot {
      width: 13px;
      height: 13px;
      border-radius: 50%;
      background: var(--green);
    }

    .dot.blue {
      background: var(--blue);
    }

    .dot.orange {
      background: var(--orange);
    }

    .recent-activity-table {
      width: 100%;
      border-collapse: collapse;
    }

    .recent-activity-table th {
      text-align: left;
      padding: 12px 16px;
      font-size: 13px;
      font-weight: 900;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
      border-bottom: 1px solid var(--line);
    }

    .recent-activity-table td {
      padding: 14px 16px;
      color: var(--text);
      border-bottom: 1px solid var(--line);
      font-size: 15px;
    }

    .student-list,
    .materials-list {
      display: grid;
      gap: 16px;
      margin-top: 26px;
    }

    .student-row {
      display: grid;
      grid-template-columns: 48px 1fr auto;
      align-items: center;
      gap: 18px;
      transition: transform 0.25s ease;
      border-radius: 12px;
      padding: 4px 6px;
    }

    .student-row:hover,
    .material-item:hover {
      transform: translateX(6px);
    }

    .rank {
      width: 46px;
      height: 46px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      color: var(--blue);
      background: rgba(202, 223, 242, 0.62);
      font-weight: 900;
      transition: background 0.25s ease, color 0.25s ease;
    }

    .student-row:hover .rank {
      background: var(--blue);
      color: white;
    }

    .student-name,
    .material-title {
      color: var(--text);
      font-size: 18px;
      font-weight: 900;
      line-height: 1.2;
    }

    .student-meta,
    .material-meta {
      margin-top: 4px;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.25;
    }

    .score-pill {
      min-width: 60px;
      display: inline-flex;
      justify-content: center;
      border-radius: 999px;
      padding: 7px 12px;
      color: #14633E;
      background: rgba(45, 190, 120, 0.22);
      font-size: 15px;
      font-weight: 900;
    }

    .material-item {
      min-height: 88px;
      display: grid;
      grid-template-columns: 54px 1fr;
      align-items: center;
      gap: 16px;
      border: 1px solid var(--line);
      border-radius: 15px;
      padding: 16px 18px;
      background: #FEFFFF;
      transition: transform 0.32s ease, box-shadow 0.32s ease;
      text-decoration: none;
      color: inherit;
      cursor: pointer;
    }

    .material-item:hover {
      box-shadow: 0 14px 26px rgba(88, 96, 138, 0.14);
    }

    .material-icon {
      width: 50px;
      height: 50px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      color: #14633E;
      background: rgba(45, 190, 120, 0.20);
    }

    .reveal {
      opacity: 0;
      transform: translateY(28px);
      transition: opacity 0.8s ease, transform 0.8s ease;
      will-change: transform, opacity;
    }

    .reveal.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .reveal.stagger > * {
      opacity: 0;
      transform: translateY(22px);
      transition: opacity 0.6s ease, transform 0.6s ease;
    }

    .reveal.stagger.visible > * {
      opacity: 1;
      transform: translateY(0);
    }

    .reveal.stagger.visible > *:nth-child(1) { transition-delay: 0.02s; }
    .reveal.stagger.visible > *:nth-child(2) { transition-delay: 0.09s; }
    .reveal.stagger.visible > *:nth-child(3) { transition-delay: 0.16s; }
    .reveal.stagger.visible > *:nth-child(4) { transition-delay: 0.23s; }

    @keyframes floatCard {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-8px); }
    }

    .topbar.scrolled {
      box-shadow: 0 12px 24px rgba(88, 96, 138, 0.08);
    }

    /* ---------- Spotlight (image) section ---------- */

    .spotlight-grid {
      display: grid;
      grid-template-columns: minmax(240px, 1fr) minmax(260px, 1.1fr);
      gap: 26px;
      align-items: center;
    }

    .spotlight-copy .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--ink-blue);
      font-size: 12px;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      background: rgba(202, 223, 242, 0.55);
      border-radius: 999px;
      padding: 6px 12px;
      margin-bottom: 14px;
    }

    .spotlight-copy h3 {
      margin: 0 0 10px;
      font-size: 22px;
    }

    .spotlight-copy p {
      margin: 0 0 20px;
    }

    .spotlight-meta {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .spotlight-meta .mchip {
      display: flex;
      align-items: center;
      gap: 10px;
      background: #F6FAFE;
      border: 1px solid var(--line);
      border-radius: 13px;
      padding: 10px 14px;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .spotlight-meta .mchip:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 18px rgba(88, 96, 138, 0.12);
    }

    .mchip .mchip-icon {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      display: grid;
      place-items: center;
      color: var(--ink-blue);
      background: rgba(202, 223, 242, 0.75);
      flex: 0 0 auto;
    }

    .mchip strong {
      display: block;
      font-size: 15px;
      line-height: 1.1;
    }

    .mchip span {
      display: block;
      font-size: 12px;
      color: var(--muted);
      font-weight: 700;
    }

    .spotlight-photo {
      position: relative;
      border-radius: 20px;
      overflow: hidden;
      min-height: 230px;
      box-shadow: 0 18px 36px rgba(88, 96, 138, 0.18);
    }

    .spotlight-photo svg {
      width: 100%;
      height: 100%;
      display: block;
      transform: scale(1.02);
      transition: transform 0.6s ease;
    }

    .spotlight-photo:hover svg {
      transform: scale(1.08);
    }

    .spotlight-photo::after {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(190deg, rgba(23, 35, 58, 0) 55%, rgba(15, 25, 46, 0.55) 100%);
    }

    .spotlight-photo .sp-badge {
      position: absolute;
      left: 16px;
      bottom: 16px;
      z-index: 1;
      color: white;
      font-weight: 800;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(20, 30, 54, 0.45);
      backdrop-filter: blur(6px);
      border-radius: 999px;
      padding: 8px 14px;
    }

    .sp-badge .live-pip {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #4ADE80;
      box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.6);
      animation: livePulse 1.8s ease-out infinite;
    }

    @keyframes livePulse {
      0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.55); }
      70% { box-shadow: 0 0 0 8px rgba(74, 222, 128, 0); }
      100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 250;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(16, 28, 56, 0.58);
      backdrop-filter: blur(4px);
      padding: 24px;
    }

    .modal-overlay.show {
      display: flex;
    }

    .modal {
      width: min(640px, 100%);
      max-height: min(92vh, 760px);
      overflow: auto;
      background: white;
      border-radius: 24px;
      padding: 28px 30px;
      box-shadow: 0 28px 80px rgba(18, 38, 64, 0.28);
      transform: translateY(18px) scale(0.98);
      opacity: 0;
      transition: transform 0.26s ease, opacity 0.26s ease;
    }

    .modal-overlay.show .modal {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    .modal h3 {
      margin-top: 0;
      margin-bottom: 14px;
      font-size: 22px;
    }

    .modal p {
      margin: 0 0 18px;
      color: var(--muted);
    }

    .modal .form-row {
      display: grid;
      gap: 10px;
      margin-bottom: 18px;
    }

    .modal label {
      font-size: 14px;
      font-weight: 700;
      color: var(--ink-blue);
    }

    .modal input[type="text"],
    .modal select,
    .modal textarea,
    .modal input[type="file"] {
      width: 100%;
      min-height: 44px;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 12px 14px;
      font-size: 15px;
      outline: none;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      background: #FBFDFF;
    }

    .modal textarea {
      min-height: 118px;
      resize: vertical;
    }

    .modal input:focus,
    .modal select:focus,
    .modal textarea:focus,
    .modal input[type="file"]:focus {
      border-color: var(--sky);
      box-shadow: 0 0 0 4px rgba(150, 185, 217, 0.18);
    }

    .modal .option-group {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .modal .option-item {
      display: grid;
      gap: 8px;
    }

    .modal .modal-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 10px;
    }

    .modal .close-button {
      border: 0;
      background: transparent;
      color: var(--muted);
      font-weight: 700;
      cursor: pointer;
      padding: 10px 14px;
      border-radius: 12px;
      transition: background 0.2s ease;
    }

    .modal .close-button:hover {
      background: rgba(202, 223, 242, 0.65);
    }

    .modal .submit-button {
      min-width: 160px;
    }

    .upload-note {
      font-size: 13px;
      color: var(--muted);
      margin-top: -8px;
    }

    .notification-panel {
      position: absolute;
      top: 70px;
      right: 32px;
      width: 280px;
      border-radius: 18px;
      background: white;
      box-shadow: 0 26px 55px rgba(18, 38, 64, 0.18);
      border: 1px solid rgba(17, 31, 55, 0.08);
      padding: 18px;
      opacity: 0;
      visibility: hidden;
      transform: translateY(12px);
      transition: opacity 0.22s ease, transform 0.22s ease, visibility 0.22s ease;
      z-index: 150;
    }

    .notification-panel.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }

    .notification-panel h4 {
      margin: 0 0 12px;
      font-size: 16px;
      color: var(--text);
    }

    .notification-panel .note-item {
      display: grid;
      gap: 6px;
      padding: 12px 12px;
      border-radius: 14px;
      background: #F8FBFF;
      margin-bottom: 10px;
      transition: background 0.2s ease;
    }

    .notification-panel .note-item:last-child {
      margin-bottom: 0;
    }

    .notification-panel .note-item:hover {
      background: #EFF6FF;
    }

    .notification-panel .note-item strong {
      display: block;
      font-size: 14px;
      color: var(--text);
    }

    .notification-panel .note-item span {
      font-size: 13px;
      color: var(--muted);
    }

    .toast {
      position: fixed;
      left: 50%;
      bottom: 28px;
      transform: translateX(-50%) translateY(18px);
      min-width: 220px;
      max-width: 92%;
      padding: 14px 18px;
      border-radius: 16px;
      background: rgba(23, 40, 64, 0.94);
      color: white;
      font-size: 14px;
      box-shadow: 0 18px 42px rgba(23, 40, 64, 0.28);
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.22s ease, transform 0.22s ease;
      z-index: 260;
    }

    .toast.show {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }

    /* ---------- Scroll progress bar ---------- */

    .scroll-progress {
      position: fixed;
      top: 0;
      left: 0;
      height: 3px;
      width: 0%;
      background: linear-gradient(90deg, var(--mist), var(--ink-blue));
      z-index: 999;
      transition: width 0.08s linear;
    }

    /* ---------- Back to top ---------- */

    .back-to-top {
      position: fixed;
      right: 28px;
      bottom: 28px;
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: var(--ink-blue);
      color: white;
      display: grid;
      place-items: center;
      box-shadow: 0 12px 26px rgba(88, 96, 138, 0.35);
      cursor: pointer;
      border: none;
      opacity: 0;
      transform: translateY(14px) scale(0.9);
      pointer-events: none;
      transition: opacity 0.25s ease, transform 0.25s ease, background 0.25s ease;
      z-index: 200;
    }

    .back-to-top.show {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    .back-to-top:hover {
      background: var(--steel);
    }

    .back-to-top svg {
      width: 20px;
      height: 20px;
      stroke-width: 2.4;
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

      .stats {
        grid-template-columns: repeat(2, minmax(180px, 1fr));
      }

      .spotlight-grid {
        grid-template-columns: 1fr;
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

      .hero {
        padding: 28px 22px;
      }

      .hero .hero-content {
        max-width: 100%;
      }

      .charts,
      .bottom-grid,
      .stats {
        grid-template-columns: 1fr;
      }

      .panel {
        padding: 22px 18px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
      }
    }

    /* ---------- Dark mode contrast fixes ----------
       These tinted icon badges use fixed light rgba backgrounds regardless of
       theme, so their foreground colors need brighter dark-mode variants to
       stay readable instead of blending into their own (now-relatively-light)
       background. */
    html[data-theme="dark"] .stat-icon {
      color: #DCE8F7;
      background: rgba(122, 154, 186, 0.28);
    }

    html[data-theme="dark"] .stat-icon.green {
      color: #6FE3AC;
      background: rgba(61, 214, 140, 0.20);
    }

    html[data-theme="dark"] .stat-icon.orange {
      color: #FFCE73;
      background: rgba(255, 179, 64, 0.20);
    }

    html[data-theme="dark"] .material-icon {
      color: #6FE3AC;
      background: rgba(61, 214, 140, 0.20);
    }

    html[data-theme="dark"] .material-item {
      background: #141C28;
      border-color: var(--line);
    }

    html[data-theme="dark"] .score-pill {
      color: #6FE3AC;
      background: rgba(61, 214, 140, 0.22);
    }

    html[data-theme="dark"] .rank {
      color: #4DA3FF;
      background: rgba(74, 106, 138, 0.35);
    }

    html[data-theme="dark"] .dash-filter-select.form-select {
      background-color: #141C28;
      color: var(--text);
      border-color: var(--line);
    }

    html[data-theme="dark"] .dash-filter-select.form-select option {
      background-color: #141C28;
      color: var(--text);
    }

    html[data-theme="dark"] .dash-filter-clear {
      color: var(--steel);
      border-color: var(--line);
    }

    html[data-theme="dark"] .dash-filter-clear:hover {
      background: rgba(74, 106, 138, 0.25);
    }

    html[data-theme="dark"] .stat-change.negative {
      color: var(--red);
    }
  </style>
</head>
<body data-page="dashboard">
  <div class="scroll-progress" id="scrollProgress"></div>

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
        <a href="TReport.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg><span>Report</span></a>
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
          <div class="avatar" aria-hidden="true" style="<?= profileAvatarStyle($teacher['profile_picture'] ?? '') ?>"></div>
          <div><strong><?= e($teacher['username'] ?? 'Teacher') ?></strong><span><?= e($classLabel) ?></span></div>
        </div>
      </header>


      <section class="content">
        <div class="hero">
          <div class="hero-content">
            <p>Welcome back,</p>
            <h2><?= e($teacher['username'] ?? 'Teacher') ?> <span class="wave" aria-hidden="true">👋</span></h2>
            <p>Here’s what’s happening across your classes today. You are managing <?= e($quizCount) ?> quiz<?= $quizCount === 1 ? '' : 'zes' ?> and <?= e($materialCount) ?> material<?= $materialCount === 1 ? '' : 's' ?> from the database.</p>
            <div class="actions">
              <a class="button primary" id="newQuizButton" href="/SmartLearn/Teacher/TCreateQuiz.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14" /><path d="M5 12h14" /></svg>
                Create Quiz
              </a>
              <a class="button" id="uploadMaterialButton" href="TLearningMaterials.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3v12" /><path d="m17 8-5-5-5 5" /><path d="M5 21h14" /></svg>
                Upload Material
              </a>
            </div>
          </div>
          <div class="hero-visual" aria-hidden="true">
            <svg viewBox="0 0 320 320" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="160" cy="160" r="120" fill="rgba(255,255,255,0.18)" />
              <circle cx="220" cy="90" r="48" fill="rgba(255,255,255,0.24)" />
              <circle cx="95" cy="220" r="34" fill="rgba(255,255,255,0.18)" />
              <path d="M70 160C94 122 130 112 164 128C210 150 230 188 216 216" stroke="rgba(255,255,255,0.55)" stroke-width="18" stroke-linecap="round" />
              <path d="M110 82C126 70 156 76 172 92" stroke="rgba(255,255,255,0.75)" stroke-width="12" stroke-linecap="round" />
              <path d="M180 40L240 90" stroke="rgba(255,255,255,0.5)" stroke-width="10" stroke-linecap="round" />
              <path d="M32 252L116 210" stroke="rgba(255,255,255,0.4)" stroke-width="12" stroke-linecap="round" />
            </svg>
          </div>
        </div>

        <section class="stats reveal stagger" aria-label="Dashboard statistics">
          <article class="card stat-card" id="stat-total-students">
            <div class="stat-icon">
              <svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /></svg>
            </div>
            <div>
              <div class="stat-label">Total Students</div>
              <div class="stat-value" id="statTotalStudents"><?= e($studentCount) ?></div>
              <div class="stat-change<?= statChangeClass($statsChange['studentsChangeDirection']) ?>" id="statTotalStudentsChange"><?= e(statChangeArrow($statsChange['studentsChangeDirection']) . $statsChange['studentsChangeText']) ?></div>
            </div>
          </article>

          <article class="card stat-card" id="stat-total-quizzes">
            <div class="stat-icon green">
              <svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" /></svg>
            </div>
            <div>
              <div class="stat-label">Total Quizzes</div>
              <div class="stat-value" id="statTotalQuizzes"><?= e($statTotalQuizzes) ?></div>
              <div class="stat-change<?= statChangeClass($statsChange['quizzesChangeDirection']) ?>" id="statTotalQuizzesChange"><?= e(statChangeArrow($statsChange['quizzesChangeDirection']) . $statsChange['quizzesChangeText']) ?></div>
            </div>
          </article>

          <article class="card stat-card" id="stat-avg-class-score">
            <div class="stat-icon orange">
              <svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="6" /><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" /></svg>
            </div>
            <div>
              <div class="stat-label">Avg. Class Score</div>
              <div class="stat-value" id="statAvgScore"><?= e($averageScore) ?>%</div>
              <div class="stat-change<?= statChangeClass($statsChange['avgScoreChangeDirection']) ?>" id="statAvgScoreChange"><?= e(statChangeArrow($statsChange['avgScoreChangeDirection']) . $statsChange['avgScoreChangeText']) ?></div>
            </div>
          </article>
        </section>

        <div class="dash-filter-bar reveal" id="dashFilterBar">
          <div class="dash-filter-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3Z" /></svg>
            Filter Quiz Performance, Quiz Completion, Recent Activity, Top Students &amp; Materials
          </div>
          <div class="dash-filter-controls">
            <select class="form-select dash-filter-select" id="dashFilterSubject" aria-label="Filter by subject">
              <option value="">All Subjects</option>
              <?php foreach ($subjectOptions as $subjectOption): ?>
                <option value="<?= (int) $subjectOption['subject_id'] ?>"><?= e($subjectOption['subject_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="form-select dash-filter-select" id="dashFilterClass" aria-label="Filter by class">
              <option value="">All Classes</option>
              <?php foreach ($classOptions as $classOption): ?>
                <option value="<?= (int) $classOption['class_id'] ?>"><?= e($classOption['class_name']) ?> (Year <?= (int) $classOption['year'] ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="dash-filter-clear" id="dashFilterClear" hidden>Clear</button>
          </div>
        </div>

        <section class="charts reveal">
          <article class="card panel" id="quizPerfPanel">
            <h3>Quiz Performance</h3>
            <p>Average score per quiz</p>
            <div class="empty-chart-state" id="quizPerfEmpty" style="<?= empty($quizPerformance) ? '' : 'display:none;' ?>">
              <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M18 17V9" /><path d="M13 17V5" /><path d="M8 17v-3" /></svg>
              <p id="quizPerfEmptyText">Create a quiz to start tracking performance here.</p>
            </div>
            <div class="chart-wrap" id="quizPerfChartWrap" style="<?= empty($quizPerformance) ? 'display:none;' : '' ?>">
              <svg class="bar-chart" id="quizPerfChart" viewBox="0 0 920 310" role="img" aria-label="Average score per quiz" preserveAspectRatio="none">
                <defs>
                  <linearGradient id="perfBarGradient" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#6B8AA6" />
                    <stop offset="100%" stop-color="#96B9D9" />
                  </linearGradient>
                </defs>
                <g id="quizPerfGrid"></g>
                <g id="quizPerfBars"></g>
                <g id="quizPerfAxis"></g>
              </svg>
            </div>
            <div class="chart-tooltip" id="quizPerfTooltip">
              <div class="tt-title" id="quizPerfTtTitle">Quiz</div>
              <div class="tt-row">
                <span class="tt-key"><i style="background:#3298D5"></i>Average score</span>
                <span class="tt-val" id="quizPerfTtAvg">0%</span>
              </div>
              <div class="tt-row">
                <span class="tt-key"><i style="background:#8795A8"></i>Students attempted</span>
                <span class="tt-val" id="quizPerfTtAttempts">0</span>
              </div>
            </div>
          </article>

          <article class="card panel" id="quiz-completion">
            <h3>Quiz Completion</h3>
            <p>Across all assigned quizzes</p>
            <div class="donut-area" id="donutArea">
              <svg class="donut-svg" id="donutSvg" viewBox="0 0 250 250" aria-label="Quiz completion donut chart">
                <!-- segments built by JS based on DONUT_DATA -->
                <g id="donutSegs"></g>
              </svg>
              <div class="donut-center-label" id="donutCenterLabel">
                <div class="dc-value" id="dcValue"><?= $hasCompletionData ? e($completionPercent) . '%' : '—' ?></div>
                <div class="dc-label"><?= $hasCompletionData ? 'Completed' : 'No students yet' ?></div>
              </div>
              <div class="chart-tooltip" id="donutTooltip">
                <div class="tt-title" id="donutTtTitle">Completed</div>
                <div class="tt-row">
                  <span class="tt-key" id="donutTtKey"><i style="background:#2DBE78"></i>Quizzes</span>
                  <span class="tt-val" id="donutTtVal">0%</span>
                </div>
              </div>
            </div>
            <div class="legend" id="donutLegend">
              <?php if ($hasCompletionData): ?>
                <span data-seg="completed" style="color:#2DBE78"><i class="dot"></i> Completed</span>
                <span data-seg="notcompleted" style="color:#F6A313"><i class="dot orange"></i> Not Completed</span>
              <?php else: ?>
                <span data-seg="nodata" style="color:#9AA5B5"><i class="dot" style="background:#C7CEDA"></i> No student data yet</span>
              <?php endif; ?>
            </div>
          </article>
        </section>


        <section class="reveal" style="margin-bottom: 28px;">
          <article class="card panel" id="recent-activity">
            <h3>Recent Activity</h3>
            <p>Latest student quiz submissions</p>
            <div class="table-responsive">
              <table class="recent-activity-table">
                <thead>
                  <tr>
                    <th>Student Name</th>
                    <th>Quiz</th>
                    <th>Score</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody id="recentActivityBody">
                  <?php if (!empty($recentActivity)): ?>
                    <?php foreach ($recentActivity as $row): ?>
                      <tr>
                        <td><?= e($row['student_name']) ?></td>
                        <td><?= e($row['quiz_title']) ?></td>
                        <td data-score="<?= e($row['percentage']) ?>"><?= e($row['percentage']) ?>%</td>
                        <td><?= e($row['attempt_date']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="4">No quiz submissions have been recorded yet.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </article>
        </section>

        <section class="bottom-grid reveal">
          <article class="card panel" id="top-performing-students">
            <h3>Top Performing Students</h3>
            <div class="student-list" id="topStudentsList">
              <?php if (!empty($topStudents)): ?>
                <?php foreach ($topStudents as $index => $student): ?>
                  <div class="student-row">
                    <div class="rank"><?= e($index + 1) ?></div>
                    <div>
                      <div class="student-name"><?= e($student['student_name']) ?></div>
                      <div class="student-meta"><?= e($student['quiz_title']) ?></div>
                    </div>
                    <div class="score-pill"><?= e($student['percentage']) ?>%</div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="student-row">
                  <div class="rank">•</div>
                  <div>
                    <div class="student-name">No result data yet</div>
                    <div class="student-meta">Student quiz results will appear here once available.</div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </article>

          <article class="card panel" id="materials-snapshot">
            <h3>Materials Snapshot</h3>
            <p id="materialsSnapshotCount"><?= e($statMaterialsCount) ?> resources uploaded</p>
            <div class="materials-list" id="materialsSnapshotList">
              <?php if (!empty($materials)): ?>
                <?php foreach ($materials as $material): ?>
                  <a class="material-item" href="TLearningMaterials.php?view=<?= (int) $material['material_id'] ?>">
                    <div class="material-icon">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z" /><path d="M14 2v5h5" /><path d="M10 13h4" /><path d="M12 11v6" /></svg>
                    </div>
                    <div>
                      <div class="material-title"><?= e($material['title']) ?></div>
                      <div class="material-meta"><?= e($material['material_type']) ?> · <?= e($material['upload_date']) ?></div>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="material-item" style="cursor: default;">
                  <div class="material-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z" /><path d="M14 2v5h5" /><path d="M10 13h4" /><path d="M12 11v6" /></svg>
                  </div>
                  <div>
                    <div class="material-title">No materials uploaded yet</div>
                    <div class="material-meta">Upload learning resources to populate this list.</div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </article>
        </section>
      </section>
    </main>
  </div>

  <div class="notification-panel" id="notificationPanel" role="dialog" aria-label="Notifications">
    <div class="note-header">
      <h4>Notifications</h4>
      <button type="button" class="mark-read" id="markAllRead">Mark all read</button>
    </div>
    <!-- Real notifications are rendered here dynamically by teacher-shared.js
         (renderNotifications), populated from TDashboard.php?feed=activity. -->
  </div>

  <div class="modal-overlay" id="quizModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="quizModalTitle">
      <h3 id="quizModalTitle">Create New Quiz</h3>
      <p>Pick a subject, describe one question, and add four answer options.</p>
      <form id="quizForm">
        <div class="form-row">
          <label for="quizSubject">Subject</label>
          <select id="quizSubject" required>
            <option value="">Select subject</option>
            <option>Computer Science</option>
            <option>Mathematics</option>
            <option>Physics</option>
            <option>Data Science</option>
          </select>
        </div>
        <div class="form-row">
          <label for="quizQuestion">Question</label>
          <textarea id="quizQuestion" placeholder="Type the question here..." required></textarea>
        </div>
        <div class="form-row option-group">
          <div class="option-item">
            <label for="optionA">Option A</label>
            <input id="optionA" type="text" placeholder="Option A" required />
          </div>
          <div class="option-item">
            <label for="optionB">Option B</label>
            <input id="optionB" type="text" placeholder="Option B" required />
          </div>
          <div class="option-item">
            <label for="optionC">Option C</label>
            <input id="optionC" type="text" placeholder="Option C" required />
          </div>
          <div class="option-item">
            <label for="optionD">Option D</label>
            <input id="optionD" type="text" placeholder="Option D" required />
          </div>
        </div>
        <div class="modal-actions">
          <button class="close-button" type="button" data-close="true">Cancel</button>
          <button class="button primary submit-button" type="submit">Publish Quiz</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="uploadModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
      <h3 id="uploadModalTitle">Upload Learning Material</h3>
      <p>Share a lesson resource with your class quickly and smoothly.</p>
      <form id="uploadForm">
        <div class="form-row">
          <label for="materialSubject">Subject</label>
          <select id="materialSubject" required>
            <option value="">Select subject</option>
            <option>Computer Science</option>
            <option>Mathematics</option>
            <option>Physics</option>
            <option>Data Science</option>
          </select>
        </div>
        <div class="form-row">
          <label for="materialTitle">Resource title</label>
          <input id="materialTitle" type="text" placeholder="Enter the material title" required />
        </div>
        <div class="form-row">
          <label for="materialFile">Attach file</label>
          <input id="materialFile" type="file" accept=".pdf,.ppt,.pptx,.docx,.zip" required />
        </div>
        <p class="upload-note">Accepted formats: PDF, PPT, DOCX, ZIP. Max file size: 10MB.</p>
        <div class="modal-actions">
          <button class="close-button" type="button" data-close="true">Close</button>
          <button class="button primary submit-button" type="submit">Upload</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast toast-msg" id="toastMsg" role="status" aria-live="polite"></div>

  <button class="back-to-top" id="backToTop" aria-label="Back to top">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6" /></svg>
  </button>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>

    window.SEARCH_INDEX = <?php
      $staticSearchEntries = [
        ['label' => 'Total Students', 'category' => 'Dashboard stat card', 'page' => '', 'anchor' => 'stat-total-students', 'icon' => 'dashboard'],
        ['label' => 'Total Quizzes', 'category' => 'Dashboard stat card', 'page' => '', 'anchor' => 'stat-total-quizzes', 'icon' => 'dashboard'],
        ['label' => 'Avg. Class Score', 'category' => 'Dashboard stat card', 'page' => '', 'anchor' => 'stat-avg-class-score', 'icon' => 'dashboard'],
        ['label' => 'Quiz Performance', 'category' => 'Average score per quiz', 'page' => '', 'anchor' => 'quizPerfPanel', 'icon' => 'analytics'],
        ['label' => 'Quiz Completion', 'category' => 'Completed vs not completed', 'page' => '', 'anchor' => 'quiz-completion', 'icon' => 'analytics'],
        ['label' => 'Recent Activity', 'category' => 'Latest student quiz submissions', 'page' => '', 'anchor' => 'recent-activity', 'icon' => 'dashboard'],
        ['label' => 'Top Performing Students', 'category' => 'Best scores on the Dashboard', 'page' => '', 'anchor' => 'top-performing-students', 'icon' => 'dashboard'],
        ['label' => 'Materials Snapshot', 'category' => 'Recently uploaded resources', 'page' => '', 'anchor' => 'materials-snapshot', 'icon' => 'materials'],
        ['label' => 'Create Quiz', 'category' => 'Build a new quiz', 'page' => 'TCreateQuiz.php', 'pageLabel' => 'Create Quiz', 'icon' => 'createQuiz', 'keywords' => ['new quiz', 'add quiz']],
        ['label' => 'Manage Quiz', 'category' => 'View, edit and publish quizzes', 'page' => 'TQuizManagement.php', 'pageLabel' => 'Manage Quiz', 'icon' => 'manageQuiz'],
        ['label' => 'Existing Quizzes', 'category' => 'Full quiz library', 'page' => 'TQuizManagement.php', 'pageLabel' => 'Manage Quiz', 'icon' => 'manageQuiz'],
        ['label' => 'Upload Materials', 'category' => 'Upload notes, PDFs and videos', 'page' => 'TLearningMaterials.php', 'pageLabel' => 'Materials', 'icon' => 'materials'],
        ['label' => 'All Materials', 'category' => 'Every uploaded resource', 'page' => 'TLearningMaterials.php', 'pageLabel' => 'Materials', 'icon' => 'materials'],
        ['label' => 'Analytics Dashboard', 'category' => 'Class performance overview', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Student Leaderboard', 'category' => 'Ranked by average score', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Grade Distribution', 'category' => 'A / B / C / D breakdown', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Recent Attempt Scores', 'category' => 'Score per quiz attempt', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Weak Topics Identification', 'category' => 'Topics students struggle with most', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Top Performer', 'category' => 'Analytics stat card', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Avg. Class Marks', 'category' => 'Analytics stat card', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Lowest Subject Avg', 'category' => 'Analytics stat card', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Student Performance', 'category' => 'Recent quiz submissions', 'page' => 'TClassPerformance.php', 'pageLabel' => 'Analytics', 'icon' => 'analytics'],
        ['label' => 'Student View', 'category' => 'Look up an individual student', 'page' => 'TStudentAnalytic.php', 'pageLabel' => 'Student View', 'icon' => 'studentView'],
        ['label' => 'Report', 'category' => 'Printable class report', 'page' => 'TReport.php', 'pageLabel' => 'Report', 'icon' => 'report'],
        ['label' => 'Profile Settings', 'category' => 'Your account information', 'page' => 'TProfile.php', 'pageLabel' => 'Profile', 'icon' => 'profile'],
        ['label' => 'Change Password', 'category' => 'Update your account security', 'page' => 'TProfile.php', 'pageLabel' => 'Profile', 'icon' => 'profile'],
      ];

      $dynamicSearchEntries = [];
      foreach ($quizPerformance as $quizItem) {
        $dynamicSearchEntries[] = [
          'label' => $quizItem['title'],
          'category' => 'Quiz · ' . (int) $quizItem['attempts'] . ' attempt' . ((int) $quizItem['attempts'] === 1 ? '' : 's'),
          'page' => 'TQuizManagement.php',
          'pageLabel' => 'Manage Quiz',
          'icon' => 'manageQuiz',
        ];
      }
      foreach ($materials as $materialItem) {
        $dynamicSearchEntries[] = [
          'label' => $materialItem['title'],
          'category' => 'Material · ' . $materialItem['material_type'],
          'page' => 'TLearningMaterials.php?view=' . (int) $materialItem['material_id'],
          'pageLabel' => 'Materials',
          'icon' => 'materials',
        ];
      }

      echo json_encode(array_merge($staticSearchEntries, $dynamicSearchEntries), JSON_UNESCAPED_SLASHES);
    ?>;
  </script>
  <script src="teacher-shared.js"></script>
  <script>
    /* ============================================================
       Scroll-driven chrome: progress bar, sticky topbar shadow,
       back-to-top button, and reveal-on-scroll animations
       ============================================================ */
    const revealTargets = document.querySelectorAll('.reveal');
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.16 });

    revealTargets.forEach((element) => revealObserver.observe(element));

    /* ============================================================
       Animated stat counters (run once, when scrolled into view)
       ============================================================ */
    const counters = document.querySelectorAll('.stat-value[data-target]');

    function animateCounter(el) {
      const target = Number(el.dataset.target) || 0;
      const unit = el.dataset.unit || '';
      const duration = 1500;
      let startTime = null;

      const step = (timestamp) => {
        if (!startTime) startTime = timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = `${Math.round(eased * target).toLocaleString()}${unit}`;
        if (progress < 1) {
          requestAnimationFrame(step);
        }
      };

      requestAnimationFrame(step);
    }

    const statSection = document.querySelector('.stats');
    if (statSection) {
      const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            counters.forEach(animateCounter);
            counterObserver.disconnect();
          }
        });
      }, { threshold: 0.3 });
      counterObserver.observe(statSection);
    }

    const hero = document.querySelector('.hero');
    if (hero) {
      hero.addEventListener('mousemove', (event) => {
        const rect = hero.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width - 0.5) * 24;
        const y = ((event.clientY - rect.top) / rect.height - 0.5) * 24;
        hero.style.setProperty('--hero-x', `${x}px`);
        hero.style.setProperty('--hero-y', `${y}px`);
      });
    }

    document.querySelectorAll('.button').forEach((button) => {
      button.addEventListener('mousemove', (event) => {
        const rect = button.getBoundingClientRect();
        button.style.setProperty('--mx', `${event.clientX - rect.left}px`);
        button.style.setProperty('--my', `${event.clientY - rect.top}px`);
      });
    });

    const perfPanel = document.getElementById('quizPerfPanel');
    const perfSvg = document.getElementById('quizPerfChart');
    const perfGrid = document.getElementById('quizPerfGrid');
    const perfBarsGroup = document.getElementById('quizPerfBars');
    const perfAxisGroup = document.getElementById('quizPerfAxis');
    const perfTooltip = document.getElementById('quizPerfTooltip');
    const perfTtTitle = document.getElementById('quizPerfTtTitle');
    const perfTtAvg = document.getElementById('quizPerfTtAvg');
    const perfTtAttempts = document.getElementById('quizPerfTtAttempts');
    const quizPerfEmpty = document.getElementById('quizPerfEmpty');
    const quizPerfEmptyText = document.getElementById('quizPerfEmptyText');
    const quizPerfChartWrap = document.getElementById('quizPerfChartWrap');

    const INITIAL_QUIZ_PERF_DATA = <?= json_encode($quizPerformance) ?>;

    let currentQuizPerfData = [];
    const svgNS = 'http://www.w3.org/2000/svg';
    const PX_LEFT = 72, PX_RIGHT = 890, PY_TOP = 60, PY_BOTTOM = 246;
    const plotWidth = PX_RIGHT - PX_LEFT;

    function perfValueToY(v) {
      return PY_BOTTOM - (v / 100) * (PY_BOTTOM - PY_TOP);
    }

    function showPerfTooltip(index, evt) {
      const quiz = currentQuizPerfData[index];
      if (!quiz) return;
      perfTtTitle.textContent = quiz.title;
      perfTtAvg.textContent = quiz.attempts > 0 ? quiz.avg + '%' : 'No submissions yet';
      perfTtAttempts.textContent = quiz.attempts;

      perfBarsGroup.querySelectorAll('.perf-bar').forEach((b) => b.classList.toggle('active', Number(b.dataset.index) === index));

      perfTooltip.classList.remove('align-left', 'align-right', 'align-below');

      const panelRect = perfPanel.getBoundingClientRect();
      const px = evt.clientX - panelRect.left;
      const py = evt.clientY - panelRect.top;

      const ttWidth = perfTooltip.offsetWidth || 170;
      const ttHeight = perfTooltip.offsetHeight || 80;
      const halfWidth = ttWidth / 2;
      const margin = 10;

      let finalX = px;
      if (px + halfWidth > panelRect.width - margin) {
        perfTooltip.classList.add('align-right');
        finalX = Math.min(px, panelRect.width - margin);
      } else if (px - halfWidth < margin) {
        perfTooltip.classList.add('align-left');
        finalX = Math.max(px, margin);
      }

      let finalY = py - 16;
      if (py - ttHeight - 16 < 0) {
        perfTooltip.classList.add('align-below');
        finalY = py + 16;
      }

      perfTooltip.style.left = finalX + 'px';
      perfTooltip.style.top = finalY + 'px';
      perfTooltip.classList.add('show');
    }

    function hidePerfTooltip() {
      perfTooltip.classList.remove('show');
      perfBarsGroup.querySelectorAll('.perf-bar').forEach((b) => b.classList.remove('active'));
    }

    function renderQuizPerfChart(data, emptyText) {
      currentQuizPerfData = data || [];
      perfGrid.innerHTML = '';
      perfBarsGroup.innerHTML = '';
      perfAxisGroup.innerHTML = '';
      hidePerfTooltip();

      if (currentQuizPerfData.length === 0) {
        quizPerfChartWrap.style.display = 'none';
        quizPerfEmpty.style.display = '';
        if (quizPerfEmptyText && emptyText) quizPerfEmptyText.textContent = emptyText;
        return;
      }
      quizPerfEmpty.style.display = 'none';
      quizPerfChartWrap.style.display = '';

      [0, 25, 50, 75, 100].forEach((v) => {
        const y = perfValueToY(v);
        const line = document.createElementNS(svgNS, 'line');
        line.setAttribute('class', 'grid-line');
        line.setAttribute('x1', PX_LEFT);
        line.setAttribute('y1', y);
        line.setAttribute('x2', PX_RIGHT);
        line.setAttribute('y2', y);
        perfGrid.appendChild(line);

        const label = document.createElementNS(svgNS, 'text');
        label.setAttribute('class', 'axis-text');
        label.setAttribute('x', 30);
        label.setAttribute('y', y + 4);
        label.textContent = v;
        perfAxisGroup.appendChild(label);
      });

      const axisLine = document.createElementNS(svgNS, 'path');
      axisLine.setAttribute('d', `M${PX_LEFT} ${PY_TOP} V${PY_BOTTOM} H${PX_RIGHT}`);
      axisLine.setAttribute('fill', 'none');
      axisLine.setAttribute('stroke', '#8795A8');
      axisLine.setAttribute('stroke-width', '1.5');
      perfGrid.appendChild(axisLine);

      const n = currentQuizPerfData.length;
      const slot = plotWidth / n;
      const barWidth = Math.min(56, slot * 0.5);
      const maxTitleChars = n > 5 ? 8 : 14;

      currentQuizPerfData.forEach((quiz, i) => {
        const cx = PX_LEFT + slot * (i + 0.5);
        const barX = cx - barWidth / 2;
        const hasAttempts = quiz.attempts > 0;
        const barY = hasAttempts ? perfValueToY(quiz.avg) : PY_BOTTOM - 2;
        const barHeight = Math.max(PY_BOTTOM - barY, 2);

        const rect = document.createElementNS(svgNS, 'rect');
        rect.setAttribute('x', barX);
        rect.setAttribute('y', barY);
        rect.setAttribute('width', barWidth);
        rect.setAttribute('height', barHeight);
        rect.setAttribute('rx', 8);
        rect.setAttribute('fill', hasAttempts ? 'url(#perfBarGradient)' : '#DDE3EC');
        rect.classList.add('perf-bar');
        rect.dataset.index = i;
        perfBarsGroup.appendChild(rect);

        const valueLabel = document.createElementNS(svgNS, 'text');
        valueLabel.setAttribute('class', 'axis-text');
        valueLabel.setAttribute('x', cx);
        valueLabel.setAttribute('y', Math.max(barY - 10, PY_TOP + 4));
        valueLabel.setAttribute('text-anchor', 'middle');
        valueLabel.textContent = hasAttempts ? quiz.avg + '%' : '—';
        perfAxisGroup.appendChild(valueLabel);

        const titleLabel = document.createElementNS(svgNS, 'text');
        titleLabel.setAttribute('class', 'axis-text');
        titleLabel.setAttribute('x', cx);
        titleLabel.setAttribute('y', 272);
        titleLabel.setAttribute('text-anchor', 'middle');
        titleLabel.textContent = quiz.title.length > maxTitleChars ? quiz.title.slice(0, maxTitleChars - 1) + '…' : quiz.title;
        const titleNode = document.createElementNS(svgNS, 'title');
        titleNode.textContent = quiz.title;
        titleLabel.appendChild(titleNode);
        perfAxisGroup.appendChild(titleLabel);
      });

      perfBarsGroup.querySelectorAll('.perf-bar').forEach((bar) => {
        bar.addEventListener('mouseenter', (e) => showPerfTooltip(Number(bar.dataset.index), e));
        bar.addEventListener('mousemove', (e) => showPerfTooltip(Number(bar.dataset.index), e));
        bar.addEventListener('mouseleave', hidePerfTooltip);
        bar.addEventListener('touchstart', (e) => {
          e.preventDefault();
          showPerfTooltip(Number(bar.dataset.index), e.touches[0]);
        }, { passive: false });
      });
    }

    renderQuizPerfChart(INITIAL_QUIZ_PERF_DATA, 'Create a quiz to start tracking performance here.');

    const INITIAL_DONUT_HAS_DATA = <?= $hasCompletionData ? 'true' : 'false' ?>;
    const INITIAL_DONUT_COMPLETION = <?= (int) $completionPercent ?>;

    const donutSegsGroup = document.getElementById('donutSegs');
    const donutTooltip = document.getElementById('donutTooltip');
    const donutTtTitle = document.getElementById('donutTtTitle');
    const donutTtKey = document.getElementById('donutTtKey');
    const donutTtVal = document.getElementById('donutTtVal');
    const donutArea = document.getElementById('donutArea');
    const donutSvg = document.getElementById('donutSvg');
    const dcValue = document.getElementById('dcValue');
    const donutLegend = document.getElementById('donutLegend');

    const CX = 125, CY = 125, R_OUTER = 118, R_INNER = 70;
    const GAP_DEG = 2.6;

    let currentDonutData = [];
    let currentDonutHasData = false;
    let currentDonutCompletion = 0;

    function polarToXY(cx, cy, r, angleDeg) {
      const rad = (angleDeg - 90) * (Math.PI / 180);
      return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
    }

    function donutArcPath(startAngle, endAngle) {
      const largeArc = endAngle - startAngle > 180 ? 1 : 0;
      const [x1, y1] = polarToXY(CX, CY, R_OUTER, startAngle);
      const [x2, y2] = polarToXY(CX, CY, R_OUTER, endAngle);
      const [x3, y3] = polarToXY(CX, CY, R_INNER, endAngle);
      const [x4, y4] = polarToXY(CX, CY, R_INNER, startAngle);
      return [
        `M ${x1} ${y1}`,
        `A ${R_OUTER} ${R_OUTER} 0 ${largeArc} 1 ${x2} ${y2}`,
        `L ${x3} ${y3}`,
        `A ${R_INNER} ${R_INNER} 0 ${largeArc} 0 ${x4} ${y4}`,
        'Z'
      ].join(' ');
    }

    function setDonutActive(key) {
      donutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => el.classList.toggle('active', el.dataset.key === key));
      donutLegend.querySelectorAll('span').forEach((el) => el.classList.toggle('active', el.dataset.seg === key));
    }

    function clearDonutActive() {
      donutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => el.classList.remove('active'));
      donutLegend.querySelectorAll('span').forEach((el) => el.classList.remove('active'));
    }

    function showDonutTooltip(seg, evt) {
      donutTtTitle.textContent = seg.label;
      donutTtKey.innerHTML = `<i style="background:${seg.color}"></i>Share of quizzes`;
      donutTtVal.textContent = seg.value + '%';
      dcValue.textContent = seg.value + '%';
      document.querySelector('.dc-label').textContent = seg.label;
      setDonutActive(seg.key);

      donutTooltip.classList.remove('align-left', 'align-right', 'align-below');

      const areaRect = donutArea.getBoundingClientRect();
      const px = evt.clientX - areaRect.left;
      const py = evt.clientY - areaRect.top;

      const ttWidth = donutTooltip.offsetWidth || 170;
      const ttHeight = donutTooltip.offsetHeight || 60;
      const halfWidth = ttWidth / 2;
      const margin = 10;

      let finalX = px;
      if (px + halfWidth > areaRect.width - margin) {
        donutTooltip.classList.add('align-right');
        finalX = Math.min(px, areaRect.width - margin);
      } else if (px - halfWidth < margin) {
        donutTooltip.classList.add('align-left');
        finalX = Math.max(px, margin);
      }

      let finalY = py - 16;
      if (py - ttHeight - 16 < 0) {
        donutTooltip.classList.add('align-below');
        finalY = py + 16;
      }

      donutTooltip.style.left = finalX + 'px';
      donutTooltip.style.top = finalY + 'px';
      donutTooltip.classList.add('show');
    }

    function hideDonutTooltip() {
      donutTooltip.classList.remove('show');
      clearDonutActive();
      dcValue.textContent = currentDonutHasData ? currentDonutCompletion + '%' : '—';
      document.querySelector('.dc-label').textContent = currentDonutHasData ? 'Completed' : 'No students yet';
    }

    function showDonutTooltipAboveEl(seg, targetEl) {
      donutTtTitle.textContent = seg.label;
      donutTtKey.innerHTML = `<i style="background:${seg.color}"></i>Share of quizzes`;
      donutTtVal.textContent = seg.value + '%';
      dcValue.textContent = seg.value + '%';
      document.querySelector('.dc-label').textContent = seg.label;
      setDonutActive(seg.key);

      donutTooltip.classList.remove('align-left', 'align-right', 'align-below');

      const areaRect = donutArea.getBoundingClientRect();
      const elRect = targetEl.getBoundingClientRect();
      const px = (elRect.left + elRect.width / 2) - areaRect.left;
      const py = elRect.top - areaRect.top;

      const ttWidth = donutTooltip.offsetWidth || 170;
      const halfWidth = ttWidth / 2;
      const margin = 10;

      let finalX = px;
      if (px + halfWidth > areaRect.width - margin) {
        donutTooltip.classList.add('align-right');
        finalX = Math.min(px, areaRect.width - margin);
      } else if (px - halfWidth < margin) {
        donutTooltip.classList.add('align-left');
        finalX = Math.max(px, margin);
      }

      donutTooltip.style.left = finalX + 'px';
      donutTooltip.style.top = (py - 12) + 'px';
      donutTooltip.classList.add('show');
    }

    function renderDonutChart(hasData, completion) {
      currentDonutHasData = hasData;
      currentDonutCompletion = completion;
      currentDonutData = hasData
        ? [
            { key: 'completed', label: 'Completed', value: completion, color: '#2DBE78' },
            { key: 'notcompleted', label: 'Not Completed', value: 100 - completion, color: '#F6A313' }
          ]
        : [
            { key: 'nodata', label: 'No student data yet', value: 100, color: '#C7CEDA' }
          ];

      donutSegsGroup.innerHTML = '';
      donutTooltip.classList.remove('show');

      let cursor = 0;
      currentDonutData.forEach((seg) => {
        const sweep = (seg.value / 100) * 360;
        const start = cursor + GAP_DEG / 2;
        const end = cursor + sweep - GAP_DEG / 2;

        const path = document.createElementNS(svgNS, 'path');
        path.setAttribute('d', donutArcPath(start, end));
        path.setAttribute('fill', seg.color);
        path.classList.add('donut-seg');
        path.dataset.key = seg.key;
        donutSegsGroup.appendChild(path);
        cursor += sweep;
      });

      dcValue.textContent = hasData ? completion + '%' : '—';
      document.querySelector('.dc-label').textContent = hasData ? 'Completed' : 'No students yet';

      donutLegend.innerHTML = hasData
        ? `<span data-seg="completed" style="color:#2DBE78"><i class="dot"></i> Completed</span>
           <span data-seg="notcompleted" style="color:#F6A313"><i class="dot orange"></i> Not Completed</span>`
        : `<span data-seg="nodata" style="color:#9AA5B5"><i class="dot" style="background:#C7CEDA"></i> No student data yet</span>`;

      donutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => {
        const seg = currentDonutData.find((d) => d.key === el.dataset.key);
        el.addEventListener('mouseenter', (e) => showDonutTooltip(seg, e));
        el.addEventListener('mousemove', (e) => showDonutTooltip(seg, e));
        el.addEventListener('mouseleave', hideDonutTooltip);
      });

      donutLegend.querySelectorAll('span').forEach((legendItem) => {
        const seg = currentDonutData.find((d) => d.key === legendItem.dataset.seg);
        legendItem.addEventListener('mouseenter', () => showDonutTooltipAboveEl(seg, legendItem));
        legendItem.addEventListener('mouseleave', hideDonutTooltip);
      });
    }

    renderDonutChart(INITIAL_DONUT_HAS_DATA, INITIAL_DONUT_COMPLETION);

    const INITIAL_RECENT_ACTIVITY = <?= json_encode(array_map(function ($row) {
        return [
            'student_name' => $row['student_name'],
            'quiz_title' => $row['quiz_title'],
            'percentage' => $row['percentage'],
            'attempt_date' => $row['attempt_date'],
        ];
    }, $recentActivity)) ?>;

    const INITIAL_TOP_STUDENTS = <?= json_encode(array_map(function ($row) {
        return [
            'student_name' => $row['student_name'],
            'quiz_title' => $row['quiz_title'],
            'percentage' => $row['percentage'],
        ];
    }, $topStudents)) ?>;

    const recentActivityBody = document.getElementById('recentActivityBody');
    const topStudentsList = document.getElementById('topStudentsList');

    function renderRecentActivity(rows, emptyMessage) {
      recentActivityBody.innerHTML = '';
      if (!rows || rows.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 4;
        td.textContent = emptyMessage || 'No quiz submissions have been recorded yet.';
        tr.appendChild(td);
        recentActivityBody.appendChild(tr);
        return;
      }
      rows.forEach((row) => {
        const tr = document.createElement('tr');

        const nameTd = document.createElement('td');
        nameTd.textContent = row.student_name;
        tr.appendChild(nameTd);

        const quizTd = document.createElement('td');
        quizTd.textContent = row.quiz_title;
        tr.appendChild(quizTd);

        const scoreTd = document.createElement('td');
        scoreTd.dataset.score = row.percentage;
        scoreTd.textContent = row.percentage + '%';
        tr.appendChild(scoreTd);

        const dateTd = document.createElement('td');
        dateTd.textContent = row.attempt_date;
        tr.appendChild(dateTd);

        recentActivityBody.appendChild(tr);
      });
    }

    function renderTopStudents(rows, emptyMessage) {
      topStudentsList.innerHTML = '';
      if (!rows || rows.length === 0) {
        const row = document.createElement('div');
        row.className = 'student-row';
        row.innerHTML = `<div class="rank">•</div>
          <div>
            <div class="student-name">No result data yet</div>
            <div class="student-meta">${emptyMessage || 'Student quiz results will appear here once available.'}</div>
          </div>`;
        topStudentsList.appendChild(row);
        return;
      }
      rows.forEach((student, index) => {
        const row = document.createElement('div');
        row.className = 'student-row';

        const rank = document.createElement('div');
        rank.className = 'rank';
        rank.textContent = index + 1;
        row.appendChild(rank);

        const info = document.createElement('div');
        const nameDiv = document.createElement('div');
        nameDiv.className = 'student-name';
        nameDiv.textContent = student.student_name;
        const metaDiv = document.createElement('div');
        metaDiv.className = 'student-meta';
        metaDiv.textContent = student.quiz_title;
        info.appendChild(nameDiv);
        info.appendChild(metaDiv);
        row.appendChild(info);

        const pill = document.createElement('div');
        pill.className = 'score-pill';
        pill.textContent = student.percentage + '%';
        row.appendChild(pill);

        topStudentsList.appendChild(row);
      });
    }

    const INITIAL_MATERIALS = <?= json_encode($materials) ?>;
    const INITIAL_MATERIALS_COUNT = <?= (int) $statMaterialsCount ?>;
    const materialIconSvg = '<div class="material-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z" /><path d="M14 2v5h5" /><path d="M10 13h4" /><path d="M12 11v6" /></svg></div>';
    const materialsSnapshotCountEl = document.getElementById('materialsSnapshotCount');
    const materialsSnapshotListEl = document.getElementById('materialsSnapshotList');

    function renderMaterialsSnapshot(rows, count, emptyMessage) {
      materialsSnapshotCountEl.textContent = count + ' resource' + (count === 1 ? '' : 's') + ' uploaded';
      materialsSnapshotListEl.innerHTML = '';

      if (!rows || rows.length === 0) {
        const item = document.createElement('div');
        item.className = 'material-item';
        item.style.cursor = 'default';
        item.innerHTML = materialIconSvg;
        const info = document.createElement('div');
        const title = document.createElement('div');
        title.className = 'material-title';
        title.textContent = 'No materials uploaded yet';
        const meta = document.createElement('div');
        meta.className = 'material-meta';
        meta.textContent = emptyMessage || 'Upload learning resources to populate this list.';
        info.appendChild(title);
        info.appendChild(meta);
        item.appendChild(info);
        materialsSnapshotListEl.appendChild(item);
        return;
      }

      rows.forEach((material) => {
        const link = document.createElement('a');
        link.className = 'material-item';
        link.href = `TLearningMaterials.php?view=${encodeURIComponent(material.material_id)}`;
        link.innerHTML = materialIconSvg;
        const info = document.createElement('div');
        const title = document.createElement('div');
        title.className = 'material-title';
        title.textContent = material.title;
        const meta = document.createElement('div');
        meta.className = 'material-meta';
        meta.textContent = `${material.material_type} · ${material.upload_date}`;
        info.appendChild(title);
        info.appendChild(meta);
        link.appendChild(info);
        materialsSnapshotListEl.appendChild(link);
      });
    }

    renderRecentActivity(INITIAL_RECENT_ACTIVITY, 'No quiz submissions have been recorded yet.');
    renderTopStudents(INITIAL_TOP_STUDENTS, 'Student quiz results will appear here once available.');
    renderMaterialsSnapshot(INITIAL_MATERIALS, INITIAL_MATERIALS_COUNT, 'Upload learning resources to populate this list.');

    const INITIAL_STATS = <?= json_encode([
        'totalStudents' => $studentCount,
        'totalQuizzes' => $statTotalQuizzes,
        'avgScore' => $averageScore,
        'studentsChangeText' => $statsChange['studentsChangeText'],
        'studentsChangeDirection' => $statsChange['studentsChangeDirection'],
        'quizzesChangeText' => $statsChange['quizzesChangeText'],
        'quizzesChangeDirection' => $statsChange['quizzesChangeDirection'],
        'avgScoreChangeText' => $statsChange['avgScoreChangeText'],
        'avgScoreChangeDirection' => $statsChange['avgScoreChangeDirection'],
    ]) ?>;

    const statTotalStudentsEl = document.getElementById('statTotalStudents');
    const statTotalStudentsChangeEl = document.getElementById('statTotalStudentsChange');
    const statTotalQuizzesEl = document.getElementById('statTotalQuizzes');
    const statTotalQuizzesChangeEl = document.getElementById('statTotalQuizzesChange');
    const statAvgScoreEl = document.getElementById('statAvgScore');
    const statAvgScoreChangeEl = document.getElementById('statAvgScoreChange');

    function statChangeArrow(direction) {
      if (direction === 'positive') return '↗ ';
      if (direction === 'negative') return '↘ ';
      return '';
    }

    function applyStatChangeClass(el, direction) {
      el.classList.remove('negative', 'neutral');
      if (direction === 'negative') el.classList.add('negative');
      else if (direction === 'neutral') el.classList.add('neutral');
    }

    function renderStatCards(stats) {
      statTotalStudentsEl.textContent = stats.totalStudents;
      statTotalStudentsChangeEl.textContent = statChangeArrow(stats.studentsChangeDirection) + stats.studentsChangeText;
      applyStatChangeClass(statTotalStudentsChangeEl, stats.studentsChangeDirection);

      statTotalQuizzesEl.textContent = stats.totalQuizzes;
      statTotalQuizzesChangeEl.textContent = statChangeArrow(stats.quizzesChangeDirection) + stats.quizzesChangeText;
      applyStatChangeClass(statTotalQuizzesChangeEl, stats.quizzesChangeDirection);

      statAvgScoreEl.textContent = stats.avgScore + '%';
      statAvgScoreChangeEl.textContent = statChangeArrow(stats.avgScoreChangeDirection) + stats.avgScoreChangeText;
      applyStatChangeClass(statAvgScoreChangeEl, stats.avgScoreChangeDirection);
    }

    const dashFilterSubject = document.getElementById('dashFilterSubject');
    const dashFilterClass = document.getElementById('dashFilterClass');
    const dashFilterClearBtn = document.getElementById('dashFilterClear');
    let dashFilterRequestId = 0;

    async function applyDashboardFilter() {
      const subjectId = dashFilterSubject.value;
      const classId = dashFilterClass.value;
      const hasFilter = Boolean(subjectId || classId);
      dashFilterClearBtn.hidden = !hasFilter;

      if (!hasFilter) {
        renderStatCards(INITIAL_STATS);
        renderQuizPerfChart(INITIAL_QUIZ_PERF_DATA, 'Create a quiz to start tracking performance here.');
        renderDonutChart(INITIAL_DONUT_HAS_DATA, INITIAL_DONUT_COMPLETION);
        renderRecentActivity(INITIAL_RECENT_ACTIVITY, 'No quiz submissions have been recorded yet.');
        renderTopStudents(INITIAL_TOP_STUDENTS, 'Student quiz results will appear here once available.');
        renderMaterialsSnapshot(INITIAL_MATERIALS, INITIAL_MATERIALS_COUNT, 'Upload learning resources to populate this list.');
        return;
      }

      const requestId = ++dashFilterRequestId;
      const params = new URLSearchParams({ feed: 'dashboard_filter' });
      if (subjectId) params.set('subject_id', subjectId);
      if (classId) params.set('class_id', classId);

      let data;
      try {
        const res = await fetch(`TDashboard.php?${params.toString()}`, { credentials: 'same-origin' });
        data = await res.json();
      } catch (err) {
        showToastLocal('Unable to load filtered dashboard data.');
        return;
      }
      if (requestId !== dashFilterRequestId) return; 

      const safeRender = (label, fn) => {
        try {
          fn();
        } catch (err) {
          console.error(`Dashboard filter: failed to render ${label}`, err);
        }
      };

      safeRender('stat cards', () => renderStatCards(data));
      safeRender('quiz performance chart', () => renderQuizPerfChart(data.quizPerformance || [], 'No quizzes match this filter.'));
      safeRender('quiz completion donut', () => renderDonutChart(!!data.hasCompletionData, Number(data.completionPercent) || 0));
      safeRender('recent activity', () => renderRecentActivity(data.recentActivity || [], 'No quiz submissions match this filter.'));
      safeRender('top performing students', () => renderTopStudents(data.topStudents || [], 'No student results match this filter.'));
      safeRender('materials snapshot', () => renderMaterialsSnapshot(data.materials || [], Number(data.materialsCount) || 0, 'No materials match this filter.'));
    }

    dashFilterSubject?.addEventListener('change', applyDashboardFilter);
    dashFilterClass?.addEventListener('change', applyDashboardFilter);
    dashFilterClearBtn?.addEventListener('click', () => {
      dashFilterSubject.value = '';
      dashFilterClass.value = '';
      applyDashboardFilter();
    });

    const uploadMaterialButton = document.getElementById('uploadMaterialButton');
    const uploadModal = document.getElementById('uploadModal');
    const toastMsg = document.getElementById('toastMsg');

    function setModalVisibility(modal, visible) {
      if (!modal) return;
      modal.classList.toggle('show', visible);
      modal.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function closeAllPanels() {
      setModalVisibility(uploadModal, false);
      document.getElementById('notificationPanel')?.classList.remove('show');
    }

    function showToastLocal(message) {
      if (window.showToast) window.showToast(message);
      else if (toastMsg) {
        toastMsg.textContent = message;
        toastMsg.classList.add('show');
        setTimeout(() => toastMsg.classList.remove('show'), 2200);
      }
    }

    document.querySelectorAll('[data-close="true"]').forEach((button) => {
      button.addEventListener('click', () => closeAllPanels());
    });

    [uploadModal].forEach((modal) => {
      if (!modal) return;
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeAllPanels();
        }
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeAllPanels();
      }
    });

    const quizForm = document.getElementById('quizForm');
    if (quizForm) {
      quizForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const subject = document.getElementById('quizSubject').value.trim();
        const question = document.getElementById('quizQuestion').value.trim();
        const options = [
          document.getElementById('optionA').value.trim(),
          document.getElementById('optionB').value.trim(),
          document.getElementById('optionC').value.trim(),
          document.getElementById('optionD').value.trim(),
        ];

        if (!subject || !question || options.some((value) => !value)) {
          showToastLocal('Please complete all questions and options');
          return;
        }

        closeAllPanels();
        showToastLocal(`Published ${subject} Quiz: ${question.slice(0, 40)}…`);
        quizForm.reset();
      });
    }

    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
      uploadForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const subject = document.getElementById('materialSubject').value.trim();
        const title = document.getElementById('materialTitle').value.trim();
        const file = document.getElementById('materialFile').files[0];

        if (!subject || !title || !file) {
          showToastLocal('Please upload a file and fill in all required fields');
          return;
        }
        if (file.size > 10 * 1024 * 1024) {
          showToastLocal('File too large. Please choose a file smaller than 10MB.');
          return;
        }

        closeAllPanels();
        showToastLocal(`Uploaded ${subject} Material：${title}`);
        uploadForm.reset();
      });
    }
  </script>
</body>
</html>