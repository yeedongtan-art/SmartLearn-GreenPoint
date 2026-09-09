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

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function profileAvatarStyle($path) {
    if (empty($path)) {
        return '';
    }
    return "background-image: url('" . e($path) . "'); background-size: cover; background-position: center;";
}

function getClassPerformanceFilteredData(mysqli $conn, int $userId, ?int $subjectId, ?int $classId): array {
    $subjectClauseQ = $subjectId !== null ? " AND q.subject_id = {$subjectId}" : '';
    $subjectClauseQz = $subjectId !== null ? " AND qz.subject_id = {$subjectId}" : '';
    $classSubquery = $classId !== null ? "(SELECT user_id FROM classes WHERE class_id = {$classId} AND subject_id IS NULL)" : null;
    $classClauseR = $classSubquery !== null ? " AND r.user_id IN {$classSubquery}" : '';

    $data = [
        'stats' => [
            'average_score' => 0,
            'top_performer' => 'N/A',
            'top_performer_score' => 0,
            'lowest_topic' => 'N/A',
            'lowest_topic_score' => 0,
        ],
        'quizPerformance' => [],
        'gradeDistribution' => ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0],
        'recentSubmissions' => [],
        'weakTopics' => [],
    ];

    $scoreQuery = "SELECT (r.score / r.total_questions * 100) AS percentage, u.user_id, u.name, q.quiz_id, q.quiz_title, q.subject_id, s.subject_name, r.attempt_date
        FROM result r
        JOIN users u ON r.user_id = u.user_id
        JOIN quiz q ON r.quiz_id = q.quiz_id
        LEFT JOIN subject s ON q.subject_id = s.subject_id
        WHERE u.role = 'Student' AND q.user_id = {$userId}{$subjectClauseQ}{$classClauseR}
        ORDER BY r.attempt_date DESC";

    $scoreResult = mysqli_query($conn, $scoreQuery);
    $scoreRows = [];
    if ($scoreResult) {
        while ($row = mysqli_fetch_assoc($scoreResult)) {
            $scoreRows[] = $row;
        }
    }

    $subjectPerformances = [];

    if (!empty($scoreRows)) {
        $total = 0;
        $count = 0;
        $studentScores = [];
        $subjectGroups = [];
        $quizGroups = [];

        foreach ($scoreRows as $row) {
            $percentage = isset($row['percentage']) ? (float) $row['percentage'] : 0;
            $total += $percentage;
            $count++;

            $studentId = (int) $row['user_id'];
            $name = trim($row['name'] ?: 'Unknown Student');
            $subjectName = $row['subject_name'] ?: 'General';
            $quizTitle = $row['quiz_title'] ?: 'Quiz';
            $attemptDate = $row['attempt_date'];

            if (!isset($studentScores[$studentId])) {
                $studentScores[$studentId] = ['name' => $name, 'total' => 0, 'count' => 0];
            }
            $studentScores[$studentId]['total'] += $percentage;
            $studentScores[$studentId]['count']++;

            if (!isset($subjectGroups[$subjectName])) {
                $subjectGroups[$subjectName] = ['total' => 0, 'count' => 0, 'top_score' => 0];
            }
            $subjectGroups[$subjectName]['total'] += $percentage;
            $subjectGroups[$subjectName]['count']++;
            $subjectGroups[$subjectName]['top_score'] = max($subjectGroups[$subjectName]['top_score'], $percentage);

            if (!isset($quizGroups[$quizTitle])) {
                $quizGroups[$quizTitle] = ['total' => 0, 'count' => 0, 'top_score' => 0];
            }
            $quizGroups[$quizTitle]['total'] += $percentage;
            $quizGroups[$quizTitle]['count']++;
            $quizGroups[$quizTitle]['top_score'] = max($quizGroups[$quizTitle]['top_score'], $percentage);

            $data['recentSubmissions'][] = [
                'student' => $name,
                'quiz' => $quizTitle,
                'score' => (int) round($percentage),
                'date' => substr($attemptDate, 0, 10),
            ];

            $grade = 'd';
            if ($percentage >= 90) {
                $grade = 'a';
            } elseif ($percentage >= 75) {
                $grade = 'b';
            } elseif ($percentage >= 60) {
                $grade = 'c';
            }
            $data['gradeDistribution'][$grade]++;
        }

        $data['stats']['average_score'] = $count > 0 ? round($total / $count) : 0;

        foreach ($studentScores as $studentId => $studentScore) {
            $studentScores[$studentId]['average'] = $studentScore['count'] ? $studentScore['total'] / $studentScore['count'] : 0;
        }
        usort($studentScores, function ($a, $b) {
            return $b['average'] <=> $a['average'];
        });
        if (!empty($studentScores)) {
            $data['stats']['top_performer'] = $studentScores[0]['name'];
            $data['stats']['top_performer_score'] = (int) round($studentScores[0]['average']);
        }

        foreach ($subjectGroups as $subjectName => $subjectData) {
            $avg = $subjectData['count'] ? round($subjectData['total'] / $subjectData['count']) : 0;
            $subjectPerformances[] = [
                'subject_name' => $subjectName,
                'avg' => $avg,
                'top_score' => (int) round($subjectData['top_score']),
            ];
        }

        foreach ($quizGroups as $quizTitle => $quizData) {
            $avg = $quizData['count'] ? round($quizData['total'] / $quizData['count']) : 0;
            $data['quizPerformance'][] = [
                'title' => $quizTitle,
                'avg' => $avg,
                'topScore' => (int) round($quizData['top_score']),
                'attempts' => $quizData['count'],
            ];
        }
        usort($data['quizPerformance'], function ($a, $b) {
            return strcmp($a['title'], $b['title']);
        });

        if (!empty($subjectPerformances)) {
            usort($subjectPerformances, function ($a, $b) {
                return $a['avg'] <=> $b['avg'];
            });
            $data['stats']['lowest_topic'] = $subjectPerformances[0]['subject_name'];
            $data['stats']['lowest_topic_score'] = $subjectPerformances[0]['avg'];
        }
    }

    $topicQuery = "SELECT t.topic_id, t.topic_name, s.subject_name,
        COUNT(DISTINCT r.user_id) AS students_attempted,
        COUNT(DISTINCT CASE WHEN UPPER(sa.student_answer) <> UPPER(q.correct_answer) THEN r.user_id END) AS students_struggling,
        AVG(IF(UPPER(sa.student_answer) = UPPER(q.correct_answer), 100, 0)) AS avg_score
        FROM student_answer sa
        JOIN question q ON sa.question_id = q.question_id
        JOIN quiz qz ON q.quiz_id = qz.quiz_id
        JOIN result r ON sa.result_id = r.result_id
        JOIN topic t ON q.topic_id = t.topic_id
        LEFT JOIN subject s ON t.subject_id = s.subject_id
        WHERE qz.user_id = {$userId}{$subjectClauseQz}{$classClauseR}
        GROUP BY t.topic_id, t.topic_name, s.subject_name
        HAVING students_attempted >= 1
        ORDER BY avg_score ASC, students_struggling DESC
        LIMIT 5";

    $topicResult = mysqli_query($conn, $topicQuery);
    if ($topicResult) {
        while ($row = mysqli_fetch_assoc($topicResult)) {
            $avg = isset($row['avg_score']) ? (int) round($row['avg_score']) : 0;
            $attempted = (int) ($row['students_attempted'] ?? 0);
            $struggling = (int) ($row['students_struggling'] ?? 0);
            $strugglingRate = $attempted > 0 ? $struggling / $attempted : 0;

            if ($avg < 50 || $strugglingRate >= 0.6) {
                $concern = 'high';
            } elseif ($avg < 70 || $strugglingRate >= 0.35) {
                $concern = 'medium';
            } else {
                $concern = 'low';
            }

            $topicId = (int) ($row['topic_id'] ?? 0);

            $data['weakTopics'][] = [
                'topic_name' => $row['topic_name'] ?: 'Unknown Topic',
                'subject_name' => $row['subject_name'] ?: 'Unknown Subject',
                'avg_score' => $avg,
                'attempted' => $attempted,
                'struggling' => $struggling,
                'struggling_pct' => (int) round($strugglingRate * 100),
                'difficulty' => 'difficulty-' . $concern,
                'concern_label' => ucfirst($concern),
                'material_href' => 'TLearningMaterials.php' . ($topicId ? '?topic_id=' . $topicId : ''),
                'quiz_href' => 'TCreateQuiz.php' . ($topicId ? '?topic_id=' . $topicId : ''),
            ];
        }
    }

    return $data;
}

$stats = [
    'average_score' => 0,
    'top_performer' => 'N/A',
    'top_performer_score' => 0,
    'lowest_topic' => 'N/A',
    'lowest_topic_score' => 0,
];
$quizPerformances = [];
$gradeDistribution = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
$recentSubmissions = [];
$weakTopics = [];
$subjectOptions = [];
$classOptions = [];

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

    if (isset($_GET['feed']) && $_GET['feed'] === 'class_performance_filter') {
        header('Content-Type: application/json');
        $filterSubjectId = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int) $_GET['subject_id'] : null;
        $filterClassId = (isset($_GET['class_id']) && $_GET['class_id'] !== '') ? (int) $_GET['class_id'] : null;
        $filtered = getClassPerformanceFilteredData($conn, (int) $userId, $filterSubjectId, $filterClassId);
        echo json_encode($filtered, JSON_UNESCAPED_UNICODE);
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

    $subjectOptionsResult = mysqli_query($conn, "SELECT DISTINCT s.subject_id, s.subject_name FROM classes cl JOIN subject s ON cl.subject_id = s.subject_id WHERE cl.user_id = {$userId} ORDER BY s.subject_name ASC");
    if ($subjectOptionsResult) {
        $subjectOptions = mysqli_fetch_all($subjectOptionsResult, MYSQLI_ASSOC);
    }

    $classOptionsResult = mysqli_query($conn, "SELECT DISTINCT c.class_id, c.class_name, c.year FROM classes cl JOIN class c ON cl.class_id = c.class_id WHERE cl.user_id = {$userId} AND cl.subject_id IS NOT NULL ORDER BY c.class_name ASC, c.year ASC");
    if ($classOptionsResult) {
        $classOptions = mysqli_fetch_all($classOptionsResult, MYSQLI_ASSOC);
    }

    if (!empty($subjectOptions)) {
        $classLabel = implode(', ', array_column($subjectOptions, 'subject_name'));
    }

    $initial = getClassPerformanceFilteredData($conn, (int) $userId, null, null);
    $stats = $initial['stats'];
    $quizPerformances = $initial['quizPerformance'];
    $gradeDistribution = $initial['gradeDistribution'];
    $recentSubmissions = $initial['recentSubmissions'];
    $weakTopics = $initial['weakTopics'];

    mysqli_close($conn);
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Analytics - SmartLearn Teacher Portal</title>
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
      --yellow: #F2C230;
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

    .stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 36px;
    }

    .stat-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 28px;
      box-shadow: var(--shadow);
      transition: all 0.3s;
    }

    .stat-card:hover {
      border-color: var(--sky);
      box-shadow: 0 12px 28px rgba(88, 96, 138, 0.18);
      transform: translateY(-2px);
    }

    .stat-label {
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin-bottom: 16px;
    }

    .stat-icon {
      width: 44px;
      height: 44px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      flex: 0 0 auto;
    }

    .stat-icon.blue {
      background: rgba(14, 128, 215, 0.15);
      color: var(--blue);
    }

    .stat-icon.green {
      background: rgba(45, 190, 120, 0.15);
      color: #1E9C64;
    }

    .stat-icon.orange {
      background: rgba(246, 163, 19, 0.15);
      color: #7C560A;
    }

    .stat-icon svg {
      width: 24px;
      height: 24px;
      stroke-width: 2;
    }

    .stat-value {
      font-size: 36px;
      font-weight: 900;
      color: var(--text);
      margin-bottom: 4px;
    }

    .stat-desc {
      font-size: 14px;
      color: var(--muted);
    }

    .charts-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-bottom: 36px;
    }

    .chart-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 28px 30px;
      box-shadow: var(--shadow);
    }

    .chart-card h3 {
      margin: 0 0 20px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .chart-wrapper {
      width: 100%;
      display: flex;
      align-items: flex-end;
      justify-content: space-around;
      gap: 16px;
      min-height: 280px;
      position: relative;
    }

    .subject-bars {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      flex: 1;
      justify-content: center;
    }

    .bar-group {
      display: flex;
      align-items: flex-end;
      gap: 6px;
    }

    .bar {
      width: 32px;
      border-radius: 6px 6px 0 0;
      transition: all 0.2s;
      cursor: pointer;
    }

    .bar.class-avg {
      background: linear-gradient(180deg, #0E80D7, #3298D5);
    }

    .bar.top-score {
      background: linear-gradient(180deg, #2DBE78, #1E9C64);
    }

    .subject-label {
      text-align: center;
      font-size: 13px;
      color: var(--muted);
      font-weight: 600;
      margin-top: 12px;
      width: 80px;
    }

    .bar:hover {
      filter: brightness(1.15);
      transform: translateY(-4px);
    }

    .chart-legend {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 24px;
      margin-top: 20px;
      padding-top: 16px;
      border-top: 1px solid var(--line);
      font-size: 14px;
      font-weight: 700;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .legend-dot {
      width: 12px;
      height: 12px;
      border-radius: 3px;
    }

    .legend-dot.blue {
      background: #0E80D7;
    }

    .legend-dot.green {
      background: #2DBE78;
    }

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
      display: inline-block;
      flex: 0 0 auto;
    }

    .weak-topics-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 28px 30px;
      box-shadow: var(--shadow);
    }

    .weak-topics-card h3 {
      margin: 0 0 8px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .weak-topics-card p {
      margin: 0 0 24px 0;
      color: var(--muted);
      font-size: 14px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }

    thead {
      background: linear-gradient(90deg, rgba(202, 223, 242, 0.4), rgba(150, 185, 217, 0.2));
      border-bottom: 1px solid var(--line);
    }

    th {
      padding: 14px 16px;
      text-align: left;
      font-weight: 700;
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    th.text-center {
      text-align: center;
    }

    tbody tr {
      border-bottom: 1px solid var(--line);
      transition: background-color 0.2s;
    }

    tbody tr:last-child {
      border-bottom: none;
    }

    tbody tr:hover {
      background-color: rgba(202, 223, 242, 0.12);
    }

    td {
      padding: 16px;
      color: var(--text);
    }

    .topic-name {
      font-weight: 700;
      color: var(--text);
    }

    .subject-name {
      color: var(--blue);
      font-weight: 600;
    }

    .score-bar-container {
      width: 100%;
      max-width: 180px;
    }

    .score-bar-bg {
      height: 8px;
      background: #E7EEF5;
      border-radius: 999px;
      overflow: hidden;
      margin-bottom: 6px;
    }

    .score-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #0E80D7, #3298D5);
      border-radius: 999px;
    }

    .score-text {
      font-size: 13px;
      font-weight: 700;
      color: var(--text);
    }

    .struggling-count {
      display: flex;
      align-items: center;
      gap: 6px;
      color: var(--red);
      font-weight: 700;
    }

    .difficulty-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 70px;
      height: 28px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      text-transform: capitalize;
    }

    .difficulty-high {
      background: rgba(238, 61, 89, 0.15);
      color: #C41E3A;
    }

    .difficulty-medium {
      background: rgba(246, 163, 19, 0.16);
      color: #B4740A;
    }

    .difficulty-low {
      background: rgba(45, 190, 120, 0.15);
      color: #1E9C64;
    }

    .action-text {
      color: var(--muted);
      font-size: 13px;
      font-weight: 600;
    }

    .action-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--blue);
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      white-space: nowrap;
    }

    .action-btn:hover {
      text-decoration: underline;
    }

    .action-btn svg {
      width: 14px;
      height: 14px;
      stroke-width: 2.4;
    }

    .action-links {
      display: flex;
      flex-direction: column;
      gap: 6px;
      align-items: flex-start;
    }

    .weak-topics-empty {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--muted);
      font-size: 14px;
      padding: 18px 4px 4px;
    }

    .weak-topics-note {
      margin: -8px 0 18px !important;
      font-size: 12px !important;
      color: var(--muted);
    }

    @media (max-width: 1400px) {
      .stats {
        grid-template-columns: 1fr;
      }

      .charts-grid {
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

      .stats {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .chart-wrapper {
        gap: 8px;
      }

      .subject-label {
        font-size: 12px;
        width: 70px;
      }

      table {
        font-size: 12px;
      }

      th,
      td {
        padding: 10px 12px;
      }

      .score-bar-container {
        max-width: 100px;
      }
    }

    @media (max-width: 640px) {
      .content {
        padding: 16px;
      }

      .header h2 {
        font-size: 20px;
      }

      .stats {
        grid-template-columns: 1fr;
      }

      .stat-card {
        padding: 16px;
      }
    }

    .dash-filter-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 28px;
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
      background-color: var(--surface);
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

    .chart-tooltip.align-below {
      transform: translate(-50%, 0);
    }

    .chart-tooltip.align-below.align-right {
      transform: translate(calc(-100% + 14px), 0);
    }

    .chart-tooltip.align-below.align-left {
      transform: translate(-14px, 0);
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

    .chart-card {
      position: relative;
    }
  </style>
</head>
<body data-page="analytics">
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <img class="brand-logo" src="smartlearn_logo_teacher.png" alt="SmartLearn Teacher Portal" />
      </div>

      <nav class="nav" aria-label="Main navigation">
        <a href="TDashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" /></svg><span>Dashboard</span></a>
        <a href="TProfile.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg><span>Profile</span></a>
        <a href="TCreateQuiz.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14" /><path d="M5 12h14" /></svg><span>Create Quiz</span></a>
        <a href="TQuizManagement.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" /></svg><span>Manage Quiz</span></a>
        <a href="TLearningMaterials.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z" /></svg><span>Upload Materials</span></a>
        <a href="TClassPerformance.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M7 16V9" /><path d="M12 16V5" /><path d="M17 16v-3" /></svg><span>Analytics</span></a>
        <a href="TStudentAnalytic.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg><span>Student View</span></a>
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
          <div><strong><?= e($teacherName) ?></strong><span><?= e($classLabel) ?></span></div>
        </div>
      </header>

      <section class="content">
<div class="header">
          <h2>Analytics Dashboard</h2>
          <p>Student performance, class averages, and weak topics analysis.</p>
        </div>

        <section class="stats" aria-label="Performance statistics">
          <article class="stat-card">
            <div class="stat-label">
              <div class="stat-icon blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="6" /><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" /></svg>
              </div>
              AVG. CLASS MARKS
            </div>
            <div class="stat-value" id="cpStatAvgValue"><?= e($stats['average_score']) ?>%</div>
            <div class="stat-desc" id="cpStatAvgDesc">Across all quizzes</div>
          </article>

          <article class="stat-card">
            <div class="stat-label">
              <div class="stat-icon green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /></svg>
              </div>
              TOP PERFORMER
            </div>
            <div class="stat-value" id="cpStatTopValue" style="font-size: 26px;"><?= e($stats['top_performer']) ?></div>
            <div class="stat-desc" id="cpStatTopDesc"><?= e($stats['top_performer_score']) ?>% average score</div>
          </article>

          <article class="stat-card">
            <div class="stat-label">
              <div class="stat-icon orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" /></svg>
              </div>
              LOWEST SUBJECT AVG
            </div>
            <div class="stat-value" id="cpStatLowValue" style="font-size: 26px;"><?= e($stats['lowest_topic']) ?></div>
            <div class="stat-desc" id="cpStatLowDesc"><?= e($stats['lowest_topic_score']) ?>% average score</div>
          </article>
        </section>

        <div class="dash-filter-bar" id="cpFilterBar">
          <div class="dash-filter-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3Z" /></svg>
            Filter Class Marks, Top Performer, Lowest Subject, Quiz Performance, Grade Distribution, Student Performance &amp; Weak Topics
          </div>
          <div class="dash-filter-controls">
            <select class="form-select dash-filter-select" id="cpFilterSubject" aria-label="Filter by subject">
              <option value="">All Subjects</option>
              <?php foreach ($subjectOptions as $subjectOption): ?>
                <option value="<?= (int) $subjectOption['subject_id'] ?>"><?= e($subjectOption['subject_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="form-select dash-filter-select" id="cpFilterClass" aria-label="Filter by class">
              <option value="">All Classes</option>
              <?php foreach ($classOptions as $classOption): ?>
                <option value="<?= (int) $classOption['class_id'] ?>"><?= e($classOption['class_name']) ?> (Year <?= (int) $classOption['year'] ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="dash-filter-clear" id="cpFilterClear" hidden>Clear</button>
          </div>
        </div>

        <section class="charts-grid">
          <article class="chart-card" id="quizPerfCompPanel">
            <h3>Quiz Performance Comparison</h3>
            <p class="section-subtitle" style="margin:-10px 0 20px;color:var(--muted);">Class average vs. top score, per quiz — more actionable than a 2-subject comparison</p>

            <div class="empty-chart-state" id="quizPerfCompEmpty" style="<?= empty($quizPerformances) ? '' : 'display:none;' ?>">
              <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18" /><path d="M18 17V9" /><path d="M13 17V5" /><path d="M8 17v-3" /></svg>
              <p id="quizPerfCompEmptyText">No quiz attempts to compare yet.</p>
            </div>

            <div class="chart-wrap" id="quizPerfCompChartWrap" style="<?= empty($quizPerformances) ? 'display:none;' : '' ?>">
              <svg class="bar-chart" id="quizPerfCompChart" viewBox="0 0 920 310" role="img" aria-label="Class average vs top score per quiz" preserveAspectRatio="none">
                <defs>
                  <linearGradient id="perfAvgGradient" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#0E80D7" />
                    <stop offset="100%" stop-color="#3298D5" />
                  </linearGradient>
                  <linearGradient id="perfTopGradient" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#2DBE78" />
                    <stop offset="100%" stop-color="#1E9C64" />
                  </linearGradient>
                </defs>
                <g id="quizPerfCompGrid"></g>
                <g id="quizPerfCompBars"></g>
                <g id="quizPerfCompAxis"></g>
              </svg>
            </div>

            <div class="chart-tooltip" id="quizPerfCompTooltip">
              <div class="tt-title" id="quizPerfCompTtTitle">Quiz</div>
              <div class="tt-row">
                <span class="tt-key"><i style="background:#0E80D7"></i>Class average</span>
                <span class="tt-val" id="quizPerfCompTtAvg">0%</span>
              </div>
              <div class="tt-row">
                <span class="tt-key"><i style="background:#2DBE78"></i>Top score</span>
                <span class="tt-val" id="quizPerfCompTtTop">0%</span>
              </div>
              <div class="tt-row">
                <span class="tt-key"><i style="background:#8795A8"></i>Students attempted</span>
                <span class="tt-val" id="quizPerfCompTtAttempts">0</span>
              </div>
            </div>

            <div class="chart-legend" id="quizPerfCompLegend" style="<?= empty($quizPerformances) ? 'display:none;' : '' ?>">
              <div class="legend-item">
                <div class="legend-dot blue"></div>
                Class Avg
              </div>
              <div class="legend-item">
                <div class="legend-dot green"></div>
                Top Score
              </div>
            </div>
          </article>

          <article class="chart-card" id="gradeDistPanel">
            <h3 style="margin:0;">Grade Distribution</h3>
            <p style="margin:4px 0 0;color:var(--muted);font-size:15px;">Across all quiz attempts</p>
            <div class="donut-area" id="gradeDonutArea">
              <svg class="donut-svg" id="gradeDonutSvg" viewBox="0 0 250 250" aria-label="Grade distribution donut chart">

                <g id="gradeDonutSegs"></g>
              </svg>
              <div class="donut-center-label" id="gradeDonutCenterLabel">
                <div class="dc-value" id="gradeDcValue">—</div>
                <div class="dc-label" id="gradeDcLabel">No attempts yet</div>
              </div>
              <div class="chart-tooltip" id="gradeDonutTooltip">
                <div class="tt-title" id="gradeDonutTtTitle">A (90+)</div>
                <div class="tt-row">
                  <span class="tt-key" id="gradeDonutTtKey"><i style="background:#2DBE78"></i>Share of attempts</span>
                  <span class="tt-val" id="gradeDonutTtVal">0%</span>
                </div>
              </div>
            </div>
            <div class="legend" id="gradeDonutLegend"></div>
          </article>
        </section>

        <article class="weak-topics-card" style="margin-bottom: 28px;">
          <h3>Student Performance</h3>
          <p>Recent quiz submissions across all classes</p>
          <div class="empty-chart-state" id="studentPerfEmpty" style="<?= empty($recentSubmissions) ? '' : 'display:none;' ?>min-height:120px;">
            <p id="studentPerfEmptyText">No submissions match this filter.</p>
          </div>
          <table class="recent-activity-table" id="studentPerfTable" style="<?= empty($recentSubmissions) ? 'display:none;' : '' ?>">
            <thead>
              <tr>
                <th>Student Name</th>
                <th>Quiz</th>
                <th>Score</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody id="studentPerfBody">
              <?php foreach ($recentSubmissions as $row): ?>
                <tr>
                  <td><?= e($row['student']) ?></td>
                  <td><?= e($row['quiz']) ?></td>
                  <td data-score="<?= e($row['score']) ?>"><?= e($row['score']) ?>%</td>
                  <td><?= e($row['date']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </article>

        <article class="weak-topics-card">
          <h3>Weak Topics Identification</h3>
          <p>Topics where students struggle most — take action</p>
          <p class="weak-topics-note">Based on individual question attempts on your own quizzes — includes every topic with at least one attempt, even from a single student.</p>
          <div class="weak-topics-empty" id="weakTopicsEmpty" style="<?= empty($weakTopics) ? '' : 'display:none;' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4 12 14.01l-3-3" /></svg>
            <span id="weakTopicsEmptyText">No topic has enough attempts yet to flag as weak — once students answer more questions, this list will fill in.</span>
          </div>
          <table id="weakTopicsTable" style="<?= empty($weakTopics) ? 'display:none;' : '' ?>">
            <thead>
              <tr>
                <th>Topic</th>
                <th>Subject</th>
                <th class="text-center">Avg. Score</th>
                <th class="text-center">Struggling</th>
                <th class="text-center">Concern</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="weakTopicsBody">
              <?php foreach ($weakTopics as $topic): ?>
                <tr>
                  <td class="topic-name"><?= e($topic['topic_name']) ?></td>
                  <td class="subject-name"><?= e($topic['subject_name']) ?></td>
                  <td class="text-center">
                    <div class="score-bar-container">
                      <div class="score-bar-bg">
                        <div class="score-bar-fill" style="width: <?= e($topic['avg_score']) ?>%;"></div>
                      </div>
                      <div class="score-text"><?= e($topic['avg_score']) ?>%</div>
                    </div>
                  </td>
                  <td class="text-center">
                    <div class="struggling-count">
                      <span>↙</span> <?= e($topic['struggling']) ?> of <?= e($topic['attempted']) ?> (<?= e($topic['struggling_pct']) ?>%)
                    </div>
                  </td>
                  <td class="text-center">
                    <div class="difficulty-badge <?= e($topic['difficulty']) ?>"><?= e($topic['concern_label']) ?></div>
                  </td>
                  <td class="action-text">
                    <div class="action-links">
                      <a class="action-btn" href="<?= e($topic['material_href']) ?>">
                        Upload material
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                      </a>
                      <a class="action-btn" href="<?= e($topic['quiz_href']) ?>">
                        Create quiz
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </article>
      </section>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="teacher-shared.js"></script>
  <script>
    function animateCount(el, target, duration) {
      duration = duration || 700;
      const start = performance.now();
      function tick(now) {
        const progress = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(eased * target);
        if (progress < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    }

    function escapeHtml(value) {
      const div = document.createElement('div');
      div.textContent = value;
      return div.innerHTML;
    }

    const INITIAL_STATS = <?= json_encode($stats) ?>;
    const INITIAL_QUIZ_PERF = <?= json_encode($quizPerformances) ?>;
    const INITIAL_GRADE_DIST = <?= json_encode($gradeDistribution) ?>;
    const INITIAL_STUDENT_PERF = <?= json_encode($recentSubmissions) ?>;
    const INITIAL_WEAK_TOPICS = <?= json_encode($weakTopics) ?>;

    function renderStatCards(stats) {
      document.getElementById('cpStatAvgValue').textContent = stats.average_score + '%';
      document.getElementById('cpStatTopValue').textContent = stats.top_performer;
      document.getElementById('cpStatTopDesc').textContent = stats.top_performer_score + '% average score';
      document.getElementById('cpStatLowValue').textContent = stats.lowest_topic;
      document.getElementById('cpStatLowDesc').textContent = stats.lowest_topic_score + '% average score';
    }

    const perfPanel = document.getElementById('quizPerfCompPanel');
    const perfSvg = document.getElementById('quizPerfCompChart');
    const perfGrid = document.getElementById('quizPerfCompGrid');
    const perfBarsGroup = document.getElementById('quizPerfCompBars');
    const perfAxisGroup = document.getElementById('quizPerfCompAxis');
    const perfTooltip = document.getElementById('quizPerfCompTooltip');
    const perfTtTitle = document.getElementById('quizPerfCompTtTitle');
    const perfTtAvg = document.getElementById('quizPerfCompTtAvg');
    const perfTtTop = document.getElementById('quizPerfCompTtTop');
    const perfTtAttempts = document.getElementById('quizPerfCompTtAttempts');
    const quizPerfCompEmpty = document.getElementById('quizPerfCompEmpty');
    const quizPerfCompEmptyText = document.getElementById('quizPerfCompEmptyText');
    const quizPerfCompChartWrap = document.getElementById('quizPerfCompChartWrap');
    const quizPerfCompLegend = document.getElementById('quizPerfCompLegend');

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
      perfTtTop.textContent = quiz.attempts > 0 ? quiz.topScore + '%' : '—';
      perfTtAttempts.textContent = quiz.attempts;

      perfBarsGroup.querySelectorAll('.perf-bar').forEach((b) => b.classList.toggle('active', Number(b.dataset.index) === index));

      perfTooltip.classList.remove('align-left', 'align-right', 'align-below');

      const panelRect = perfPanel.getBoundingClientRect();
      const px = evt.clientX - panelRect.left;
      const py = evt.clientY - panelRect.top;

      const ttWidth = perfTooltip.offsetWidth || 180;
      const ttHeight = perfTooltip.offsetHeight || 100;
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

    function renderQuizPerfCompChart(data, emptyText) {
      currentQuizPerfData = data || [];
      perfGrid.innerHTML = '';
      perfBarsGroup.innerHTML = '';
      perfAxisGroup.innerHTML = '';
      hidePerfTooltip();

      if (currentQuizPerfData.length === 0) {
        quizPerfCompChartWrap.style.display = 'none';
        quizPerfCompLegend.style.display = 'none';
        quizPerfCompEmpty.style.display = '';
        if (quizPerfCompEmptyText && emptyText) quizPerfCompEmptyText.textContent = emptyText;
        return;
      }
      quizPerfCompEmpty.style.display = 'none';
      quizPerfCompChartWrap.style.display = '';
      quizPerfCompLegend.style.display = '';

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
      const pairWidth = Math.min(74, slot * 0.62);
      const barWidth = pairWidth / 2 - 2;
      const maxTitleChars = n > 5 ? 8 : 14;

      currentQuizPerfData.forEach((quiz, i) => {
        const cx = PX_LEFT + slot * (i + 0.5);
        const hasAttempts = quiz.attempts > 0;

        const avgY = hasAttempts ? perfValueToY(quiz.avg) : PY_BOTTOM - 2;
        const avgHeight = Math.max(PY_BOTTOM - avgY, 2);
        const avgX = cx - pairWidth / 2;

        const topY = hasAttempts ? perfValueToY(quiz.topScore) : PY_BOTTOM - 2;
        const topHeight = Math.max(PY_BOTTOM - topY, 2);
        const topX = cx + pairWidth / 2 - barWidth;

        const avgRect = document.createElementNS(svgNS, 'rect');
        avgRect.setAttribute('x', avgX);
        avgRect.setAttribute('y', avgY);
        avgRect.setAttribute('width', barWidth);
        avgRect.setAttribute('height', avgHeight);
        avgRect.setAttribute('rx', 6);
        avgRect.setAttribute('fill', hasAttempts ? 'url(#perfAvgGradient)' : '#DDE3EC');
        avgRect.classList.add('perf-bar');
        avgRect.dataset.index = i;
        perfBarsGroup.appendChild(avgRect);

        const topRect = document.createElementNS(svgNS, 'rect');
        topRect.setAttribute('x', topX);
        topRect.setAttribute('y', topY);
        topRect.setAttribute('width', barWidth);
        topRect.setAttribute('height', topHeight);
        topRect.setAttribute('rx', 6);
        topRect.setAttribute('fill', hasAttempts ? 'url(#perfTopGradient)' : '#DDE3EC');
        topRect.classList.add('perf-bar');
        topRect.dataset.index = i;
        perfBarsGroup.appendChild(topRect);

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

    renderQuizPerfCompChart(INITIAL_QUIZ_PERF, 'No quiz attempts to compare yet.');

    const gradeSegmentDefs = [
      { key: 'a', color: '#2DBE78', label: 'A (90+)' },
      { key: 'b', color: '#0E80D7', label: 'B (75-89)' },
      { key: 'c', color: '#F2C230', label: 'C (60-74)' },
      { key: 'd', color: '#EE3D59', label: 'D (<60)' },
    ];
    const GRADE_NO_DATA_COLOR = '#C7CEDA';

    const gradeDonutSegsGroup = document.getElementById('gradeDonutSegs');
    const gradeDonutTooltip = document.getElementById('gradeDonutTooltip');
    const gradeDonutTtTitle = document.getElementById('gradeDonutTtTitle');
    const gradeDonutTtKey = document.getElementById('gradeDonutTtKey');
    const gradeDonutTtVal = document.getElementById('gradeDonutTtVal');
    const gradeDonutArea = document.getElementById('gradeDonutArea');
    const gradeDcValue = document.getElementById('gradeDcValue');
    const gradeDcLabel = document.getElementById('gradeDcLabel');
    const gradeDonutLegend = document.getElementById('gradeDonutLegend');

    const G_CX = 125, G_CY = 125, G_R_OUTER = 118, G_R_INNER = 70;
    const G_GAP_DEG = 2.6;

    let currentGradeDonutData = [];
    let currentGradeDonutTotal = 0;

    function gradePolarToXY(cx, cy, r, angleDeg) {
      const rad = (angleDeg - 90) * (Math.PI / 180);
      return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
    }

    function gradeDonutArcPath(startAngle, endAngle) {
      const largeArc = endAngle - startAngle > 180 ? 1 : 0;
      const [x1, y1] = gradePolarToXY(G_CX, G_CY, G_R_OUTER, startAngle);
      const [x2, y2] = gradePolarToXY(G_CX, G_CY, G_R_OUTER, endAngle);
      const [x3, y3] = gradePolarToXY(G_CX, G_CY, G_R_INNER, endAngle);
      const [x4, y4] = gradePolarToXY(G_CX, G_CY, G_R_INNER, startAngle);
      return [
        `M ${x1} ${y1}`,
        `A ${G_R_OUTER} ${G_R_OUTER} 0 ${largeArc} 1 ${x2} ${y2}`,
        `L ${x3} ${y3}`,
        `A ${G_R_INNER} ${G_R_INNER} 0 ${largeArc} 0 ${x4} ${y4}`,
        'Z'
      ].join(' ');
    }

    function setGradeDonutActive(key) {
      gradeDonutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => el.classList.toggle('active', el.dataset.key === key));
      gradeDonutLegend.querySelectorAll('span').forEach((el) => el.classList.toggle('active', el.dataset.seg === key));
    }

    function clearGradeDonutActive() {
      gradeDonutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => el.classList.remove('active'));
      gradeDonutLegend.querySelectorAll('span').forEach((el) => el.classList.remove('active'));
    }

    function positionGradeTooltip(px, py, areaRect) {
      gradeDonutTooltip.classList.remove('align-left', 'align-right', 'align-below');
      const ttWidth = gradeDonutTooltip.offsetWidth || 170;
      const ttHeight = gradeDonutTooltip.offsetHeight || 60;
      const halfWidth = ttWidth / 2;
      const margin = 10;

      let finalX = px;
      if (px + halfWidth > areaRect.width - margin) {
        gradeDonutTooltip.classList.add('align-right');
        finalX = Math.min(px, areaRect.width - margin);
      } else if (px - halfWidth < margin) {
        gradeDonutTooltip.classList.add('align-left');
        finalX = Math.max(px, margin);
      }

      let finalY = py - 16;
      if (py - ttHeight - 16 < 0) {
        gradeDonutTooltip.classList.add('align-below');
        finalY = py + 16;
      }

      gradeDonutTooltip.style.left = finalX + 'px';
      gradeDonutTooltip.style.top = finalY + 'px';
    }

    function showGradeDonutTooltip(seg, evt) {
      gradeDonutTtTitle.textContent = seg.label;
      gradeDonutTtKey.innerHTML = `<i style="background:${seg.color}"></i>Share of attempts`;
      gradeDonutTtVal.textContent = seg.pct + '%';
      gradeDcValue.textContent = seg.count;
      gradeDcLabel.textContent = seg.label;
      setGradeDonutActive(seg.key);

      const areaRect = gradeDonutArea.getBoundingClientRect();
      positionGradeTooltip(evt.clientX - areaRect.left, evt.clientY - areaRect.top, areaRect);
      gradeDonutTooltip.classList.add('show');
    }

    function showGradeDonutTooltipAboveEl(seg, targetEl) {
      gradeDonutTtTitle.textContent = seg.label;
      gradeDonutTtKey.innerHTML = `<i style="background:${seg.color}"></i>Share of attempts`;
      gradeDonutTtVal.textContent = seg.pct + '%';
      gradeDcValue.textContent = seg.count;
      gradeDcLabel.textContent = seg.label;
      setGradeDonutActive(seg.key);

      const areaRect = gradeDonutArea.getBoundingClientRect();
      const elRect = targetEl.getBoundingClientRect();
      const px = (elRect.left + elRect.width / 2) - areaRect.left;
      const py = elRect.top - areaRect.top;
      positionGradeTooltip(px, py, areaRect);
      gradeDonutTooltip.style.top = (py - 12) + 'px';
      gradeDonutTooltip.classList.add('show');
    }

    function hideGradeDonutTooltip() {
      gradeDonutTooltip.classList.remove('show');
      clearGradeDonutActive();
      gradeDcValue.textContent = currentGradeDonutTotal > 0 ? currentGradeDonutTotal : '—';
      gradeDcLabel.textContent = currentGradeDonutTotal > 0 ? 'Attempts' : 'No attempts yet';
    }

    function renderGradeDonut(dist) {
      const total = (dist.a || 0) + (dist.b || 0) + (dist.c || 0) + (dist.d || 0);
      currentGradeDonutTotal = total;

      currentGradeDonutData = total > 0
        ? gradeSegmentDefs
            .map((def) => ({ ...def, count: dist[def.key] || 0, pct: Math.round((dist[def.key] || 0) / total * 100) }))
            .filter((seg) => seg.count > 0)
        : [{ key: 'nodata', label: 'No student data yet', color: GRADE_NO_DATA_COLOR, count: 0, pct: 100 }];

      gradeDonutSegsGroup.innerHTML = '';
      gradeDonutTooltip.classList.remove('show');

      let cursor = 0;
      currentGradeDonutData.forEach((seg) => {
        const sweep = (seg.pct / 100) * 360;
        const start = cursor + G_GAP_DEG / 2;
        const end = cursor + sweep - G_GAP_DEG / 2;

        const path = document.createElementNS(svgNS, 'path');
        path.setAttribute('d', gradeDonutArcPath(start, end));
        path.setAttribute('fill', seg.color);
        path.classList.add('donut-seg');
        path.dataset.key = seg.key;
        gradeDonutSegsGroup.appendChild(path);
        cursor += sweep;
      });

      gradeDcValue.textContent = total > 0 ? total : '—';
      gradeDcLabel.textContent = total > 0 ? 'Attempts' : 'No attempts yet';

      gradeDonutLegend.innerHTML = total > 0
        ? gradeSegmentDefs.map((def) => `<span data-seg="${def.key}" style="color:${def.color}"><i class="dot" style="background:${def.color}"></i> ${def.label}: ${dist[def.key] || 0}</span>`).join('')
        : `<span data-seg="nodata" style="color:#9AA5B5"><i class="dot" style="background:${GRADE_NO_DATA_COLOR}"></i> No student data yet</span>`;

      gradeDonutSegsGroup.querySelectorAll('.donut-seg').forEach((el) => {
        const seg = currentGradeDonutData.find((d) => d.key === el.dataset.key);
        el.addEventListener('mouseenter', (e) => showGradeDonutTooltip(seg, e));
        el.addEventListener('mousemove', (e) => showGradeDonutTooltip(seg, e));
        el.addEventListener('mouseleave', hideGradeDonutTooltip);
      });

      gradeDonutLegend.querySelectorAll('span').forEach((legendItem) => {
        const seg = currentGradeDonutData.find((d) => d.key === legendItem.dataset.seg);
        legendItem.addEventListener('mouseenter', () => showGradeDonutTooltipAboveEl(seg, legendItem));
        legendItem.addEventListener('mouseleave', hideGradeDonutTooltip);
      });
    }

    renderGradeDonut(INITIAL_GRADE_DIST);

    const studentPerfBody = document.getElementById('studentPerfBody');
    const studentPerfTable = document.getElementById('studentPerfTable');
    const studentPerfEmpty = document.getElementById('studentPerfEmpty');
    const studentPerfEmptyText = document.getElementById('studentPerfEmptyText');

    function renderStudentPerformance(rows, emptyMessage) {
      studentPerfBody.innerHTML = '';
      if (!rows || rows.length === 0) {
        studentPerfTable.style.display = 'none';
        studentPerfEmpty.style.display = '';
        if (studentPerfEmptyText && emptyMessage) studentPerfEmptyText.textContent = emptyMessage;
        return;
      }
      studentPerfEmpty.style.display = 'none';
      studentPerfTable.style.display = '';

      rows.forEach((row) => {
        const tr = document.createElement('tr');

        const nameTd = document.createElement('td');
        nameTd.textContent = row.student;
        tr.appendChild(nameTd);

        const quizTd = document.createElement('td');
        quizTd.textContent = row.quiz;
        tr.appendChild(quizTd);

        const scoreTd = document.createElement('td');
        scoreTd.dataset.score = row.score;
        scoreTd.textContent = row.score + '%';
        tr.appendChild(scoreTd);

        const dateTd = document.createElement('td');
        dateTd.textContent = row.date;
        tr.appendChild(dateTd);

        studentPerfBody.appendChild(tr);
      });
    }

    const weakTopicsBody = document.getElementById('weakTopicsBody');
    const weakTopicsTable = document.getElementById('weakTopicsTable');
    const weakTopicsEmpty = document.getElementById('weakTopicsEmpty');
    const weakTopicsEmptyText = document.getElementById('weakTopicsEmptyText');

    function renderWeakTopics(rows, emptyMessage) {
      weakTopicsBody.innerHTML = '';
      if (!rows || rows.length === 0) {
        weakTopicsTable.style.display = 'none';
        weakTopicsEmpty.style.display = '';
        if (weakTopicsEmptyText && emptyMessage) weakTopicsEmptyText.textContent = emptyMessage;
        return;
      }
      weakTopicsEmpty.style.display = 'none';
      weakTopicsTable.style.display = '';

      rows.forEach((topic) => {
        const tr = document.createElement('tr');

        const topicTd = document.createElement('td');
        topicTd.className = 'topic-name';
        topicTd.textContent = topic.topic_name;
        tr.appendChild(topicTd);

        const subjectTd = document.createElement('td');
        subjectTd.className = 'subject-name';
        subjectTd.textContent = topic.subject_name;
        tr.appendChild(subjectTd);

        const scoreTd = document.createElement('td');
        scoreTd.className = 'text-center';
        scoreTd.innerHTML = `<div class="score-bar-container">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width: ${topic.avg_score}%;"></div></div>
            <div class="score-text">${topic.avg_score}%</div>
          </div>`;
        tr.appendChild(scoreTd);

        const strugglingTd = document.createElement('td');
        strugglingTd.className = 'text-center';
        strugglingTd.innerHTML = `<div class="struggling-count"><span>↙</span> ${topic.struggling} of ${topic.attempted} (${topic.struggling_pct}%)</div>`;
        tr.appendChild(strugglingTd);

        const concernTd = document.createElement('td');
        concernTd.className = 'text-center';
        concernTd.innerHTML = `<div class="difficulty-badge ${escapeHtml(topic.difficulty)}">${escapeHtml(topic.concern_label)}</div>`;
        tr.appendChild(concernTd);

        const actionTd = document.createElement('td');
        actionTd.className = 'action-text';
        actionTd.innerHTML = `<div class="action-links">
            <a class="action-btn" href="${escapeHtml(topic.material_href)}">
              Upload material
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
            </a>
            <a class="action-btn" href="${escapeHtml(topic.quiz_href)}">
              Create quiz
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
            </a>
          </div>`;
        tr.appendChild(actionTd);

        weakTopicsBody.appendChild(tr);
      });
    }

    const cpFilterSubject = document.getElementById('cpFilterSubject');
    const cpFilterClass = document.getElementById('cpFilterClass');
    const cpFilterClearBtn = document.getElementById('cpFilterClear');
    let cpFilterRequestId = 0;

    function showToastLocal(message) {
      if (typeof window.showToast === 'function') {
        window.showToast(message);
      }
    }

    async function applyClassPerfFilter() {
      const subjectId = cpFilterSubject.value;
      const classId = cpFilterClass.value;
      const hasFilter = Boolean(subjectId || classId);
      cpFilterClearBtn.hidden = !hasFilter;

      if (!hasFilter) {
        renderStatCards(INITIAL_STATS);
        renderQuizPerfCompChart(INITIAL_QUIZ_PERF, 'No quiz attempts to compare yet.');
        renderGradeDonut(INITIAL_GRADE_DIST);
        renderStudentPerformance(INITIAL_STUDENT_PERF, 'No submissions yet.');
        renderWeakTopics(INITIAL_WEAK_TOPICS, 'No topic has enough attempts yet to flag as weak — once students answer more questions, this list will fill in.');
        return;
      }

      const requestId = ++cpFilterRequestId;
      const params = new URLSearchParams({ feed: 'class_performance_filter' });
      if (subjectId) params.set('subject_id', subjectId);
      if (classId) params.set('class_id', classId);

      let data;
      try {
        const res = await fetch(`TClassPerformance.php?${params.toString()}`, { credentials: 'same-origin' });
        data = await res.json();
      } catch (err) {
        showToastLocal('Unable to load filtered analytics data.');
        return;
      }
      if (requestId !== cpFilterRequestId) return; 

      const safeRender = (label, fn) => {
        try {
          fn();
        } catch (err) {
          console.error(`Class performance filter: failed to render ${label}`, err);
        }
      };

      safeRender('stat cards', () => renderStatCards(data.stats || INITIAL_STATS));
      safeRender('quiz performance chart', () => renderQuizPerfCompChart(data.quizPerformance || [], 'No quizzes match this filter.'));
      safeRender('grade distribution', () => renderGradeDonut(data.gradeDistribution || { a: 0, b: 0, c: 0, d: 0 }));
      safeRender('student performance', () => renderStudentPerformance(data.recentSubmissions || [], 'No submissions match this filter.'));
      safeRender('weak topics', () => renderWeakTopics(data.weakTopics || [], 'No topic matches this filter yet.'));
    }

    cpFilterSubject?.addEventListener('change', applyClassPerfFilter);
    cpFilterClass?.addEventListener('change', applyClassPerfFilter);
    cpFilterClearBtn?.addEventListener('click', () => {
      cpFilterSubject.value = '';
      cpFilterClass.value = '';
      applyClassPerfFilter();
    });
  </script>
</body>
</html>