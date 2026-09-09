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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_recommendation') {
    header('Content-Type: application/json');

    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }

    $resultId = isset($_POST['result_id']) ? (int) $_POST['result_id'] : 0;
    $text = trim($_POST['text'] ?? '');

    if ($resultId <= 0 || $text === '') {
        echo json_encode(['success' => false, 'message' => 'Missing result or text.']);
        exit();
    }

    $lookupStmt = $conn->prepare("SELECT u.user_id
        FROM result r
        JOIN users u ON r.user_id = u.user_id
        JOIN quiz q ON r.quiz_id = q.quiz_id
        WHERE r.result_id = ? AND q.user_id = ? AND u.role = 'Student'
        LIMIT 1");
    $lookupStmt->bind_param('ii', $resultId, $userId);
    $lookupStmt->execute();
    $lookupRow = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();

    if (!$lookupRow) {
        echo json_encode(['success' => false, 'message' => 'Result not found for this teacher.']);
        exit();
    }

    $studentUserId = (int) $lookupRow['user_id'];

    $now = date('Y-m-d H:i:s');

    $insertTeacherStmt = $conn->prepare("INSERT INTO recommendation (user_id, recommendation_text, generated_date) VALUES (?, ?, ?)");
    $insertTeacherStmt->bind_param('iss', $userId, $text, $now);
    $insertTeacherStmt->execute();
    $insertTeacherStmt->close();

    $insertStudentStmt = $conn->prepare("INSERT INTO recommendation (user_id, recommendation_text, generated_date) VALUES (?, ?, ?)");
    $insertStudentStmt->bind_param('iss', $studentUserId, $text, $now);

    if (!$insertStudentStmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Could not save recommendation.']);
        exit();
    }

    $newId = $insertStudentStmt->insert_id;
    $insertStudentStmt->close();
    mysqli_close($conn);

    echo json_encode([
        'success' => true,
        'recommendation' => [
            'id' => $newId,
            'text' => $text,
            'date' => $now,
        ],
    ]);
    exit();
}

$teacherName = 'Teacher';
$teacherAvatar = '';
$classLabel = 'Teacher';
$students = [];
$stats = [
    'total_results' => 0,
    'total_score' => 0.0,
    'at_risk' => 0,
];
$chartBars = [];
$gradeDistribution = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
$answerBreakdown = [];
$recommendationBreakdown = [];

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');

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

    $subjectOptions = [];

    $classByStudent = [];
    $classListResult = mysqli_query($conn, "SELECT c.user_id AS student_user_id, cl.class_id, cl.class_name, cl.year
        FROM classes c
        JOIN class cl ON c.class_id = cl.class_id
        WHERE c.subject_id IS NULL AND c.classes_status = 'Active'
        ORDER BY cl.year ASC, cl.class_name ASC");
    if ($classListResult) {
        while ($classRow = mysqli_fetch_assoc($classListResult)) {
            $studentUid = (int) $classRow['student_user_id'];
            if (isset($classByStudent[$studentUid])) {
                continue; 
            }
            $classByStudent[$studentUid] = [
                'key' => (string) (int) $classRow['class_id'],
                'label' => trim($classRow['class_name']) . ' (Year ' . (int) $classRow['year'] . ')',
            ];
        }
    }

    $materialsBySubject = [];
    $materialListResult = mysqli_query($conn, "SELECT m.title, m.material_type, t.topic_name, t.subject_id
        FROM material m
        JOIN topic t ON m.topic_id = t.topic_id
        WHERE m.user_id = {$userId}
        ORDER BY m.upload_date DESC");
    if ($materialListResult) {
        while ($mRow = mysqli_fetch_assoc($materialListResult)) {
            $subjId = (int) $mRow['subject_id'];
            if (!isset($materialsBySubject[$subjId])) {
                $materialsBySubject[$subjId] = [];
            }
            $materialsBySubject[$subjId][] = [
                'title' => $mRow['title'],
                'type' => $mRow['material_type'] ?: 'Material',
                'topic' => $mRow['topic_name'] ?: 'General',
            ];
        }
    }

    $recommendationsByUser = [];
    $recStmt = $conn->prepare("SELECT r1.recommendation_id, r1.user_id, r1.recommendation_text, r1.generated_date
        FROM recommendation r1
        WHERE r1.user_id <> ?
          AND EXISTS (
            SELECT 1 FROM recommendation r2
            WHERE r2.user_id = ?
              AND r2.recommendation_text = r1.recommendation_text
              AND r2.generated_date = r1.generated_date
          )
        ORDER BY r1.generated_date DESC");
    $recStmt->bind_param('ii', $userId, $userId);
    $recStmt->execute();
    $recListResult = $recStmt->get_result();
    if ($recListResult) {
        while ($rRow = mysqli_fetch_assoc($recListResult)) {
            $uid = (int) $rRow['user_id'];
            if (!isset($recommendationsByUser[$uid])) {
                $recommendationsByUser[$uid] = [];
            }
            $recommendationsByUser[$uid][] = [
                'id' => (int) $rRow['recommendation_id'],
                'text' => $rRow['recommendation_text'],
                'date' => $rRow['generated_date'],
            ];
        }
        $recStmt->close();
    }

    $studentQuery = "SELECT r.result_id, u.user_id, u.name, r.quiz_id, q.quiz_title, q.subject_id AS quiz_subject_id, s.subject_name, (r.score / r.total_questions * 100) AS percentage, r.attempt_date, r.total_questions
        FROM result r
        JOIN users u ON r.user_id = u.user_id
        JOIN quiz q ON r.quiz_id = q.quiz_id
        LEFT JOIN subject s ON q.subject_id = s.subject_id
        WHERE u.role = 'Student' AND q.user_id = {$userId}
        ORDER BY r.attempt_date DESC";

    $studentResult = mysqli_query($conn, $studentQuery);
    $classFilterOptions = [];
    $quizFilterOptions = [];
    if ($studentResult && mysqli_num_rows($studentResult) > 0) {
        while ($row = mysqli_fetch_assoc($studentResult)) {
            $username = trim($row['name'] ?? 'Unknown Student');
            $subjectLabel = trim($row['subject_name'] ?? '') ?: 'General';
            $subjectKey = strtolower(str_replace([' ', '/'], ['', ''], $subjectLabel));
            $subjectKey = $subjectKey ?: 'general';
            $percentage = isset($row['percentage']) ? (float) $row['percentage'] : 0.0;
            $score = (int) round($percentage);

            $badgeClass = 'good';
            if ($score >= 85) {
                $badgeClass = 'excellent';
            } elseif ($score >= 70) {
                $badgeClass = 'good';
            } elseif ($score >= 60) {
                $badgeClass = 'warning';
            } else {
                $badgeClass = 'danger';
            }

            $students[] = [
                'result_id' => (int) ($row['result_id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'quiz_subject_id' => (int) ($row['quiz_subject_id'] ?? 0),
                'initials' => implode('', array_map(function ($part) { return strtoupper($part[0] ?? ''); }, explode(' ', $username))),
                'name' => $username,
                'subject' => $subjectKey,
                'subject_label' => $subjectLabel,
                'quiz_id' => (int) ($row['quiz_id'] ?? 0),
                'quiz_title' => trim($row['quiz_title'] ?? '') ?: 'Untitled Quiz',
                'class_key' => $classByStudent[(int) ($row['user_id'] ?? 0)]['key'] ?? 'unassigned',
                'class_label' => $classByStudent[(int) ($row['user_id'] ?? 0)]['label'] ?? 'Unassigned',
                'attempt_date' => $row['attempt_date'] ?? null,
                'score' => $score,
                'engagement' => min(100, max(0, $score)),
                'badge' => $badgeClass,
            ];

            $stats['total_results']++;
            $stats['total_score'] += $percentage;
            if ($percentage < 60) {
                $stats['at_risk']++;
            }
        }

        $classOptionsSeen = [];
        $classFilterOptions = [];
        $quizOptionsSeen = [];
        $quizFilterOptions = [];
        $subjectOptionsSeen = [];
        $subjectOptions = [];
        foreach ($students as $s) {
            if (!isset($classOptionsSeen[$s['class_key']])) {
                $classOptionsSeen[$s['class_key']] = true;
                $classFilterOptions[] = ['key' => $s['class_key'], 'label' => $s['class_label']];
            }
            $quizKey = (string) $s['quiz_id'];
            if (!isset($quizOptionsSeen[$quizKey])) {
                $quizOptionsSeen[$quizKey] = true;
                $quizFilterOptions[] = ['key' => $quizKey, 'label' => $s['quiz_title'], 'subject' => $s['subject']];
            }
            if (!isset($subjectOptionsSeen[$s['subject']])) {
                $subjectOptionsSeen[$s['subject']] = true;
                $subjectOptions[] = ['key' => $s['subject'], 'label' => $s['subject_label']];
            }
        }
        usort($classFilterOptions, function ($a, $b) { return strcmp($a['label'], $b['label']); });
        usort($quizFilterOptions, function ($a, $b) { return strcmp($a['label'], $b['label']); });
        usort($subjectOptions, function ($a, $b) { return strcmp($a['label'], $b['label']); });

        $classFilterOptions = array_values(array_filter($classFilterOptions, function ($c) {
            return $c['key'] !== 'unassigned';
        }));


        $chartSource = $students;
        usort($chartSource, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $topStudents = array_slice($chartSource, 0, 8);
        foreach ($topStudents as $student) {
            $chartBars[] = [
                'name' => $student['name'],
                'quiz_title' => $student['quiz_title'],
                'value' => $student['score'],
                'badge' => $student['badge'],
            ];
        }

        foreach ($students as $student) {
            $score = $student['score'];
            if ($score >= 90) {
                $gradeDistribution['a']++;
            } elseif ($score >= 75) {
                $gradeDistribution['b']++;
            } elseif ($score >= 60) {
                $gradeDistribution['c']++;
            } else {
                $gradeDistribution['d']++;
            }
        }
    }

    $answerBreakdown = [];
    $answerQuery = "SELECT sa.result_id, sa.question_id, sa.student_answer,
            qq.question_text, qq.image, qq.mark, qq.option_a, qq.option_b, qq.option_c, qq.option_d, qq.correct_answer,
            qq.topic_id, t.topic_name
        FROM student_answer sa
        JOIN question qq ON sa.question_id = qq.question_id
        LEFT JOIN topic t ON qq.topic_id = t.topic_id
        JOIN result r ON sa.result_id = r.result_id
        JOIN users u ON r.user_id = u.user_id
        JOIN quiz q ON r.quiz_id = q.quiz_id
        WHERE u.role = 'Student' AND q.user_id = {$userId}
        ORDER BY sa.result_id, sa.answer_id";
    $answerResult = mysqli_query($conn, $answerQuery);
    if ($answerResult) {
        while ($aRow = mysqli_fetch_assoc($answerResult)) {
            $resultId = (int) ($aRow['result_id'] ?? 0);
            $optionsMap = [
                'A' => $aRow['option_a'] ?? '',
                'B' => $aRow['option_b'] ?? '',
                'C' => $aRow['option_c'] ?? '',
                'D' => $aRow['option_d'] ?? '',
            ];
            $studentLetter = strtoupper(trim($aRow['student_answer'] ?? ''));
            $correctLetter = strtoupper(trim($aRow['correct_answer'] ?? ''));

            if (!isset($answerBreakdown[$resultId])) {
                $answerBreakdown[$resultId] = [];
            }
            $answerBreakdown[$resultId][] = [
                'question' => $aRow['question_text'] ?? '',
                'image' => $aRow['image'] ?: null,
                'marks' => (int) ($aRow['mark'] ?? 0),
                'options' => [
                    ['letter' => 'A', 'text' => $optionsMap['A']],
                    ['letter' => 'B', 'text' => $optionsMap['B']],
                    ['letter' => 'C', 'text' => $optionsMap['C']],
                    ['letter' => 'D', 'text' => $optionsMap['D']],
                ],
                'studentLetter' => $studentLetter,
                'studentText' => $optionsMap[$studentLetter] ?? '',
                'correctLetter' => $correctLetter,
                'correctText' => $optionsMap[$correctLetter] ?? '',
                'isCorrect' => $studentLetter !== '' && $studentLetter === $correctLetter,
                'topicName' => $aRow['topic_name'] ?? null,
            ];
        }
    }

    foreach ($students as $student) {
        $resultId = $student['result_id'];
        $studentUserId = $student['user_id'];
        $quizSubjectId = $student['quiz_subject_id'];

        $weakTopics = [];
        if (!empty($answerBreakdown[$resultId])) {
            foreach ($answerBreakdown[$resultId] as $q) {
                if (!$q['isCorrect'] && !empty($q['topicName'])) {
                    $weakTopics[$q['topicName']] = true;
                }
            }
        }
        $weakTopics = array_keys($weakTopics);

        $suggestedMaterials = [];
        if (!empty($weakTopics)) {
            $subjectMaterials = $materialsBySubject[$quizSubjectId] ?? [];
            $suggestedMaterials = array_values(array_filter($subjectMaterials, function ($mat) use ($weakTopics) {
                return in_array($mat['topic'], $weakTopics, true);
            }));
        }

        $recommendationBreakdown[$resultId] = [
            'studentUserId' => $studentUserId,
            'weakTopics' => $weakTopics,
            'materials' => array_slice($suggestedMaterials, 0, 3),
            'sentHistory' => $recommendationsByUser[$studentUserId] ?? [],
        ];
    }

    mysqli_close($conn);
}

$averageEngagement = $stats['total_results'] > 0 ? round($stats['total_score'] / $stats['total_results']) : 0;
$averageScore = $stats['total_results'] > 0 ? round($stats['total_score'] / $stats['total_results']) : 0;


function computeTrends(array $rows) {
    $fallback = [
        'engagement' => 'Not enough data yet',
        'completed' => 'Not enough data yet',
        'atrisk' => 'Not enough data yet',
        'avgscore' => 'Not enough data yet',
    ];
    $total = count($rows);
    if ($total < 4) {
        return $fallback;
    }

    $recentCount = (int) ceil($total / 2);
    $recent = array_slice($rows, 0, $recentCount);
    $older = array_slice($rows, $recentCount);
    if (count($older) === 0) {
        return $fallback;
    }

    $avg = function ($items, $field) {
        if (count($items) === 0) {
            return 0;
        }
        $sum = 0;
        foreach ($items as $item) {
            $sum += $item[$field];
        }
        return $sum / count($items);
    };
    $countAtRisk = function ($items) {
        $n = 0;
        foreach ($items as $item) {
            if ($item['score'] < 60) {
                $n++;
            }
        }
        return $n;
    };

    $text = function ($delta, $suffix = '') {
        $rounded = (int) round($delta);
        if ($rounded > 0) {
            return "↗ +{$rounded}{$suffix} vs earlier attempts";
        }
        if ($rounded < 0) {
            return "↘ {$rounded}{$suffix} vs earlier attempts";
        }
        return '→ No change vs earlier attempts';
    };

    $engagementDelta = $avg($recent, 'engagement') - $avg($older, 'engagement');
    $scoreDelta = $avg($recent, 'score') - $avg($older, 'score');
    $completedDelta = count($recent) - count($older);
    $atRiskDelta = $countAtRisk($recent) - $countAtRisk($older);

    return [
        'engagement' => $text($engagementDelta, 'pp'),
        'completed' => $text($completedDelta),
        'atrisk' => $text($atRiskDelta),
        'avgscore' => $text($scoreDelta, 'pp'),
    ];
}

$trends = computeTrends($students);

$statsCards = [
    [
        'id' => 'engagement',
        'label' => 'AVG. ENGAGEMENT',
        'value' => $averageEngagement . '%',
        'change' => $trends['engagement'],
        'iconClass' => 'green',
        'iconSvg' => '<path d="m22 10-10-5-10 5 10 5 10-5Z" /><path d="M6 12v5c3.6 2 8.4 2 12 0v-5" />',
    ],
    [
        'id' => 'completed',
        'label' => 'QUIZZES COMPLETED',
        'value' => (string) $stats['total_results'],
        'change' => $trends['completed'],
        'iconClass' => 'blue',
        'iconSvg' => '<path d="M9 9h.01" /><path d="M9 15h.01" /><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8" /><path d="M12 15a3 3 0 1 0 0-6" />',
    ],
    [
        'id' => 'atrisk',
        'label' => 'AT-RISK STUDENTS',
        'value' => (string) $stats['at_risk'],
        'change' => $trends['atrisk'],
        'iconClass' => 'orange',
        'iconSvg' => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />',
    ],
    [
        'id' => 'avgscore',
        'label' => 'AVG. SCORE',
        'value' => $averageScore . '%',
        'change' => $trends['avgscore'],
        'iconClass' => 'green',
        'iconSvg' => '<circle cx="12" cy="8" r="6" /><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" />',
    ],
];

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
  <title>Student View - SmartLearn Teacher Portal</title>
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

    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(220px, 1fr));
      gap: 18px;
      margin-bottom: 36px;
    }

    .stat-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
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
      margin-bottom: 12px;
    }

    .stat-icon {
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      flex: 0 0 auto;
    }

    .stat-icon.green {
      background: rgba(45, 190, 120, 0.15);
      color: #1E9C64;
    }

    .stat-icon.blue {
      background: rgba(14, 128, 215, 0.15);
      color: var(--blue);
    }

    .stat-icon.orange {
      background: rgba(246, 163, 19, 0.15);
      color: #7C560A;
    }

    .stat-icon.red {
      background: rgba(238, 61, 89, 0.15);
      color: #C41E3A;
    }

    .stat-icon svg {
      width: 22px;
      height: 22px;
      stroke-width: 2;
    }

    .stat-value {
      font-size: 32px;
      font-weight: 900;
      color: var(--text);
      margin-bottom: 8px;
    }

    .stat-change {
      color: var(--green);
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .section-title {
      margin: 0 0 16px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .section-subtitle {
      margin: 0 0 24px 0;
      color: var(--muted);
      font-size: 14px;
    }

    .students-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 24px;
    }

    .search-export {
      display: flex;
      align-items: center;
      gap: 16px;
      flex: 1;
      max-width: 600px;
    }

    .search-student {
      flex: 1;
      height: 40px;
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--surface);
      padding: 0 14px;
      color: var(--muted);
    }

    .search-student svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
      flex: 0 0 auto;
    }

    .search-student input {
      flex: 1;
      border: none;
      background: transparent;
      color: var(--text);
      font-size: 14px;
      outline: none;
    }

    .search-student input::placeholder {
      color: var(--muted);
    }

    html[data-theme="dark"] .search-student {
      background: var(--surface);
      border-color: var(--line);
    }

    html[data-theme="dark"] .search-student input {
      background: transparent;
    }

    .export-btn {
      display: flex;
      align-items: center;
      gap: 8px;
      height: 40px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--surface);
      padding: 0 16px;
      color: var(--text);
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }

    .export-btn:hover {
      border-color: var(--sky);
      background: rgba(202, 223, 242, 0.1);
    }

    .export-btn svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
    }

    .sv-send-btn {
      background: var(--blue, #0E80D7);
      border-color: var(--blue, #0E80D7);
      color: white;
    }

    .sv-send-btn:hover {
      background: #0C6EB8;
      border-color: #0C6EB8;
      color: white;
    }

    .sv-send-btn:disabled {
      opacity: 0.6;
      cursor: wait;
    }

    .table-wrapper {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-bottom: 36px;
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
      padding: 18px 20px;
      color: var(--text);
    }

    .student-cell {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .student-avatar {
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--pale), var(--steel));
      color: white;
      font-size: 12px;
      font-weight: 700;
    }

    .student-name {
      color: var(--blue);
      font-weight: 700;
    }

    .score-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 56px;
      height: 32px;
      border-radius: 20px;
      font-size: 14px;
      font-weight: 800;
      color: white;
    }

    .score-badge.excellent {
      background: #2DBE78;
    }

    .score-badge.good {
      background: #0E80D7;
    }

    .score-badge.warning {
      background: #F6A313;
    }

    .score-badge.danger {
      background: #EE3D59;
    }

    .score-badge.score-low,
    .score-badge.score-high,
    html[data-theme="dark"] .score-badge.score-low,
    html[data-theme="dark"] .score-badge.score-high {
      color: #FFFFFF !important;
    }

    .engagement-bar {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 250px;
    }

    .progress-container {
      flex: 1;
      height: 8px;
      background: #E7EEF5;
      border-radius: 999px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #0E80D7, #2DBE78);
      border-radius: 999px;
    }

    .engagement-text {
      min-width: 50px;
      text-align: right;
      color: var(--muted);
      font-size: 14px;
      font-weight: 600;
    }

    .view-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--surface);
      color: var(--text);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
    }

    .view-btn:hover {
      border-color: var(--sky);
      background: rgba(150, 185, 217, 0.1);
      color: var(--steel);
    }

    .view-btn svg {
      width: 16px;
      height: 16px;
      stroke-width: 2;
    }

    .charts-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .chart-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 28px 30px;
      box-shadow: var(--shadow);
    }

    .chart-card h3 {
      margin: 0 0 24px 0;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
    }

    .chart-wrapper {
      width: 100%;
      min-height: 320px;
      display: flex;
      align-items: flex-end;
      justify-content: space-around;
      gap: 12px;
    }

    .bar-chart {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
    }

    .bar-value {
      font-size: 13px;
      font-weight: 800;
      color: var(--text);
    }

    .bar {
      width: 100%;
      min-height: 6px;
      border-radius: 8px 8px 0 0;
      transition: all 0.3s;
      cursor: pointer;
    }

    .bar.excellent {
      background: linear-gradient(180deg, #3DD68C, #2DBE78);
    }

    .bar.good {
      background: linear-gradient(180deg, #4DA3FF, #0E80D7);
    }

    .bar.warning {
      background: linear-gradient(180deg, #FFC670, #F6A313);
    }

    .bar.danger {
      background: linear-gradient(180deg, #FF7B8F, #EE3D59);
    }

    .bar:hover {
      opacity: 0.8;
      transform: translateY(-4px);
    }

    .bar-label {
      font-size: 12px;
      color: var(--muted);
      font-weight: 600;
      text-align: center;
      white-space: nowrap;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .bar-label small {
      display: block;
      font-weight: 500;
      opacity: 0.75;
      font-size: 11px;
    }

    .grade-donut-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 40px;
      min-height: 280px;
      flex-wrap: wrap;
    }

    @keyframes gradeDonutIn {
      from {
        opacity: 0;
        transform: scale(0.55) rotate(-90deg);
      }
      to {
        opacity: 1;
        transform: scale(1) rotate(0deg);
      }
    }

    .grade-donut {
      width: 200px;
      height: 200px;
      border-radius: 50%;
      position: relative;
      flex: 0 0 auto;
      animation: gradeDonutIn 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) both;
      transition: transform 0.35s ease, filter 0.35s ease;
    }

    .grade-donut:hover {
      transform: scale(1.05);
      filter: drop-shadow(0 10px 22px rgba(18, 38, 64, 0.35));
    }

    .grade-donut-hole {
      position: absolute;
      inset: 26px;
      background: var(--surface);
      border-radius: 50%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      transition: background 0.35s ease;
    }

    .grade-donut-hole span {
      font-size: 26px;
      font-weight: 800;
      color: var(--text);
      line-height: 1;
    }

    .grade-donut-hole small {
      font-size: 12px;
      color: var(--muted);
      margin-top: 4px;
    }

    .grade-legend {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 12px;
      font-size: 14px;
      color: var(--text);
      font-weight: 600;
    }

    .grade-legend li {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .grade-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      flex: 0 0 auto;
    }

    @media (max-width: 1400px) {
      .stats {
        grid-template-columns: repeat(2, 1fr);
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

      .stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }

      .stat-card {
        padding: 16px;
      }

      .stat-value {
        font-size: 24px;
      }

      .header h2 {
        font-size: 24px;
      }

      .students-header {
        flex-direction: column;
        align-items: stretch;
      }

      .search-export {
        max-width: 100%;
        flex-direction: column;
      }

      .table-wrapper {
        overflow-x: auto;
      }

      table {
        min-width: 600px;
        font-size: 14px;
      }

      th,
      td {
        padding: 12px 15px;
      }

      .engagement-bar {
        min-width: 180px;
      }

      .charts-grid {
        grid-template-columns: 1fr;
      }

      .chart-wrapper {
        min-height: 260px;
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
        gap: 12px;
      }

      .stat-card {
        padding: 16px;
      }

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

      .engagement-bar {
        min-width: 150px;
      }

      .search-student {
        height: 36px;
        font-size: 13px;
      }
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

    .sv-modal {
      display: block;
      width: min(680px, 100%);
      max-height: min(92vh, 720px);
      overflow: auto;
      background: var(--surface);
      color: var(--text);
      border-radius: 24px;
      padding: 28px 30px;
      box-shadow: 0 28px 80px rgba(18, 38, 64, 0.28);
      transform: translateY(18px) scale(0.98);
      opacity: 0;
      transition: transform 0.26s ease, opacity 0.26s ease;
    }

    .modal-overlay.show .sv-modal {
      transform: translateY(0) scale(1);
      opacity: 1;
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

    .qv-option.wrong {
      background: rgba(238, 61, 89, 0.14);
      border-color: var(--red, #EE3D59);
      color: #C41E3A;
      font-weight: 700;
    }

    .qv-option.wrong .qv-letter {
      background: #EE3D59;
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

    html[data-theme="dark"] .qv-option.wrong {
      background: rgba(255, 90, 114, 0.16);
      border-color: #FF5A72;
      color: #FF8FA0;
    }

    html[data-theme="dark"] .qv-option.wrong .qv-letter {
      background: #FF5A72;
      color: #2A0A10;
    }

    @media (max-width: 640px) {
      .qv-options {
        grid-template-columns: 1fr;
      }
    }

    .rec-bubble {
      border-radius: 14px;
      padding: 12px 14px;
      font-size: 13.5px;
      line-height: 1.5;
      border: 1px solid var(--line);
    }

    .rec-bubble.rec-note {
      background: rgba(14, 128, 215, 0.10);
      border-color: rgba(14, 128, 215, 0.3);
      color: var(--text);
    }

    html[data-theme="dark"] .rec-bubble.rec-note {
      background: rgba(77, 163, 255, 0.14);
    }

    .rec-topic-pill {
      display: inline-flex;
      align-items: center;
      padding: 5px 12px;
      border-radius: 999px;
      background: rgba(238, 61, 89, 0.12);
      color: #C41E3A;
      font-size: 12.5px;
      font-weight: 700;
    }

    html[data-theme="dark"] .rec-topic-pill {
      background: rgba(255, 90, 114, 0.18);
      color: #FF8FA0;
    }
  </style>
</head>
<body data-page="student-view">
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
        <a href="TLearningMaterials.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg><span>Upload Materials</span></a>
        <a href="TClassPerformance.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/></svg><span>Analytics</span></a>
        <a href="TStudentAnalytic.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Student View</span></a>
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
        <div class="header">
          <h2>Student Performance</h2>
          <p>View individual student quiz scores and progress by subject.</p>
        </div>

        <section class="stats" aria-label="Analytics statistics">
          <?php foreach ($statsCards as $stat): ?>
            <article class="stat-card" data-stat="<?= e($stat['id']) ?>">
              <div class="stat-label">
                <div class="stat-icon <?= e($stat['iconClass']) ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= $stat['iconSvg'] ?></svg>
                </div>
                <?= e($stat['label']) ?>
              </div>
              <div class="stat-value" id="stat-value-<?= e($stat['id']) ?>"><?= e($stat['value']) ?></div>
              <div class="stat-change" id="stat-change-<?= e($stat['id']) ?>"><?= e($stat['change']) ?></div>
            </article>
          <?php endforeach; ?>
        </section>

        <h3 class="section-title">Students</h3>
        <p class="section-subtitle">Individual scores and engagement metrics</p>

        <div class="students-header">
          <div class="search-export">
            <select id="subjectFilter" style="height:44px;padding:0 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--surface);color:var(--text);min-width:180px;">
              <option value="all">All Subjects</option>
              <?php foreach ($subjectOptions as $subjectOption): ?>
                <option value="<?= e($subjectOption['key']) ?>"><?= e($subjectOption['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="classFilter" style="height:44px;padding:0 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--surface);color:var(--text);min-width:180px;">
              <option value="all">All Classes</option>
              <?php foreach ($classFilterOptions as $classOption): ?>
                <option value="<?= e($classOption['key']) ?>"><?= e($classOption['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="quizFilter" style="height:44px;padding:0 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--surface);color:var(--text);min-width:180px;">
              <option value="all">All Quizzes</option>
              <?php foreach ($quizFilterOptions as $quizOption): ?>
                <option value="<?= e($quizOption['key']) ?>" data-subject="<?= e($quizOption['subject']) ?>"><?= e($quizOption['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="search-student">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
              <input type="text" id="studentSearch" placeholder="Search student...">
            </div>
            <button class="export-btn" id="exportReportBtn" type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 17V3" /><path d="m17 8-5-5-5 5" /><path d="M5 21h14" /></svg>
              Export Report
            </button>
          </div>
        </div>

        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Student Name</th>
                <th class="text-center">Subject</th>
                <th>Quiz Name</th>
                <th class="text-center">Score</th>
                <th>Progress</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody id="studentTableBody">
              <?php if (!empty($students)): ?>
                <?php foreach ($students as $student): ?>
                  <tr data-subject="<?= e($student['subject']) ?>"
                      data-subject-label="<?= e($student['subject_label']) ?>"
                      data-class="<?= e($student['class_key']) ?>"
                      data-quiz="<?= e((string) $student['quiz_id']) ?>"
                      data-name="<?= e($student['name']) ?>"
                      data-quiz-title="<?= e($student['quiz_title']) ?>"
                      data-result-id="<?= e($student['result_id']) ?>"
                      data-attempt-date="<?= e($student['attempt_date']) ?>"
                      data-student-score="<?= e($student['score']) ?>"
                      data-engagement="<?= e($student['engagement']) ?>"
                      data-initials="<?= e($student['initials']) ?>"
                      data-badge="<?= e($student['badge']) ?>">
                    <td>
                      <div class="student-cell">
                        <div class="student-avatar"><?= e($student['initials']) ?></div>
                        <span class="student-name"><?= e($student['name']) ?></span>
                      </div>
                    </td>
                    <td class="text-center"><?= e($student['subject_label']) ?></td>
                    <td><?= e($student['quiz_title']) ?></td>
                    <td class="text-center">
                      <span class="score-badge <?= e($student['badge']) ?>" data-score="<?= e($student['score']) ?>"><?= e($student['score']) ?>%</span>
                    </td>
                    <td>
                      <div class="engagement-bar">
                        <div class="progress-container">
                          <div class="progress-fill" style="width: <?= e($student['engagement']) ?>%;"></div>
                        </div>
                        <div class="engagement-text"><?= e($student['engagement']) ?>%</div>
                      </div>
                    </td>
                    <td class="text-center">
                      <button class="view-btn" type="button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                        View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" style="text-align:center;padding:36px 20px;color:var(--muted);">

                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="modal-overlay" id="studentViewModal" aria-hidden="true">
          <div class="sv-modal" role="dialog" aria-modal="true" aria-labelledby="studentViewModalTitle" style="width:min(680px, 100%);">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
              <div class="student-avatar" id="svModalInitials" style="width:52px;height:52px;font-size:18px;flex:0 0 auto;"></div>
              <div>
                <h3 id="studentViewModalTitle" style="margin:0;"></h3>
                <p id="svModalSubject" style="margin:2px 0 0;color:var(--muted);font-size:14px;"></p>
              </div>
              <span id="svModalScoreBadge" class="score-badge" style="margin-left:auto;"></span>
            </div>
            <div id="svModalQuestions" style="display:flex;flex-direction:column;gap:12px;"></div>
            <div id="svModalRecommendation" style="margin-top:18px;"></div>
            <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
              <button type="button" class="export-btn" id="studentViewModalClose">Close</button>
              <button type="button" class="export-btn sv-send-btn" id="svRecSendBtn">Send</button>
            </div>
          </div>
        </div>


        <section class="charts-grid">
          <article class="chart-card">
            <h3>Score by Student</h3>
            <div id="scoreByStudentChart">
            <?php if (!empty($chartBars)): ?>
              <div class="chart-wrapper">
                <?php foreach ($chartBars as $bar):
                  $barHeightPx = max(6, (int) round($bar['value'] / 100 * 220));
                ?>
                  <div class="bar-chart">
                    <div class="bar-value"><?= e($bar['value']) ?>%</div>
                    <div class="bar <?= e($bar['badge']) ?>" style="height: <?= e($barHeightPx) ?>px;"></div>
                    <div class="bar-label"><?= e($bar['name']) ?><small><?= e($bar['quiz_title']) ?></small></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p style="color:var(--muted);padding:20px 0;">No quiz attempts to chart yet.</p>
            <?php endif; ?>
            </div>
          </article>

          <article class="chart-card">
            <h3>Grade Distribution</h3>
            <p class="section-subtitle" style="margin:-10px 0 20px;">A/B/C/D breakdown across all quiz attempts shown above</p>
            <?php
              $gradeTotal = array_sum($gradeDistribution);

              $gradeSegments = [
                  'a' => ['light' => '#6FE3AA', 'base' => '#2DBE78', 'dark' => '#1B8F5A'],
                  'b' => ['light' => '#6FC2FF', 'base' => '#0E80D7', 'dark' => '#0A5FA0'],
                  'c' => ['light' => '#FFDE7A', 'base' => '#F2C230', 'dark' => '#C79A12'],
                  'd' => ['light' => '#FF8FA0', 'base' => '#EE3D59', 'dark' => '#B82D44'],
              ];
              $gradeStops = [];
              $cumulative = 0;
              foreach ($gradeSegments as $key => $colors) {
                  $count = $gradeDistribution[$key];
                  $pct = $gradeTotal > 0 ? ($count / $gradeTotal * 100) : 0;
                  if ($pct <= 0) {
                      continue;
                  }
                  $start = $cumulative;
                  $mid = $cumulative + $pct / 2;
                  $end = $cumulative + $pct;
                  $gradeStops[] = sprintf('%s %.3f%%, %s %.3f%%, %s %.3f%%', $colors['light'], $start, $colors['base'], $mid, $colors['dark'], $end);
                  $cumulative = $end;
              }
              $gradeGradientCss = implode(', ', $gradeStops);
            ?>
            <div id="gradeDistributionChart">
            <?php if ($gradeTotal > 0): ?>
              <div class="grade-donut-wrap">
                <div class="grade-donut" style="background: conic-gradient(from -90deg, <?= $gradeGradientCss ?>);">
                  <div class="grade-donut-hole"><span data-count-to="<?= e($gradeTotal) ?>">0</span><small>attempts</small></div>
                </div>
                <ul class="grade-legend">
                  <li><span class="grade-dot" style="background:linear-gradient(135deg,#6FE3AA,#1B8F5A);"></span>A (90+): <?= e($gradeDistribution['a']) ?></li>
                  <li><span class="grade-dot" style="background:linear-gradient(135deg,#6FC2FF,#0A5FA0);"></span>B (75-89): <?= e($gradeDistribution['b']) ?></li>
                  <li><span class="grade-dot" style="background:linear-gradient(135deg,#FFDE7A,#C79A12);"></span>C (60-74): <?= e($gradeDistribution['c']) ?></li>
                  <li><span class="grade-dot" style="background:linear-gradient(135deg,#FF8FA0,#B82D44);"></span>D (&lt;60): <?= e($gradeDistribution['d']) ?></li>
                </ul>
              </div>
            <?php else: ?>
              <p style="color:var(--muted);padding:20px 0;">No quiz attempts to chart yet.</p>
            <?php endif; ?>
            </div>
          </article>
        </section>
      </section>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="teacher-shared.js"></script>
  <script>
    (function () {
      const subjectFilter = document.getElementById('subjectFilter');
      const classFilter = document.getElementById('classFilter');
      const quizFilter = document.getElementById('quizFilter');
      const searchInput = document.getElementById('studentSearch');
      const tableBody = document.getElementById('studentTableBody');
      const exportBtn = document.getElementById('exportReportBtn');
      const rows = tableBody ? Array.from(tableBody.querySelectorAll('tr[data-name]')) : [];
      const scoreChartEl = document.getElementById('scoreByStudentChart');
      const gradeChartEl = document.getElementById('gradeDistributionChart');

      function notify(message) {
        if (typeof window.showToast === 'function') {
          window.showToast(message);
        }
      }

      const quizOptionEls = quizFilter ? Array.from(quizFilter.querySelectorAll('option[value]:not([value="all"])')) : [];

      function syncQuizOptionsToSubject() {
        if (!quizFilter) return;
        const subject = subjectFilter ? subjectFilter.value : 'all';
        let selectedIsHidden = false;

        quizOptionEls.forEach((opt) => {
          const matches = subject === 'all' || opt.dataset.subject === subject;
          opt.hidden = !matches;
          opt.disabled = !matches;
          if (!matches && opt.value === quizFilter.value) {
            selectedIsHidden = true;
          }
        });

        if (selectedIsHidden) {
          quizFilter.value = 'all';
        }
      }

      function matchesFilters(row) {
        const subject = subjectFilter ? subjectFilter.value : 'all';
        const cls = classFilter ? classFilter.value : 'all';
        const quiz = quizFilter ? quizFilter.value : 'all';
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

        const matchesSubject = !subject || subject === 'all' || row.dataset.subject === subject;
        const matchesClass = !cls || cls === 'all' || row.dataset.class === cls;
        const matchesQuiz = !quiz || quiz === 'all' || row.dataset.quiz === quiz;
        const matchesQuery = !query || (row.dataset.name || '').toLowerCase().includes(query);

        return matchesSubject && matchesClass && matchesQuiz && matchesQuery;
      }

      function getVisibleRows() {
        return rows.filter((row) => matchesFilters(row));
      }


      function applyFilters() {
        let visibleCount = 0;
        rows.forEach((row) => {
          const visible = matchesFilters(row);
          row.style.display = visible ? '' : 'none';
          if (visible) visibleCount++;
        });
        return visibleCount;
      }


      function recomputeStats(visibleRows) {
        const data = visibleRows.map((row) => ({
          score: parseInt(row.dataset.studentScore, 10) || 0,
          engagement: parseInt(row.dataset.engagement, 10) || 0,
        }));

        const total = data.length;
        const totalScore = data.reduce((sum, d) => sum + d.score, 0);
        const atRiskCount = data.filter((d) => d.score < 60).length;
        const avgEngagement = total > 0 ? Math.round(totalScore / total) : 0;
        const avgScore = avgEngagement;

        const setStat = (id, value, change) => {
          const valueEl = document.getElementById(`stat-value-${id}`);
          const changeEl = document.getElementById(`stat-change-${id}`);
          if (valueEl) valueEl.textContent = value;
          if (changeEl) changeEl.textContent = change;
        };

        const trendText = (delta, suffix) => {
          const rounded = Math.round(delta);
          if (rounded > 0) return `↗ +${rounded}${suffix || ''} vs earlier attempts`;
          if (rounded < 0) return `↘ ${rounded}${suffix || ''} vs earlier attempts`;
          return '→ No change vs earlier attempts';
        };

        let trends = {
          engagement: 'Not enough data yet',
          completed: 'Not enough data yet',
          atrisk: 'Not enough data yet',
          avgscore: 'Not enough data yet',
        };

        if (total >= 4) {
          const recentCount = Math.ceil(total / 2);
          const recent = data.slice(0, recentCount);
          const older = data.slice(recentCount);
          if (older.length > 0) {
            const avg = (items, field) => items.reduce((s, d) => s + d[field], 0) / items.length;
            const countAtRisk = (items) => items.filter((d) => d.score < 60).length;

            trends = {
              engagement: trendText(avg(recent, 'engagement') - avg(older, 'engagement'), 'pp'),
              completed: trendText(recent.length - older.length, ''),
              atrisk: trendText(countAtRisk(recent) - countAtRisk(older), ''),
              avgscore: trendText(avg(recent, 'score') - avg(older, 'score'), 'pp'),
            };
          }
        }

        setStat('engagement', `${avgEngagement}%`, trends.engagement);
        setStat('completed', String(total), trends.completed);
        setStat('atrisk', String(atRiskCount), trends.atrisk);
        setStat('avgscore', `${avgScore}%`, trends.avgscore);
      }


      function refreshAll() {
        const visibleCount = applyFilters();
        updateCharts();
        recomputeStats(getVisibleRows());
        return visibleCount;
      }

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

      function updateCharts() {
        const subjectRows = getVisibleRows();

        if (scoreChartEl) {
          const bars = subjectRows
            .map((row) => ({
              name: row.dataset.name || '',
              quizTitle: row.dataset.quizTitle || '',
              score: parseInt(row.dataset.studentScore, 10) || 0,
              badge: row.dataset.badge || 'good',
            }))
            .sort((a, b) => b.score - a.score)
            .slice(0, 8);

          if (bars.length === 0) {
            scoreChartEl.innerHTML = '<p style="color:var(--muted);padding:20px 0;">No quiz attempts to chart yet.</p>';
          } else {
            const barsHtml = bars.map((bar) => {
              const heightPx = Math.max(6, Math.round(bar.score / 100 * 220));
              return `<div class="bar-chart">
                <div class="bar-value">${bar.score}%</div>
                <div class="bar ${bar.badge}" style="height: ${heightPx}px;"></div>
                <div class="bar-label">${escapeHtml(bar.name)}<small>${escapeHtml(bar.quizTitle)}</small></div>
              </div>`;
            }).join('');
            scoreChartEl.innerHTML = `<div class="chart-wrapper">${barsHtml}</div>`;
          }
        }

        if (gradeChartEl) {
          const counts = { a: 0, b: 0, c: 0, d: 0 };
          subjectRows.forEach((row) => {
            const score = parseInt(row.dataset.studentScore, 10) || 0;
            if (score >= 90) counts.a++;
            else if (score >= 75) counts.b++;
            else if (score >= 60) counts.c++;
            else counts.d++;
          });
          const total = counts.a + counts.b + counts.c + counts.d;

          if (total === 0) {
            gradeChartEl.innerHTML = '<p style="color:var(--muted);padding:20px 0;">No quiz attempts to chart yet.</p>';
          } else {
            const segments = [
              { key: 'a', light: '#6FE3AA', base: '#2DBE78', dark: '#1B8F5A', label: 'A (90+)' },
              { key: 'b', light: '#6FC2FF', base: '#0E80D7', dark: '#0A5FA0', label: 'B (75-89)' },
              { key: 'c', light: '#FFDE7A', base: '#F2C230', dark: '#C79A12', label: 'C (60-74)' },
              { key: 'd', light: '#FF8FA0', base: '#EE3D59', dark: '#B82D44', label: 'D (<60)' },
            ];
            let cumulative = 0;
            const stops = [];
            segments.forEach((seg) => {
              const pct = counts[seg.key] / total * 100;
              if (pct <= 0) return;
              const start = cumulative;
              const mid = cumulative + pct / 2;
              const end = cumulative + pct;
              stops.push(`${seg.light} ${start.toFixed(3)}%, ${seg.base} ${mid.toFixed(3)}%, ${seg.dark} ${end.toFixed(3)}%`);
              cumulative = end;
            });
            const legendHtml = segments.map((seg) => `
              <li><span class="grade-dot" style="background:linear-gradient(135deg,${seg.light},${seg.dark});"></span>${seg.label}: ${counts[seg.key]}</li>
            `).join('');

            gradeChartEl.innerHTML = `<div class="grade-donut-wrap">
              <div class="grade-donut" style="background: conic-gradient(from -90deg, ${stops.join(', ')});">
                <div class="grade-donut-hole"><span data-count-to="${total}">0</span><small>attempts</small></div>
              </div>
              <ul class="grade-legend">${legendHtml}</ul>
            </div>`;

            const countEl = gradeChartEl.querySelector('.grade-donut-hole span[data-count-to]');
            if (countEl) animateCount(countEl, total);
          }
        }
      }

      function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
      }

      syncQuizOptionsToSubject();

      subjectFilter?.addEventListener('change', () => {
        syncQuizOptionsToSubject();
        const count = refreshAll();
        notify(subjectFilter.value === 'all' ? 'Showing all subjects' : `Filtered to ${count} student(s)`);
      });

      classFilter?.addEventListener('change', () => {
        const count = refreshAll();
        notify(classFilter.value === 'all' ? 'Showing all classes' : `Filtered to ${count} student(s)`);
      });

      quizFilter?.addEventListener('change', () => {
        const count = refreshAll();
        notify(quizFilter.value === 'all' ? 'Showing all quizzes' : `Filtered to ${count} student(s)`);
      });

      searchInput?.addEventListener('input', () => refreshAll());

      exportBtn?.addEventListener('click', () => {
        const visibleRows = rows.filter((row) => row.style.display !== 'none');
        if (visibleRows.length === 0) {
          notify('No student data to export');
          return;
        }

        const escapeCsv = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
        const header = ['Student Name', 'Subject', 'Quiz Name', 'Score (%)', 'Engagement (%)'];
        const lines = [header.map(escapeCsv).join(',')];

        visibleRows.forEach((row) => {
          lines.push([
            row.dataset.name,
            row.dataset.subjectLabel,
            row.dataset.quizTitle,
            row.dataset.studentScore,
            row.dataset.engagement,
          ].map(escapeCsv).join(','));
        });

        const csvContent = '\ufeff' + lines.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const today = new Date().toISOString().slice(0, 10);
        link.href = url;
        link.download = `student-report-${today}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        notify('Report exported');
      });

      const answerData = <?= json_encode($answerBreakdown, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const recommendationData = <?= json_encode($recommendationBreakdown, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      const modalOverlay = document.getElementById('studentViewModal');
      const modalTitle = document.getElementById('studentViewModalTitle');
      const modalSubject = document.getElementById('svModalSubject');
      const modalInitials = document.getElementById('svModalInitials');
      const modalScoreBadge = document.getElementById('svModalScoreBadge');
      const modalQuestions = document.getElementById('svModalQuestions');
      const modalClose = document.getElementById('studentViewModalClose');

      function badgeClassForScore(score) {
        if (score >= 85) return 'excellent';
        if (score >= 70) return 'good';
        if (score >= 60) return 'warning';
        return 'danger';
      }

      function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
      }

      function renderQuestions(resultId) {
        const questions = answerData[resultId];
        if (!questions || questions.length === 0) {
          return '<p style="color:var(--muted);font-size:14px;padding:14px 0;">No per-question answer details were recorded for this attempt.</p>';
        }

        return questions.map((q, index) => {
          const imageHtml = q.image ? `<img class="qv-question-image" src="${escapeHtml(q.image)}" alt="Question ${index + 1} image">` : '';
          const topicLabel = q.topicName || 'General';

          const optionsHtml = (q.options || []).map((opt) => {
            const isCorrect = opt.letter === q.correctLetter;
            const isStudentWrongPick = !q.isCorrect && opt.letter === q.studentLetter && opt.letter !== q.correctLetter;
            const cls = isCorrect ? 'correct' : (isStudentWrongPick ? 'wrong' : '');
            return `<div class="qv-option ${cls}">
              <span class="qv-letter">${escapeHtml(opt.letter)}</span>
              <span>${escapeHtml(opt.text || '(option not found)')}</span>
            </div>`;
          }).join('');

          const noAnswerNote = q.studentLetter ? '' : `<div style="margin-top:10px;font-size:13px;color:var(--muted);">No answer was submitted for this question.</div>`;

          return `
            <div class="qv-question">
              <div class="qv-question-top">
                <span class="qv-topic">${escapeHtml(topicLabel)}</span>
                <span class="qv-marks">${q.marks} mark${q.marks === 1 ? '' : 's'}</span>
              </div>
              ${imageHtml}
              <p class="qv-question-text">Q${index + 1}. ${escapeHtml(q.question)}</p>
              <div class="qv-options">${optionsHtml}</div>
              ${noAnswerNote}
            </div>`;
        }).join('');
      }

      function renderHistoryItem(note) {
        const when = note.date ? new Date(String(note.date).replace(' ', 'T')).toLocaleDateString() : '';
        return `<div class="rec-bubble rec-note">💬 ${escapeHtml(note.text)}<div style="margin-top:4px;font-size:11px;color:var(--muted);">${when}</div></div>`;
      }

      function renderRecommendation(resultId, studentName) {
        const rec = recommendationData[resultId];
        const container = document.getElementById('svModalRecommendation');
        if (!rec) {
          container.innerHTML = '';
          return;
        }

        const firstName = (studentName || 'this student').split(' ')[0];
        let html = '';

        if (rec.weakTopics && rec.weakTopics.length) {
          html += `<div style="margin-bottom:16px;">
            <div style="font-weight:700;color:var(--text);margin-bottom:6px;font-size:14px;">Weak Topics</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
              ${rec.weakTopics.map((t) => `<span class="rec-topic-pill">${escapeHtml(t)}</span>`).join('')}
            </div>
            ${rec.materials && rec.materials.length ? `<div style="margin-top:8px;font-size:13px;color:var(--muted);">Related materials: ${rec.materials.map((m) => `"${escapeHtml(m.title)}"`).join(', ')}</div>` : ''}
          </div>`;
        }

        html += `<div id="svRecHistoryWrap" style="${(rec.sentHistory && rec.sentHistory.length) ? '' : 'display:none;'}margin-bottom:16px;">
          <div style="font-weight:700;color:var(--text);margin-bottom:6px;font-size:14px;">Previously Sent</div>
          <div id="svRecHistory" style="display:flex;flex-direction:column;gap:8px;">
            ${(rec.sentHistory || []).map(renderHistoryItem).join('')}
          </div>
        </div>`;

        html += `
          <div style="font-weight:700;color:var(--text);margin-bottom:6px;font-size:14px;">Send a Recommendation</div>
          <textarea id="svRecInput" placeholder="Write a note for ${escapeHtml(firstName)} — e.g. what to review before the next attempt. This is optional." style="width:100%;min-height:80px;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font-size:14px;font-family:inherit;resize:vertical;background:var(--bg);color:var(--text);"></textarea>
        `;

        container.innerHTML = html;

        const sendBtn = document.getElementById('svRecSendBtn');
        if (sendBtn) {
          sendBtn.disabled = false;
          sendBtn.textContent = 'Send';
          sendBtn.onclick = () => sendRecommendation(resultId, firstName);
        }
      }

      async function sendRecommendation(resultId, firstName) {
        const input = document.getElementById('svRecInput');
        const btn = document.getElementById('svRecSendBtn');
        const text = input.value.trim();
        if (!text) {
          notify('Write something before sending');
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Sending…';

        try {
          const res = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=send_recommendation&result_id=${encodeURIComponent(resultId)}&text=${encodeURIComponent(text)}`,
            credentials: 'same-origin',
          });
          const data = await res.json();

          if (data.success) {
            const rec = recommendationData[resultId];
            rec.sentHistory = rec.sentHistory || [];
            rec.sentHistory.unshift(data.recommendation);
            notify(`Sent to ${firstName}`);
            renderRecommendation(resultId, firstName);
          } else {
            notify(data.message || 'Could not send — please try again');
            btn.disabled = false;
            btn.textContent = 'Send';
          }
        } catch (err) {
          console.error(err);
          notify('Could not send — please try again');
          btn.disabled = false;
          btn.textContent = 'Send';
        }
      }

      function openModal(row) {
        if (!modalOverlay) return;
        const score = parseInt(row.dataset.studentScore, 10) || 0;
        modalTitle.textContent = `${row.dataset.name || 'Student'} — ${row.dataset.quizTitle || 'Quiz'}`;
        modalSubject.textContent = row.dataset.subjectLabel || 'General';
        modalInitials.textContent = row.dataset.initials || '';
        modalScoreBadge.className = `score-badge ${badgeClassForScore(score)}`;
        modalScoreBadge.textContent = `${score}%`;
        modalQuestions.innerHTML = renderQuestions(row.dataset.resultId);
        renderRecommendation(row.dataset.resultId, row.dataset.name);
        modalOverlay.classList.add('show');
        modalOverlay.setAttribute('aria-hidden', 'false');
      }

      function closeModal() {
        modalOverlay?.classList.remove('show');
        modalOverlay?.setAttribute('aria-hidden', 'true');
      }

      tableBody?.addEventListener('click', (event) => {
        const btn = event.target.closest('.view-btn');
        if (!btn) return;
        const row = btn.closest('tr[data-name]');
        if (row) openModal(row);
      });

      modalClose?.addEventListener('click', closeModal);
      modalOverlay?.addEventListener('click', (event) => {
        if (event.target === modalOverlay) closeModal();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeModal();
      });

      const gradeCountEl = document.querySelector('.grade-donut-hole span[data-count-to]');
      if (gradeCountEl) {
        const target = parseInt(gradeCountEl.dataset.countTo, 10) || 0;
        const duration = 700;
        const start = performance.now();
        function tick(now) {
          const progress = Math.min(1, (now - start) / duration);
          const eased = 1 - Math.pow(1 - progress, 3);
          gradeCountEl.textContent = Math.round(eased * target);
          if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
      }
    })();
  </script>
</body>
</html>