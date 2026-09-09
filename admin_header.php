<?php
/*
 * =============================================================================
 * FILE  : header.php
 * FOLDER: Admin/
 * ROLE  : Admin Panel
 * DESC  : HTML <head> + Google Fonts + CSS link + Sidebar navigation
 *         Included at top of every Admin page via require_once 'header.php'
 * NEEDS : $tab, $pending_subs, $profile_req_count  (set before include)
 * =============================================================================
 */
?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin Dashboard – GreenPoints</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="CSS/style.css">
</head>
<body>

<button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="mainSidebar">
  <div class="sidebar-brand">
    <div class="icon">🌱</div>
    <div class="brand-text">
      <div class="name">Green<span>Points</span></div>
      <div class="sub">Admin Panel</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Admin Panel</div>
    <a href="?tab=overview"    class="nav-item <?= $tab==='overview'    ?'active':'' ?>"><span class="icon">📊</span> Overview</a>
    <a href="?tab=submissions" class="nav-item <?= $tab==='submissions' ?'active':'' ?>">
      <span class="icon">📋</span> Submissions
      <?php if ($pending_subs > 0): ?><span class="badge-count"><?= $pending_subs ?></span><?php endif; ?>
    </a>
    <a href="?tab=users" class="nav-item <?= $tab==='users' ?'active':'' ?>">
      <span class="icon">👥</span> Manage Users
      <?php if ($profile_req_count > 0): ?><span class="badge-count"><?= $profile_req_count ?></span><?php endif; ?>
    </a>
    <a href="?tab=challenges"  class="nav-item <?= $tab==='challenges'  ?'active':'' ?>"><span class="icon">🎯</span> Challenges</a>
    <a href="?tab=reports"     class="nav-item <?= $tab==='reports'     ?'active':'' ?>"><span class="icon">📈</span> Reports</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar-wrap" id="avatarToggle" onclick="toggleDarkMode()">
        <span class="dark-toggle-ring"></span>
        <div class="user-avatar">A</div>
      </div>
      <div class="user-info" style="position:relative;flex:1;min-width:0;overflow:hidden;cursor:default;">
        <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
        <div class="urole">Administrator</div>
      </div>
    </div>
    <a href="../Login/logout.php" class="btn-logout">🚪 Sign Out</a>
  </div>
</aside>
