<?php
require_once "config/database.php";
require_once "config/session.php";

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'Student';

$totalStudents = 0;
$totalQuizzes = 0;
$totalSubjects = 0;
$totalMaterials = 0;

$q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role = 'Student' AND status = 'Active'");
if ($q && ($row = mysqli_fetch_assoc($q))) { $totalStudents = (int) $row['cnt']; }

$q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz");
if ($q && ($row = mysqli_fetch_assoc($q))) { $totalQuizzes = (int) $row['cnt']; }

$q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM subject");
if ($q && ($row = mysqli_fetch_assoc($q))) { $totalSubjects = (int) $row['cnt']; }

$q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM material");
if ($q && ($row = mysqli_fetch_assoc($q))) { $totalMaterials = (int) $row['cnt']; }

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SmartLearn · Personalized Learning Platform</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="Main.css?v=3" />
</head>
<body data-page="landing">
  <canvas id="particleCanvas" aria-hidden="true"></canvas>

  <header class="site-navbar">
    <div class="site-navbar-inner">
      <a href="#top" class="site-brand">
        <img src="smartlearn-logo.png" alt="SmartLearn" class="site-brand-logo" />
      </a>
      <nav class="site-nav-links" aria-label="Primary">
        <a href="#materials">Materials</a>
        <a href="#quizzes">Quizzes</a>
        <a href="#schedule">Schedule</a>
        <a href="#history">History</a>
        <a href="#ai-chatbot">AI Chatbot</a>
      </nav>
      <div class="site-navbar-actions">
        <button class="top-icon theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        </button>
        <?php if ($isLoggedIn): ?>
          <a href="student/dashboard.php" class="btn-primary">Go to Dashboard →</a>
        <?php else: ?>
          <a href="Admin/login.php" class="btn-primary">Login</a>
        <?php endif; ?>
      </div>
      <button class="site-nav-toggle" id="navToggle" type="button" aria-label="Open menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
    <nav class="site-nav-mobile" id="navMobile" aria-label="Mobile">
      <a href="#materials">Materials</a>
      <a href="#quizzes">Quizzes</a>
      <a href="#schedule">Schedule</a>
      <a href="#history">History</a>
      <a href="#ai-chatbot">AI Chatbot</a>
      <?php if ($isLoggedIn): ?>
        <a href="student/dashboard.php" class="btn-primary">Go to Dashboard →</a>
      <?php else: ?>
        <a href="Admin/login.php" class="btn-primary">Login</a>
      <?php endif; ?>
    </nav>
  </header>

  <main id="top">
    <section class="landing-hero">
      <div class="landing-hero-inner">
        <p class="hero-kicker">Personalized Learning, Powered by Data</p>
        <h1>Learn <span id="typedWord" data-words="Smarter,Faster,Better"></span></h1>
        <p class="hero-desc">
          SmartLearn adapts to every student — timed quizzes, topic-based materials, AI-driven
          recommendations, and a study planner that keeps you on track, all in one place.
        </p>
        <div class="cta-buttons">
          <a href="Admin/login.php" class="btn-primary btn-lg">Get Started →</a>
          <a href="#materials" class="btn-ghost">Explore Features</a>
        </div>
      </div>
      <div class="landing-hero-visual" aria-hidden="true">
        <div class="hero-preview-card">
          <div class="hpc-top"><span></span><span></span><span></span></div>
          <div class="hpc-row">
            <div>
              <p class="hpc-label">Today Focus</p>
              <p class="hpc-title">Biology: Cell Structure</p>
            </div>
            <div class="hpc-ring"><span>78%</span></div>
          </div>
          <div class="hpc-divider"></div>
          <div class="hpc-row">
            <div>
              <p class="hpc-label">Next Quiz</p>
              <p class="hpc-title-sm">12 questions</p>
            </div>
            <div class="hpc-hint">
              <p class="hpc-label">AI Hint</p>
              <p class="hpc-title-sm">Review mitochondria</p>
            </div>
          </div>
          <p class="hpc-label" style="margin-top:16px">Weekly Progress</p>
          <div class="hpc-bars">
            <span style="height:38%"></span>
            <span style="height:55%"></span>
            <span style="height:34%"></span>
            <span style="height:70%"></span>
            <span style="height:48%"></span>
            <span style="height:82%"></span>
            <span style="height:60%"></span>
          </div>
        </div>
      </div>
    </section>

    <section class="stats-strip" id="stats" aria-label="Platform statistics">
      <div class="stats-strip-inner">
        <div class="stat-chip">
          <strong data-count="<?= e($totalStudents) ?>">0</strong>
          <span>Active Students</span>
        </div>
        <div class="stat-chip">
          <strong data-count="<?= e($totalQuizzes) ?>">0</strong>
          <span>Quizzes Available</span>
        </div>
        <div class="stat-chip">
          <strong data-count="<?= e($totalSubjects) ?>">0</strong>
          <span>Subjects Covered</span>
        </div>
        <div class="stat-chip">
          <strong data-count="<?= e($totalMaterials) ?>">0</strong>
          <span>Learning Materials</span>
        </div>
      </div>
    </section>

    <section class="story" id="materials" aria-label="Learning materials">
      <div class="story-inner">
        <div class="story-copy">
          <span class="story-kicker">Choose what to learn</span>
          <h2>Learning materials organized for every study goal.</h2>
          <div class="story-rule"></div>
          <p class="story-desc">Browse subjects, pick a module, and continue right where you left off — notes and videos are grouped by topic, not scattered across folders.</p>
        </div>
        <div class="story-visual">
          <div class="material-row">
            <div class="material-thumb mt-1">
              <div class="material-thumb-img"></div>
              <div class="material-thumb-body">
                <span class="material-thumb-tag">Mathematics</span>
                <p class="material-thumb-title">Algebra &amp; Functions</p>
                <p class="material-thumb-desc">Practice formulas, patterns, equations.</p>
              </div>
            </div>
            <div class="material-thumb mt-2">
              <div class="material-thumb-img"></div>
              <div class="material-thumb-body">
                <span class="material-thumb-tag">Science</span>
                <p class="material-thumb-title">Biology Essentials</p>
                <p class="material-thumb-desc">Explore cells, systems, ecosystems.</p>
              </div>
            </div>
            <div class="material-thumb mt-3">
              <div class="material-thumb-img"></div>
              <div class="material-thumb-body">
                <span class="material-thumb-tag">English</span>
                <p class="material-thumb-title">Reading &amp; Writing</p>
                <p class="material-thumb-desc">Build comprehension and grammar.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="story dark" id="quizzes" aria-label="Interactive quizzes">
      <div class="story-inner">
        <div class="story-copy">
          <span class="story-kicker">Interactive quizzes</span>
          <h2>Generate quizzes from selected materials.</h2>
          <div class="story-rule"></div>
          <p class="story-desc">Pick a learning material and SmartLearn prepares matching questions. High scores unlock harder topics; low scores suggest a focused review path.</p>
        </div>
        <div class="story-visual">
          <div class="quiz-demo-card">
            <p class="quiz-demo-q">Which organelle is known as the powerhouse of the cell?</p>
            <div class="quiz-demo-grid">
              <div class="quiz-demo-opt">Nucleus</div>
              <div class="quiz-demo-opt correct">Mitochondria</div>
              <div class="quiz-demo-opt">Ribosome</div>
              <div class="quiz-demo-opt">Cell wall</div>
            </div>
            <p class="quiz-demo-hint">Choose an answer to check your understanding.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="story" id="schedule" aria-label="Study schedule">
      <div class="story-inner">
        <div class="story-copy">
          <span class="story-kicker">Study schedule</span>
          <h2>Plan study sessions with a calm weekly rhythm.</h2>
          <div class="story-rule"></div>
          <p class="story-desc">Auto-generated daily study steps and reminders for unfinished tasks, built around your quizzes and deadlines — not a wall of due dates.</p>
        </div>
        <div class="story-visual">
          <div class="schedule-rows">
            <div class="schedule-row today">
              <span class="schedule-day">Mon</span>
              <span class="schedule-task">Math quiz</span>
              <span class="schedule-dur">45 min</span>
            </div>
            <div class="schedule-row">
              <span class="schedule-day">Tue</span>
              <span class="schedule-task">Biology notes</span>
              <span class="schedule-dur">30 min</span>
            </div>
            <div class="schedule-row">
              <span class="schedule-day">Wed</span>
              <span class="schedule-task">English writing</span>
              <span class="schedule-dur">50 min</span>
            </div>
            <div class="schedule-row">
              <span class="schedule-day">Thu</span>
              <span class="schedule-task">Retake Algebra Quiz 3</span>
              <span class="schedule-dur">20 min</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="story story-visual-first" id="history" aria-label="Study history">
      <div class="story-inner">
        <div class="story-copy">
          <span class="story-kicker">Study history</span>
          <h2>Understand progress through visual learning records.</h2>
          <div class="story-rule"></div>
          <p class="story-desc">A personal dashboard that follows your journey — every quiz, every resource, every milestone logged in one timeline.</p>
        </div>
        <div class="story-visual">
          <div class="history-timeline">
            <div class="history-item">
              <span class="history-time">09:00</span>
              <p class="history-text">Completed Algebra mini quiz with 86% accuracy.</p>
            </div>
            <div class="history-item">
              <span class="history-time">11:30</span>
              <p class="history-text">Read Biology cell structure notes and saved 4 key points.</p>
            </div>
            <div class="history-item">
              <span class="history-time">15:10</span>
              <p class="history-text">Asked AI assistant for essay feedback and received revision tips.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- AI Chatbot -->
    <section class="story dark" id="ai-chatbot" aria-label="AI chatbot assistant">
      <div class="story-inner">
        <div class="story-copy">
          <span class="story-kicker">AI chatbot assistant</span>
          <h2>Ask questions, get hints, and revise smarter.</h2>
          <div class="story-rule"></div>
          <p class="story-desc">Ask about a wrong answer and get formulas, tips, and step-by-step help instantly, right where you're studying.</p>
        </div>
        <div class="story-visual">
          <div class="chatbot-demo-card">
            <div class="chat-preview">
              <div class="chat-bubble chat-bubble-bot">Hi, I am your SmartLearn assistant. What are you studying today?</div>
              <div class="chat-bubble chat-bubble-user">I need help with photosynthesis</div>
              <div class="chat-bubble chat-bubble-bot">Let me explain it in a simple way...</div>
            </div>
            <div class="chatbot-demo-input">
              <input type="text" placeholder="Ask a study question…" disabled />
              <button type="button" class="chatbot-demo-send" disabled>Send</button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section-head landing-section-head" id="how-it-works">
      <h3>How it works</h3>
      <p>Three steps between you and a personalized study plan.</p>
    </section>
    <section class="how-steps">
      <article class="how-step">
        <div class="how-step-num">1</div>
        <h4>Create your account</h4>
        <p>Sign in with your student login and get placed in your class automatically.</p>
      </article>
      <article class="how-step">
        <div class="how-step-num">2</div>
        <h4>Take a quiz</h4>
        <p>Attempt topic quizzes — SmartLearn scores you instantly and logs your progress.</p>
      </article>
      <article class="how-step">
        <div class="how-step-num">3</div>
        <h4>Follow your plan</h4>
        <p>Get a recommendation, a study schedule, and materials matched to your level.</p>
      </article>
    </section>

    <section class="cta-banner">
      <h3>Ready to start learning smarter?</h3>
      <p>Jump into your dashboard and pick up where you left off.</p>
      <?php if ($isLoggedIn): ?>
        <a href="student/dashboard.php" class="btn-primary btn-lg">Go to Dashboard →</a>
      <?php else: ?>
        <a href="Admin/login.php" class="btn-primary btn-lg">Login to SmartLearn →</a>
      <?php endif; ?>
    </section>
  </main>

  <footer class="site-footer">
    <div class="site-footer-inner">
      <span>© <?= date('Y') ?> SmartLearn. All rights reserved.</span>
      <a href="#top" class="back-to-top">Back to top ↑</a>
    </div>
  </footer>

  <script src="MainDashboard.js"></script>
</body>
</html>