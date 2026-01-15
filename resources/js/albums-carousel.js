
function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

onReady(() => {
  const carousel = document.getElementById('albums-carousel');
  if (!carousel) return;
  const container = carousel.parentElement;
  const items = carousel.querySelectorAll('.album-carousel-item');
  if (!items.length) return;

  let currentTranslate = 0; let previousTranslate = 0; let isDragging = false; let startPos = 0; let autoPlayId = null;
  let hasMoved = false; // Track if user actually dragged
  const DRAG_THRESHOLD = 5; // Minimum pixels to consider it a drag

  const getItemWidth = () => { const item = items[0]; const styles = getComputedStyle(item); return item.offsetWidth + parseFloat(styles.marginLeft) + parseFloat(styles.marginRight); };
  const itemWidth = getItemWidth(); const totalWidth = itemWidth * items.length;
  const setSliderPosition = () => { carousel.style.transform = `translate3d(${currentTranslate}px, 0, 0)`; };
  const getPositionX = (e) => e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;

  const isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  const autoPlay = () => { currentTranslate -= 0.6; if (Math.abs(currentTranslate) >= totalWidth) currentTranslate = 0; setSliderPosition(); autoPlayId = requestAnimationFrame(autoPlay); };
  if (!isMobile) autoPlayId = requestAnimationFrame(autoPlay);

  const dragStart = (e) => {
    // Don't prevent default here - allow clicks to work
    isDragging = true;
    hasMoved = false;
    startPos = getPositionX(e);
    previousTranslate = currentTranslate;
    if (autoPlayId) { cancelAnimationFrame(autoPlayId); autoPlayId = null; }
    carousel.classList.add('dragging');
    document.body.style.userSelect = 'none';
  };

  const dragMove = (e) => {
    if (!isDragging) return;
    const currentPosition = getPositionX(e);
    const diff = currentPosition - startPos;

    // Only prevent default and handle drag if moved beyond threshold
    if (Math.abs(diff) > DRAG_THRESHOLD) {
      e.preventDefault();
      e.stopPropagation();
      hasMoved = true;
      currentTranslate = previousTranslate + diff;
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
    if (!isMobile) setTimeout(() => { if (!autoPlayId) autoPlayId = requestAnimationFrame(autoPlay); }, 1200);
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
    container.addEventListener('mouseleave', () => { if (!autoPlayId && !isDragging) { autoPlayId = requestAnimationFrame(autoPlay); } });
  }

  const prevBtn = container.parentElement.querySelector('.albums-arrow-left');
  const nextBtn = container.parentElement.querySelector('.albums-arrow-right');
  const stepBy = () => getItemWidth() * 1;
  const goPrev = () => { currentTranslate += stepBy(); wrap(); setSliderPosition(); };
  const goNext = () => { currentTranslate -= stepBy(); wrap(); setSliderPosition(); };
  prevBtn && prevBtn.addEventListener('click', goPrev);
  nextBtn && nextBtn.addEventListener('click', goNext);
});
