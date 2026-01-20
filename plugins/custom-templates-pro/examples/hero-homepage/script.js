/**
 * Hero Homepage - Optional JavaScript
 * Puoi aggiungere qui interazioni personalizzate
 */

// Esempio: Intersection Observer per animazioni scroll
(function() {
  'use strict';

  // Verifica se IntersectionObserver è disponibile
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, {
      threshold: 0.1
    });

    // Osserva le card album
    const cards = document.querySelectorAll('.hero-album-card');
    if (cards.length > 0) {
      cards.forEach(card => observer.observe(card));
    }
  }
})();
