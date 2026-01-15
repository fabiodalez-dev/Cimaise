
function onReady(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

onReady(() => {
  const carousel = document.getElementById('albums-carousel');
  if (!carousel) return;
  const container = carousel.parentElement;
  const items = carousel.querySelectorAll('.album-carousel-item');
  if (!items.length) return;

  let currentTranslate = 0;
  let previousTranslate = 0;
  let isDragging = false;
  let startPos = 0;
  let autoPlayId = null;
  let startTime = 0;
  let hasMoved = false;
  const DRAG_THRESHOLD = 10; // pixels to consider it a drag

  const getItemWidth = () => {
    const item = items[0];
    const styles = getComputedStyle(item);
    return item.offsetWidth + parseFloat(styles.marginLeft) + parseFloat(styles.marginRight);
  };

  const totalWidth = getItemWidth() * items.length;
  const setSliderPosition = () => {
    carousel.style.transform = `translate3d(${currentTranslate}px, 0, 0)`;
  };

  const getPositionX = (e) => {
    return e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;
  };

  const isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;

  // Auto-play only on desktop
  const autoPlay = () => {
    currentTranslate -= 0.6;
    if (Math.abs(currentTranslate) >= totalWidth) currentTranslate = 0;
    setSliderPosition();
    autoPlayId = requestAnimationFrame(autoPlay);
  };
  if (!isMobile) autoPlayId = requestAnimationFrame(autoPlay);

  const wrap = () => {
    const w = getItemWidth() * items.length;
    if (Math.abs(currentTranslate) >= w) currentTranslate = currentTranslate % w;
    if (currentTranslate > 0) currentTranslate = -w + (currentTranslate % w);
  };

  // --- MOUSE EVENTS (Desktop only) ---
  const mouseDown = (e) => {
    isDragging = true;
    hasMoved = false;
    startPos = e.clientX;
    startTime = Date.now();
    previousTranslate = currentTranslate;
    if (autoPlayId) {
      cancelAnimationFrame(autoPlayId);
      autoPlayId = null;
    }
  };

  const mouseMove = (e) => {
    if (!isDragging) return;
    const diff = e.clientX - startPos;
    if (Math.abs(diff) > DRAG_THRESHOLD) {
      hasMoved = true;
      carousel.classList.add('dragging');
      document.body.style.userSelect = 'none';
      currentTranslate = previousTranslate + diff;
      setSliderPosition();
    }
  };

  const mouseUp = () => {
    if (!isDragging) return;
    isDragging = false;
    carousel.classList.remove('dragging');
    document.body.style.userSelect = '';
    wrap();
    setSliderPosition();
    if (!isMobile) {
      setTimeout(() => {
        if (!autoPlayId) autoPlayId = requestAnimationFrame(autoPlay);
      }, 1200);
    }
  };

  // Mouse events - only prevent default if actually dragging
  carousel.addEventListener('mousedown', mouseDown);
  carousel.addEventListener('mousemove', mouseMove);
  carousel.addEventListener('mouseup', mouseUp);
  carousel.addEventListener('mouseleave', mouseUp);

  // --- TOUCH EVENTS (Mobile) ---
  let touchStartX = 0;
  let touchHasMoved = false;

  carousel.addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
    touchHasMoved = false;
    previousTranslate = currentTranslate;
    if (autoPlayId) {
      cancelAnimationFrame(autoPlayId);
      autoPlayId = null;
    }
  }, { passive: true });

  carousel.addEventListener('touchmove', (e) => {
    const diff = e.touches[0].clientX - touchStartX;
    if (Math.abs(diff) > DRAG_THRESHOLD) {
      touchHasMoved = true;
      carousel.classList.add('dragging');
      currentTranslate = previousTranslate + diff;
      setSliderPosition();
      // Only prevent default when actually swiping
      e.preventDefault();
    }
  }, { passive: false });

  carousel.addEventListener('touchend', () => {
    carousel.classList.remove('dragging');
    if (touchHasMoved) {
      wrap();
      setSliderPosition();
    }
    // Don't restart autoplay on mobile
  }, { passive: true });

  // Block click only if user dragged (mouse)
  carousel.addEventListener('click', (e) => {
    if (hasMoved) {
      e.preventDefault();
      e.stopPropagation();
      hasMoved = false; // Reset for next interaction
    }
  }, true);

  // Prevent image drag
  carousel.addEventListener('dragstart', (e) => e.preventDefault());

  // Desktop hover pause
  if (!isMobile) {
    container.addEventListener('mouseenter', () => {
      if (autoPlayId) {
        cancelAnimationFrame(autoPlayId);
        autoPlayId = null;
      }
    });
    container.addEventListener('mouseleave', () => {
      if (!autoPlayId && !isDragging) {
        autoPlayId = requestAnimationFrame(autoPlay);
      }
    });
  }

  // Arrow buttons
  const prevBtn = container.parentElement.querySelector('.albums-arrow-left');
  const nextBtn = container.parentElement.querySelector('.albums-arrow-right');
  const stepBy = () => getItemWidth();
  const goPrev = () => { currentTranslate += stepBy(); wrap(); setSliderPosition(); };
  const goNext = () => { currentTranslate -= stepBy(); wrap(); setSliderPosition(); };
  if (prevBtn) prevBtn.addEventListener('click', goPrev);
  if (nextBtn) nextBtn.addEventListener('click', goNext);
});
