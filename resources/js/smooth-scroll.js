// Global smooth scroll with Lenis
// This script initializes Lenis smooth scrolling on all pages
import Lenis from 'lenis'

if (typeof window !== 'undefined') {
  window.Lenis = Lenis

  // Only initialize if not already done
  if (!window.lenisInstance) {
    // CONDITIONAL DISABLE: Skip if 'no-lenis' class is present on html or body
    if (document.documentElement.classList.contains('no-lenis') || (document.body && document.body.classList.contains('no-lenis'))) {
        // console.log('Lenis disabled by class');
    } else {
        const lenis = new Lenis({
          lerp: 0.1,
          wheelMultiplier: 1.2,
          infinite: false,
          gestureOrientation: 'vertical',
          normalizeWheel: false,
          smoothTouch: false,
          autoResize: true,
          syncTouch: false,
          touchMultiplier: 2.0,
        })
    
        window.lenisInstance = lenis
    
        let rafId = null
        let resizeTimeout
        let recalcInterval
        let loadHandler = null
        let resizeHandler = null
        let imageLoadHandler = null
        let mutationObserver = null
        let gsapTickHandler = null

        // GSAP integration if available
        if (typeof window.gsap !== 'undefined' && window.gsap.ticker) {
          lenis.on('scroll', window.gsap.updateRoot)
          gsapTickHandler = (time) => { lenis.raf(time * 1000) }
          window.gsap.ticker.add(gsapTickHandler)
          window.gsap.ticker.lagSmoothing(0)
        } else {
          function raf(time) {
            lenis.raf(time)
            rafId = requestAnimationFrame(raf)
          }
          rafId = requestAnimationFrame(raf)
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
          loadHandler = () => recalculate()
          window.addEventListener('load', loadHandler)
        }
    
        // Recalculate on resize
        resizeHandler = () => {
          clearTimeout(resizeTimeout)
          resizeTimeout = setTimeout(recalculate, 100)
        }
        window.addEventListener('resize', resizeHandler)
    
        // Recalculate periodically for first few seconds (for lazy-loaded content)
        let recalcCount = 0
        recalcInterval = setInterval(() => {
          recalculate()
          recalcCount++
          if (recalcCount >= 20) clearInterval(recalcInterval) // Stop after 10 seconds
        }, 500)
    
        // Recalculate when images load
        imageLoadHandler = (e) => {
          if (e.target.tagName === 'IMG') {
            recalculate()
          }
        }
        document.addEventListener('load', imageLoadHandler, true)
    
        // MutationObserver for dynamic content changes
        mutationObserver = new MutationObserver(() => {
          clearTimeout(resizeTimeout)
          resizeTimeout = setTimeout(recalculate, 100)
        })
        if (document.body) {
          mutationObserver.observe(document.body, { childList: true, subtree: true })
        }
    
        // Expose recalculate function globally
        window.lenisResize = recalculate
        window.lenisMutationObserver = mutationObserver
        window.lenisCleanup = () => {
          if (loadHandler) window.removeEventListener('load', loadHandler)
          if (resizeHandler) window.removeEventListener('resize', resizeHandler)
          if (imageLoadHandler) document.removeEventListener('load', imageLoadHandler, true)
          if (mutationObserver) mutationObserver.disconnect()
          clearInterval(recalcInterval)
          clearTimeout(resizeTimeout)
          if (rafId) cancelAnimationFrame(rafId)
          if (gsapTickHandler && window.gsap && window.gsap.ticker) {
            window.gsap.ticker.remove(gsapTickHandler)
          }
          if (window.lenisInstance && typeof window.lenisInstance.destroy === 'function') {
            window.lenisInstance.destroy()
          }
          window.lenisInstance = null
        }
    }
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
