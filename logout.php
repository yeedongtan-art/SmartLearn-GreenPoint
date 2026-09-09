<?php
/*
 * =============================================================================
 * FILE  : logout.php
 * FOLDER: Login/
 * ROLE  : All Roles
 * DESC  : Destroys the current session and redirects back to index.php (login page).
 *         Called by all role dashboards via href="../Login/logout.php"
 * =============================================================================
 */
session_start();
session_destroy();
header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login/index.php');
exit;