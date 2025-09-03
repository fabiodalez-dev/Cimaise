// Expose Lenis constructor globally so inline scripts can use it without bundler imports
import Lenis from 'lenis'
import './home-gallery.js'
import './albums-carousel.js'

if (typeof window !== 'undefined') {
  window.Lenis = Lenis
  const lenis = new window.Lenis({
    lerp: 0.1,
    wheelMultiplier: 1,
    infinite: false,
    gestureOrientation: 'vertical',
    normalizeWheel: false,
    smoothTouch: false,
  })
  if (typeof window.gsap !== 'undefined' && window.gsap.ticker) {
    lenis.on('scroll', window.gsap.updateRoot)
    window.gsap.ticker.add((time) => { lenis.raf(time * 1000) })
    window.gsap.ticker.lagSmoothing(0)
  } else {
    function raf(time){ lenis.raf(time); requestAnimationFrame(raf) }
    requestAnimationFrame(raf)
  }
}

