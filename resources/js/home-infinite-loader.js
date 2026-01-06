/**
 * Home Infinite Gallery Loader
 * Fetches remaining images in background to complete the full gallery set.
 */
(function () {
    'use strict';

    const gallery = document.getElementById('home-infinite-gallery');
    if (!gallery) return;

    // Configuration
    const API_ENDPOINT = '/api/home/gallery';
    const MAX_IMAGES = 500; // Total desired images
    let isFetching = false;

    // Helper to determine base path from existing DOM links
    function getBasePath() {
        if (typeof window.basePath === 'string') return window.basePath;

        // Try to find a link to an album
        const link = gallery.querySelector('a[href*="/album/"]');
        if (link) {
            const href = link.getAttribute('href');
            return href.split('/album/')[0];
        }
        // Fallback: check an image src
        const img = gallery.querySelector('img');
        if (img) {
            const src = img.getAttribute('src');
            // If src is absolute /uploads/..., base path might be empty string or subfolder
            if (src.startsWith('http')) return ''; // Can't determine easily
            if (src.startsWith('/')) return ''; // Root
            // If relative, implies current folder? No, base tag?
        }
        return '';
    }

    const basePath = getBasePath();

    // Normalize image object from Raw JSON (initial) or API JSON
    function normalizeImage(img) {
        // API returns 'url' (full), 'fallback_src' (full or relative?), 'sources' (formatted strings)
        // Initial JSON returns 'original_path', 'fallback_src' (raw), 'sources' (raw arrays)

        let url = img.url || img.original_path;
        let fallback = img.fallback_src || url;
        let sources = img.sources || {};

        // Helper to fix path
        const fixPath = (p) => {
            if (!p) return '';
            if (p.startsWith('http')) return p;
            if (p.startsWith('/')) {
                // If p is /uploads/..., and basePath is empty, it's fine.
                // If basePath is /subdir, then we need /subdir/uploads/...
                // But usually original_path in DB is 'uploads/...'.
                return basePath + p;
            }
            // If p is 'uploads/...', prepend basePath + '/'
            return (basePath ? basePath + '/' : '') + p;
        };

        // Check if it's already normalized (API response)
        if (img.url && img.url.includes('/')) {
            // Assume API returns valid URLs (it does in PageController)
        } else {
            // Normalize Initial JSON
            if (url && !url.startsWith('/') && !url.startsWith('http')) url = fixPath(url);
            if (fallback && !fallback.startsWith('/') && !fallback.startsWith('http')) fallback = fixPath(fallback);
        }

        // Normalize sources
        // API returns { jpg: ['url 1x', ...], ... }
        // Initial JSON returns { jpg: ['path/to/img.jpg 1x', ...], ... } 
        // We need to ensure paths in sources are prefixed if needed.
        const normSources = {};
        for (const [type, srcList] of Object.entries(sources)) {
            if (Array.isArray(srcList)) {
                normSources[type] = srcList.map(s => {
                    const parts = s.split(' ');
                    let sUrl = parts[0];
                    if (!sUrl.startsWith('/') && !sUrl.startsWith('http')) {
                        sUrl = fixPath(sUrl);
                    }
                    return sUrl + (parts[1] ? ' ' + parts[1] : '');
                });
            }
        }

        return {
            id: img.id,
            album_slug: img.album_slug || img.slug, // API vs partial
            album_title: img.album_title || img.title,
            alt: img.alt_text || img.alt || img.album_title,
            width: parseInt(img.width || 1600),
            height: parseInt(img.height || 1067),
            url: url,
            fallback_src: fallback,
            sources: normSources,
            // priority not in obj
        };
    }

    function createHomeItem(image, eager = false) {
        const ratio = image.width / image.height;

        let sourcesHtml = '';
        const s = image.sources;
        // Order: AVIF -> WebP -> JPG
        if (s.avif && s.avif.length) sourcesHtml += `<source type="image/avif" srcset="${s.avif.join(', ')}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">`;
        if (s.webp && s.webp.length) sourcesHtml += `<source type="image/webp" srcset="${s.webp.join(', ')}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">`;
        if (s.jpg && s.jpg.length) sourcesHtml += `<source type="image/jpeg" srcset="${s.jpg.join(', ')}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">`;

        const priorityAttr = eager ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';

        return `
      <div class="home-item home-item--revealed group rounded-xl overflow-hidden shadow-sm relative transition-transform hover:scale-105 duration-300" 
           style="aspect-ratio: ${ratio};">
        <a href="${basePath}/album/${image.album_slug}" class="block w-full h-full relative z-10" title="${image.album_title || ''}">
          <picture class="block w-full h-full">
            ${sourcesHtml}
            <img src="${image.fallback_src}"
                 alt="${image.alt || ''}"
                 width="${image.width}" height="${image.height}"
                 ${priorityAttr}
                 decoding="async" class="w-full h-full object-cover block">
          </picture>
          <div class="absolute inset-0 bg-black/70 text-white flex items-center justify-center transform translate-y-full group-hover:translate-y-0 transition-transform duration-300">
            <span class="px-4 text-base md:text-lg lg:text-xl font-medium tracking-tight text-center">${image.album_title || ''}</span>
          </div>
        </a>
      </div>
    `;
    }

    function fetchMoreImages() {
        if (isFetching) return;
        isFetching = true;

        // Get initial images
        const initialRaw = window.initialGalleryImages || [];
        const initialMapped = initialRaw.map(normalizeImage);
        const existingIds = initialMapped.map(i => i.id).filter(id => id); // filter nulls

        const need = MAX_IMAGES - existingIds.length;
        if (need <= 0) return;

        // Wait for idle
        const startFetch = () => {
            // Exclude Ids
            const excludeStr = existingIds.join(',');
            fetch(`${API_ENDPOINT}?limit=${need}&exclude=${excludeStr}`)
                .then(res => res.json())
                .then(data => {
                    if (data.images) {
                        const newMapped = data.images.map(normalizeImage);
                        const combined = [...initialMapped, ...newMapped];
                        updateGallery(combined);
                    }
                })
                .catch(e => console.error('Gallery bg load err', e))
                .finally(() => isFetching = false);
        };

        if ('requestIdleCallback' in window) {
            requestIdleCallback(startFetch, { timeout: 3000 });
        } else {
            setTimeout(startFetch, 2000);
        }
    }

    function updateGallery(allImages) {
        // Rotation logic
        const total = allImages.length;
        const o2 = Math.floor(total / 3);
        const o3 = Math.floor(total * 2 / 3);

        const col1 = allImages;
        const col2 = [...allImages.slice(o2), ...allImages.slice(0, o2)];
        const col3 = [...allImages.slice(o3), ...allImages.slice(0, o3)];
        const datasets = [col1, col2, col3];

        const isVertical = document.querySelector('.home-desktop-vertical') !== null;
        const isHorizontal = document.querySelector('.home-desktop-horizontal') !== null;
        const selector = isVertical ? '.home-desktop-vertical .home-col' : '.home-desktop-horizontal .home-row';

        const containers = document.querySelectorAll(selector);
        containers.forEach((container, idx) => {
            const track = container.querySelector(isVertical ? '.home-track' : '.home-track-h');
            if (!track) return;

            const images = datasets[idx] || datasets[0];
            // For large sets, repeat 2 times is enough for seamless loop
            const repeatTimes = 2;

            // Optim: build big string
            let htmlParts = [];
            for (let r = 0; r < repeatTimes; r++) {
                htmlParts.push(images.map(img =>
                    isVertical
                        ? `<div class="home-cell">${createHomeItem(img)}</div>`
                        : `<div class="home-cell-h">${createHomeItem(img)}</div>`
                ).join(''));
            }

            // Update Duration
            const currentDur = getComputedStyle(container).getPropertyValue('--home-duration');
            const oldVal = parseFloat(currentDur);
            // Estimate density: Current DOM has ~96 items (48*2). New has 1000.
            // We know initialRaw.length (48).
            // Ratio = images.length (500) / initialRaw.length (48) ~ 10.4
            // Logic: newDuration = oldDuration * (newCount / initialCount)
            // Parse initial count from window.initialGalleryImages
            const initialCount = (window.initialGalleryImages || []).length || 48;
            if (!isNaN(oldVal) && initialCount > 0) {
                const ratio = images.length / initialCount;
                const newVal = oldVal * ratio;
                container.style.setProperty('--home-duration', `${newVal}s`);
            }

            track.innerHTML = htmlParts.join('');

            // Add class to reveal items (if home.js requires it)
            // home.js adds .home-item--revealed
            // We put it directly? Or use the class immediately
            // The CSS transition for reveal is .home-item { opacity: 0; ... } .home-item--revealed { opacity: 1 }
            // We should add it to ensure they are visible.
            // Actually, just append style or add class in createHomeItem?
            // Let's add the class via simple querySelectorAll after insert.
            // Or cleaner: add 'home-item--revealed' in HTML string.
            // But createHomeItem defines class="home-item ...". 
            // Let's modify createHomeItem to include the class if needed, or JS it.
            // Easier to JS it batch.
        });

        // Reveal new items
        const newItems = document.querySelectorAll('.home-item:not(.home-item--revealed)');
        newItems.forEach(item => item.classList.add('home-item--revealed'));
    }

    if (document.readyState === 'complete') fetchMoreImages();
    else window.addEventListener('load', fetchMoreImages);

})();
