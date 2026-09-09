<?php
/*
 * =============================================================================
 * FILE  : index.php
 * FOLDER: Login/
 * ROLE  : Landing / Login Page — Entry Point
 * DESC  : If logged in → redirect to correct dashboard based on role.
 *         Handles login (POST action=login) and register (POST action=register).
 *         On success/error, sets $open_modal to re-open the correct modal tab.
 *         Renders the full public landing page with auth modal.
 *         Includes header.php (top) and footer.php (bottom).
 * NEEDS : ../general/db.php  (loaded only on POST)
 * REDIRECTS TO:
 *   Admin     → ../Admin/admin_dashboard.php
 *   Moderator → ../moderater/moderator_dashboard.php
 *   User      → ../user/user_dashboard.php
 * =============================================================================
 */
session_start();

if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'admin':     header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/Admin/admin_dashboard.php'); exit;
        case 'moderator': header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/moderater/moderator_dashboard.php'); exit;
        default:          header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/User/user_dashboard.php'); exit;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_once '../general/db.php';

    if ($_POST['action'] === 'login') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        if (empty($username) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                switch ($user['role']) {
                    case 'admin':     header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/Admin/admin_dashboard.php'); exit;
                    case 'moderator': header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/moderater/moderator_dashboard.php'); exit;
                    default:          header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/User/user_dashboard.php'); exit;
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }

    } elseif ($_POST['action'] === 'register') {
        $username = trim($_POST['reg_username']);
        $email    = trim($_POST['reg_email']);
        $password = $_POST['reg_password'];
        $confirm  = $_POST['reg_confirm'];
        if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'Username or email already exists.';
            } else {
                $pdo->prepare("INSERT INTO users (username, email, password, role, total_points) VALUES (?, ?, ?, 'user', 0)")
                    ->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);
                $success = 'Account created! You can now sign in.';
            }
        }
    }
}

$open_modal = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($success)) $open_modal = 'login';
    elseif (!empty($error)) $open_modal = $_POST['action'] === 'register' ? 'register' : 'login';
}

// ── Load Header ─────────────────────────────────────────────
require_once 'login_header.php';
?>

<!-- NAV -->
<nav class="nav">
  <a href="#" class="nav-brand">
    <span class="ni">🌱</span>
    <span class="nt">Green<span>Points</span></span>
  </a>
  <div class="nav-links">
    <a href="#hiw"   class="nl-a">How It Works</a>
    <a href="#roles" class="nl-a">Roles</a>
    <a href="#cta"   class="nl-a">Get Started</a>
    <button class="btn-si" onclick="openModal('login')">Sign In</button>
    <button class="btn-su" onclick="openModal('register')">Sign Up</button>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg">
    <div class="blob blob1"></div>
    <div class="blob blob2"></div>
    <div class="blob blob3"></div>
  </div>
  <div class="hero-content">
    <div class="hero-badge">🌍 APU Sustainability Initiative</div>
    <h1>Earn Rewards for<br><span>Eco-Friendly</span> Actions</h1>
    <p>Complete sustainability challenges, submit evidence, earn Green Points, and redeem amazing eco-friendly rewards — all on one platform.</p>
    <div class="hero-btns">
      <button class="btn-hp" onclick="openModal('register')">🚀 Get Started Free</button>
      <button class="btn-ho" onclick="document.getElementById('hiw').scrollIntoView({behavior:'smooth'})">📖 Learn How It Works</button>
    </div>
    <div class="hero-stats">
      <div class="hstat"><div class="hstat-n">5+</div><div class="hstat-l">Active Challenges</div></div>
      <div class="hstat"><div class="hstat-n">5</div><div class="hstat-l">Eco Rewards</div></div>
      <div class="hstat"><div class="hstat-n">3</div><div class="hstat-l">User Roles</div></div>
      <div class="hstat"><div class="hstat-n">🌱</div><div class="hstat-l">Campus Green Initiative</div></div>
    </div>
  </div>
  <div class="scroll-hint"><span class="arr">↓</span>Scroll to learn more</div>
</section>

<!-- HOW IT WORKS -->
<section class="hiw" id="hiw">
  <div class="hiw-inner">
    <div class="sec-tag">How It Works</div>
    <div class="sec-title">Get Started in 5 Simple Steps</div>
    <div class="sec-sub">From registration to redeeming rewards — here's everything you need to know before joining.</div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-e">📝</div>
        <h3>Create Your Account</h3>
        <p>Register with your username and email. Once in, you'll have access to your personal dashboard to track points and progress.</p>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-e">🎯</div>
        <h3>Browse Active Challenges</h3>
        <p>Check the Challenges section for eco-tasks posted by Moderators — each shows the points you can earn and the deadline.</p>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-e">📸</div>
        <h3>Submit Your Evidence</h3>
        <p>Complete the eco-action (bring your own bag, cycle to campus, plant a tree), then upload a photo or video as proof.</p>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-e">✅</div>
        <h3>Get Reviewed & Earn Points</h3>
        <p>An Admin reviews your submission. If approved, Green Points are automatically added to your account right away!</p>
      </div>
      <div class="step">
        <div class="step-n">5</div>
        <div class="step-e">🎁</div>
        <h3>Redeem Your Rewards</h3>
        <p>Use your points to claim eco-friendly prizes — tote bags, plant kits, café vouchers, bamboo bottles, and more.</p>
      </div>
      <div class="step step-cta">
        <div class="sce">🌿</div>
        <h3>Ready to Join?</h3>
        <p>Start making a difference on campus today.</p>
        <button onclick="openModal('register')">Join Now →</button>
      </div>
    </div>
  </div>
</section>

<!-- ROLES -->
<section class="roles" id="roles">
  <div class="roles-inner">
    <div class="sec-tag">User Roles</div>
    <div class="sec-title">Three Roles, One Mission</div>
    <div class="sec-sub">GreenPoints has three distinct roles, each with different responsibilities and access.</div>
    <div class="roles-grid">
      <div class="role">
        <div class="role-ic">🙋</div>
        <div class="role-nm">User</div>
        <div class="role-ds">Regular APU community members who join challenges and earn points.</div>
        <ul class="role-ul">
          <li>View & join active challenges</li>
          <li>Upload photo or video evidence</li>
          <li>Track submission status</li>
          <li>Earn Green Points on approval</li>
          <li>Redeem eco-friendly rewards</li>
        </ul>
      </div>
      <div class="role">
        <div class="role-ic">🛡️</div>
        <div class="role-nm">Moderator</div>
        <div class="role-ds">Community managers who create challenges and keep the platform active.</div>
        <ul class="role-ul">
          <li>Create new eco challenges</li>
          <li>Set deadlines & point values</li>
          <li>Post community announcements</li>
          <li>Award bonus points to users</li>
          <li>View leaderboard rankings</li>
        </ul>
      </div>
      <div class="role">
        <div class="role-ic">🔑</div>
        <div class="role-nm">Admin</div>
        <div class="role-ds">System administrators with full control over the platform.</div>
        <ul class="role-ul">
          <li>Approve or reject submissions</li>
          <li>Manage all user accounts</li>
          <li>Change user roles</li>
          <li>Delete outdated challenges</li>
          <li>View full platform reports</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- TIPS -->
<section class="tips">
  <div class="tips-inner">
    <div class="sec-tag">Pro Tips</div>
    <div class="sec-title">Earn More Green Points</div>
    <div class="tips-grid">
      <div class="tip"><div class="tip-ic">📸</div><div><h4>Take Clear Photos</h4><p>Your evidence must clearly show the eco-action. Blurry or unrelated photos may be rejected by admins.</p></div></div>
      <div class="tip"><div class="tip-ic">⏰</div><div><h4>Don't Miss Deadlines</h4><p>Each challenge has an end date. Expired challenges won't accept new submissions, so act fast!</p></div></div>
      <div class="tip"><div class="tip-ic">🏆</div><div><h4>Check the Leaderboard</h4><p>Compete with friends and aim for the top spot to show your commitment to sustainability.</p></div></div>
      <div class="tip"><div class="tip-ic">🔔</div><div><h4>Read Announcements</h4><p>Moderators post new challenge alerts and updates — stay informed to never miss an opportunity.</p></div></div>
      <div class="tip"><div class="tip-ic">🎁</div><div><h4>Save for Big Rewards</h4><p>Some rewards need more points but are worth it! Save strategically instead of redeeming immediately.</p></div></div>
      <div class="tip"><div class="tip-ic">🌱</div><div><h4>Stack Multiple Challenges</h4><p>You can join multiple active challenges simultaneously. More challenges = more points earned!</p></div></div>
    </div>
  </div>
</section>

<!-- CTA BOTTOM -->
<section class="cta" id="cta">
  <h2>Ready to Make a Difference? 🌍</h2>
  <p>Join the GreenPoints community and start your eco-friendly journey today.</p>
  <div class="cta-btns">
    <button class="btn-cp" onclick="openModal('register')">🚀 Create Free Account</button>
    <button class="btn-co" onclick="openModal('login')">Already have an account? Sign In</button>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer">
  <div class="footer-brand">Green<span>Points</span></div>
  <div class="footer-txt">© 2026 GreenPoints · APU Sustainability Initiative</div>
</footer>

<!-- AUTH MODAL -->
<div class="overlay" id="overlay">
  <div class="mbox">
    <button class="mclose" onclick="closeModal()">✕</button>
    <div class="mtabs">
      <button class="mtab on"  id="tab-login"    onclick="switchTab('login')">Sign In</button>
      <button class="mtab"     id="tab-register" onclick="switchTab('register')">Register</button>
    </div>
    <div class="mbody">

      <?php if (!empty($error) && !empty($open_modal)): ?>
        <div class="alert a-err">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div class="alert a-ok">✅ <?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- LOGIN -->
      <div class="mpanel on" id="panel-login">
        <div class="m-title">Welcome back 👋</div>
        <div class="m-sub">Sign in to continue your green journey</div>
        <form method="POST">
          <input type="hidden" name="action" value="login">
          <div class="fg">
            <label>Username or Email</label>
            <div class="iw">
              <span class="ico">👤</span>
              <input type="text" name="username" placeholder="Enter username or email"
                     value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
          </div>
          <div class="fg">
            <label>Password</label>
            <div class="iw">
              <span class="ico">🔒</span>
              <input type="password" name="password" id="lpw" placeholder="Enter your password" required>
              <button type="button" class="tpw" onclick="togglePw('lpw',this)">👁</button>
            </div>
          </div>
          <button type="submit" class="btn-go">Sign In →</button>
        </form>
        <div class="sw">Don't have an account? <a onclick="switchTab('register')">Register here</a></div>
      </div>

      <!-- REGISTER -->
      <div class="mpanel" id="panel-register">
        <div class="m-title">Join GreenPoints 🌱</div>
        <div class="m-sub">Create your free account and start earning</div>
        <form method="POST">
          <input type="hidden" name="action" value="register">
          <div class="fg">
            <label>Username</label>
            <div class="iw">
              <span class="ico">👤</span>
              <input type="text" name="reg_username" placeholder="Choose a username"
                     value="<?= htmlspecialchars($_POST['reg_username'] ?? '') ?>" required>
            </div>
          </div>
          <div class="fg">
            <label>Email</label>
            <div class="iw">
              <span class="ico">✉️</span>
              <input type="email" name="reg_email" placeholder="your@email.com"
                     value="<?= htmlspecialchars($_POST['reg_email'] ?? '') ?>" required>
            </div>
          </div>
          <div class="fg">
            <label>Password</label>
            <div class="iw">
              <span class="ico">🔒</span>
              <input type="password" name="reg_password" id="rpw" placeholder="At least 6 characters"
                     oninput="checkStrength(this.value)" required>
              <button type="button" class="tpw" onclick="togglePw('rpw',this)">👁</button>
            </div>
            <div class="pw-bar"><div class="pw-fill" id="pwfill"></div></div>
          </div>
          <div class="fg">
            <label>Confirm Password</label>
            <div class="iw">
              <span class="ico">🔒</span>
              <input type="password" name="reg_confirm" id="rcpw" placeholder="Re-enter password" required>
              <button type="button" class="tpw" onclick="togglePw('rcpw',this)">👁</button>
            </div>
          </div>
          <button type="submit" class="btn-go">Create Account →</button>
        </form>
        <div class="sw">Already have an account? <a onclick="switchTab('login')">Sign in</a></div>
      </div>

    </div>
  </div>
</div>


<?php require_once 'login_footer.php'; ?>