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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <title>SmartLearn - Course & Subject Setup</title>

  <!-- GOOGLE FONT -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- FONT AWESOME -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="css/admin.css">

</head>

<body>

<?php

$currentPage = "subject";

require_once "includes/sidebar.php";

?>

  <!-- ================= MAIN CONTENT ================= -->

  <main class="main-content">

    <div class="top-bar">

      <div>
        <h1>Course & Subject Setup</h1>
        <p>
          Create, update and manage school subjects.
        </p>
      </div>

      <button class="add-btn" onclick="openModal('addSubjectModal')">
        <i class="fa-solid fa-plus"></i>
        Add Subject
      </button>

    </div>

    <!-- ================= STATS ================= -->
     <section class="stats-grid">

    <div class="stat-card">

        <div class="card-top">

            <div>

                <div
                class="stat-number"
                id="totalSubjects">

                    0

                </div>

                <div class="stat-label">

                    Total Subjects

                </div>

            </div>

            <div class="card-icon">

                <i class="fa-solid fa-book"></i>

            </div>

        </div>

    </div>

</section>


    <!-- ================= TABLE ================= -->

    <section class="panel">

      <h2 class="panel-title">Subjects Overview</h2>

      <div class="table-wrapper">

        <table>

          <thead>
            <tr>
              <th>Subject ID</th>
              <th>Subject Name</th>
              <th>Description</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody id="subjectTableBody"></tbody>

        </table>

      </div>

      <div class="footer-info">

      <span id="countInfo"></span>

        </div>

    </section>

  </main>

  <!-- Add Subject Modal -->

<div class="modal-overlay" id="addSubjectModal">

  <div class="modal">

    <h2>Add New Subject</h2>

    <div class="form-group">

    <label>Subject Name</label>

    <input
    type="text"
    id="newSubjectName">

  </div>

  <div class="form-group">

    <label>Description</label>

    <textarea
    id="newDescription"
    rows="4"></textarea>

  </div>


    <div class="modal-actions">

      <button
        class="cancel-btn"
        onclick="closeModal('addSubjectModal')">

        Cancel

      </button>


      <button
        class="save-btn"
        onclick="createSubject()">

        Add Subject

      </button>

    </div>

  </div>

</div>

<!-- Edit Subject Modal -->

<div class="modal-overlay" id="editSubjectModal">

  <div class="modal">

    <h2>Edit Subject</h2>

    <div class="form-group">

    <label>Subject ID</label>

    <input
    type="text"
    id="editSubjectId"
    readonly>

</div>

<div class="form-group">

    <label>Subject Name</label>

    <input
    type="text"
    id="editName">

</div>

<div class="form-group">

    <label>Description</label>

    <textarea
    id="editDescription"
    rows="4"></textarea>

</div>


    <div class="modal-actions">

      <button
      class="cancel-btn"
      onclick="closeModal('editSubjectModal')">

      Cancel

      </button>


      <button
      class="save-btn"
      onclick="saveSubjectChanges()">

      Save Changes

      </button>

    </div>

  </div>

</div>

  <script src="js/admin.js"></script>
  <script src="js/subject.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>