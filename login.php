<?php

require_once "../config/session.php";

if (isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartLearn Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="css/login.css?v=<?php echo time(); ?>">
  <script>
    (function () {
      var saved = localStorage.getItem("smartlearn-theme");
      if (saved === "dark") {
        document.documentElement.setAttribute("data-theme", "dark");
      }
    })();
  </script>
</head>
<body>

<button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle dark mode">
  <i class="fa-solid fa-moon icon-moon"></i>
  <i class="fa-solid fa-sun icon-sun"></i>
</button>

<div class="container">
  <div class="left-section">
    <div>
      <div class="logo-box">
        <img src="includes/smartlearn-logo.png" alt="SmartLearn Logo" style="width:200px; height:auto; display:block;">
      </div>
      <p class="subtitle">Personalized Learning Assistant</p>
      <h2 class="hero-title">Learn Smarter, Achieve Better</h2>
      <p class="description">SmartLearn provides personalized learning recommendations, quizzes, study schedules, performance analytics, and AI-powered learning assistance to help students improve their academic performance.</p>
    </div>
  </div>
  <div class="right-section">
    <div class="login-card">
      <div>
        <h2 class="welcome">Welcome Back</h2>
        <p class="welcome-text">Sign in to continue your learning journey</p>
        <div class="input-group">
          <label>Email Address</label>
          <div class="input-wrapper">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" placeholder="Enter your email address">
          </div>
        </div>
        <div class="input-group">
          <label>Password</label>
          <div class="input-wrapper">
            <i class="fa-solid fa-lock"></i>
            <input type="password" placeholder="Enter your password">
          </div>
        </div>
          <div class="forgot-password">

          <a href="#"

          onclick="showForgotPasswordMessage()">

          Forgot Password?

          </a>

          </div>
        <button class="signin-btn" onclick="handleLogin()">Sign in</button>
        <div class="register-text">

            Don't have an account?

            <span>Please contact the administrator.</span>

        </div>
      </div>
      <a href="../index.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
      <div class="footer">© 2026 SmartLearn. All rights reserved</div>
    </div>
  </div>
</div>

<!-- Forgot Password Modal -->

<div class="modal-overlay" id="forgotPasswordModal">

<div class="modal">

<h2>

Forgot Password

</h2>

<p>

If you have forgotten your password,
please contact the administrator
to reset your account password.

</p>

<button
onclick="closeForgotPasswordMessage()">

OK

</button>

</div>

</div>
<script src="js/admin.js"></script>
<script src="js/login.js"></script>
<script>
  function toggleTheme() {
    var html = document.documentElement;
    var isDark = html.getAttribute("data-theme") === "dark";
    if (isDark) {
      html.removeAttribute("data-theme");
      localStorage.setItem("smartlearn-theme", "light");
    } else {
      html.setAttribute("data-theme", "dark");
      localStorage.setItem("smartlearn-theme", "dark");
    }
  }
</script>

</body>
</html>