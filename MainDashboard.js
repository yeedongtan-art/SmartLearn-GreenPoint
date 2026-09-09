
(function () {
  'use strict';
  const STORAGE_THEME = 'smartlearn-theme';

  function initTheme() {
    const saved = localStorage.getItem(STORAGE_THEME);
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = saved || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('themeToggle');
    btn?.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem(STORAGE_THEME, next);
    });
  }

  class ParticleNetwork {
    constructor(canvas) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.particles = [];
      this.mouse = { x: -9999, y: -9999 };
      this.resize();
      window.addEventListener('resize', () => this.resize());
      window.addEventListener('mousemove', (e) => {
        this.mouse.x = e.clientX;
        this.mouse.y = e.clientY;
        document.body.classList.add('cursor-active');
      });
      window.addEventListener('mouseleave', () => {
        document.body.classList.remove('cursor-active');
      });
      this.animate();
    }
    resize() {
      this.canvas.width = window.innerWidth;
      this.canvas.height = window.innerHeight;
      const count = Math.min(90, Math.floor(window.innerWidth / 14));
      while (this.particles.length < count) this.particles.push(this.makeParticle());
      while (this.particles.length > count) this.particles.pop();
    }
    makeParticle() {
      return {
        x: Math.random() * this.canvas.width,
        y: Math.random() * this.canvas.height,
        vx: (Math.random() - 0.5) * 0.45,
        vy: (Math.random() - 0.5) * 0.45,
        r: Math.random() * 2 + 1,
      };
    }
    animate() {
      const { ctx, canvas, particles, mouse } = this;
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      const nodeColor = isDark ? 'rgba(0, 174, 239, 0.85)' : 'rgba(14, 128, 215, 0.7)';
      const lineColor = isDark ? 'rgba(0, 174, 239, 0.12)' : 'rgba(88, 96, 138, 0.1)';
      const mouseLine = isDark ? 'rgba(0, 174, 239, 0.25)' : 'rgba(14, 128, 215, 0.18)';
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      particles.forEach((p) => {
        p.x += p.vx;
        p.y += p.vy;
        if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
        if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
        const dx = mouse.x - p.x;
        const dy = mouse.y - p.y;
        const dist = Math.hypot(dx, dy);
        if (dist < 140) {
          p.x -= dx * 0.008;
          p.y -= dy * 0.008;
        }
      });
      for (let i = 0; i < particles.length; i++) {
        for (let j = i + 1; j < particles.length; j++) {
          const a = particles[i];
          const b = particles[j];
          const d = Math.hypot(a.x - b.x, a.y - b.y);
          if (d < 130) {
            ctx.beginPath();
            ctx.strokeStyle = lineColor;
            ctx.lineWidth = 1 - d / 130;
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.stroke();
          }
        }
      }
      particles.forEach((p) => {
        if (Math.hypot(mouse.x - p.x, mouse.y - p.y) < 160) {
          ctx.beginPath();
          ctx.strokeStyle = mouseLine;
          ctx.moveTo(p.x, p.y);
          ctx.lineTo(mouse.x, mouse.y);
          ctx.stroke();
        }
        ctx.beginPath();
        ctx.fillStyle = nodeColor;
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fill();
      });
      requestAnimationFrame(() => this.animate());
    }
  }

  /* ── Cursor glow ── */
  function initCursorGlow() {
    const glow = document.createElement('div');
    glow.className = 'cursor-glow';
    document.body.appendChild(glow);
    window.addEventListener('mousemove', (e) => {
      glow.style.left = e.clientX + 'px';
      glow.style.top = e.clientY + 'px';
    });
  }

  /* ── Scroll progress bar ── */
  function initScrollProgress() {
    const bar = document.createElement('div');
    bar.className = 'scroll-progress';
    document.body.appendChild(bar);
    window.addEventListener('scroll', update, { passive: true });
    function update() {
      const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
      const pct = scrollHeight > 0 ? (window.scrollY / scrollHeight) * 100 : 0;
      bar.style.width = pct + '%';
    }
    update();
  }

  /* ── Count-up numbers ── */
  function animateCount(el, target, suffix, duration) {
    duration = duration || 1200;
    const start = performance.now();
    function tick(now) {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(eased * target) + (suffix || '');
      if (t < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  function initStats() {
    const items = document.querySelectorAll('[data-count]');
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const el = entry.target;
            const target = parseFloat(el.dataset.count) || 0;
            const suffix = el.dataset.suffix || '';
            animateCount(el, target, suffix);
            io.unobserve(el);
          }
        });
      },
      { threshold: 0.4 }
    );
    items.forEach((el) => io.observe(el));
  }

  /* ── Rotating typed word in hero ── */
  function initTypedWord() {
    const el = document.getElementById('typedWord');
    if (!el) return;
    const words = (el.dataset.words || 'Smarter').split(',').map((w) => w.trim());
    let wordIndex = 0;
    let charIndex = 0;
    let deleting = false;
    const cursor = document.createElement('span');
    cursor.className = 'typed-cursor';
    el.textContent = '';
    el.appendChild(cursor);
    function tick() {
      const word = words[wordIndex];
      charIndex += deleting ? -1 : 1;
      el.textContent = word.slice(0, charIndex);
      el.appendChild(cursor);
      let delay = deleting ? 45 : 90;
      if (!deleting && charIndex === word.length) {
        delay = 1400;
        deleting = true;
      } else if (deleting && charIndex === 0) {
        deleting = false;
        wordIndex = (wordIndex + 1) % words.length;
        delay = 300;
      }
      setTimeout(tick, delay);
    }
    setTimeout(tick, 500);
  }

  /* ── Reveal on scroll ── */
  function initReveal() {
    const items = document.querySelectorAll(
      '.stat-card, .module-card, .how-step, .showcase, .panel, .story-copy, .story-visual'
    );
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );
    items.forEach((el, idx) => {
      el.style.transitionDelay = (idx % 6) * 0.06 + 's';
      io.observe(el);
    });
  }

  /* ── Scrollspy: highlight the nav link for the story section in view ── */
  function initScrollSpy() {
    const sectionIds = ['materials', 'quizzes', 'schedule', 'history', 'ai-chatbot'];
    const sections = sectionIds.map((id) => document.getElementById(id)).filter(Boolean);
    if (!sections.length) return;
    const navLinks = document.querySelectorAll(
      '.site-nav-links a[href^="#"], .site-nav-mobile a[href^="#"]'
    );
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const id = entry.target.id;
            navLinks.forEach((a) => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
          }
        });
      },
      { rootMargin: '-45% 0px -50% 0px', threshold: 0 }
    );
    sections.forEach((s) => io.observe(s));
  }

  /* ── 3D tilt on feature cards ── */
  function initTilt() {
    document.querySelectorAll('.module-card').forEach((card) => {
      card.addEventListener('mousemove', (e) => {
        const rect = card.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const cx = rect.width / 2;
        const cy = rect.height / 2;
        const rotateX = ((y - cy) / cy) * -6;
        const rotateY = ((x - cx) / cx) * 6;
        card.style.setProperty('--mx', x + 'px');
        card.style.setProperty('--my', y + 'px');
        card.style.transform = `perspective(800px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-4px)`;
      });
      card.addEventListener('mouseleave', () => {
        card.style.transform = '';
      });
    });
  }

  /* ── Mobile nav toggle ── */
  function initMobileNav() {
    const btn = document.getElementById('navToggle');
    const menu = document.getElementById('navMobile');
    if (!btn || !menu) return;
    btn.addEventListener('click', () => {
      const open = menu.classList.toggle('show');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    menu.querySelectorAll('a').forEach((a) =>
      a.addEventListener('click', () => {
        menu.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
      })
    );
  }

  /* ── Smooth scroll for in-page anchor links ── */
  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
      link.addEventListener('click', (e) => {
        const id = link.getAttribute('href');
        if (!id || id === '#') return;
        const target = document.querySelector(id);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  /* ── Boot ── */
  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initCursorGlow();
    initScrollProgress();
    initStats();
    initTypedWord();
    initReveal();
    initScrollSpy();
    initTilt();
    initMobileNav();
    initSmoothScroll();
    const canvas = document.getElementById('particleCanvas');
    if (canvas) new ParticleNetwork(canvas);
  });
})();