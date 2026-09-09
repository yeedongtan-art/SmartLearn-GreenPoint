<?php
/*
 * =============================================================================
 * FILE  : footer.php
 * FOLDER: user/
 * ROLE  : User Dashboard
 * DESC  : Closes </main>, contains all modals (Evidence submit, Checkout
 *         confirm, Profile modal) and all JavaScript:
 *         Live clock, sidebar scroll-active, smooth scroll, evidence modal,
 *         checkout modal, profile modal, announcements, challenge expand,
 *         star rating, char counter, address form toggle, mobile sidebar,
 *         notification read/unread state, dark/light mode + tooltips
 *         Included at bottom of every User page via require_once 'footer.php'
 * =============================================================================
 */
?>
</main>

<!-- ═══ Evidence Submit Modal ═══ -->
<div class="modal-overlay" id="evidenceModal">
    <div class="modal-box">
        <h3>📤 Submit Evidence</h3>
        <p id="evidenceChallengeName" style="font-size:14px;color:var(--text-muted);margin-bottom:16px;"></p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="challenge_id" id="evidenceChallengeId">
            <label>Upload Photo or Video</label>
            <input type="file" name="evidence" accept="image/*,video/*" required>
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="closeEvidenceModal()">Cancel</button>
                <button type="submit" name="submit_evidence" class="btn-confirm">Submit →</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Checkout Confirm Modal ═══ -->
<div class="modal-overlay" id="checkoutModal">
    <div class="modal-box">
        <h3>🛒 Confirm Order</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Please confirm your delivery address and order details.</p>
        <form method="POST" id="checkoutForm">
            <div style="margin-bottom:16px;">
                <label style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:8px;">Delivery Address</label>
                <?php if (empty($addresses)): ?>
                    <p style="font-size:13px;color:#e74c3c;">⚠️ No address saved. Please add one first.</p>
                <?php else: ?>
                    <?php foreach ($addresses as $addr): ?>
                    <label class="addr-card <?= $addr['is_default'] ? 'selected' : '' ?>" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;" onclick="this.parentNode.querySelectorAll('.addr-card').forEach(c=>c.classList.remove('selected'));this.classList.add('selected');">
                        <input type="radio" name="address_id" value="<?= $addr['address_id'] ?>" <?= $addr['is_default'] ? 'checked' : '' ?> style="margin-top:3px;flex-shrink:0;">
                        <div>
                            <strong><?= htmlspecialchars($addr['full_name']) ?></strong> · <?= htmlspecialchars($addr['phone']) ?>
                            <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($addr['address_line']) ?>, <?= htmlspecialchars($addr['city']) ?> <?= htmlspecialchars($addr['postcode']) ?>, <?= htmlspecialchars($addr['state']) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="background:#f7faf8;border-radius:10px;padding:12px;margin-bottom:16px;font-size:13px;">
                <strong>Total: <?= number_format($cart_total) ?> pts</strong> will be deducted from your <?= number_format($user['total_points']) ?> pts.
            </div>
            <?php if (!empty($addresses)): ?>
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
                <button type="submit" name="checkout" class="btn-confirm" onclick="return confirm('Confirm order and deduct <?= $cart_total ?> points?')">Confirm Order ✓</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ═══ Profile Modal ═══ -->
<div class="profile-modal-overlay" id="profileModal">
    <div class="profile-modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin-bottom:0">👤 My Profile</h3>
            <button onclick="closeProfileModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">✕</button>
        </div>

        <!-- Big avatar -->
        <div class="profile-avatar-big">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar">
            <?php else: ?>
                <?= strtoupper(substr($user['username'],0,1)) ?>
            <?php endif; ?>
        </div>

        <!-- Mini tabs -->
        <div class="tab-mini">
            <button class="tab-mini-btn active" onclick="switchProfileTab(this,'pinfo')">Info</button>
            <button class="tab-mini-btn" onclick="switchProfileTab(this,'pchange')">Change Profile</button>
            <button class="tab-mini-btn" onclick="switchProfileTab(this,'ppassword')">🔑 Password</button>
        </div>

        <!-- Info Panel -->
        <div class="panel active" id="pinfo">
            <div class="profile-info-row"><span class="profile-info-label">User ID</span><span class="profile-info-value" style="font-family:monospace;color:var(--text-muted)">#<?= $user['user_id'] ?></span></div>
            <div class="profile-info-row"><span class="profile-info-label">Username</span><span class="profile-info-value"><?= htmlspecialchars($user['username']) ?></span></div>
            <div class="profile-info-row"><span class="profile-info-label">Email</span><span class="profile-info-value"><?= htmlspecialchars($user['email']) ?></span></div>
            <div class="profile-info-row"><span class="profile-info-label">Member Since</span><span class="profile-info-value"><?= date('d M Y', strtotime($user['created_at'])) ?></span></div>
            <div class="profile-info-row"><span class="profile-info-label">Current Points</span><span class="profile-info-value" style="color:var(--green-mid)"><?= number_format($user['total_points']) ?> pts</span></div>
            <div class="profile-info-row"><span class="profile-info-label">Highest Points</span><span class="profile-info-value" style="color:var(--gold)"><?= number_format($user['highest_points'] ?? 0) ?> pts</span></div>
            <div class="profile-info-row"><span class="profile-info-label">Total Earned</span><span class="profile-info-value" style="color:#2980b9"><?= number_format($user['earned_points'] ?? 0) ?> pts</span></div>
            <div class="profile-info-row"><span class="profile-info-label">Role</span><span class="profile-info-value">User</span></div>
        </div>

        <!-- Change Panel -->
        <div class="panel" id="pchange">
            <!-- Change Username -->
            <div style="margin-bottom:18px;padding:14px;background:#f7faf8;border-radius:12px;">
                <h4 style="font-size:13px;font-weight:700;margin-bottom:10px;">Change Username</h4>
                <?php if (in_array('username', $pending_req_types)): ?>
                    <p style="font-size:12px;color:#e67e22;">⏳ Username change request pending admin approval.</p>
                <?php else: ?>
                <form method="POST">
                    <div class="form-group"><label>New Username (3–20 chars)</label>
                        <input type="text" name="new_username" minlength="3" maxlength="20" placeholder="Enter new username" required>
                    </div>
                    <button type="submit" name="request_username" class="btn-save-addr" onclick="return confirm('Submit username change request to admin?')">Request Change</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Change Avatar -->
            <div style="padding:14px;background:#f7faf8;border-radius:12px;">
                <h4 style="font-size:13px;font-weight:700;margin-bottom:10px;">Change Avatar</h4>
                <?php if (in_array('avatar', $pending_req_types)): ?>
                    <p style="font-size:12px;color:#e67e22;">⏳ Avatar change request pending admin approval.</p>
                <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group"><label>Upload New Avatar (jpg, png, webp)</label>
                        <input type="file" name="avatar_file" accept="image/*" required style="border:2px dashed #cde8d8;border-radius:10px;padding:8px;width:100%;">
                    </div>
                    <button type="submit" name="request_avatar" class="btn-save-addr" onclick="return confirm('Submit avatar change request to admin?')">Request Change</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Change Password Panel -->
        <div class="panel" id="ppassword">
            <div style="padding:14px;background:#f7faf8;border-radius:12px;">
                <h4 style="font-size:13px;font-weight:700;margin-bottom:10px;">Change Password</h4>
                <form method="POST" id="changePwForm">
                    <div class="form-group">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Current Password</label>
                        <div style="position:relative;">
                            <input type="password" name="current_password" id="curPw" placeholder="Enter current password" required style="width:100%;padding:10px 40px 10px 12px;border:2px solid #e0ede6;border-radius:8px;font-size:13px;font-family:inherit;outline:none;">
                            <button type="button" onclick="togglePwField('curPw',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;">👁</button>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:10px;">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">New Password</label>
                        <div style="position:relative;">
                            <input type="password" name="new_password" id="newPwField" placeholder="Min. 6 characters" required oninput="checkPwStrength(this.value)" style="width:100%;padding:10px 40px 10px 12px;border:2px solid #e0ede6;border-radius:8px;font-size:13px;font-family:inherit;outline:none;">
                            <button type="button" onclick="togglePwField('newPwField',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;">👁</button>
                        </div>
                        <div id="pwStrBar" style="height:3px;border-radius:2px;margin-top:5px;background:#eee;"></div>
                        <div id="pwStrLabel" style="font-size:10px;color:var(--text-muted);margin-top:2px;"></div>
                    </div>
                    <div class="form-group" style="margin-top:10px;">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Confirm New Password</label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" id="confPwField" placeholder="Re-enter new password" required style="width:100%;padding:10px 40px 10px 12px;border:2px solid #e0ede6;border-radius:8px;font-size:13px;font-family:inherit;outline:none;">
                            <button type="button" onclick="togglePwField('confPwField',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;">👁</button>
                        </div>
                    </div>
                    <button type="submit" name="change_password" class="btn-save-addr" style="margin-top:12px;width:100%;" onclick="return validatePwChange()">🔑 Change Password</button>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
// ── 1. Live Clock ──
function updateClock() {
    const now = new Date();
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('liveClock').textContent =
        days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear() + ' · ' + h + ':' + m + ':' + s;
}
updateClock();
setInterval(updateClock, 1000);

// ── 2. Sidebar active on scroll ──
const navItems = document.querySelectorAll('.nav-item[data-section]');
const sectionOrder = ['overview','challenges','history','rewards','cart','feedback'];

function updateSidebarActive() {
    let current = sectionOrder[0];
    sectionOrder.forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        const top = el.getBoundingClientRect().top;
        if (top <= 160) current = id;
    });
    navItems.forEach(function(item) {
        if (item.dataset.section === current) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

window.addEventListener('scroll', updateSidebarActive, {passive: true});
window.addEventListener('load', updateSidebarActive);
updateSidebarActive();

// ── 3. Smooth scroll helper ──
function scrollToSection(id) {
    const el = document.getElementById(id);
    if (el) { setTimeout(() => el.scrollIntoView({behavior:'smooth', block:'start'}), 10); }
}

// ── 4. Evidence Modal ──
function openEvidenceModal(id, name) {
    document.getElementById('evidenceChallengeId').value = id;
    document.getElementById('evidenceChallengeName').textContent = 'Challenge: ' + name;
    document.getElementById('evidenceModal').classList.add('open');
}
function closeEvidenceModal() { document.getElementById('evidenceModal').classList.remove('open'); }
document.getElementById('evidenceModal').addEventListener('click', function(e){ if(e.target===this) closeEvidenceModal(); });

// ── 5. Checkout Modal ──
function openCheckoutModal() { document.getElementById('checkoutModal').classList.add('open'); }
function closeCheckoutModal() { document.getElementById('checkoutModal').classList.remove('open'); }
document.getElementById('checkoutModal').addEventListener('click', function(e){ if(e.target===this) closeCheckoutModal(); });

// ── 6. Profile Modal ──
// ── Password field toggle ──
function togglePwField(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '👁' : '🙈';
}
// ── Password strength checker ──
function checkPwStrength(val) {
    const bar = document.getElementById('pwStrBar');
    const lbl = document.getElementById('pwStrLabel');
    if (!val) { bar.style.cssText='height:3px;border-radius:2px;margin-top:5px;background:#eee;'; lbl.textContent=''; return; }
    const strong = val.length>=8 && /[A-Z]/.test(val) && /[0-9]/.test(val);
    const medium = val.length>=6 && (/[A-Z]/.test(val) || /[0-9]/.test(val));
    if (strong)      { bar.style.cssText='height:3px;border-radius:2px;margin-top:5px;background:#2ecc71;width:100%;'; lbl.textContent='Strong ✅'; }
    else if (medium) { bar.style.cssText='height:3px;border-radius:2px;margin-top:5px;background:#f39c12;width:66%;';  lbl.textContent='Medium ⚠️'; }
    else             { bar.style.cssText='height:3px;border-radius:2px;margin-top:5px;background:#e74c3c;width:33%;';  lbl.textContent='Weak ❌'; }
}
// ── Validate password change form ──
function validatePwChange() {
    const newPw  = document.getElementById('newPwField').value;
    const confPw = document.getElementById('confPwField').value;
    if (newPw.length < 6) { alert('New password must be at least 6 characters.'); return false; }
    if (newPw !== confPw)  { alert('Passwords do not match!'); return false; }
    return true;
}
function openProfileModal()  { document.getElementById('profileModal').classList.add('open'); }
function closeProfileModal() { document.getElementById('profileModal').classList.remove('open'); }
document.getElementById('profileModal').addEventListener('click', function(e){ if(e.target===this) closeProfileModal(); });

function switchProfileTab(btn, panelId) {
    document.querySelectorAll('.tab-mini-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(panelId).classList.add('active');
}

// ── 7. Announcement show more ──
function toggleAnnounce(btn) {
    const item = btn.closest('.announce-item');
    const preview = item.querySelector('.announce-preview');
    const full    = item.querySelector('.announce-full');
    const isOpen  = full.classList.contains('open');
    if (isOpen) {
        full.classList.remove('open');
        preview.style.display = '';
        btn.textContent = 'Show more ▼';
    } else {
        full.classList.add('open');
        preview.style.display = 'none';
        btn.textContent = 'Show less ▲';
    }
}

// ── Challenge expand ──
function toggleChallenge(item) {
    item.classList.toggle('expanded');
}

// ── 8. Stars rating ──
function setStars(n) {
    document.getElementById('ratingInput').value = n;
    document.querySelectorAll('.star-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i < n);
        btn.style.opacity = i < n ? '1' : '0.35';
    });
}
// Init stars at half opacity
document.querySelectorAll('.star-btn').forEach(b => b.style.opacity = '0.35');

// ── 9. Char counter ──
function updateCharCount(el) {
    document.getElementById('charCount').textContent = el.value.length + ' / 300';
}

// ── 10. Address form toggle ──
function toggleAddressForm() {
    const f = document.getElementById('addr-form-wrap');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

// ── Auto-open profile modal if coming from profile_req ──
<?php if (isset($_GET['profile_req'])): ?>
window.onload = function() { /* Do nothing — alert shown above */ };
<?php endif; ?>

// ── Mobile sidebar ──
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}

// ── Sidebar scroll active (fixed: use getBoundingClientRect) ──
const _navItems = document.querySelectorAll('.nav-item[data-section]');
const _secOrder = ['overview','notifications','challenges','history','rewards','cart','feedback'];
function _updateNav() {
    let cur = _secOrder[0];
    _secOrder.forEach(function(id) {
        const el = document.getElementById(id);
        if (el && el.getBoundingClientRect().top <= 160) cur = id;
    });
    _navItems.forEach(function(item) {
        item.classList.toggle('active', item.dataset.section === cur);
    });
}
window.addEventListener('scroll', _updateNav, {passive:true});
window.addEventListener('load', _updateNav);
_updateNav();

// ── Notification read/unread (localStorage) ──
const _NKEY = 'gp_notif_read_<?= $uid ?>';
const _allIds = <?= $notif_ids_json ?>;

function _getRead() {
    try { return JSON.parse(localStorage.getItem(_NKEY) || '[]'); } catch(e) { return []; }
}
function _saveRead(ids) {
    try { localStorage.setItem(_NKEY, JSON.stringify(ids)); } catch(e) {}
}
function _applyNotifState() {
    const read = _getRead();
    let unread = 0;
    document.querySelectorAll('.notif-item').forEach(function(el) {
        const id = el.dataset.notifId;
        if (read.includes(id)) {
            el.classList.remove('unread');
            el.classList.add('read');
            const dot = document.getElementById('dot_' + id);
            if (dot) dot.style.display = 'none';
        } else {
            el.classList.add('unread');
            el.classList.remove('read');
            unread++;
        }
    });
    // Sidebar badge
    const sb = document.getElementById('sidebarNotifBadge');
    if (sb) { sb.textContent = unread; sb.style.display = unread > 0 ? 'flex' : 'none'; }
    // Card badge
    const cb = document.getElementById('notifCountBadge');
    if (cb) { cb.textContent = unread + ' new'; cb.style.display = unread > 0 ? 'inline-flex' : 'none'; }
}
function markAllRead() {
    _saveRead(_allIds);
    _applyNotifState();
}
// Click single item → mark as read
document.querySelectorAll('.notif-item').forEach(function(el) {
    el.addEventListener('click', function() {
        const id = el.dataset.notifId;
        const read = _getRead();
        if (!read.includes(id)) { read.push(id); _saveRead(read); _applyNotifState(); }
    });
});
// Expandable notification (announcements)
function toggleNotifExpand(el) {
    el.classList.toggle('expanded');
    const hint = el.querySelector('.notif-expand-hint');
    if (hint) hint.textContent = el.classList.contains('expanded') ? '▲ show less' : '▼ read more';
}
// Apply on page load
_applyNotifState();
</script>
<script>
// ── Dark / Light mode + Tooltips ──
(function(){
    const KEY = 'gp_theme';
    function apply(dark){
        document.body.classList.toggle('dark', dark);
    }
    apply(localStorage.getItem(KEY) === 'dark');
    window.toggleDarkMode = function(){
        const isDark = document.body.classList.contains('dark');
        localStorage.setItem(KEY, isDark ? 'light' : 'dark');
        apply(!isDark);
    };

    // Tooltip helper — fixed position, never clipped by overflow:hidden
    function tipShow(anchorEl, tipEl, text){
        const r = anchorEl.getBoundingClientRect();
        tipEl.textContent = text;
        tipEl.style.left = (r.left + r.width / 2) + 'px';
        tipEl.style.top  = (r.top - 32) + 'px';
        tipEl.style.opacity = '1';
    }
    function tipHide(tipEl){ tipEl.style.opacity = '0'; }

    document.addEventListener('DOMContentLoaded', function(){
        var av  = document.querySelector('.user-avatar-wrap');
        var dtip = document.getElementById('darkTip');
        if(av && dtip){
            av.addEventListener('mouseenter', function(){
                var dark = document.body.classList.contains('dark');
                tipShow(av, dtip, dark ? '☀ Switch to Light' : '🌙 Switch to Dark');
            });
            av.addEventListener('mouseleave', function(){ tipHide(dtip); });
        }
        var nm  = document.querySelector('.user-info[onclick]');
        var ptip = document.getElementById('profileTip');
        if(nm && ptip){
            nm.addEventListener('mouseenter', function(){ tipShow(nm, ptip, '👤 View Profile'); });
            nm.addEventListener('mouseleave', function(){ tipHide(ptip); });
        }
    });
})();
</script>
</body>