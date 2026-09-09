<?php
/*
 * =============================================================================
 * FILE  : admin_dashboard.php
 * FOLDER: Admin/
 * ROLE  : Admin Panel — Main Dashboard
 * DESC  : Handles all admin POST actions (review submissions, manage users,
 *         edit/delete challenges, update shipping, approve profile requests).
 *         Fetches all data, then renders tab-based content.
 *         Includes header.php (top) and footer.php (bottom).
 * TABS  : overview | submissions | users | challenges | reports
 * NEEDS : ../general/db.php
 * =============================================================================
 */
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login/index.php'); exit;
}
require_once '../general/db.php';

$admin_id = $_SESSION['user_id'];
$msg = '';

// ── Handle: Review Submission ─────────────────────────────────
if (isset($_POST['review_submission'])) {
    $sub_id = (int)$_POST['submission_id'];
    $action = $_POST['review_action'];
    $note   = trim($_POST['review_note'] ?? '');
    $stmt = $pdo->prepare("SELECT s.*, c.points FROM submissions s JOIN challenges c ON s.challenge_id=c.challenge_id WHERE s.submission_id=?");
    $stmt->execute([$sub_id]); $sub = $stmt->fetch();
    if ($sub && $sub['status'] === 'pending') {
        $pdo->prepare("UPDATE submissions SET status=?,reviewed_by=?,review_note=? WHERE submission_id=?")
            ->execute([$action,$admin_id,$note,$sub_id]);
        if ($action === 'approved') {
            $pdo->prepare("UPDATE users SET total_points=total_points+?, earned_points=earned_points+? WHERE user_id=?")
                ->execute([$sub['points'],$sub['points'],$sub['user_id']]);
            $pdo->prepare("UPDATE users SET highest_points=total_points WHERE user_id=? AND total_points>highest_points")->execute([$sub['user_id']]);
        }
        $msg = "Submission " . $action . " successfully.";
    }
}

// ── Handle: Delete User ───────────────────────────────────────
if (isset($_POST['delete_user'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid !== $admin_id) {
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
        $msg = "User deleted.";
    }
}

// ── Handle: Change Role ───────────────────────────────────────
if (isset($_POST['change_role'])) {
    $uid  = (int)$_POST['user_id'];
    $role = $_POST['new_role'];
    if (in_array($role, ['user','admin','moderator'])) {
        $pdo->prepare("UPDATE users SET role=? WHERE user_id=?")->execute([$role,$uid]);
        $msg = "Role updated to: " . ucfirst($role);
    }
}

// ── Handle: Delete Challenge ──────────────────────────────────
if (isset($_POST['delete_challenge'])) {
    $cid = (int)$_POST['challenge_id'];
    $pdo->prepare("DELETE FROM challenges WHERE challenge_id=?")->execute([$cid]);
    $msg = "Challenge deleted.";
}

// ── Handle: Edit Challenge ────────────────────────────────────
if (isset($_POST['edit_challenge'])) {
    $cid   = (int)$_POST['challenge_id'];
    $title = trim($_POST['title']);
    $desc  = trim($_POST['description']);
    $pts   = (int)$_POST['points'];
    $start = $_POST['start_date'];
    $end   = $_POST['end_date'];
    if ($end <= $start) { $msg = "error: End date must be later than start date."; }
    else {
        $pdo->prepare("UPDATE challenges SET title=?,description=?,points=?,start_date=?,end_date=? WHERE challenge_id=?")
            ->execute([$title,$desc,$pts,$start,$end,$cid]);
        $msg = "Challenge updated.";
    }
}

// ── Handle: Update Shipping Status ───────────────────────────
if (isset($_POST['update_shipping'])) {
    $rid    = (int)$_POST['redemption_id'];
    $status = $_POST['shipping_status'];
    if (in_array($status, ['processing','shipped','delivered'])) {
        $pdo->prepare("UPDATE redemptions SET shipping_status=? WHERE redemption_id=?")->execute([$status,$rid]);
        $msg = "Shipping status updated.";
    }
}

// ── Handle: Profile Change Requests ──────────────────────────
if (isset($_POST['review_profile_req'])) {
    $req_id = (int)$_POST['request_id'];
    $action = $_POST['req_action'];
    $stmt = $pdo->prepare("SELECT * FROM profile_change_requests WHERE request_id=? AND status='pending'");
    $stmt->execute([$req_id]); $req = $stmt->fetch();
    if ($req) {
        $pdo->prepare("UPDATE profile_change_requests SET status=?,reviewed_by=? WHERE request_id=?")->execute([$action,$admin_id,$req_id]);
        if ($action === 'approved') {
            if ($req['request_type'] === 'username') {
                $pdo->prepare("UPDATE users SET username=? WHERE user_id=?")->execute([$req['new_value'],$req['user_id']]);
            } elseif ($req['request_type'] === 'avatar') {
                $pdo->prepare("UPDATE users SET avatar=? WHERE user_id=?")->execute([$req['new_value'],$req['user_id']]);
            }
        }
        $msg = "Profile request " . $action . ".";
    }
}

// ── Fetch Data ────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

$total_users   = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
$pending_subs  = $pdo->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();
$total_pts     = $pdo->query("SELECT SUM(earned_points) FROM users WHERE role='user'")->fetchColumn() ?? 0;
$total_rewards = $pdo->query("SELECT COUNT(*) FROM redemptions")->fetchColumn();

$submissions = $pdo->query("SELECT s.*, u.username, c.title AS challenge_title, c.points FROM submissions s JOIN users u ON s.user_id=u.user_id JOIN challenges c ON s.challenge_id=c.challenge_id ORDER BY FIELD(s.status,'pending','approved','rejected'), s.submitted_date DESC")->fetchAll();
$users       = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
$challenges  = $pdo->query("SELECT ch.*, u.username AS created_by_name FROM challenges ch LEFT JOIN users u ON ch.created_by=u.user_id ORDER BY ch.created_at DESC")->fetchAll();
$redemptions = $pdo->query("SELECT r.*, u.username, rw.reward_name FROM redemptions r JOIN users u ON r.user_id=u.user_id JOIN rewards rw ON r.reward_id=rw.reward_id ORDER BY r.redemption_date DESC")->fetchAll();

$profile_reqs      = $pdo->query("SELECT pcr.*, u.username, u.email FROM profile_change_requests pcr JOIN users u ON pcr.user_id=u.user_id WHERE pcr.status='pending' ORDER BY pcr.requested_at DESC")->fetchAll();
$profile_req_count = count($profile_reqs);

// ── Load Header ───────────────────────────────────────────────
require_once 'admin_header.php';
?>

<main class="main">
  <div class="topbar">
    <div><h1>Admin Dashboard</h1><div class="date" id="liveClock"></div></div>
    <span class="admin-tag">🔑 Administrator</span>
  </div>

<?php
$is_error = str_starts_with($msg, 'error:');
$msg_text = $is_error ? substr($msg, 6) : $msg;
?>

<?php if ($msg): ?>
    <div class="alert <?= $is_error ? 'alert-error' : 'alert-success' ?>">
        <?= $is_error ? '⚠️' : '✅' ?> <?= htmlspecialchars($msg_text) ?>
    </div>
<?php endif; ?>


  <!-- ═══ OVERVIEW ═══ -->
  <?php if ($tab === 'overview'): ?>
  <div class="stats-row">
    <a href="?tab=users" class="stat-card"><div class="stat-icon green">👥</div><div><div class="stat-label">Total Users</div><div class="stat-value"><?= $total_users ?></div></div></a>
    <a href="?tab=submissions" class="stat-card"><div class="stat-icon red">⏳</div><div><div class="stat-label">Pending Reviews</div><div class="stat-value"><?= $pending_subs ?></div></div></a>
    <a href="?tab=reports" class="stat-card"><div class="stat-icon gold">🌿</div><div><div class="stat-label">Points Issued</div><div class="stat-value"><?= number_format($total_pts) ?></div></div></a>
    <a href="?tab=reports" class="stat-card"><div class="stat-icon blue">🎁</div><div><div class="stat-label">Redemptions</div><div class="stat-value"><?= $total_rewards ?></div></div></a>
  </div>
  <div class="card">
    <div class="card-header"><h2>⏳ Pending Submissions</h2><span style="font-size:13px;color:var(--text-muted)"><?= $pending_subs ?> pending</span></div>
    <div class="table-wrap">
    <?php $pending = array_filter($submissions, fn($s) => $s['status']==='pending'); ?>
    <?php if (empty($pending)): ?>
      <div class="empty-state">🎉 No pending submissions!</div>
    <?php else: ?>
    <table>
      <thead><tr><th>User</th><th>Challenge</th><th>Evidence</th><th>Date</th><th>Review Note</th><th style="min-width:200px">Action</th></tr></thead>
      <tbody>
      <?php foreach ($pending as $s): ?>
      <tr>
        <td><strong><?= htmlspecialchars($s['username']) ?></strong></td>
        <td><?= htmlspecialchars($s['challenge_title']) ?><br><small style="color:var(--text-muted)"><?= $s['points'] ?> pts</small></td>
        <td>
            <?php 
            $ext = strtolower(pathinfo($s['evidence'], PATHINFO_EXTENSION)); 
            $evidence_path = $s['evidence'];
            if (strpos($evidence_path, 'User/uploads') === false) {
                $evidence_path = '/GreenPoint/User/uploads/' . basename($evidence_path);
            } 
            if (!str_starts_with($evidence_path, '/')) {
                $evidence_path = '/' . $evidence_path;
            }
            ?>

            <?php if (in_array($ext, ['jpg','jpeg','png','gif','webp'])): ?>
                <img src="<?= htmlspecialchars($evidence_path) ?>" 
                    class="ev-thumb" 
                    onclick="window.open(this.src)" 
                    title="Click to view full"
                    style="cursor:pointer; max-width:80px; max-height:60px;">
            <?php endif; ?>
        </td>
        <td style="white-space:nowrap"><?= date('d M Y', strtotime($s['submitted_date'])) ?></td>
        <td>
          <form method="POST" id="rf-<?= $s['submission_id'] ?>">
            <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
            <input type="hidden" name="review_action" id="ra_<?= $s['submission_id'] ?>" value="">
            <input type="text" name="review_note" class="review-note-input" placeholder="Optional note...">
        </td>
        <td>
            <div class="review-form">
              <button type="submit" form="rf-<?= $s['submission_id'] ?>" name="review_submission" value="1" class="btn btn-approve"
                onclick="document.getElementById('ra_<?= $s['submission_id'] ?>').value='approved';return confirm('Approve this submission?')">✅ Approve</button>
              <button type="submit" form="rf-<?= $s['submission_id'] ?>" name="review_submission" value="1" class="btn btn-reject"
                onclick="document.getElementById('ra_<?= $s['submission_id'] ?>').value='rejected';return confirm('Reject this submission?')">❌ Reject</button>
            </div>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
  </div>

  <!-- ═══ SUBMISSIONS ═══ -->
  <?php elseif ($tab === 'submissions'): ?>
  <div class="card">
    <div class="card-header"><h2>📋 All Submissions</h2><span style="font-size:13px;color:var(--text-muted)"><?= count($submissions) ?> total</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>User</th><th>Challenge</th><th>Evidence</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($submissions as $s): ?>
      <tr>
        <td><strong><?= htmlspecialchars($s['username']) ?></strong></td>
        <td><?= htmlspecialchars($s['challenge_title']) ?><br><small style="color:var(--text-muted)"><?= $s['points'] ?> pts</small></td>
        <td>
            <?php 
            $ext = strtolower(pathinfo($s['evidence'], PATHINFO_EXTENSION)); 
            $evidence_path = $s['evidence'];
            if (strpos($evidence_path, 'User/uploads') === false) {
                $evidence_path = '/GreenPoint/User/uploads/' . basename($evidence_path);
            } 
            if (!str_starts_with($evidence_path, '/')) {
                $evidence_path = '/' . $evidence_path;
            }
            ?>

            <?php if (in_array($ext, ['jpg','jpeg','png','gif','webp'])): ?>
                <img src="<?= htmlspecialchars($evidence_path) ?>" 
                    class="ev-thumb" 
                    onclick="window.open(this.src)" 
                    title="Click to view full"
                    style="cursor:pointer; max-width:80px; max-height:60px;">
            <?php endif; ?>
        </td>
        <td><span class="status-badge status-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
        <td><?= date('d M Y', strtotime($s['submitted_date'])) ?></td>
        <td>
          <?php if ($s['status']==='pending'): ?>
          <form method="POST" id="rfsub-<?= $s['submission_id'] ?>">
            <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
            <input type="hidden" name="review_action" id="ra2_<?= $s['submission_id'] ?>" value="">
            <input type="text" name="review_note" class="review-note-input" placeholder="Note" style="margin-bottom:4px;display:block">
            <button type="submit" name="review_submission" value="1" class="btn btn-approve" style="margin-right:4px"
              onclick="document.getElementById('ra2_<?= $s['submission_id'] ?>').value='approved';return confirm('Approve?')">✅</button>
            <button type="submit" name="review_submission" value="1" class="btn btn-reject"
              onclick="document.getElementById('ra2_<?= $s['submission_id'] ?>').value='rejected';return confirm('Reject?')">❌</button>
          </form>
          <?php else: ?>
            <span style="font-size:12px;color:var(--text-muted)"><?= $s['review_note'] ?: '—' ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ═══ USERS ═══ -->
  <?php elseif ($tab === 'users'): ?>

  <?php if (!empty($profile_reqs)): ?>
  <div class="card">
    <div class="card-header"><h2>📝 Profile Change Requests</h2><span style="font-size:13px;color:#e74c3c"><?= $profile_req_count ?> pending</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>User</th><th>Type</th><th>New Value</th><th>Requested</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($profile_reqs as $req): ?>
      <tr>
        <td><strong><?= htmlspecialchars($req['username']) ?></strong><br><small style="color:var(--text-muted)"><?= htmlspecialchars($req['email']) ?></small></td>
        <td><span class="status-badge status-pending"><?= ucfirst($req['request_type']) ?></span></td>
        <td>
          <?php if ($req['request_type'] === 'avatar'): ?>
            <img src="<?= htmlspecialchars($req['new_value']) ?>" class="avatar-preview" onerror="this.style.display='none'">
          <?php else: ?>
            <strong><?= htmlspecialchars($req['new_value']) ?></strong>
          <?php endif; ?>
        </td>
        <td><?= date('d M Y H:i', strtotime($req['requested_at'])) ?></td>
        <td style="white-space:nowrap">
          <form method="POST" style="display:inline">
            <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
            <input type="hidden" name="req_action" value="approved">
            <button type="submit" name="review_profile_req" class="btn btn-approve" onclick="return confirm('Approve this change?')">✅ Approve</button>
          </form>
          <form method="POST" style="display:inline">
            <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
            <input type="hidden" name="req_action" value="rejected">
            <button type="submit" name="review_profile_req" class="btn btn-reject" onclick="return confirm('Reject this change?')">❌ Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h2>👥 All Accounts</h2><span style="font-size:13px;color:var(--text-muted)"><?= count($users) ?> accounts</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Points</th><th>Joined</th><th>Delete</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td style="color:var(--text-muted)">#<?= $u['user_id'] ?></td>
        <td>
          <strong><?= htmlspecialchars($u['username']) ?></strong>
          <?php if (!empty($u['avatar'])): ?>
            <img src="<?= htmlspecialchars($u['avatar']) ?>" style="width:22px;height:22px;border-radius:5px;vertical-align:middle;margin-left:6px;object-fit:cover">
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <form method="POST" class="role-form" id="role-form-<?= $u['user_id'] ?>">
            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
            <select name="new_role" class="role-select" onchange="showSaveRole(<?= $u['user_id'] ?>)" <?= $u['user_id']===$admin_id?'disabled':'' ?>>
              <option value="user"      <?= $u['role']==='user'      ?'selected':'' ?>>User</option>
              <option value="admin"     <?= $u['role']==='admin'     ?'selected':'' ?>>Admin</option>
              <option value="moderator" <?= $u['role']==='moderator' ?'selected':'' ?>>Moderator</option>
            </select>
            <button type="submit" name="change_role" class="btn-save-role" id="save-role-<?= $u['user_id'] ?>" onclick="return confirm('Change this user\'s role?')">Save</button>
            <input type="hidden" name="change_role" value="1">
          </form>
        </td>
        <td><strong><?= number_format($u['total_points']) ?></strong></td>
        <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
        <td>
          <?php if ($u['user_id'] !== $admin_id): ?>
          <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars($u['username']) ?>? This cannot be undone.')">
            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
            <button type="submit" name="delete_user" class="btn-delete">🗑</button>
          </form>
          <?php else: ?>
            <span style="font-size:11px;color:var(--text-muted)">You</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ═══ CHALLENGES ═══ -->
  <?php elseif ($tab === 'challenges'): ?>
  <div class="card">
    <div class="card-header"><h2>🎯 All Challenges</h2><span style="font-size:13px;color:var(--text-muted)"><?= count($challenges) ?> total</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th>Points</th><th>Start</th><th>End</th><th>Created By</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($challenges as $ch): ?>
      <tr id="ach-row-<?= $ch['challenge_id'] ?>">
        <td><strong><?= htmlspecialchars($ch['title']) ?></strong><br><small style="color:var(--text-muted)"><?= htmlspecialchars(substr($ch['description'],0,50)) ?>...</small></td>
        <td><strong style="color:var(--green-mid)"><?= $ch['points'] ?> pts</strong></td>
        <td><?= $ch['start_date'] ?></td>
        <td><?= $ch['end_date'] ?></td>
        <td><?= htmlspecialchars($ch['created_by_name'] ?? 'System') ?></td>
        <td style="white-space:nowrap">
          <button class="btn-edit" onclick="toggleAdminEditCh(<?= $ch['challenge_id'] ?>)">✏️ Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this challenge?')">
            <input type="hidden" name="challenge_id" value="<?= $ch['challenge_id'] ?>">
            <button type="submit" name="delete_challenge" class="btn-delete">🗑</button>
          </form>
        </td>
      </tr>
      <tr class="edit-row" id="ach-edit-<?= $ch['challenge_id'] ?>">
        <td colspan="6">
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
            <button type="submit" name="edit_challenge" class="btn-save-edit" onclick="return confirm('Save changes to this challenge?')">💾 Save Changes</button>
            <button type="button" class="btn-cancel-edit" onclick="toggleAdminEditCh(<?= $ch['challenge_id'] ?>)">Cancel</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ═══ REPORTS ═══ -->
  <?php elseif ($tab === 'reports'): ?>
  <div class="stats-row">
    <div class="stat-card"><div class="stat-icon green">👥</div><div><div class="stat-label">Total Users</div><div class="stat-value"><?= $total_users ?></div></div></div>
    <div class="stat-card"><div class="stat-icon gold">📋</div><div><div class="stat-label">Total Submissions</div><div class="stat-value"><?= count($submissions) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green">✅</div><div><div class="stat-label">Approved</div><div class="stat-value"><?= count(array_filter($submissions,fn($s)=>$s['status']==='approved')) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon blue">🎁</div><div><div class="stat-label">Redemptions</div><div class="stat-value"><?= count($redemptions) ?></div></div></div>
  </div>

  <div class="card">
    <div class="card-header"><h2>🏆 Top Users by Total Earned Points</h2><span style="font-size:12px;color:var(--text-muted)">Cumulative, never decreases</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Rank</th><th>Username</th><th>Email</th><th>Total Earned</th><th>Current Points</th></tr></thead>
      <tbody>
      <?php $top=$pdo->query("SELECT username,email,earned_points,total_points FROM users WHERE role='user' ORDER BY earned_points DESC LIMIT 10")->fetchAll();
      foreach($top as $i=>$u): ?>
      <tr>
        <td><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':'#'.($i+1))) ?></td>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><strong style="color:#2980b9"><?= number_format($u['earned_points']) ?> pts</strong></td>
        <td style="color:var(--green-mid)"><?= number_format($u['total_points']) ?> pts</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card">
    <div class="card-header"><h2>🎁 Redemption History</h2></div>
    <div class="table-wrap">
    <?php if(empty($redemptions)): ?><div class="empty-state">No redemptions yet.</div>
    <?php else: ?>
    <table>
      <thead><tr><th>User</th><th>Reward</th><th>Points</th><th>Delivery Address</th><th>Shipping Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($redemptions as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['username']) ?></td>
        <td><?= htmlspecialchars($r['reward_name']) ?></td>
        <td><?= $r['points_spent'] ?> pts</td>
        <td style="font-size:12px;max-width:180px;color:var(--text-muted)"><?= $r['address_snapshot'] ? htmlspecialchars($r['address_snapshot']) : '—' ?></td>
        <td>
          <form method="POST" style="display:flex;align-items:center;gap:6px;">
            <input type="hidden" name="redemption_id" value="<?= $r['redemption_id'] ?>">
            <select name="shipping_status" class="role-select" onchange="this.nextElementSibling.style.display='inline-block'">
              <option value="processing" <?= $r['shipping_status']==='processing'?'selected':'' ?>>Processing</option>
              <option value="shipped"    <?= $r['shipping_status']==='shipped'   ?'selected':'' ?>>Shipped</option>
              <option value="delivered"  <?= $r['shipping_status']==='delivered' ?'selected':'' ?>>Delivered</option>
            </select>
            <button type="submit" name="update_shipping" style="display:none;padding:4px 10px;background:var(--green-mid);color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer;" onclick="return confirm('Update shipping status?')">Save</button>
          </form>
        </td>
        <td><?= date('d M Y', strtotime($r['redemption_date'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php require_once 'admin_footer.php'; ?>