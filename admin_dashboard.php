<?php

require_once "../config/session.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SmartLearn Admin Dashboard</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>

  <!-- Admin 样式 -->
  <link rel="stylesheet" href="css/admin.css">
</head>
<body>

<?php

$currentPage = "dashboard";

require_once "includes/sidebar.php";

?>

  <!-- 主内容区 -->
   <main class="main-content">

  <div class="top-bar">

    <h1>Admin Dashboard</h1>

    <p>
      Manage users, subjects, teacher assignments, and platform activities efficiently.
    </p>

  </div>
      

    <!-- 统计卡片 -->
    <section class="stats-grid">
      <div class="stat-card">
        <div class="card-top">
          <div class="card-icon"><i class="fa-solid fa-users"></i></div>
        </div>
      <div
        class="stat-number"
        id="totalUsers">

        0

        </div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <div class="card-top">
          <div class="card-icon"><i class="fa-solid fa-user-graduate"></i></div>
        </div>
        <div
        class="stat-number"
        id="totalStudents">

        0

        </div>
        <div class="stat-label">Total Students</div>
      </div>
      <div class="stat-card">
        <div class="card-top">
          <div class="card-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
        </div>
        <div
        class="stat-number"
        id="totalTeachers">

        0

        </div>
        <div class="stat-label">Total Teachers</div>
      </div>
      <div class="stat-card">
        <div class="card-top">
          <div class="card-icon"><i class="fa-solid fa-book-open"></i></div>
        </div>
        <div
        class="stat-number"
        id="totalSubjects">

        0

        </div>
        <div class="stat-label">Total Subjects</div>
      </div>
       </section>

    <section class="content-grid">

  <!-- Recent Activities -->
  <div class="panel">

    <h2 class="panel-title">
      Recent Activities
    </h2>

    <div
        class="activity-list"
        id="recentActivityList">
    </div>

  </div>



  <!-- Quick Actions -->

  <div class="panel">

    <h2 class="panel-title">
      Quick Actions
    </h2>

    <div class="admin-buttons">

      <button class="admin-btn"
      onclick="location.href='user_management.php'">
        <i class="fa-solid fa-user-plus"></i>
        Add User
      </button>

      <button class="admin-btn"
      onclick="location.href='subject_setup.php'">
        <i class="fa-solid fa-book-open"></i>
        Add Subject
      </button>

      <button class="admin-btn"
      onclick="location.href='teacher_assignment.php'">
        <i class="fa-solid fa-chalkboard-user"></i>
        Assign Teacher
      </button>

      <button class="admin-btn"
      onclick="location.href='activity_logs.php'">
        <i class="fa-solid fa-clock-rotate-left"></i>
        View Logs
      </button>

    </div>

  </div>

</section>

    
  </main>

<script src="js/admin.js"></script>
<script src="js/dashboard.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>
</html>