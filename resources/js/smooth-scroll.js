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
  }
}
