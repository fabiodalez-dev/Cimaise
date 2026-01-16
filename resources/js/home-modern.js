/**
 * Modern Home Template
 * JavaScript: Infinite scroll grid + hover effects + Lenis smooth scroll
 */
import { createFetchPriorityObserver } from './utils/fetch-priority-observer.js';

document.addEventListener('DOMContentLoaded', function() {

    // ============================================
    // MOBILE DETECTION
    // ============================================

    const MOBILE_BREAKPOINT = 768;
    const isMobile = () => window.innerWidth < MOBILE_BREAKPOINT;

    // ============================================
    // LENIS SMOOTH SCROLL (desktop only)
    // ============================================

    const enableLenis = false;
    let lenisInstance = null;

    // Only initialize Lenis on desktop for smooth scrolling
    if (enableLenis && !isMobile()) {
        Promise.all([
            import('lenis'),
            import('lenis/dist/lenis.css')
        ]).then(([module]) => {
            const Lenis = module.default;
            lenisInstance = new Lenis({
                duration: 2.0,
                easing: (t) => 1 - Math.pow(1 - t, 4),
                direction: 'vertical',
                gestureDirection: 'vertical',
                smooth: true,
                smoothTouch: false,
                touchMultiplier: 1.5,
                wheelMultiplier: 0.8
            });

            let rafId = null;
            const raf = (time) => {
                lenisInstance.raf(time);
                rafId = requestAnimationFrame(raf);
            };

            rafId = requestAnimationFrame(raf);
            window.addEventListener('beforeunload', () => {
                if (rafId) cancelAnimationFrame(rafId);
                if (lenisInstance && typeof lenisInstance.destroy === 'function') {
                    lenisInstance.destroy();
                }
            }, { once: true });
        }).catch(err => {
            console.warn('Lenis smooth scroll could not be loaded:', err);
        });
    }

    // ============================================
    // INFINITE SCROLL GRID
    // ============================================

    const $menu = document.querySelector('.inf-work_list');
    const $scroller = document.querySelector('.work-layout');
    let $allItems = Array.from(document.querySelectorAll('.inf-work_item'));
    let cachedItems = [];
    let cachedItemsCol1 = [];
    let cachedItemsCol2 = [];

    // Track state
    let isFiltered = false;
    let useInfiniteScroll = false;
    let scrollY = 0;
    let y = 0;
    let y2 = 0;
    let itemHeight = 0;
    let wrapHeight = 0;
    let lastScrollY = 0; // Track last scroll position for optimization
    let needsRender = false; // Only render when needed
    const LERP_COL1 = 0.08;
    const LERP_COL2 = 0.07;
    const SCROLL_DELTA_THRESHOLD = 0.05;
    const LERP_DELTA_THRESHOLD = 0.2;
    let triggerRender = null;

    // Minimum items for good infinite scroll effect
    const MIN_ITEMS_FOR_INFINITE = 8;
    let scrollerWheelHandler = null;

    const updateCachedItems = () => {
        cachedItems = Array.from(document.querySelectorAll('.inf-work_item'));
        $allItems = cachedItems;
        cachedItemsCol1 = cachedItems.filter((_, i) => i % 2 === 0);
        cachedItemsCol2 = cachedItems.filter((_, i) => i % 2 === 1);
    };

    updateCachedItems();

    const updateDimensions = () => {
        if (!cachedItems[0]) return;
        const newHeight = cachedItems[0].clientHeight;
        if (newHeight > 0 && newHeight !== itemHeight) {
            itemHeight = newHeight;
            wrapHeight = (cachedItems.length / 2) * itemHeight;
            needsRender = true;
            if (typeof triggerRender === 'function') {
                triggerRender();
            }
        }
    };

    let dimensionUpdateTimer = null;
    const scheduleDimensionUpdate = () => {
        if (dimensionUpdateTimer) return;
        dimensionUpdateTimer = setTimeout(() => {
            dimensionUpdateTimer = null;
            updateCachedItems();
            updateDimensions();
        }, 0);
    };

    const bindImageLoadHandlers = () => {
        const images = document.querySelectorAll('.inf-work_item img');
        images.forEach(img => {
            if (img.dataset.dimensionsSetup) return;
            img.dataset.dimensionsSetup = 'true';
            const onLoad = () => scheduleDimensionUpdate();
            img.addEventListener('load', onLoad, { once: true });
            img.addEventListener('error', onLoad, { once: true });
            if (img.complete) {
                onLoad();
            }
        });
    };

    // Clear transforms
    const clearTransforms = (items) => {
        items.forEach(item => {
            item.style.transform = '';
        });
    };

    const applyWillChange = (items, enabled) => {
        items.forEach(item => {
            item.style.willChange = enabled ? 'transform' : '';
        });
    };

    // Clone items to fill the wall if we have too few
    const cloneItemsForWall = () => {
        if (!$menu || $allItems.length === 0) return;

        const originalItems = Array.from(document.querySelectorAll('.inf-work_item:not(.is-clone)'));
        const originalCount = originalItems.length;

        if (originalCount >= MIN_ITEMS_FOR_INFINITE) return; // Already have enough
        if (originalCount === 0) return;

        // Calculate how many clones we need
        const clonesNeeded = Math.ceil(MIN_ITEMS_FOR_INFINITE / originalCount) * originalCount - originalCount;

        for (let i = 0; i < clonesNeeded; i++) {
            const sourceItem = originalItems[i % originalCount];
            const clone = sourceItem.cloneNode(true);
            clone.classList.add('is-clone');
            // Remove unique IDs to prevent conflicts
            clone.removeAttribute('id');
            $menu.appendChild(clone);
        }

        // Update items list
        updateCachedItems();
    };

    // Dispose items with wrapping
    const dispose = (scroll, items) => {
        if (isMobile() || !useInfiniteScroll || isFiltered) return;
        items.forEach((item, i) => {
            let newY = i * itemHeight + scroll;
            // Wrap around
            const s = ((newY % wrapHeight) + wrapHeight) % wrapHeight;
            const finalY = s - itemHeight;
            item.style.transform = `translate(0px, ${finalY}px)`;
        });
    };

    // Initialize infinite scroll
    const initInfiniteScroll = () => {
        updateCachedItems();
        if (!$menu || $allItems.length === 0 || isMobile()) {
            if ($menu) {
                $menu.classList.add('simple-layout');
                clearTransforms($allItems);
            }
            applyWillChange($allItems, false);
            return;
        }

        // Clone items if needed for wall effect
        cloneItemsForWall();

        // Get updated items after cloning
        const allItems = cachedItems;
        const $items = cachedItemsCol1; // column 1 (0, 2, 4...)
        const $items2 = cachedItemsCol2; // column 2 (1, 3, 5...)

        if (allItems.length < 4) {
            $menu.classList.add('simple-layout');
            allItems.forEach(item => item.classList.add('is-visible'));
            applyWillChange(allItems, false);
            return;
        }

        useInfiniteScroll = true;
        $menu.classList.remove('simple-layout');
        $menu.classList.remove('filtered-layout');
        applyWillChange(allItems, true);

        // Calculate dimensions
        itemHeight = allItems[0].clientHeight || 400;
        wrapHeight = (allItems.length / 2) * itemHeight;
        bindImageLoadHandlers();

        // Initial positioning
        dispose(0, $items);
        dispose(0, $items2);

        // Make all visible
        allItems.forEach(item => item.classList.add('is-visible'));

        // Animation loop - run only while movement is in progress
        let rafActive = false;
        let lastInputTime = 0;
        const render = () => {
            if (isMobile() || !useInfiniteScroll || isFiltered) {
                rafActive = false;
                return;
            }

            const scrollDelta = Math.abs(scrollY - lastScrollY);
            const lerpDelta = Math.abs(scrollY - y) + Math.abs(scrollY - y2);
            const isRecentInput = (performance.now() - lastInputTime) < 200;

            if (scrollDelta > SCROLL_DELTA_THRESHOLD || lerpDelta > LERP_DELTA_THRESHOLD || needsRender || isRecentInput) {
                needsRender = false;
                lastScrollY = scrollY;

                const $items = cachedItemsCol1;
                const $items2 = cachedItemsCol2;

                // Lerp for smooth animation
                y = y + (scrollY - y) * LERP_COL1;
                y2 = y2 + (scrollY - y2) * LERP_COL2;

                dispose(y, $items);
                dispose(y2, $items2);

                requestAnimationFrame(render);
            } else {
                rafActive = false;
            }
        };

        const startRender = () => {
            if (rafActive) return;
            rafActive = true;
            requestAnimationFrame(render);
        };

        startRender();
        triggerRender = startRender;

        // Handle mouse wheel
        const handleMouseWheel = (e) => {
            if (isMobile() || !useInfiniteScroll || isFiltered) return;
            e.preventDefault();
            scrollY -= e.deltaY;
            needsRender = true; // Mark that we need to render
            lastInputTime = performance.now();
            startRender();
        };

        if ($scroller) {
            if (scrollerWheelHandler) {
                $scroller.removeEventListener('wheel', scrollerWheelHandler, { passive: false });
            }
            scrollerWheelHandler = handleMouseWheel;
            $scroller.addEventListener('wheel', scrollerWheelHandler, { passive: false });
        }
    };

    // ============================================
    // CATEGORY FILTER
    // ============================================

    const filterItems = Array.from(document.querySelectorAll('.grid-toggle_item[data-filter]'));

    const enableFilteredMode = () => {
        if (!$menu) return;
        isFiltered = true;
        clearTransforms($allItems);
        $menu.classList.add('filtered-layout');
        $menu.classList.remove('simple-layout');
        applyWillChange($allItems, false);
    };

    const disableFilteredMode = () => {
        if (!$menu) return;
        isFiltered = false;
        $menu.classList.remove('filtered-layout');

        // Reinitialize infinite scroll
        if (!isMobile()) {
            updateCachedItems();
            const allItems = cachedItems;
            const $items = cachedItemsCol1;
            const $items2 = cachedItemsCol2;

            if (useInfiniteScroll) {
                dispose(y, $items);
                dispose(y2, $items2);
                applyWillChange(allItems, true);
            }
        }
    };

    const applyFilter = (filter, activeEl) => {
        // Update active state
        filterItems.forEach(f => f.classList.remove('is-active'));
        if (activeEl) activeEl.classList.add('is-active');

        const allItems = Array.from(document.querySelectorAll('.inf-work_item'));

        if (filter === 'all') {
            allItems.forEach(item => {
                item.style.display = '';
                item.classList.remove('is-hidden');
            });
            disableFilteredMode();
        } else {
            enableFilteredMode();
            allItems.forEach(item => {
                if (item.classList.contains('category-' + filter)) {
                    item.style.display = '';
                    item.classList.remove('is-hidden');
                } else {
                    item.style.display = 'none';
                    item.classList.add('is-hidden');
                }
            });
        }

        const counter = document.querySelector('.photos_total');
        if (counter) {
            const visibleItems = document.querySelectorAll('.inf-work_item:not(.is-hidden):not(.is-clone)');
            counter.textContent = visibleItems.length;
        }
    };

    const filterRoot = filterItems.length
        ? filterItems[0].closest('.work-toggle_holder')
        : null;

    if (filterRoot) {
        filterRoot.addEventListener('click', (e) => {
            const target = e.target.closest('.grid-toggle_item[data-filter]');
            if (!target) return;
            const filter = target.getAttribute('data-filter') || 'all';
            e.preventDefault();
            applyFilter(filter, target);
        });
    }

    // ============================================
    // WORK GRID HOVER EFFECTS (desktop only)
    // ============================================

    const setupHoverEffects = () => {
        const infItems = document.querySelectorAll('[data-inf-item]');
        const infoHolder = document.querySelector('.image-grid-info_holder');
        const infoTitle = document.querySelector('[data-image-grid-title]');
        const infoCopy = document.querySelector('[data-image-grid-copy]');

        infItems.forEach(item => {
            // Skip if already has listeners
            if (item.dataset.hoverSetup) return;
            item.dataset.hoverSetup = 'true';

            const projectTitle = item.getAttribute('data-work-title') || '';
            const projectCopy = item.getAttribute('data-work-copy') || '';

            item.addEventListener('mouseenter', function() {
                if (isMobile()) return;

                this.classList.add('highlight');
                if ($menu) {
                    $menu.classList.add('has-hover');
                }

                if (infoHolder) infoHolder.classList.add('show');
                if (infoTitle) infoTitle.textContent = projectTitle;
                if (infoCopy) infoCopy.textContent = projectCopy;
            });

            item.addEventListener('mouseleave', function() {
                if (isMobile()) return;

                this.classList.remove('highlight');
                if ($menu) {
                    $menu.classList.remove('has-hover');
                }

                if (infoHolder) infoHolder.classList.remove('show');
            });

            // Mobile tap shows description but doesn't block navigation
            // (navigation handled separately in page transition setup)
        });
    };

    // ============================================
    // MENU TOGGLE
    // ============================================

    const menuBtn = document.querySelector('.menu-btn');
    const menuClose = document.querySelector('.menu-close');
    const menuOverlay = document.querySelector('.menu-component');

    function openMenu() {
        if (menuOverlay) {
            menuOverlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            if (lenisInstance) lenisInstance.stop();
        }
    }

    function closeMenu() {
        if (menuOverlay) {
            menuOverlay.classList.remove('is-open');
            document.body.style.overflow = '';
            if (lenisInstance) lenisInstance.start();
        }
    }

    if (menuBtn) menuBtn.addEventListener('click', openMenu);
    if (menuClose) menuClose.addEventListener('click', closeMenu);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMenu();
    });

    // Close menu when clicking on current page link (e.g., Home when already on home)
    const currentPageLinks = document.querySelectorAll('.mega-menu_link.is-current');
    currentPageLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            closeMenu();
        });
    });

    // ============================================
    // DYNAMIC MEGA MENU SCROLL
    // ============================================
    
    const megaMenuLinks = document.querySelectorAll('.mega-menu_link');
    
    megaMenuLinks.forEach(link => {
        if (link.querySelector('.mega-menu_scroll-wrapper')) return;
        // Create wrapper for the text
        const text = link.textContent;
        if (!text || !text.trim()) return;
        link.innerHTML = '';
        
        const wrapper = document.createElement('span');
        wrapper.className = 'mega-menu_scroll-wrapper';
        
        // Create two spans for infinite scroll effect
        const span1 = document.createElement('span');
        span1.textContent = text;
        span1.className = 'mega-menu_text';
        
        const span2 = document.createElement('span');
        span2.textContent = text;
        span2.className = 'mega-menu_text';
        span2.setAttribute('aria-hidden', 'true');
        
        wrapper.appendChild(span1);
        wrapper.appendChild(span2);
        link.appendChild(wrapper);
    });

    // ============================================
    // PAGE TRANSITION
    // ============================================

    const pageTransition = document.querySelector('.page-transition');

    const setupPageTransition = () => {
        const projectLinks = document.querySelectorAll('.inf-work_link');

        projectLinks.forEach(link => {
            if (link.dataset.transitionSetup) return;
            link.dataset.transitionSetup = 'true';

            link.addEventListener('click', function(e) {
                e.preventDefault();
                const href = this.getAttribute('href');

                if (pageTransition) {
                    pageTransition.classList.add('is-active');
                    setTimeout(() => {
                        window.location.href = href;
                    }, 600);
                } else {
                    window.location.href = href;
                }
            });
        });
    };

    // Handle browser back/forward navigation (bfcache)
    // Reset page transition overlay when page is shown from cache
    window.addEventListener('pageshow', function(event) {
        if (pageTransition) {
            pageTransition.classList.remove('is-active');
        }
    });

    // ============================================
    // FORCE IMMEDIATE IMAGE DISPLAY
    // ============================================

    const forceImmediateImages = () => {
        const allItems = document.querySelectorAll('.inf-work_item');
        allItems.forEach(item => item.classList.add('is-visible'));
    };

    // ============================================
    // DYNAMIC FETCHPRIORITY (only above-the-fold)
    // ============================================

    const setupFetchPriorityObserver = () => {
        const observer = createFetchPriorityObserver(3);
        if (!observer) return;
        const images = Array.from(document.querySelectorAll('.inf-work_item:not(.is-clone) img'));
        if (!images.length) return;
        images.forEach(img => observer.observe(img));
    };

    // ============================================
    // INITIALIZATION
    // ============================================

    forceImmediateImages();
    setupFetchPriorityObserver();
    initInfiniteScroll();
    setupHoverEffects();
    setupPageTransition();

    // Handle resize - also updates dimensions for render loop
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            if (isMobile()) {
                clearTransforms($allItems);
                $menu?.classList.add('simple-layout');
                applyWillChange($allItems, false);
            } else if (!isFiltered) {
                updateCachedItems();
                updateDimensions();
                applyWillChange(cachedItems, useInfiniteScroll && !isFiltered);
            }
        }, 100);
    });
});
