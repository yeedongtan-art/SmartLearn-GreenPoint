<?php
/*
 * =============================================================================
 * FILE  : moderator_dashboard.php
 * FOLDER: moderater/
 * ROLE  : Moderator Panel — Main Dashboard
 * DESC  : Handles all moderator POST actions (create/edit/delete challenges,
 *         post/edit/delete announcements, award bonus points, approve feedback).
 *         Fetches all data, then renders tab-based content.
 *         Includes header.php (top) and footer.php (bottom).
 * TABS  : overview | challenges | announcements | bonus | feedback | leaderboard
 * NEEDS : ../general/db.php
 * =============================================================================
 */
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'moderator') {
    header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login/index.php'); exit;
}
require_once '../general/db.php';

$mod_id = $_SESSION['user_id'];
$msg = '';

// ── Handle: Create Challenge ──────────────────────────────────
if (isset($_POST['create_challenge'])) {
    $title = trim($_POST['title']);
    $desc  = trim($_POST['description']);
    $pts   = (int)$_POST['points'];
    $start = $_POST['start_date'];
    $end   = $_POST['end_date'];
    if ($title && $desc && $pts > 0 && $start && $end) {
        if ($end <= $start) {
            $msg = "error:End date must be later than start date.";
        } else {
            $pdo->prepare("INSERT INTO challenges (title,description,points,start_date,end_date,created_by) VALUES(?,?,?,?,?,?)")
                ->execute([$title,$desc,$pts,$start,$end,$mod_id]);
            header('Location: ?tab=challenges&msg=' . urlencode('Challenge created successfully!')); exit;
        }
    } else { $msg = "error:Please fill in all fields."; }
}

// ── Handle: Edit Challenge ────────────────────────────────────
if (isset($_POST['edit_challenge'])) {
    $cid   = (int)$_POST['challenge_id'];
    $title = trim($_POST['title']);
    $desc  = trim($_POST['description']);
    $pts   = (int)$_POST['points'];
    $start = $_POST['start_date'];
    $end   = $_POST['end_date'];
    if ($end <= $start) {
        $msg = "error:End date must be later than start date.";
    } else {
        $pdo->prepare("UPDATE challenges SET title=?,description=?,points=?,start_date=?,end_date=? WHERE challenge_id=? AND created_by=?")
            ->execute([$title,$desc,$pts,$start,$end,$cid,$mod_id]);
        header('Location: ?tab=challenges&msg=' . urlencode('Challenge updated.')); exit;
    }
}

// ── Handle: Delete Challenge ──────────────────────────────────
if (isset($_POST['delete_challenge'])) {
    $cid = (int)$_POST['challenge_id'];
    $pdo->prepare("DELETE FROM challenges WHERE challenge_id=? AND created_by=?")->execute([$cid,$mod_id]);
    header('Location: ?tab=challenges&msg=' . urlencode('Challenge deleted.')); exit;
}

// ── Handle: Post Announcement ─────────────────────────────────
if (isset($_POST['post_announcement'])) {
    $title   = trim($_POST['ann_title']);
    $content = trim($_POST['ann_content']);
    if ($title && $content) {
        $pdo->prepare("INSERT INTO announcements (title,content,posted_by) VALUES(?,?,?)")
            ->execute([$title,$content,$mod_id]);
        header('Location: ?tab=announcements&msg=' . urlencode('Announcement posted!')); exit;
    } else { $msg = "error:Title and content required."; }
}

// ── Handle: Edit Announcement ────────────────────────────────
if (isset($_POST['edit_announcement'])) {
    $aid     = (int)$_POST['announcement_id'];
    $title   = trim($_POST['ann_title_edit']);
    $content = trim($_POST['ann_content_edit']);
    if ($title && $content) {
        $pdo->prepare("UPDATE announcements SET title=?,content=? WHERE announcement_id=?")->execute([$title,$content,$aid]);
        header('Location: ?tab=announcements&msg=' . urlencode('Announcement updated.')); exit;
    } else { $msg = "error:Title and content required."; }
}

// ── Handle: Delete Announcement ──────────────────────────────
if (isset($_POST['delete_announcement'])) {
    $aid = (int)$_POST['announcement_id'];
    $pdo->prepare("DELETE FROM announcements WHERE announcement_id=?")->execute([$aid]);
    header('Location: ?tab=announcements&msg=' . urlencode('Announcement deleted.')); exit;
}

// ── Handle: Award Bonus Points ────────────────────────────────
if (isset($_POST['award_points'])) {
    $uid    = (int)$_POST['target_user_id'];
    $pts    = (int)$_POST['bonus_points'];
    $reason = trim($_POST['bonus_reason']);
    if ($uid && $pts > 0) {
        $pdo->prepare("UPDATE users SET total_points=total_points+?, earned_points=earned_points+? WHERE user_id=? AND role='user'")
            ->execute([$pts,$pts,$uid]);
        // Update highest_points if needed
        $pdo->prepare("UPDATE users SET highest_points=total_points WHERE user_id=? AND total_points>highest_points")->execute([$uid]);
        // Log history
        $pdo->prepare("INSERT INTO bonus_history (user_id,awarded_by,points,reason) VALUES(?,?,?,?)")
            ->execute([$uid,$mod_id,$pts,$reason]);
        header('Location: ?tab=bonus&msg=' . urlencode('Bonus points awarded!')); exit;
    } else { $msg = "error:Please select a user and enter valid points."; }
}

// ── Handle: Approve/Reject Feedback ──────────────────────────
if (isset($_POST['review_feedback'])) {
    $fid    = (int)$_POST['feedback_id'];
    $action = $_POST['feedback_action'];
    if (in_array($action, ['approved','rejected'])) {
        $pdo->prepare("UPDATE user_feedback SET status=? WHERE feedback_id=?")->execute([$action,$fid]);
        header('Location: ?tab=feedback&msg=' . urlencode('Feedback ' . $action . '.'));
        exit;
    }
}

// ── Fetch Data ────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

$challenges         = $pdo->query("SELECT ch.*, u.username AS creator FROM challenges ch LEFT JOIN users u ON ch.created_by=u.user_id ORDER BY ch.created_at DESC")->fetchAll();
$announcements      = $pdo->query("SELECT a.*, u.username AS creator FROM announcements a LEFT JOIN users u ON a.posted_by=u.user_id ORDER BY a.created_at DESC")->fetchAll();
$all_users          = $pdo->query("SELECT user_id, username, total_points, highest_points, email, created_at FROM users WHERE role='user' ORDER BY username ASC")->fetchAll();

$total_challenges    = count($challenges);
$total_announcements = count($announcements);
$active_challenges   = count(array_filter($challenges, fn($c) => $c['end_date'] >= date('Y-m-d')));
$total_participants  = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM submissions")->fetchColumn();

$leaderboard = $pdo->query("SELECT user_id, username, total_points, highest_points, email, created_at FROM users WHERE role='user' ORDER BY highest_points DESC")->fetchAll();

$bonus_log = $pdo->query("SELECT bph.*, u.username AS user_name, m.username AS mod_name FROM bonus_history bph JOIN users u ON bph.user_id=u.user_id JOIN users m ON bph.awarded_by=m.user_id ORDER BY bph.awarded_at DESC LIMIT 20")->fetchAll();

$pending_feedback = $pdo->query("SELECT uf.*, u.username FROM user_feedback uf JOIN users u ON uf.user_id=u.user_id WHERE uf.status='pending' ORDER BY uf.created_at DESC")->fetchAll();

// After redirect, read msg from GET; inline errors stay in $msg
if ($msg === '' && isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
$is_error  = str_starts_with($msg, 'error:');
$msg_text  = $is_error ? substr($msg, 6) : $msg;

// ── Load Header ─────────────────────────────────────────────
require_once 'moderator_header.php';
?>

<main class="main">
  <div class="topbar">
    <div><h1>Moderator Dashboard</h1><div class="date" id="liveClock"></div></div>
    <span class="mod-tag">🛡️ Moderator</span>
  </div>

  <?php if ($msg_text): ?>
  <div class="alert <?= $is_error ? 'alert-error' : 'alert-success' ?>">
    <?= $is_error ? '⚠️' : '✅' ?> <?= htmlspecialchars($msg_text) ?>
  </div>
  <?php endif; ?>

  <!-- ═══ OVERVIEW ═══ -->
  <?php if ($tab === 'overview'): ?>
  <div class="stats-row">
    <a href="?tab=challenges" class="stat-card"><div class="stat-icon green">🎯</div><div><div class="stat-label">Total Challenges</div><div class="stat-value"><?= $total_challenges ?></div></div></a>
    <a href="?tab=challenges" class="stat-card"><div class="stat-icon gold">✅</div><div><div class="stat-label">Active Challenges</div><div class="stat-value"><?= $active_challenges ?></div></div></a>
    <a href="?tab=announcements" class="stat-card"><div class="stat-icon orange">📢</div><div><div class="stat-label">Announcements</div><div class="stat-value"><?= $total_announcements ?></div></div></a>
    <a href="?tab=leaderboard" class="stat-card"><div class="stat-icon blue">👥</div><div><div class="stat-label">Participants</div><div class="stat-value"><?= $total_participants ?></div></div></a>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-header"><h2>➕ Create Challenge</h2></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Challenge title" required></div>
          <div class="form-group"><label>Description</label><textarea name="description" placeholder="Describe the challenge..." required></textarea></div>
          <div class="form-group"><label>Points Reward</label><input type="number" name="points" placeholder="e.g. 30" min="1" required></div>
          <div class="form-row">
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label>End Date</label><input type="date" name="end_date" required></div>
          </div>
          <button type="submit" name="create_challenge" class="btn-submit-form">🚀 Publish</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2>📢 Post Announcement</h2></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-group"><label>Title</label><input type="text" name="ann_title" placeholder="Announcement title" required></div>
          <div class="form-group"><label>Content</label><textarea name="ann_content" placeholder="Write your announcement..." required style="min-height:130px"></textarea></div>
          <button type="submit" name="post_announcement" class="btn-submit-form">📣 Post</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h2>🎯 Recent Challenges</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th>Points</th><th>End Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($challenges,0,5) as $ch): ?>
      <tr>
        <td><strong><?= htmlspecialchars($ch['title']) ?></strong></td>
        <td><?= $ch['points'] ?> pts</td>
        <td><?= $ch['end_date'] ?></td>
        <td><?= $ch['end_date'] >= date('Y-m-d') ? '<span class="badge-active">Active</span>' : '<span class="badge-expired">Expired</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ═══ CHALLENGES ═══ -->
  <?php elseif ($tab === 'challenges'): ?>
  <div class="card">
    <div class="card-header"><h2>➕ Create New Challenge</h2></div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Challenge title" required></div>
        <div class="form-group"><label>Description</label><textarea name="description" placeholder="Describe the challenge..." required></textarea></div>
        <div class="form-group"><label>Points Reward</label><input type="number" name="points" placeholder="e.g. 30" min="1" max="500" required></div>
        <div class="form-row">
          <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="form-group"><label>End Date</label><input type="date" name="end_date" required></div>
        </div>
        <button type="submit" name="create_challenge" class="btn-submit-form">🚀 Publish Challenge</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h2>📋 All Challenges</h2><span style="font-size:13px;color:var(--text-muted)"><?= $total_challenges ?> total</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th>Points</th><th>Start</th><th>End</th><th>Created By</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="challengeTbody">
      <?php foreach ($challenges as $ch): ?>
      <tr id="ch-row-<?= $ch['challenge_id'] ?>">
        <td><strong><?= htmlspecialchars($ch['title']) ?></strong><br><small style="color:var(--text-muted)"><?= htmlspecialchars(substr($ch['description'],0,50)) ?>...</small></td>
        <td><?= $ch['points'] ?> pts</td>
        <td><?= $ch['start_date'] ?></td>
        <td><?= $ch['end_date'] ?></td>
        <td><?= htmlspecialchars($ch['creator'] ?? 'System') ?></td>
        <td><?= $ch['end_date'] >= date('Y-m-d') ? '<span class="badge-active">Active</span>' : '<span class="badge-expired">Expired</span>' ?></td>
        <td style="white-space:nowrap">
          <?php if ($ch['created_by'] == $mod_id): ?>
          <button class="btn-edit" onclick="toggleEditChallenge(<?= $ch['challenge_id'] ?>)">✏️ Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this challenge?')">
            <input type="hidden" name="challenge_id" value="<?= $ch['challenge_id'] ?>">
            <button type="submit" name="delete_challenge" class="btn-delete">🗑</button>
          </form>
          <?php else: ?>
            <span style="font-size:11px;color:var(--text-muted)">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <!-- Inline Edit Row -->
      <?php if ($ch['created_by'] == $mod_id): ?>
      <tr class="edit-row" id="ch-edit-<?= $ch['challenge_id'] ?>">
        <td colspan="7">
          <form method="POST">
            <input type="hidden" name="challenge_id" value="<?= $ch['challenge_id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
              <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Title</label>
                <input class="inline-input" type="text" name="title" value="<?= htmlspecialchars($ch['title']) ?>" required></div>
              <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Points</label>
                <input class="inline-input" type="number" name="points" value="<?= $ch['points'] ?>" min="1" required></div>
            </div>
            <div style="margin-bottom:10px;"><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Description</label>
              <textarea class="inline-input" name="description" rows="2" required><?= htmlspecialchars($ch['description']) ?></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
              <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Start Date</label>
                <input class="inline-input" type="date" name="start_date" value="<?= $ch['start_date'] ?>" required></div>
              <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">End Date</label>
                <input class="inline-input" type="date" name="end_date" value="<?= $ch['end_date'] ?>" required></div>
            </div>
            <button type="submit" name="edit_challenge" class="btn-save-edit" onclick="return confirm('Save changes to this challenge?')">💾 Save</button>
            <button type="button" class="btn-cancel-edit" onclick="toggleEditChallenge(<?= $ch['challenge_id'] ?>)">Cancel</button>
          </form>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ═══ ANNOUNCEMENTS ═══ -->
  <?php elseif ($tab === 'announcements'): ?>
  <div class="card">
    <div class="card-header"><h2>📢 Post New Announcement</h2></div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group"><label>Title</label><input type="text" name="ann_title" placeholder="Announcement title" required></div>
        <div class="form-group"><label>Content</label><textarea name="ann_content" placeholder="Write your announcement here..." required style="min-height:120px"></textarea></div>
        <button type="submit" name="post_announcement" class="btn-submit-form">📣 Post Announcement</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h2>📋 All Announcements</h2></div>
    <?php if (empty($announcements)): ?>
      <div class="empty-state">No announcements yet.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th>Content</th><th>Posted By</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($announcements as $a): ?>
      <tr id="ann-row-<?= $a['announcement_id'] ?>">
        <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
        <td style="max-width:260px;font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(substr($a['content'],0,80)) ?>...</td>
        <td><?= htmlspecialchars($a['creator'] ?? 'System') ?></td>
        <td><?= date('d M Y', strtotime($a['created_at'])) ?></td>
        <td style="white-space:nowrap">
          <button class="btn-edit" onclick="toggleEditAnn(<?= $a['announcement_id'] ?>)">✏️ Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this announcement?')">
            <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
            <button type="submit" name="delete_announcement" class="btn-delete">🗑</button>
          </form>
        </td>
      </tr>
      <!-- Inline Edit Row -->
      <tr class="edit-row" id="ann-edit-<?= $a['announcement_id'] ?>">
        <td colspan="5">
          <form method="POST">
            <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
            <div style="margin-bottom:10px;"><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Title</label>
              <input class="inline-input" type="text" name="ann_title_edit" value="<?= htmlspecialchars($a['title']) ?>" required></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Content</label>
              <textarea class="inline-input" name="ann_content_edit" rows="4" required><?= htmlspecialchars($a['content']) ?></textarea></div>
            <button type="submit" name="edit_announcement" class="btn-save-edit" onclick="return confirm('Save changes to this announcement?')">💾 Save</button>
            <button type="button" class="btn-cancel-edit" onclick="toggleEditAnn(<?= $a['announcement_id'] ?>)">Cancel</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- ═══ BONUS POINTS ═══ -->
  <?php elseif ($tab === 'bonus'): ?>
  <div class="grid-2">
    <div class="card" style="margin-bottom:0">
      <div class="card-header"><h2>⭐ Award Bonus Points</h2></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-group">
            <label>Select User</label>
            <select name="target_user_id" required>
              <option value="">-- Choose a user --</option>
              <?php foreach ($all_users as $u): ?>
              <option value="<?= $u['user_id'] ?>">#<?= $u['user_id'] ?> — <?= htmlspecialchars($u['username']) ?> (<?= number_format($u['total_points']) ?> pts)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Bonus Points</label><input type="number" name="bonus_points" placeholder="e.g. 10" min="1" max="500" required></div>
          <div class="form-group"><label>Reason (optional)</label><input type="text" name="bonus_reason" placeholder="e.g. Community volunteer participation"></div>
          <button type="submit" name="award_points" class="btn-submit-form" onclick="return confirm('Award these bonus points?')">⭐ Award Points</button>
        </form>
      </div>
    </div>
    <div class="card" style="margin-bottom:0">
      <div class="card-header"><h2>👥 All Users</h2></div>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Username</th><th>Current Points</th></tr></thead>
        <tbody>
        <?php foreach ($all_users as $u): ?>
        <tr>
          <td style="color:var(--text-muted)">#<?= $u['user_id'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><strong style="color:var(--green-mid)"><?= number_format($u['total_points']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- Bonus History -->
  <div class="card">
    <div class="card-header"><h2>📜 Bonus Points History</h2></div>
    <?php if (empty($bonus_log)): ?>
      <div class="empty-state">No bonus points awarded yet.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>User</th><th>Points</th><th>Reason</th><th>Awarded By</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($bonus_log as $b): ?>
      <tr>
        <td><strong><?= htmlspecialchars($b['user_name']) ?></strong></td>
        <td><strong style="color:var(--green-mid)">+<?= $b['points'] ?> pts</strong></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= $b['reason'] ? htmlspecialchars($b['reason']) : '—' ?></td>
        <td><?= htmlspecialchars($b['mod_name']) ?></td>
        <td><?= date('d M Y, H:i', strtotime($b['awarded_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- ═══ FEEDBACK MODERATION ═══ -->
  <?php elseif ($tab === 'feedback'): ?>
  <div class="card">
    <div class="card-header"><h2>💬 Pending User Feedback</h2><span style="font-size:13px;color:var(--text-muted)"><?= count($pending_feedback) ?> pending</span></div>
    <?php if (empty($pending_feedback)): ?>
      <div class="empty-state">No pending feedback. All caught up! 🎉</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>User</th><th>Stars</th><th>Comment</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($pending_feedback as $fb): ?>
      <tr>
        <td><strong><?= htmlspecialchars($fb['username']) ?></strong></td>
        <td><span style="color:#f0c040"><?= str_repeat('★',$fb['rating']) ?><?= str_repeat('☆',5-$fb['rating']) ?></span></td>
        <td style="max-width:260px;font-size:13px"><?= htmlspecialchars($fb['comment']) ?></td>
        <td><?= date('d M Y', strtotime($fb['created_at'])) ?></td>
        <td style="white-space:nowrap">
          <form method="POST" style="display:inline">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="feedback_action" value="approved">
            <button type="submit" name="review_feedback" class="btn-approve" onclick="return confirm('Approve this feedback?')">✅ Approve</button>
          </form>
          <form method="POST" style="display:inline">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="feedback_action" value="rejected">
            <button type="submit" name="review_feedback" class="btn-reject" onclick="return confirm('Reject this feedback?')">❌ Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- ═══ LEADERBOARD ═══ -->
  <?php elseif ($tab === 'leaderboard'): ?>
  <div class="card">
    <div class="card-header"><h2>🏆 Points Leaderboard</h2><span style="font-size:12px;color:var(--text-muted)">Ranked by Highest Points</span></div>
    <?php if (empty($leaderboard)): ?>
      <div class="empty-state">No users yet.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Rank</th><th>Username</th><th>Highest Points</th><th>Current Points</th><th>Progress</th></tr></thead>
      <tbody>
      <?php $max = $leaderboard[0]['highest_points'] ?: 1; ?>
      <?php foreach ($leaderboard as $i => $u): ?>
      <tr style="cursor:pointer" onclick="openUserModal(<?= htmlspecialchars(json_encode($u)) ?>)">
        <td style="font-size:20px"><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':'#'.($i+1))) ?></td>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td><strong style="color:var(--gold)"><?= number_format($u['highest_points']) ?> pts</strong></td>
        <td style="color:var(--green-mid)"><?= number_format($u['total_points']) ?> pts</td>
        <td style="min-width:150px">
          <div style="background:#f0f5f2;border-radius:20px;height:8px;overflow:hidden">
            <div style="background:var(--green-bright);height:100%;width:<?= round($u['highest_points']/$max*100) ?>%;border-radius:20px;transition:width .5s"></div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>


<?php require_once 'moderator_footer.php'; ?>