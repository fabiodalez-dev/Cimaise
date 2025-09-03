// Expose Lenis constructor globally so inline scripts can use it without bundler imports
import Lenis from 'lenis'

// Make available on window for templates
// Do not instantiate here to avoid double RAF loops; inline script controls lifecycle
if (typeof window !== 'undefined') {
  window.Lenis = Lenis
}

