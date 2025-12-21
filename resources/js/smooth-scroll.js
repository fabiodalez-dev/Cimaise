// Global smooth scroll with Lenis
// This script initializes Lenis smooth scrolling on all pages
import Lenis from 'lenis'

if (typeof window !== 'undefined') {
  window.Lenis = Lenis

  // Only initialize if not already done
  if (!window.lenisInstance) {
    const lenis = new Lenis({
      lerp: 0.1,
      wheelMultiplier: 1,
      infinite: false,
      gestureOrientation: 'vertical',
      normalizeWheel: false,
      smoothTouch: false,
      autoResize: true,
    })

    window.lenisInstance = lenis

    // GSAP integration if available
    if (typeof window.gsap !== 'undefined' && window.gsap.ticker) {
      lenis.on('scroll', window.gsap.updateRoot)
      window.gsap.ticker.add((time) => { lenis.raf(time * 1000) })
      window.gsap.ticker.lagSmoothing(0)
    } else {
      function raf(time) {
        lenis.raf(time)
        requestAnimationFrame(raf)
      }
      requestAnimationFrame(raf)
    }

    // Recalculate scroll height after page load and content changes
    const recalculate = () => {
      if (window.lenisInstance && typeof window.lenisInstance.resize === 'function') {
        window.lenisInstance.resize()
      }
    }

    // Recalculate after DOM is ready
    if (document.readyState === 'complete') {
      recalculate()
    } else {
      window.addEventListener('load', recalculate)
    }

    // Recalculate on resize
    let resizeTimeout
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout)
      resizeTimeout = setTimeout(recalculate, 100)
    })

    // Recalculate periodically for first few seconds (for lazy-loaded content)
    let recalcCount = 0
    const recalcInterval = setInterval(() => {
      recalculate()
      recalcCount++
      if (recalcCount >= 20) clearInterval(recalcInterval) // Stop after 10 seconds
    }, 500)

    // Recalculate when images load
    document.addEventListener('load', (e) => {
      if (e.target.tagName === 'IMG') {
        recalculate()
      }
    }, true)

    // MutationObserver for dynamic content changes
    const observer = new MutationObserver(() => {
      clearTimeout(resizeTimeout)
      resizeTimeout = setTimeout(recalculate, 100)
    })
    observer.observe(document.body, { childList: true, subtree: true })

    // Expose recalculate function globally
    window.lenisResize = recalculate
  }
}

// Helpers to pause/resume Lenis (used by lightbox)
export function pauseLenis() {
  if (window.lenisInstance && typeof window.lenisInstance.stop === 'function') {
    window.lenisInstance.stop()
  }
}

export function resumeLenis() {
  if (window.lenisInstance && typeof window.lenisInstance.start === 'function') {
    window.lenisInstance.start()
  }
}
