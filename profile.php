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

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SmartLearn - Profile</title>

    <!-- GOOGLE FONT -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

    <!-- FONT AWESOME -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- ADMIN CSS -->
    <link rel="stylesheet"
          href="css/admin.css">

</head>

<body>

<?php

$currentPage = "profile";

require_once "includes/sidebar.php";

?>

    <!-- ================= MAIN CONTENT ================= -->

    <main class="main-content">

        <div class="top-bar">

            <div>

                <h1>

                    Profile

                </h1>

                <p>

                    Manage your account information and security settings.

                </p>

            </div>

        </div>


<!-- ================= PROFILE ================= -->

<section class="profile-layout">

<!-- LEFT CARD -->

<div class="panel profile-sidebar">

    <div class="profile-avatar" id="profileAvatar">

        TY

    </div>

    <h2 id="sidebarName"></h2>

    <p
    class="profile-role"
    id="sidebarRole">
    </p>

    <div class="profile-summary">

        <div class="summary-item">

            <span>Admin ID</span>

            <strong
            id="sidebarId">
            </strong>

        </div>

        <div class="summary-item">

            <span>Email</span>

            <strong id="sidebarEmail"></strong>

        </div>

        <div class="summary-item">

            <span>Status</span>

            <strong
            class="status-active"
            id="sidebarStatus">

        </div>

        <div class="summary-item">

            <span>Last Login</span>

            <strong
            id="sidebarLastLogin">
            </strong>

        </div>

    </div>

    <input
        type="file"
        id="avatarInput"
        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
        hidden
        onchange="uploadAvatar()">

    <button
        type="button"
        class="profile-btn"
        onclick="document.getElementById('avatarInput').click()">

        <i class="fa-solid fa-camera"></i>

        Upload Photo

    </button>

</div>


    <!-- RIGHT CONTENT -->

    <div class="profile-content">


        <!-- ACCOUNT INFORMATION -->

        <section class="panel">

            <div class="table-header">

                <h2>

                    Account Information

                </h2>

                <button
                    class="add-btn"
                    onclick="updateProfile()">

                    <i class="fa-solid fa-floppy-disk"></i>

                    Save

                </button>

            </div>


            <div class="profile-info-grid">


                <div>

                    <label>

                        Name

                    </label>

                    <input
                    type="text"
                    id="profileName">

                </div>


                <div>

                    <label>

                        Admin ID

                    </label>

                    <input
                    type="text"
                    id="profileId"
                    readonly>

                </div>


                <div>

                    <label>

                        Email

                    </label>

                    <input
                    type="email"
                    id="profileEmail"
                    readonly>

                </div>

            </div>

        </section>



        <!-- SECURITY -->

        <section class="panel">

            <h2 class="panel-title">

                <i class="fa-solid fa-lock"></i>

                Security

            </h2>


            <div class="profile-info-grid">


                <div>

                    <label>

                        Current Password

                    </label>

                    <input
                    type="password"
                    id="currentPassword"
                    placeholder="Enter current password">

                </div>


                <div>

                    <label>

                        New Password

                    </label>

                    <input
                    type="password"
                    id="newPassword"
                    placeholder="Enter new password">

                </div>


                <div>

                    <label>

                        Confirm Password

                    </label>

                    <input
                    type="password"
                    id="confirmPassword"
                    placeholder="Confirm new password">

                </div>

            </div>


            <div
            style="display:flex;
            justify-content:flex-end;
            margin-top:30px;">

                <button
                class="add-btn"
                onclick="changePassword()">

                    <i class="fa-solid fa-key"></i>

                    Update Password

                </button>

            </div>

        </section>

    </div>

</section>

    </main>

    <script src="js/admin.js"></script>

    <script src="js/profile.js"></script>

<?php

require_once "includes/logout_modal.php";

?>

</body>

</html>