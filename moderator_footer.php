<?php
/*
 * =============================================================================
 * FILE  : footer.php
 * FOLDER: moderater/
 * ROLE  : Moderator Panel
 * DESC  : Closes </main>, User Detail Modal HTML, and all JavaScript:
 *         Live clock, date validation, toggle edit rows, user modal, dark mode
 *         Included at bottom of every Moderator page via require_once 'footer.php'
 * =============================================================================
 */
?>
</main>

<!-- User Detail Modal -->
<div class="user-modal-overlay" id="userModal">
  <div class="user-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 id="modalUsername" style="margin:0">User Profile</h3>
      <button onclick="closeUserModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">✕</button>
    </div>
    <div id="modalContent"></div>
  </div>
</div>

<script>
// ── Live Clock ──
function updateClock() {
    const now = new Date();
    const days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
    const h=String(now.getHours()).padStart(2,'0'), m=String(now.getMinutes()).padStart(2,'0'), s=String(now.getSeconds()).padStart(2,'0');
    const el = document.getElementById('liveClock');
    if (el) el.textContent = days[now.getDay()]+', '+now.getDate()+' '+months[now.getMonth()]+' '+now.getFullYear()+' · '+h+':'+m+':'+s;
}
updateClock(); setInterval(updateClock, 1000);

// ── Date Validation ──
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[name="start_date"]').forEach(function(startInput) {
        startInput.min = today;
        const form = startInput.closest('form');
        const endInput = form ? form.querySelector('input[name="end_date"]') : null;
        if (!endInput) return;
        startInput.addEventListener('change', function() {
            const nextDay = new Date(this.value); nextDay.setDate(nextDay.getDate()+1);
            endInput.min = nextDay.toISOString().split('T')[0];
            if (endInput.value && endInput.value <= this.value) { endInput.value=''; endInput.style.borderColor='#e74c3c'; }
        });
        form.addEventListener('submit', function(e) {
            if (endInput.value && startInput.value && endInput.value <= startInput.value) {
                e.preventDefault(); alert('End date must be later than start date!'); endInput.focus();
            }
        });
        endInput.addEventListener('change', function() { this.style.borderColor=''; });
    });
});

// ── Toggle Edit Challenge ──
function toggleEditChallenge(id) {
    const editRow = document.getElementById('ch-edit-'+id);
    editRow.style.display = editRow.style.display === 'table-row' ? 'none' : 'table-row';
}

// ── Toggle Edit Announcement ──
function toggleEditAnn(id) {
    const editRow = document.getElementById('ann-edit-'+id);
    editRow.style.display = editRow.style.display === 'table-row' ? 'none' : 'table-row';
}

// ── User Detail Modal ──
function openUserModal(u) {
    document.getElementById('modalUsername').textContent = '👤 ' + u.username;
    document.getElementById('modalContent').innerHTML =
        '<div class="info-row"><span class="info-label">User ID</span><span class="info-value">#'+u.user_id+'</span></div>'+
        '<div class="info-row"><span class="info-label">Username</span><span class="info-value">'+u.username+'</span></div>'+
        '<div class="info-row"><span class="info-label">Email</span><span class="info-value">'+u.email+'</span></div>'+
        '<div class="info-row"><span class="info-label">Current Points</span><span class="info-value" style="color:var(--green-mid)">'+parseInt(u.total_points).toLocaleString()+' pts</span></div>'+
        '<div class="info-row"><span class="info-label">Highest Points</span><span class="info-value" style="color:var(--gold)">'+(parseInt(u.highest_points)||0).toLocaleString()+' pts</span></div>'+
        '<div class="info-row"><span class="info-label">Joined</span><span class="info-value">'+new Date(u.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+'</span></div>';
    document.getElementById('userModal').classList.add('open');
}
function closeUserModal() { document.getElementById('userModal').classList.remove('open'); }
document.getElementById('userModal').addEventListener('click', function(e){ if(e.target===this) closeUserModal(); });
</script>

<script>
// ── Dark / Light mode toggle ──
(function() {
    const KEY = 'gp_theme';
    function apply(dark) {
        document.body.classList.toggle('dark', dark);
        const tt = document.querySelector('.dark-mode-tooltip');
        if (tt) tt.textContent = dark ? '☀ Switch to Light' : '🌙 Switch to Dark';
    }
    apply(localStorage.getItem(KEY) === 'dark');
    window.toggleDarkMode = function() {
        const isDark = document.body.classList.contains('dark');
        localStorage.setItem(KEY, isDark ? 'light' : 'dark');
        apply(!isDark);
    };
})();
</script>

</body>
</html>
