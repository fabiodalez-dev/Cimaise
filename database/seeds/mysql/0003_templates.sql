-- Default template configurations for MySQL
INSERT INTO templates (name, slug, description, settings, libs, created_at) VALUES
(
  'Grid Classica',
  'grid-classica',
  'Layout a griglia responsivo - desktop 3 colonne, tablet 2, mobile 1',
  '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}',
  '["photoswipe"]',
  NOW()
),
(
  'Masonry Portfolio',
  'masonry-portfolio',
  'Layout masonry responsivo per portfolio - desktop 4 colonne, tablet 3, mobile 2',
  '{"layout":"grid","columns":{"desktop":4,"tablet":3,"mobile":2},"masonry":true,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true}}',
  '["photoswipe"]',
  NOW()
),
(
  'Slideshow Minimal',
  'slideshow-minimal',
  'Slideshow minimalista con controlli essenziali',
  '{"layout":"slideshow","columns":{"desktop":1,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":false,"arrowKeys":true,"escKey":true,"bgOpacity":0.95,"spacing":0.05,"allowPanToNext":false}}',
  '["photoswipe"]',
  NOW()
),
(
  'Gallery Fullscreen',
  'gallery-fullscreen',
  'Layout fullscreen responsivo - desktop 2 colonne, tablet 1, mobile 1',
  '{"layout":"fullscreen","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":1.0,"spacing":0,"allowPanToNext":true}}',
  '["photoswipe"]',
  NOW()
),
(
  'Grid Compatta',
  'grid-compatta',
  'Layout compatto con molte colonne - desktop 5 colonne, tablet 3, mobile 2',
  '{"layout":"grid","columns":{"desktop":5,"tablet":3,"mobile":2},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}',
  '["photoswipe"]',
  NOW()
),
(
  'Grid Ampia',
  'grid-ampia',
  'Layout con poche colonne per immagini grandi - desktop 2 colonne, tablet 1, mobile 1',
  '{"layout":"grid","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.85,"spacing":0.15,"allowPanToNext":true}}',
  '["photoswipe"]',
  NOW()
)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  settings = VALUES(settings),
  libs = VALUES(libs);