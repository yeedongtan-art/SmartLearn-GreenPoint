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

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>SmartLearn - System Monitoring</title>

  <!-- GOOGLE FONT -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- FONT AWESOME -->
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

  <!-- ADMIN CSS -->
  <link rel="stylesheet" href="css/admin.css">
</head>

<body>

<?php

$currentPage = "monitoring";

require_once "includes/sidebar.php";

?>

  <!-- ================= MAIN CONTENT ================= -->

  <main class="main-content">


    <!-- ================= TOP BAR ================= -->

    <div class="top-bar">

      <div>

        <h1>System Monitoring</h1>

        <p>

          Monitor learning activities, quiz statistics, and educational resources across the platform.

        </p>

      </div>

    </div>


    <!-- ================= STATS ================= -->

    <section class="stats-grid">


      <div class="stat-card">

        <div class="card-top">

          <div>

            <div
                class="stat-number"
                id="activeUsers">

                0

            </div>

            <div class="stat-label">

              Active Users

            </div>

          </div>

        </div>

      </div>



      <div class="stat-card">

        <div class="card-top">

          <div>

            <div
            class="stat-number"
            id="totalMaterials">

            0

            </div>

            <div class="stat-label">

              Materials Uploaded

            </div>

          </div>

        </div>

      </div>



      <div class="stat-card">

        <div class="card-top">

          <div>

            <div
            class="stat-number"
            id="totalQuizzes">

            0

            </div>

            <div class="stat-label">

              Total Quizzes

            </div>

          </div>

        </div>

      </div>



      <div class="stat-card">

        <div class="card-top">

          <div>

            <div
            class="stat-number"
            id="completedQuizzes">

            0

            </div>

            <div class="stat-label">

              Completed Quizzes

            </div>

          </div>

        </div>

      </div>


    </section>



    <!-- ================= TWO CHARTS ================= -->

    <section class="monitor-grid">


      <!-- Area Chart -->

      <div class="panel chart-panel">

        <h2 class="panel-title">

          Quiz Completion Trend

        </h2>

        <div class="chart-placeholder">

          <canvas id="quizTrendChart"></canvas>

        </div>
       
      </div>


      <!-- Bar Chart -->

      <div class="panel chart-panel">

        <h2 class="panel-title">

          Quiz Distribution by Subject

        </h2>

        <div class="chart-placeholder">

            <canvas id="subjectDistributionChart"></canvas>

        </div>

        

      </div>


    </section>

  </main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="js/admin.js"></script>

<script src="js/system_monitoring.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>