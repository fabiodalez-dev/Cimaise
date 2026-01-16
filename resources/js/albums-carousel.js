
function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

onReady(() => {
  const carousel = document.getElementById('albums-carousel');
  if (!carousel) return;
  const container = carousel.parentElement;
  const items = carousel.querySelectorAll('.album-carousel-item');
  if (!items.length) return;

  let currentTranslate = 0; let previousTranslate = 0; let isDragging = false; let startPosX = 0; let startPosY = 0; let autoPlayId = null;
  let hasMoved = false; // Track if user actually dragged
  const DRAG_THRESHOLD = 8; // Minimum pixels to consider it a drag

  const getItemWidth = () => { const item = items[0]; const styles = getComputedStyle(item); return item.offsetWidth + parseFloat(styles.marginLeft) + parseFloat(styles.marginRight); };
  const itemWidth = getItemWidth(); const totalWidth = itemWidth * items.length;
  const setSliderPosition = () => { carousel.style.transform = `translate3d(${currentTranslate}px, 0, 0)`; };
  const getPosition = (e) => {
    if (e.type.includes('mouse')) return { x: e.clientX, y: e.clientY };
    return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  };

  const isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  const autoPlay = () => { currentTranslate -= 0.6; if (Math.abs(currentTranslate) >= totalWidth) currentTranslate = 0; setSliderPosition(); autoPlayId = requestAnimationFrame(autoPlay); };

  // PERFORMANCE: Defer autoplay start using requestIdleCallback
  const startAutoPlay = () => {
    if (!isMobile && !autoPlayId && !document.hidden) {
      autoPlayId = requestAnimationFrame(autoPlay);
    }
  };

  if (!isMobile) {
    if ('requestIdleCallback' in window) {
      requestIdleCallback(startAutoPlay, { timeout: 1000 });
    } else {
      setTimeout(startAutoPlay, 100);
    }
  }

  // PERFORMANCE: Pause autoplay when tab is in background
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (autoPlayId) { cancelAnimationFrame(autoPlayId); autoPlayId = null; }
    } else if (!isMobile && !isDragging) {
      startAutoPlay();
    }
  });

  const dragStart = (e) => {
    // Don't prevent default here - allow clicks to work
    isDragging = true;
    hasMoved = false;
    const pos = getPosition(e);
    startPosX = pos.x;
    startPosY = pos.y;
    previousTranslate = currentTranslate;
    if (autoPlayId) { cancelAnimationFrame(autoPlayId); autoPlayId = null; }
    // Don't add 'dragging' class here - wait until user actually moves
    document.body.style.userSelect = 'none';
  };

  const dragMove = (e) => {
    if (!isDragging) return;
    const pos = getPosition(e);
    const diffX = pos.x - startPosX;
    const diffY = pos.y - startPosY;
    const shouldDrag = Math.abs(diffX) > DRAG_THRESHOLD && Math.abs(diffX) > Math.abs(diffY);

    // Only prevent default and handle drag if moved beyond threshold
    if (hasMoved || shouldDrag) {
      e.preventDefault();
      e.stopPropagation();
      if (!hasMoved) {
        hasMoved = true;
        carousel.classList.add('dragging'); // Only add class when actually dragging
      }
      currentTranslate = previousTranslate + diffX;
      setSliderPosition();
    }
  };

  const wrap = () => { const w = getItemWidth() * items.length; if (Math.abs(currentTranslate) >= w) currentTranslate = currentTranslate % w; if (currentTranslate > 0) currentTranslate = -w + (currentTranslate % w); };

  const dragEnd = () => {
    if (!isDragging) return;
    isDragging = false;
    carousel.classList.remove('dragging');
    document.body.style.userSelect = '';
    wrap();
    setSliderPosition();
    if (!isMobile) setTimeout(startAutoPlay, 1200);
  };

  // Prevent link clicks only if user dragged
  carousel.addEventListener('click', (e) => {
    if (hasMoved) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  carousel.addEventListener('mousedown', dragStart);
  carousel.addEventListener('mousemove', dragMove);
  carousel.addEventListener('mouseup', dragEnd);
  carousel.addEventListener('mouseleave', dragEnd);
  carousel.addEventListener('touchstart', dragStart, { passive: true });
  carousel.addEventListener('touchmove', dragMove, { passive: false });
  carousel.addEventListener('touchend', dragEnd);
  carousel.addEventListener('dragstart', (e) => e.preventDefault());
  carousel.addEventListener('selectstart', (e) => e.preventDefault());

  if (!isMobile) {
    container.addEventListener('mouseenter', () => { if (autoPlayId) { cancelAnimationFrame(autoPlayId); autoPlayId = null; } });
    container.addEventListener('mouseleave', () => { if (!isDragging) startAutoPlay(); });
  }

  const prevBtn = container.parentElement.querySelector('.albums-arrow-left');
  const nextBtn = container.parentElement.querySelector('.albums-arrow-right');
  const stepBy = () => getItemWidth() * 1;
  const goPrev = () => { currentTranslate += stepBy(); wrap(); setSliderPosition(); };
  const goNext = () => { currentTranslate -= stepBy(); wrap(); setSliderPosition(); };
  prevBtn && prevBtn.addEventListener('click', goPrev);
  nextBtn && nextBtn.addEventListener('click', goNext);
});
