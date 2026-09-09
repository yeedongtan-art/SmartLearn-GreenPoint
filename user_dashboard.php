<?php
/*
 * =============================================================================
 * FILE  : user_dashboard.php
 * FOLDER: user/
 * ROLE  : User Dashboard — Main Page
 * DESC  : Handles all user POST actions (submit evidence, add/remove cart,
 *         checkout, save address, set default address, submit feedback,
 *         request username/avatar change).
 *         Fetches all user data (profile, challenges, submissions, rewards,
 *         cart, addresses, notifications, bonus history, feedback).
 *         Renders the full single-page dashboard with scroll-based sections.
 *         Includes header.php (top) and footer.php (bottom).
 * TABS  : overview | notifications | challenges | history | rewards | cart | feedback
 * NEEDS : ../general/db.php
 * =============================================================================
 */
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: http://' . $_SERVER['HTTP_HOST'] . 
    dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login/index.php'); exit;
}
require_once '../general/db.php';

$uid = $_SESSION['user_id'];
$msg = '';

// ── Handle: Submit Evidence ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evidence'])) {
    $challenge_id = (int)$_POST['challenge_id'];
    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','mp4','mov'];
        if (in_array($ext, $allowed)) {
            $filename = 'evidence_' . $uid . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['evidence']['tmp_name'], $upload_dir . $filename);
            $pdo->prepare("INSERT INTO submissions (user_id, challenge_id, evidence, status) 
            VALUES (?, ?, ?, 'pending')")
                ->execute([$uid, $challenge_id, $upload_dir . $filename]);
            // Redirect to same page to refresh counts (PRG pattern)
            header('Location: user_dashboard.php?submitted=1#history'); exit;
        } else { $msg = 'error:Invalid file type. Only images and videos allowed.'; }
    } else { $msg = 'error:Please select a file to upload.'; }
}

// ── Handle: Add to Cart ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $reward_id = (int)$_POST['reward_id'];
    // Check reward exists and has stock
    $rw = $pdo->prepare("SELECT * FROM rewards WHERE reward_id=? AND stock>0");
    $rw->execute([$reward_id]);
    if ($rw->fetch()) {
        // Upsert cart item
        $pdo->prepare("INSERT INTO cart (user_id, reward_id, quantity) VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1")
            ->execute([$uid, $reward_id]);
    }
    header('Location: user_dashboard.php?tab=cart#cart'); exit;
}

// ── Handle: Remove from Cart ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_cart'])) {
    $cart_id = (int)$_POST['cart_id'];
    $pdo->prepare("DELETE FROM cart WHERE cart_id=? AND user_id=?")->execute([$cart_id, $uid]);
    header('Location: user_dashboard.php?tab=cart#cart'); exit;
}

// ── Handle: Checkout (Place Order) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $address_id = (int)$_POST['address_id'];
    // Fetch address
    $addrStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE address_id=? AND user_id=?");
    $addrStmt->execute([$address_id, $uid]);
    $addr = $addrStmt->fetch();

    if (!$addr) { $msg = 'error:Please select a valid address.'; }
    else {
        $addr_snapshot = $addr['full_name'] . ', ' . $addr['phone'] . ', ' . $addr['address_line'] . ', ' . $addr['city'] . ' ' . $addr['postcode'] . ', ' . $addr['state'];
        // Fetch cart
        $cartItems = $pdo->prepare("SELECT ci.*, r.points_cost, r.reward_name, r.stock FROM cart ci JOIN rewards r ON ci.reward_id=r.reward_id WHERE ci.user_id=?");
        $cartItems->execute([$uid]);
        $cart = $cartItems->fetchAll();

        // Check points sufficiency
        $total_cost = array_sum(array_map(fn($i) => $i['points_cost'] * $i['quantity'], $cart));
        $freshUser = $pdo->prepare("SELECT total_points FROM users WHERE user_id=?");
        $freshUser->execute([$uid]);
        $curPoints = $freshUser->fetchColumn();

        if ($curPoints < $total_cost) {
            $msg = 'error:Insufficient points. You need ' . 
            $total_cost . ' pts but have ' . $curPoints . ' pts.';
        } else {
            // Process each cart item
            foreach ($cart as $item) {
                if ($item['stock'] < $item['quantity']) {
                    $msg = 'error:Not enough stock for: ' . htmlspecialchars($item['reward_name']);
                    break;
                }
                $pdo->prepare("INSERT INTO redemptions (user_id, reward_id, points_spent, 
                address_snapshot, shipping_status) VALUES (?,?,?,?,'processing')")
                    ->execute([$uid, $item['reward_id'], $item['points_cost'] * $item['quantity'], $addr_snapshot]);
                $pdo->prepare("UPDATE rewards SET stock = stock - ? WHERE reward_id=?")
                    ->execute([$item['quantity'], $item['reward_id']]);
            }
            if (!$msg) {
                $pdo->prepare("UPDATE users SET total_points = total_points - ? 
                WHERE user_id=?")->execute([$total_cost, $uid]);
                // Clear cart
                $pdo->prepare("DELETE FROM cart WHERE user_id=?")->execute([$uid]);
                header('Location: user_dashboard.php?ordered=1#overview'); exit;
            }
        }
    }
}

// ── Handle: Save Address ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_address'])) {
    $recipient = trim($_POST['full_name']);
    $phone     = trim($_POST['phone']);
    $addr_line = trim($_POST['address_line']);
    $city      = trim($_POST['city']);
    $postcode  = trim($_POST['postcode']);
    $state     = trim($_POST['state']);
    if ($recipient && $phone && $addr_line && $city && $postcode && $state) {
        // Set all existing to not default
        $pdo->prepare("UPDATE user_addresses SET is_default=0 WHERE user_id=?")->execute([$uid]);
        $pdo->prepare("INSERT INTO user_addresses (user_id, full_name, phone, address_line, city, postcode, 
        state, is_default) VALUES (?,?,?,?,?,?,?,1)")
            ->execute([$uid, $recipient, $phone, $addr_line, $city, $postcode, $state]);
        header('Location: user_dashboard.php?tab=cart&addr_saved=1#cart'); exit;
    } else { $msg = 'error:Please fill in all address fields.'; }
}

// ── Handle: Set Default Address ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default_address'])) {
    $aid = (int)$_POST['address_id'];
    $pdo->prepare("UPDATE user_addresses SET is_default=0 WHERE user_id=?")->execute([$uid]);
    $pdo->prepare("UPDATE user_addresses SET is_default=1 WHERE address_id=? AND user_id=?")->execute([$aid, $uid]);
    header('Location: user_dashboard.php?tab=cart#cart'); exit;
}

// ── Handle: Submit Feedback ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $stars   = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    if ($stars >= 1 && $stars <= 5 && strlen($comment) >= 5) {

        $existing = $pdo->prepare("SELECT COUNT(*) FROM user_feedback WHERE user_id=? 
        AND status IN ('pending','approved')");
        $existing->execute([$uid]);
        if ($existing->fetchColumn() > 0) {
            $msg = 'error:You already have a feedback submission pending or approved.';
        } else {
            $pdo->prepare("INSERT INTO user_feedback (user_id, rating, comment) VALUES (?,?,?)")
                ->execute([$uid, $stars, $comment]);
            header('Location: user_dashboard.php?feedback_sent=1#feedback'); exit;
        }
    } else { $msg = 'error:Please provide a star rating (1–5) and a comment (min 5 characters).'; }
}

// ── Handle: Profile Change Request ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_username'])) {
    $new_username = trim($_POST['new_username']);
    if (strlen($new_username) >= 3 && strlen($new_username) <= 20) {
        // Check uniqueness
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? AND user_id!=?");
        $check->execute([$new_username, $uid]);
        if ($check->fetchColumn() > 0) {
            $msg = 'error:Username already taken.';
        } else {
            $pdo->prepare("DELETE FROM profile_change_requests WHERE user_id=? AND request_type='username' AND status='pending'")->execute([$uid]);
            $pdo->prepare("INSERT INTO profile_change_requests (user_id, request_type, new_value) VALUES (?, 'username', ?)")
                ->execute([$uid, $new_username]);
            header('Location: user_dashboard.php?profile_req=1'); exit;
        }
    } else { $msg = 'error:Username must be 3–20 characters.'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_avatar'])) {
    $upload_dir = 'uploads/avatars/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['avatar_file']['tmp_name'], $upload_dir . $filename);
            $pdo->prepare("DELETE FROM profile_change_requests WHERE user_id=? AND request_type='avatar' AND status='pending'")->execute([$uid]);
            $pdo->prepare("INSERT INTO profile_change_requests (user_id, request_type, new_value) VALUES (?, 'avatar', ?)")
                ->execute([$uid, $upload_dir . $filename]);
            header('Location: user_dashboard.php?profile_req=1'); exit;
        } else { $msg = 'error:Avatar must be jpg, png, gif, or webp.'; }
    } else { $msg = 'error:Please select an image file.'; }
}

// ── Handle: Change Password ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $newPw   = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id=?");
    $stmt->execute([$uid]); $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password'])) {
        $msg = 'error:Current password is incorrect.';
    } elseif (strlen($newPw) < 6) {
        $msg = 'error:New password must be at least 6 characters.';
    } elseif ($newPw !== $confirm) {
        $msg = 'error:New passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")
        ->execute([password_hash($newPw, PASSWORD_BCRYPT), $uid]);
        $msg = 'Password changed successfully!';
    }
}

// ── Fetch Data ───────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

$challenges = $pdo->query("SELECT * FROM challenges WHERE end_date >= CURDATE() ORDER BY end_date ASC")->fetchAll();

$stmt2 = $pdo->prepare("SELECT s.*, c.title, c.points FROM submissions s JOIN challenges c ON s.challenge_id=c.challenge_id WHERE s.user_id=? ORDER BY s.submitted_date DESC");
$stmt2->execute([$uid]);
$submissions = $stmt2->fetchAll();

$rewards = $pdo->query("SELECT * FROM rewards WHERE stock > 0 ORDER BY points_cost ASC")->fetchAll();
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ── Build unified Notifications array ─────────────────────
$notifs = [];

// Type 1: Announcements (📢 global, shown to all users)
foreach ($announcements as $a) {
    $notifs[] = [
        'id'    => 'ann_' . $a['announcement_id'],
        'type'  => 'announcement',
        'icon'  => '📢',
        'color' => '#1a6b4a',
        'title' => htmlspecialchars($a['title']),
        'body'  => htmlspecialchars(mb_substr($a['content'], 0, 100)) . 
        (mb_strlen($a['content']) > 100 ? '...' : ''),
        'full'  => htmlspecialchars($a['content']),
        'expandable' => mb_strlen($a['content']) > 100,
        'time'  => $a['created_at'],
    ];
}

// Type 2: Submission reviewed
$_st = $pdo->prepare("SELECT s.*, c.title AS ch_title, c.points FROM submissions s JOIN challenges c ON s.challenge_id=c.challenge_id WHERE s.user_id=? AND s.status IN ('approved','rejected') ORDER BY s.submitted_date DESC LIMIT 10");
$_st->execute([$uid]);
foreach ($_st->fetchAll() as $s) {
    $notifs[] = [
        'id'    => 'sub_' . $s['submission_id'],
        'type'  => 'submission',
        'icon'  => $s['status'] === 'approved' ? '✅' : '❌',
        'color' => $s['status'] === 'approved' ? '#1a6b4a' : '#c0392b',
        'title' => $s['status'] === 'approved' ? 'Submission Approved' : 'Submission Rejected',
        'body'  => htmlspecialchars($s['ch_title']) . ($s['status'] === 'approved' ? ' — +' . number_format($s['points']) . ' pts earned!' : ($s['review_note'] ? ' — ' . htmlspecialchars(mb_substr($s['review_note'],0,60)) : '')),
        'full'  => null,
        'expandable' => false,
        'time'  => $s['submitted_date'],
    ];
}

// Type 3: Bonus points received
$_bt = $pdo->prepare("SELECT bh.*, u.username AS mod_name FROM bonus_history bh JOIN users u ON bh.awarded_by=u.user_id WHERE bh.user_id=? ORDER BY bh.awarded_at DESC LIMIT 5");
$_bt->execute([$uid]);
foreach ($_bt->fetchAll() as $b) {
    $notifs[] = [
        'id'    => 'bonus_' . $b['bonus_id'],
        'type'  => 'bonus',
        'icon'  => '🎁',
        'color' => '#b8860b',
        'title' => 'Bonus Points Received',
        'body'  => '+' . number_format($b['points']) . ' pts from ' . htmlspecialchars($b['mod_name']) . ($b['reason'] ? ' — ' . htmlspecialchars(mb_substr($b['reason'],0,60)) : ''),
        'full'  => null,
        'expandable' => false,
        'time'  => $b['awarded_at'],
    ];
}

// Type 4: Redemption status shipped / delivered
$_rt = $pdo->prepare("SELECT rd.*, r.reward_name FROM redemptions rd JOIN rewards r ON rd.reward_id=r.reward_id WHERE rd.user_id=? AND rd.shipping_status IN ('shipped','delivered') ORDER BY rd.redemption_date DESC LIMIT 5");
$_rt->execute([$uid]);
foreach ($_rt->fetchAll() as $rd) {
    $notifs[] = [
        'id'    => 'redeem_' . $rd['redemption_id'] . '_' . $rd['shipping_status'],
        'type'  => 'redemption',
        'icon'  => $rd['shipping_status'] === 'delivered' ? '📦' : '🚚',
        'color' => '#2471a3',
        'title' => $rd['shipping_status'] === 'delivered' ? 'Reward Delivered' : 'Reward Shipped',
        'body'  => htmlspecialchars($rd['reward_name']) . ' is now ' . ucfirst($rd['shipping_status']),
        'full'  => null,
        'expandable' => false,
        'time'  => $rd['redemption_date'],
    ];
}

// Type 5: Profile change request result
$_pt = $pdo->prepare("SELECT * FROM profile_change_requests WHERE user_id=? AND status IN ('approved','rejected') ORDER BY requested_at DESC LIMIT 5");
$_pt->execute([$uid]);
foreach ($_pt->fetchAll() as $pc) {
    $notifs[] = [
        'id'    => 'pcr_' . $pc['request_id'],
        'type'  => 'profile',
        'icon'  => $pc['status'] === 'approved' ? '✅' : '❌',
        'color' => $pc['status'] === 'approved' ? '#1a6b4a' : '#c0392b',
        'title' => ucfirst($pc['request_type']) . ' Change ' . ucfirst($pc['status']),
        'body'  => 'Your ' . htmlspecialchars($pc['request_type']) . ' change request was ' . htmlspecialchars($pc['status']),
        'full'  => null,
        'expandable' => false,
        'time'  => $pc['requested_at'],
    ];
}

// Sort all by time desc
usort($notifs, fn($a,$b) => strtotime($b['time']) - strtotime($a['time']));
$notif_ids_json   = json_encode(array_column($notifs,'id'));
$notif_total      = count($notifs);

// Cart
$cartStmt = $pdo->prepare("SELECT ci.*, r.reward_name, r.description, r.points_cost, r.stock FROM cart ci JOIN rewards r ON ci.reward_id=r.reward_id WHERE ci.user_id=?");
$cartStmt->execute([$uid]);
$cart = $cartStmt->fetchAll();
$cart_total = array_sum(array_map(fn($i) => $i['points_cost'] * $i['quantity'], $cart));
$cart_count = count($cart);

// Addresses
$addrStmt2 = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, created_at DESC");
$addrStmt2->execute([$uid]);
$addresses = $addrStmt2->fetchAll();
$default_addr = $addresses[0] ?? null;

// Bonus history for overview
$bonusStmt = $pdo->prepare("SELECT bph.*, u.username AS mod_name FROM bonus_history bph JOIN users u ON bph.awarded_by=u.user_id WHERE bph.user_id=? ORDER BY bph.awarded_at DESC LIMIT 5");
$bonusStmt->execute([$uid]);
$bonus_history = $bonusStmt->fetchAll();

// Approved feedback (public)
$feedbacks = $pdo->query("SELECT uf.*, u.username FROM user_feedback uf JOIN users u ON uf.user_id=u.user_id WHERE uf.status='approved' ORDER BY uf.created_at DESC")->fetchAll();

// My feedback
$myFeedbackStmt = $pdo->prepare("SELECT * FROM user_feedback WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
$myFeedbackStmt->execute([$uid]);
$my_feedback = $myFeedbackStmt->fetch();

// Stats
$approved_count  = count(array_filter($submissions, fn($s) => $s['status'] === 'approved'));
$redeem_count_s  = $pdo->prepare("SELECT COUNT(*) FROM redemptions WHERE user_id=?");
$redeem_count_s->execute([$uid]); $redeem_count = $redeem_count_s->fetchColumn();

// Pending profile requests
$pendingReqStmt = $pdo->prepare("SELECT * FROM profile_change_requests WHERE user_id=? AND status='pending'");
$pendingReqStmt->execute([$uid]);
$pending_requests = $pendingReqStmt->fetchAll();
$pending_req_types = array_column($pending_requests, 'request_type');

$is_error  = str_starts_with($msg, 'error:');
$msg_text  = $is_error ? substr($msg, 6) : $msg;

// ── Load Header ─────────────────────────────────────────────
require_once 'user_header.php';
?>

<main class="main">
<div class="topbar">
        <div>
            <h1>Welcome, <?= htmlspecialchars($user['username']) ?> 👋</h1>
            <div class="date" id="liveClock"></div>
        </div>
    </div>

    <?php if ($msg_text && $is_error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($msg_text) ?></div>
    <?php elseif ($msg_text): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['submitted'])): ?>
        <div class="alert alert-success">✅ Evidence submitted! Awaiting admin review.</div>
    <?php endif; ?>
    <?php if (isset($_GET['ordered'])): ?>
        <div class="alert alert-success">🎉 Order placed! Your reward is being processed.</div>
    <?php endif; ?>
    <?php if (isset($_GET['feedback_sent'])): ?>
        <div class="alert alert-success">⭐ Feedback submitted! Awaiting moderator approval.</div>
    <?php endif; ?>
    <?php if (isset($_GET['profile_req'])): ?>
        <div class="alert alert-success">📝 Request submitted! Awaiting admin approval.</div>
    <?php endif; ?>
    <?php if (isset($_GET['addr_saved'])): ?>
        <div class="alert alert-success">📍 Address saved successfully!</div>
    <?php endif; ?>

    <!-- ═══ STATS (OVERVIEW) ═══ -->
    <div id="overview" class="stats-row">
        <a class="stat-card" href="#overview" onclick="scrollToSection('overview')">
            <div class="stat-icon green">🌿</div>
            <div>
                <div class="stat-label">Green Points</div>
                <div class="stat-value"><?= number_format($user['total_points']) ?></div>
            </div>
        </a>
        <a class="stat-card" href="#challenges" onclick="scrollToSection('challenges')">
            <div class="stat-icon gold">📋</div>
            <div>
                <div class="stat-label">Submissions</div>
                <div class="stat-value"><?= count($submissions) ?></div>
            </div>
        </a>
        <a class="stat-card" href="#history" onclick="scrollToSection('history')">
            <div class="stat-icon green">✅</div>
            <div>
                <div class="stat-label">Approved</div>
                <div class="stat-value"><?= $approved_count ?></div>
            </div>
        </a>
        <a class="stat-card" href="user_dashboard.php?tab=cart#cart">
            <div class="stat-icon blue">🎁</div>
            <div>
                <div class="stat-label">Redeemed</div>
                <div class="stat-value"><?= $redeem_count ?></div>
            </div>
        </a>
    </div>

    <div class="grid-2">
        <!-- Notifications Panel -->
        <div class="card" id="notifications">
            <div class="card-header">
                <div class="notif-header-row" style="width:100%">
                    <h2 style="display:flex;align-items:center;gap:8px;">🔔 Notifications
                        <span class="notif-count-badge" id="notifCountBadge" style="display:none"></span>
                    </h2>
                    <button class="btn-mark-all" onclick="markAllRead()">Mark all read</button>
                </div>
            </div>
            <div class="card-body" style="padding:4px 20px 16px;" id="notifListWrap">
                <?php if (empty($notifs)): ?>
                    <p style="color:var(--text-muted);font-size:13px;padding:12px 0;">No notifications yet.</p>
                <?php else: ?>
                    <?php foreach ($notifs as $n): ?>
                    <div class="notif-item unread" data-notif-id="<?= htmlspecialchars($n['id']) ?>"
                         <?= $n['expandable'] ? 'onclick="toggleNotifExpand(this)"' : '' ?>
                         style="<?= $n['expandable'] ? 'cursor:pointer;' : '' ?>">
                        <div class="notif-icon-wrap"><?= $n['icon'] ?></div>
                        <div class="notif-body-wrap">
                            <div class="notif-title-line" style="color:<?= $n['color'] ?>"><?= $n['title'] ?></div>
                            <div class="notif-desc">
                                <span class="notif-preview"><?= $n['body'] ?><?= $n['expandable'] ? '<span class="notif-expand-hint">▼ read more</span>' : '' ?></span>
                                <?php if ($n['expandable']): ?>
                                <span class="notif-full"><?= $n['full'] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-time-line"><?= date('d M Y, H:i', strtotime($n['time'])) ?></div>
                        </div>
                        <div class="notif-unread-dot" id="dot_<?= htmlspecialchars($n['id']) ?>"></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Challenges -->
        <div id="challenges" class="card">
            <div class="card-header"><h2>🎯 Active Challenges</h2></div>
            <div class="card-body">
                <?php if (empty($challenges)): ?>
                    <p style="color:var(--text-muted);font-size:13px;">No active challenges right now.</p>
                <?php else: ?>
                <div class="challenge-list">
                    <?php foreach ($challenges as $ch): ?>
                    <div class="challenge-item" onclick="toggleChallenge(this)">
                        <div style="flex:1;min-width:0;">
                            <div class="ch-title"><?= htmlspecialchars($ch['title']) ?></div>
                            <div class="ch-desc-short"><?= htmlspecialchars(mb_substr($ch['description'],0,60)) ?>... <span class="ch-expand-hint">▼ click to read more</span></div>
                            <div class="ch-desc-full"><?= htmlspecialchars($ch['description']) ?></div>
                            <div class="ch-deadline">📅 Ends: <?= $ch['end_date'] ?></div>
                        </div>
                        <div class="ch-right">
                            <div class="badge-points">+<?= $ch['points'] ?> pts</div>
                            <button class="btn-submit-ev" onclick="event.stopPropagation();openEvidenceModal(<?= $ch['challenge_id'] ?>, '<?= htmlspecialchars($ch['title'], ENT_QUOTES) ?>')">Submit</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Bonus history in overview -->
    <?php if (!empty($bonus_history)): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h2>⭐ Recent Bonus Points</h2></div>
        <div class="card-body" style="padding:8px 20px;">
            <?php foreach ($bonus_history as $b): ?>
            <div class="bonus-row">
                <div>
                    <strong>+<?= $b['points'] ?> pts</strong> from <em><?= htmlspecialchars($b['mod_name']) ?></em>
                    <?php if ($b['reason']): ?> — <?= htmlspecialchars($b['reason']) ?><?php endif; ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted)"><?= date('d M Y', strtotime($b['awarded_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Submission History -->
    <div id="history" class="card">
        <div class="card-header"><h2>📋 My Submission History</h2></div>
        <div style="overflow-x:auto;">
            <?php if (empty($submissions)): ?>
                <p style="color:var(--text-muted);font-size:13px;padding:20px;">No submissions yet. Join a challenge above!</p>
            <?php else: ?>
            <table>
                <thead><tr><th>Challenge</th><th>Points</th><th>Submitted</th><th>Status</th><th>Review Note</th></tr></thead>
                <tbody>
                <?php foreach ($submissions as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['title']) ?></td>
                    <td><?= $s['points'] ?> pts</td>
                    <td><?= date('d M Y, H:i', strtotime($s['submitted_date'])) ?></td>
                    <td><span class="status-badge status-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= $s['review_note'] ? htmlspecialchars($s['review_note']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rewards -->
    <div id="rewards" class="card">
        <div class="card-header">
            <h2>🎁 Browse Rewards</h2>
            <span style="font-size:13px;color:var(--text-muted);">Your points: <strong><?= number_format($user['total_points']) ?></strong></span>
        </div>
        <div class="card-body">
            <div class="reward-grid">
                <?php
                $emojis = ['🛍️','🌱','☕','🍶','👕'];
                foreach ($rewards as $i => $r):
                $can = $user['total_points'] >= $r['points_cost'];
                ?>
                <div class="reward-card">
                    <div class="reward-emoji"><?= $emojis[$i % count($emojis)] ?></div>
                    <div class="reward-name"><?= htmlspecialchars($r['reward_name']) ?></div>
                    <div class="reward-pts"><?= $r['points_cost'] ?> pts · Stock: <?= $r['stock'] ?></div>
                    <form method="POST">
                        <input type="hidden" name="reward_id" value="<?= $r['reward_id'] ?>">
                        <button type="submit" name="add_to_cart" class="btn-cart" <?= $can ? '' : 'disabled' ?>>
                            <?= $can ? '🛒 Add to Cart' : 'Need more pts' ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Cart & Checkout -->
    <?php if ($tab === 'cart'): ?>
    <div id="cart" class="card">
        <div class="card-header">
            <h2>🛒 My Cart</h2>
            <span style="font-size:13px;color:var(--text-muted);"><?= $cart_count ?> item(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($cart)): ?>
                <p style="color:var(--text-muted);font-size:13px;">Your cart is empty. Add rewards above!</p>
            <?php else: ?>
                <?php
                $emojis2 = ['🛍️','🌱','☕','🍶','👕'];
                foreach ($cart as $j => $item):
                ?>
                <div class="cart-item-row">
                    <div class="cart-emoji"><?= $emojis2[$j % count($emojis2)] ?></div>
                    <div class="cart-info">
                        <div class="cart-name"><?= htmlspecialchars($item['reward_name']) ?></div>
                        <div class="cart-pts"><?= $item['points_cost'] ?> pts × <?= $item['quantity'] ?> = <strong><?= $item['points_cost'] * $item['quantity'] ?> pts</strong></div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                        <button type="submit" name="remove_cart" class="btn-remove">Remove</button>
                    </form>
                </div>
                <?php endforeach; ?>

                <div class="cart-total-row">
                    <div>
                        <div style="font-size:13px;color:var(--text-muted);">Total Cost</div>
                        <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--green-mid)"><?= number_format($cart_total) ?> pts</div>
                        <?php if ($user['total_points'] < $cart_total): ?>
                            <div style="font-size:12px;color:#e74c3c;margin-top:4px;">⚠️ Insufficient points (you have <?= number_format($user['total_points']) ?> pts)</div>
                        <?php else: ?>
                            <div style="font-size:12px;color:var(--green-mid);margin-top:4px;">✅ After checkout: <?= number_format($user['total_points'] - $cart_total) ?> pts remaining</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($user['total_points'] >= $cart_total): ?>
                    <button class="btn-checkout" onclick="openCheckoutModal()">Proceed to Checkout →</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Address Management -->
    <div class="card">
        <div class="card-header"><h2>📍 My Addresses</h2></div>
        <div class="card-body">
            <button class="btn-toggle-addr" onclick="toggleAddressForm()">+ Add New Address</button>
            <div id="addr-form-wrap" style="display:none;background:#f7faf8;border-radius:12px;padding:16px;margin-bottom:16px;">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group"><label>Recipient Name</label><input type="text" name="full_name" placeholder="Full name" required></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="e.g. 01x-xxxxxxx" required></div>
                    </div>
                    <div class="form-group"><label>Address Line</label><input type="text" name="address_line" placeholder="Street address, unit, block" required></div>
                    <div class="form-row">
                        <div class="form-group"><label>City</label><input type="text" name="city" placeholder="e.g. Petaling Jaya" required></div>
                        <div class="form-group"><label>Postcode</label><input type="text" name="postcode" placeholder="e.g. 47810" required></div>
                    </div>
                    <div class="form-group"><label>State</label>
                        <select name="state" required>
                            <option value="">-- Select State --</option>
                            <?php foreach (['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','Kuala Lumpur','Labuan','Putrajaya'] as $st): ?>
                            <option><?= $st ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="save_address" class="btn-save-addr">💾 Save Address</button>
                </form>
            </div>
            <?php if (empty($addresses)): ?>
                <p style="color:var(--text-muted);font-size:13px;">No addresses saved yet. Add one above.</p>
            <?php else: ?>
                <?php foreach ($addresses as $addr): ?>
                <div class="addr-card <?= $addr['is_default'] ? 'selected' : '' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <strong><?= htmlspecialchars($addr['full_name']) ?></strong> · <?= htmlspecialchars($addr['phone']) ?>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                <?= htmlspecialchars($addr['address_line']) ?>, <?= htmlspecialchars($addr['city']) ?> <?= htmlspecialchars($addr['postcode']) ?>, <?= htmlspecialchars($addr['state']) ?>
                            </div>
                        </div>
                        <?php if (!$addr['is_default']): ?>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="address_id" value="<?= $addr['address_id'] ?>">
                            <button type="submit" name="set_default_address" style="background:none;border:1px solid var(--green-mid);color:var(--green-mid);border-radius:8px;padding:4px 10px;font-size:11px;cursor:pointer;">Set Default</button>
                        </form>
                        <?php else: ?>
                        <span style="font-size:11px;background:var(--green-light);color:var(--green-mid);padding:3px 8px;border-radius:20px;font-weight:600;">✓ Default</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Feedback -->
    <div id="feedback" class="card">
        <div class="card-header"><h2>⭐ Feedback</h2></div>
        <div class="card-body">
            <?php if (!$my_feedback || $my_feedback['status'] === 'rejected'): ?>
            <div style="background:#f7faf8;border-radius:12px;padding:18px;margin-bottom:20px;">
                <h3 style="font-size:15px;font-family:'Syne',sans-serif;margin-bottom:12px;">Share Your Experience</h3>
                <form method="POST" id="feedbackForm">
                    <div style="margin-bottom:12px;">
                        <label style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:8px;">Your Rating</label>
                        <div class="star-rating" id="starRating">
                            <?php for ($s=1;$s<=5;$s++): ?>
                            <button type="button" class="star-btn" data-star="<?= $s ?>" onclick="setStars(<?= $s ?>)">⭐</button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="ratingInput" value="0">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Comment <span style="color:#ccc">(max 300 chars)</span></label>
                        <textarea name="comment" maxlength="300" rows="3" placeholder="Share your thoughts about GreenPoints..." style="width:100%;padding:10px 14px;border:2px solid #e8ede9;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;resize:vertical;outline:none;" oninput="updateCharCount(this)" required></textarea>
                        <div style="font-size:11px;color:var(--text-muted);text-align:right;" id="charCount">0 / 300</div>
                    </div>
                    <button type="submit" name="submit_feedback" style="padding:10px 22px;background:var(--green-mid);color:white;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">Submit Feedback ✓</button>
                </form>
            </div>
            <?php elseif ($my_feedback['status'] === 'pending'): ?>
            <div class="alert alert-success" style="margin-bottom:20px;">⏳ Your feedback is pending moderator approval.</div>
            <?php elseif ($my_feedback['status'] === 'approved'): ?>
            <div class="alert alert-success" style="margin-bottom:20px;">✅ Your feedback has been published.</div>
            <?php endif; ?>

            <h3 style="font-size:14px;font-family:'Syne',sans-serif;margin-bottom:12px;color:var(--text-muted);">Community Feedback</h3>
            <?php if (empty($feedbacks)): ?>
                <p style="font-size:13px;color:var(--text-muted);">No approved feedback yet.</p>
            <?php else: ?>
                <?php foreach ($feedbacks as $fb): ?>
                <div class="feedback-card">
                    <div class="feedback-meta">
                        <span class="feedback-user"><?= htmlspecialchars($fb['username']) ?></span>
                        <span class="stars-display"><?= str_repeat('★', $fb['rating']) ?><?= str_repeat('☆', 5-$fb['rating']) ?></span>
                    </div>
                    <div class="feedback-comment"><?= htmlspecialchars($fb['comment']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:6px;"><?= date('d M Y', strtotime($fb['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


<?php require_once 'user_footer.php'; ?>