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

  <title>SmartLearn - Teacher Assignment</title>

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

$currentPage = "teacher";

require_once "includes/sidebar.php";

?>

  <!-- ================= MAIN CONTENT ================= -->

  <main class="main-content">


    <!-- ================= TOP BAR ================= -->

    <div class="top-bar">

      <div>

        <h1>Teacher Assignment</h1>

        <p>
          Assign teachers to subjects and classes.
        </p>

      </div>

     </div>


    <!-- ================= CREATE ASSIGNMENT ================= -->

    <section class="panel">

      <h2 class="panel-title">

        <i class="fa-solid fa-plus"></i>

        Create Assignment

      </h2>


      <div class="assignment-form">


        <div>

          <label>
            Select Teacher
          </label>

          <select id="teacherSelect">

          </select>

        </div>


        <div>

          <label>
            Select Subject
          </label>

          <select id="subjectSelect">

          </select>

        </div>


        <div>

          <label>
            Assign Class
          </label>

          <select id="classSelect">

          </select>

        </div>


        <div>

          <label>

            &nbsp;

          </label>

          <button
            class="search-btn"
            onclick="createAssignment()">

            Assign

          </button>

        </div>

      </div>

    </section>


    <!-- ================= CURRENT ASSIGNMENT ================= -->

    <section class="panel assignment-panel">


      <div class="table-header">

        <h2>

          Current Assignment

        </h2>

      </div>


      <!-- INNER TABLE CARD -->

      <div class="table-container">

        <div class="table-wrapper">

          <table>

            <thead>

              <tr>

                <th>
                  Teacher Name
                </th>

                <th>
                  Subject
                </th>

                <th>
                  Class
                </th>

                <th>
                  Status
                </th>

                <th>
                  Actions
                </th>

              </tr>

            </thead>


            <tbody id="assignmentTableBody">

            </tbody>

          </table>

        </div>


        <div class="footer-info">

          <span id="assignmentCountInfo">

            Showing 0 assignments

          </span>

        </div>

      </div>

    </section>

    <!-- ================= EDIT ASSIGNMENT MODAL ================= -->

<div
class="modal-overlay"
id="editAssignmentModal">

    <div class="modal">

        <h2>

            Edit Assignment

        </h2>


        <div class="form-group">

            <label>

                Teacher

            </label>

            <select
            id="editTeacher">

            </select>

        </div>


        <div class="form-group">

            <label>

                Subject

            </label>

            <select
            id="editSubject">

            </select>

        </div>


        <div class="form-group">

            <label>

                Class

            </label>

            <select
            id="editClass">

            </select>

        </div>


        <div class="form-group">

            <label>

                Status

            </label>

            <select
            id="editStatus">

                <option value="Active">

                    Active

                </option>

                <option value="Inactive">

                    Inactive

                </option>

            </select>

        </div>


        <div class="modal-actions">

            <button
            class="cancel-btn"
            onclick="closeModal('editAssignmentModal')">

                Cancel

            </button>


            <button
            class="save-btn"
            onclick="saveAssignmentChanges()">

                Save Changes

            </button>

        </div>

    </div>

</div>
  </main>


  <!-- ADMIN JS -->

<script src="js/admin.js"></script>
<script src="js/teacher_assignment.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>