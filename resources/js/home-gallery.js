
// Home gallery behaviors: entry reveal, column autoscroll controls, drag + ramp resume
function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
onReady(() => {
  const gallery = document.getElementById('home-infinite-gallery');
  const homeCols = document.querySelectorAll('.home-col');
  if (gallery) {
    gallery.classList.add('entry-init');
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const scope = isDesktop ? document.querySelector('.home-desktop-wrap') : document.querySelector('.home-mobile-wrap');
    let items = scope ? Array.from(scope.querySelectorAll('.home-item')) : [];
    // Build priority: items currently in viewport first (top to bottom, then left), then near viewport, then others (shuffled)
    const ih = window.innerHeight || document.documentElement.clientHeight;
    const withRect = items.map((el) => { const r = el.getBoundingClientRect(); return { el, top: r.top, left: r.left, vis: (r.top < ih && r.bottom > 0), near: (r.top < ih * 2) }; });
    const inView = withRect.filter(x => x.vis).sort((a,b)=> a.top-b.top || a.left-b.left);
    const nearView = withRect.filter(x => !x.vis && x.near);
    // shuffle nearView
    for (let i = nearView.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [nearView[i], nearView[j]] = [nearView[j], nearView[i]]; }
    const others = withRect.filter(x => !x.vis && !x.near);
    for (let i = others.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [others[i], others[j]] = [others[j], others[i]]; }
    const order = [...inView, ...nearView, ...others].map(x => x.el);

    // Schedule: fill visible area fast (~1s), rest by 6s total
    const totalMs = 6000;
    const visibleMs = Math.min(1200, Math.max(400, Math.floor(totalMs * 0.2)));
    const base = 0;
    const k = Math.max(1, inView.length);
    const stepVisible = Math.max(6, Math.floor(visibleMs / k));
    const remaining = Math.max(0, totalMs - (k * stepVisible));
    const stepRest = order.length > k ? Math.max(6, Math.floor(remaining / (order.length - k))) : 0;

    let t = base;
    order.forEach((el, i) => {
      const step = i < k ? stepVisible : stepRest;
      setTimeout(() => el.classList.add('home-item--revealed'), t);
      t += step;
    });
    requestAnimationFrame(()=>{ gallery.style.opacity = '1'; });
    setTimeout(() => gallery.classList.remove('entry-init'), t + 200);
  }
  if (!homeCols.length) return;
  // IO pause per column
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        const track = entry.target.querySelector('.home-track');
        if (!track) return;
        track.style.animationPlayState = entry.isIntersecting ? 'running' : 'paused';
      });
    }, { threshold: 0.05 });
    homeCols.forEach(col => io.observe(col));
  }
  // Reduced motion
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.home-col .home-track').forEach(track => {
      track.style.animationDuration = '200s';
    });
  }
  // Drag + ramp resume per column
  homeCols.forEach((col) => {
    const track = col.querySelector('.home-track'); if (!track) return;
    let isDragging = false, startY = 0, startTranslate = 0;
    const getTranslateY = (el) => { const st = getComputedStyle(el).transform; if (!st || st === 'none') return 0; const m = st.match(/matrix\(([^)]+)\)/); if (m){ const v=m[1].split(',').map(parseFloat); return v[5]||0; } const m3 = st.match(/matrix3d\(([^)]+)\)/); if (m3){ const v=m3[1].split(',').map(parseFloat); return v[13]||0; } return 0; };
    const clampLoop = (y) => { const half = track.scrollHeight/2; if (half<=0) return y; while (y<=-half) y+=half; while (y>0) y-=half; return y; };
    const onDown = (e)=>{ isDragging=true; startY=(e.touches?e.touches[0].clientY:e.clientY); startTranslate=getTranslateY(track); track.style.animationPlayState='paused'; col.classList.add('dragging'); e.preventDefault(); };
    const onMove = (e)=>{ if(!isDragging) return; const y=(e.touches?e.touches[0].clientY:e.clientY); let next=startTranslate+(y-startY); next=clampLoop(next); track.style.transform=`translate3d(0, ${next}px, 0)`; e.preventDefault(); };
    const onUp = ()=>{ if(!isDragging) return; isDragging=false; col.classList.remove('dragging'); };
    col.addEventListener('mousedown', onDown); col.addEventListener('mousemove', onMove); window.addEventListener('mouseup', onUp);
    col.addEventListener('touchstart', onDown, {passive:false}); col.addEventListener('touchmove', onMove, {passive:false}); col.addEventListener('touchend', onUp);
    let pauseTimer=null, resumeTimer=null;
    col.addEventListener('mouseenter', ()=>{ clearTimeout(resumeTimer); pauseTimer=setTimeout(()=>{ track.style.animationPlayState='paused'; },100); });
    col.addEventListener('mouseleave', ()=>{
      if (isDragging) return; clearTimeout(pauseTimer);
      const dir=(col.getAttribute('data-direction')||'down').toLowerCase();
      const half=track.scrollHeight/2; const curr=getTranslateY(track);
      const durVar=(getComputedStyle(col).getPropertyValue('--home-duration').trim()||'420s'); const durSec=parseFloat(durVar); const pxPerSec=half/durSec; const sign=(dir==='up')?-1:1;
      const rampMs=2500; let last=performance.now(); const start=last; const easeIn=(t)=>1-Math.cos((t*Math.PI)/2);
      track.style.animation='none'; track.style.animationPlayState=''; track.style.transform=`translate3d(0, ${curr}px, 0)`;
      const rafStep=(now)=>{ const dt=Math.max(0, now-last)/1000; const p=Math.min(1, (now-start)/rampMs); const vfactor=easeIn(p); const dy=sign*pxPerSec*vfactor*dt*-1; const currentY=getTranslateY(track); let next=clampLoop(currentY+dy); track.style.transform=`translate3d(0, ${next}px, 0)`; last=now; if(p<1){ requestAnimationFrame(rafStep);} else {
        let progress=0; if(dir==='down'){ progress=Math.max(0, Math.min(1, -next/half)); } else { progress=Math.max(0, Math.min(1, (next+half)/half)); }
        const animName=(dir==='up')?'homeScrollUp':'homeScrollDown'; track.style.animation=`${animName} ${durSec}s linear infinite`; track.style.animationDelay=`-${(progress*durSec).toFixed(3)}s`; track.style.animationPlayState='running'; requestAnimationFrame(()=>{ track.style.transform=''; });
      }}; requestAnimationFrame(rafStep);
    });
  });
});
