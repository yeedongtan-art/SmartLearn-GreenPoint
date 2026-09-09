<?php
/*
 * =============================================================================
 * FILE  : header.php
 * FOLDER: moderater/
 * ROLE  : Moderator Panel
 * DESC  : HTML <head> + Google Fonts + CSS link + Sidebar navigation
 *         Included at top of every Moderator page via require_once 'header.php'
 * NEEDS : $tab, $pending_feedback  (set before include)
 * =============================================================================
 */
?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Moderator Dashboard – GreenPoints</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="CSS/style.css">
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="icon">🌱</div>
    <div class="brand-text"><div class="name">Green<span>Points</span></div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Moderator Panel</div>
    <a href="?tab=overview"      class="nav-item <?= $tab==='overview'      ?'active':'' ?>"><span class="icon">📊</span> Overview</a>
    <a href="?tab=challenges"    class="nav-item <?= $tab==='challenges'    ?'active':'' ?>"><span class="icon">🎯</span> Manage Challenges</a>
    <a href="?tab=announcements" class="nav-item <?= $tab==='announcements' ?'active':'' ?>"><span class="icon">📢</span> Announcements</a>
    <a href="?tab=bonus"         class="nav-item <?= $tab==='bonus'         ?'active':'' ?>"><span class="icon">⭐</span> Award Bonus Points</a>
    <a href="?tab=feedback"      class="nav-item <?= $tab==='feedback'      ?'active':'' ?>">
        <span class="icon">💬</span> User Feedback
        <?php $pending_fb_count = count($pending_feedback); if ($pending_fb_count > 0): ?>
        <span style="margin-left:auto;background:#e74c3c;color:white;font-size:10px;font-weight:700;padding:2px 6px;border-radius:20px;flex-shrink:0;"><?= $pending_fb_count ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=leaderboard"   class="nav-item <?= $tab==='leaderboard'   ?'active':'' ?>"><span class="icon">🏆</span> Leaderboard</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar-wrap" onclick="toggleDarkMode()" style="position:relative;cursor:pointer;flex-shrink:0;">
        <span class="dark-toggle-ring"></span>
        <span class="dark-mode-tooltip">🌙 Switch to Dark</span>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'M', 0, 1)) ?></div>
      </div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($_SESSION['username'] ?? 'Moderator') ?></div>
        <div class="urole">Moderator</div>
      </div>
    </div>
    <a href="../Login/logout.php" class="btn-logout">🚪 Sign Out</a>
  </div>
</aside>
