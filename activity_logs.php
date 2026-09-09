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

  <title>SmartLearn - Activity Logs</title>

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

$currentPage = "activity";

require_once "includes/sidebar.php";

?>
  <!-- ================= MAIN CONTENT ================= -->

  <main class="main-content">


    <!-- ================= TOP BAR ================= -->

    <div class="top-bar">

      <div>

        <h1>System Activity Logs</h1>

        <p>

          Review platform-wide activity including logins, uploads, and quizzes.

        </p>

      </div>

      <button
        class="add-btn"
        onclick="exportLogs()">

        <i class="fa-solid fa-download"></i>

        Export CSV

      </button>

    </div>


    <!-- ================= LOG PANEL ================= -->

    <section class="panel activity-panel">


      <!-- FILTERS -->

      <div class="filters">

        <input
          type="text"
          id="logSearchInput"
          placeholder="Search logs by user or action..." />

        <button
          class="search-btn"
          onclick="searchLogs()">

          <i class="fa-solid fa-magnifying-glass"></i>

          Search

        </button>

      </div>


      <!-- TABLE -->

      <div class="table-container">

        <div class="table-wrapper">

          <table>

            <thead>

              <tr>

                <th>Date</th>

                <th>User</th>

                <th>Action</th>

                <th>Module</th>

                <th>Status</th>

              </tr>

            </thead>


            <tbody id="logsTableBody">

            </tbody>

          </table>

        </div>


        <div class="footer-info">

          <span id="logsCountInfo">

            Showing 0 logs

          </span>

        </div>

      </div>


    </section>

  </main>


  <script src="js/admin.js"></script>
  <script src="js/activity_logs.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>