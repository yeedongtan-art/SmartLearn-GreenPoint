<?php
/*
 * =============================================================================
 * FILE  : db.php
 * FOLDER: general/
 * ROLE  : Shared — All Roles
 * DESC  : Single database connection file for the entire project.
 *         Creates a PDO instance ($pdo) connected to the 'greenpoints' database.
 *         Must be included at the top of every dashboard/page that needs DB access.
 * USAGE : require_once '../general/db.php';
 * USED BY:
 *   Admin/admin_dashboard.php
 *   moderater/moderator_dashboard.php
 *   user/user_dashboard.php
 *   Login/index.php  (on POST only)
 * =============================================================================
 */

$host     = 'localhost';
$dbname   = 'greenpoints';
$username = 'root';
$password = '';          // XAMPP default is empty

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
