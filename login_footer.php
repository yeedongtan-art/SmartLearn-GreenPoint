<?php
/*
 * =============================================================================
 * FILE  : footer.php
 * FOLDER: Login/
 * ROLE  : Landing / Login Page
 * DESC  : All JavaScript for the landing page:
 *         openModal/closeModal, switchTab, togglePw, checkStrength (password bar),
 *         navbar scroll opacity, auto-open modal on POST error
 *         Closes </body></html>
 *         Included at bottom of index.php via require_once 'footer.php'
 * =============================================================================
 */
?>
<script>
function openModal(tab) {
  switchTab(tab);
  document.getElementById('overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal() {
  document.getElementById('overlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.getElementById('overlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
function switchTab(tab) {
  document.querySelectorAll('.mtab').forEach(b => b.classList.remove('on'));
  document.querySelectorAll('.mpanel').forEach(p => p.classList.remove('on'));
  document.getElementById('tab-' + tab).classList.add('on');
  document.getElementById('panel-' + tab).classList.add('on');
}
function togglePw(id, btn) {
  var i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
  btn.textContent = i.type === 'password' ? '👁' : '🙈';
}
function checkStrength(v) {
  var s = 0;
  if (v.length >= 6)  s++;
  if (v.length >= 10) s++;
  if (/[A-Z]/.test(v)) s++;
  if (/[0-9]/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  var f = document.getElementById('pwfill');
  f.style.width = (s/5*100)+'%';
  f.style.background = s<=1?'#e74c3c':s<=3?'#f0c040':'#2ecc71';
}
// Navbar scroll opacity
window.addEventListener('scroll', function() {
  document.querySelector('.nav').style.background =
    window.scrollY > 30 ? 'rgba(13,59,46,0.99)' : 'rgba(13,59,46,0.96)';
});
<?php if (!empty($open_modal)): ?>
document.addEventListener('DOMContentLoaded', () => openModal('<?= $open_modal ?>'));
<?php endif; ?>
</script>
</body>
</html>
