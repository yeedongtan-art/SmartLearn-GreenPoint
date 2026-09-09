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

  <title>SmartLearn - User Management</title>

  <!-- GOOGLE FONT -->

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- FONT AWESOME -->

  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    
  <link rel="stylesheet" href="css/admin.css">
</head>

<body data-userid="<?php echo $_SESSION['user_id']; ?>">
  
<?php

$currentPage = "user";

require_once "includes/sidebar.php";

?>
  <!-- ================= MAIN ================= -->

  <main class="main-content">

    <div class="top-bar">

      <h1>User Account Management</h1>

      <p>
        Manage user accounts and maintain access to the SmartLearn platform.
      </p>

    </div>

    <!-- ================= FILTERS ================= -->

    <div class="filters">

      <input id="searchInput"
        type="text"
        placeholder="Search by name or email" />

      <select id="roleFilter">

        <option value="All">All roles</option>
        <option value="Student">Student</option>
        <option value="Teacher">Teacher</option>
        <option value="Admin">Admin</option>

      </select>

      <button class="search-btn" onclick="applyFilter()">

        <i class="fa-solid fa-magnifying-glass"></i>
        Search

      </button>

      <button class="add-btn"
        onclick="addUser()">

        <i class="fa-solid fa-user-plus"></i>
        Add User

      </button>

    </div>

    <!-- ================= TABLE ================= -->

    <div class="table-container">

      <div class="table-wrapper">

        <table>

          <thead>

            <tr>

              <th>User ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Class</th>
              <th>Status</th>
              <th>Actions</th>

            </tr>

          </thead>

          <tbody id="tableBody"></tbody>

        </table>

      </div>

      <div class="footer-info">

        <span id="countInfo">
          Showing 4 of 4 results
        </span>

      </div>

    </div>

  </main>
  <!-- Reset Password Modal -->

<div class="modal-overlay" id="resetModal">

  <div class="modal">

    <h2>Reset Password</h2>

    <div class="form-group">

      <label>User</label>

      <input
        type="text"
        id="resetUser"
        readonly>

    </div>

<div class="form-group">

    <label>Temporary Password</label>

    <input
        type="text"
        id="resetPassword"
        readonly>

</div>

    <div class="modal-actions">

        <button
            class="search-btn"
            onclick="generatePassword()">

            Generate Password

        </button>

        <button
            class="cancel-btn"
            onclick="closeModal('resetModal')">

            Cancel

        </button>

        <button
            class="save-btn"
            onclick="confirmReset()">

            Confirm

        </button>

    </div>

  </div>

</div>

<!-- Add User Modal -->

<div class="modal-overlay" id="addUserModal">

  <div class="modal">

    <h2>Add New User</h2>

    <div class="form-group">

      <label>Full Name</label>

      <input
        type="text"
        id="newName">

    </div>


    <div class="form-group">

      <label>Email</label>

      <input
        type="email"
        id="newEmail">

    </div>


    <div class="form-group">

      <label>Role</label>

      <select id="newRole"
        onchange="toggleClassField()">

        <option>Student</option>
        <option>Teacher</option>
        <option>Admin</option>

      </select>

    </div>

    <div class="form-group">

    <label>

        Temporary Password

    </label>

    <input
    type="text"
    id="newPassword"
    readonly>

    </div>

    <div
        class="form-group"
        id="classGroup"
        style="display:none;">

    <label>

        Assign Class

    </label>

    <select id="newClass">

    </select>

    </div>


    <div class="modal-actions">

      <button
        class="cancel-btn"
        onclick="closeModal('addUserModal')">

        Cancel

      </button>


      <button
        class="save-btn"
        onclick="createUser()">

        Create User

      </button>

    </div>

  </div>

</div>

<!-- Edit User Modal -->

<div class="modal-overlay" id="editUserModal">

  <div class="modal">

    <h2>Edit User</h2>

    <div class="form-group">

      <label>User ID</label>

      <input
      type="text"
      id="editId"
      readonly>

    </div>

    <div class="form-group">

      <label>Full Name</label>

      <input
      type="text"
      id="editName">

    </div>

    <div class="form-group">

      <label>Email</label>

      <input
      type="email"
      id="editEmail">

    </div>

    <div class="form-group">

      <label>Role</label>

      <select
      id="editRole" disabled
      onchange="toggleEditClassField()">

        <option>Student</option>
        <option>Teacher</option>
        <option>Admin</option>

      </select>

    </div>

    <div
    class="form-group"
    id="editClassGroup"
    style="display:none;">

      <label>Assign Class</label>

      <select id="editClass">

      </select>

    </div>

    <div class="form-group">

      <label>Status</label>

      <select id="editStatus">

        <option>Active</option>
        <option>Inactive</option>

      </select>

    </div>

    <div class="modal-actions">

      <button
      class="cancel-btn"
      onclick="closeModal('editUserModal')">

        Cancel

      </button>

      <button
      class="save-btn"
      onclick="saveEditedUser()">

        Save Changes

      </button>

    </div>

  </div>

</div>

<script src="js/admin.js"></script>
<script src="js/user_management.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>