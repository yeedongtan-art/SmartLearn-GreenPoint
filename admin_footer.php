<?php
/*
 * =============================================================================
 * FILE  : footer.php
 * FOLDER: Admin/
 * ROLE  : Admin Panel
 * DESC  : Closes </main>, contains all JavaScript:
 *         Live clock, mobile sidebar toggle, dark/light mode, floating tooltip
 *         Included at bottom of every Admin page via require_once 'footer.php'
 * =============================================================================
 */
?>
</main>

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

// ── Show Save Role Button ──
function showSaveRole(uid) {
    document.getElementById('save-role-'+uid).style.display = 'inline-block';
}

// ── Toggle Edit Challenge ──
function toggleAdminEditCh(id) {
    const editRow = document.getElementById('ach-edit-'+id);
    editRow.style.display = editRow.style.display === 'table-row' ? 'none' : 'table-row';
}
</script>

<script>
// ── Mobile sidebar ──
function toggleSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar(){
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}
document.querySelectorAll('.nav-item').forEach(function(item){
    item.addEventListener('click',function(){ if(window.innerWidth<=768) closeSidebar(); });
});

// ── Dark / Light mode ──
(function(){
    const KEY='gp_theme';
    function apply(dark){
        document.body.classList.toggle('dark',dark);
        const tip=document.getElementById('darkTip');
        if(tip) tip.textContent=dark?'☀ Light mode':'🌙 Dark mode';
    }
    apply(localStorage.getItem(KEY)==='dark');
    window.toggleDarkMode=function(){
        const isDark=document.body.classList.contains('dark');
        localStorage.setItem(KEY,isDark?'light':'dark');
        apply(!isDark);
    };
})();
</script>

<script>
// ── Shared floating tooltip ──
(function(){
    const tip = document.createElement('div');
    tip.id = 'floatTip';
    document.body.appendChild(tip);

    function show(el, text){
        tip.textContent = text;
        tip.classList.add('show');
        const r = el.getBoundingClientRect();
        tip.style.left = (r.left + r.width/2) + 'px';
        tip.style.top  = (r.top - 36) + 'px';
    }
    function hide(){ tip.classList.remove('show'); }

    const av = document.getElementById('avatarToggle');
    if(av){
        av.addEventListener('mouseenter', function(){
            const isDark = document.body.classList.contains('dark');
            show(av, isDark ? '☀ Switch to Light' : '🌙 Switch to Dark');
        });
        av.addEventListener('mouseleave', hide);
    }

    const origToggle = window.toggleDarkMode;
    window.toggleDarkMode = function(){
        origToggle && origToggle();
        hide();
    };
})();
</script>

</body>
</html>
