<?php
/*
 * =============================================================================
 * FILE  : header.php
 * FOLDER: user/
 * ROLE  : User Dashboard
 * DESC  : HTML <head> + Google Fonts + CSS link + Mobile hamburger button
 *         + Full sidebar navigation (with cart badge & notification bell)
 *         Included at top of every User page via require_once 'header.php'
 * NEEDS : $user, $cart_count  (set before include)
 * =============================================================================
 */
?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard – GreenPoints</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="CSS/style.css">
</head>
<body>

<!-- Mobile hamburger -->
<button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="s-icon">🌱</div>
        <div class="brand-text">
            <div class="name">Green<span>Points</span></div>
        </div>
    </div>
    <nav class="sidebar-nav" id="sidebarNav">
        <div class="nav-section">Menu</div>
        <a href="#overview"  class="nav-item" data-section="overview"><span class="ni">🏠</span> Overview</a>
        <a href="#notifications" class="nav-item" data-section="notifications" onclick="if(window.innerWidth<=768)closeSidebar()">
            <span class="ni">
                <span class="notif-bell-wrap">🔔<span class="notif-dot-badge" id="sidebarNotifBadge" style="display:none"></span></span>
            </span>
            Notifications
        </a>
        <a href="#challenges" class="nav-item" data-section="challenges"><span class="ni">🎯</span> Challenges</a>
        <a href="#history"   class="nav-item" data-section="history"><span class="ni">📋</span> My Submissions</a>
        <a href="#rewards"   class="nav-item" data-section="rewards"><span class="ni">🎁</span> Rewards</a>
        <a href="user_dashboard.php?tab=cart#cart" class="nav-item" data-section="cart">
            <span class="ni">🛒</span> My Cart
            <?php if ($cart_count > 0): ?><span class="cart-badge"><?= $cart_count ?></span><?php endif; ?>
        </a>
        <a href="#feedback"  class="nav-item" data-section="feedback"><span class="ni">⭐</span> Feedback</a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="user-avatar-wrap" onclick="toggleDarkMode()" style="cursor:pointer;" title="">
                <span class="dark-toggle-ring"></span>
                <span class="dark-mode-tip" id="darkTip">🌙 Dark mode</span>
                <div class="user-avatar">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar">
                    <?php else: ?>
                        <?= strtoupper(substr($user['username'],0,1)) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info" onclick="openProfileModal()" style="cursor:pointer;flex:1;min-width:0;position:relative;">
                <span class="profile-tip" id="profileTip">👤 View Profile</span>
                <div class="uname"><?= htmlspecialchars($user['username']) ?></div>
                <div class="urole">User · <?= number_format($user['total_points']) ?> pts</div>
            </div>
        </div>
        <a href="../Login/logout.php" class="btn-logout">🚪 Sign Out</a>
    </div>
</aside>
