/**
 * Modern Home Template
 * JavaScript: Infinite scroll grid + hover effects + Lenis smooth scroll
 */

// Import Lenis and its CSS
import Lenis from 'lenis';
import 'lenis/dist/lenis.css';

document.addEventListener('DOMContentLoaded', function() {

    // ============================================
    // MOBILE DETECTION
    // ============================================

    const MOBILE_BREAKPOINT = 768;
    const isMobile = () => window.innerWidth < MOBILE_BREAKPOINT;

    // ============================================
    // LENIS SMOOTH SCROLL (desktop only)
    // ============================================

    let lenis = null;
    let rafId = null;

    // Only initialize Lenis on desktop for smooth scrolling
    if (!isMobile()) {
        lenis = new Lenis({
            duration: 2.0,
            easing: (t) => 1 - Math.pow(1 - t, 4),
            direction: 'vertical',
            gestureDirection: 'vertical',
            smooth: true,
            smoothTouch: false,
            touchMultiplier: 1.5,
            wheelMultiplier: 0.8
        });

        function raf(time) {
            lenis.raf(time);
            rafId = requestAnimationFrame(raf);
        }

        rafId = requestAnimationFrame(raf);
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

    // Minimum items for good infinite scroll effect
    const MIN_ITEMS_FOR_INFINITE = 8;

    const updateCachedItems = () => {
        cachedItems = Array.from(document.querySelectorAll('.inf-work_item'));
        $allItems = cachedItems;
        cachedItemsCol1 = cachedItems.filter((_, i) => i % 2 === 0);
        cachedItemsCol2 = cachedItems.filter((_, i) => i % 2 === 1);
    };

    updateCachedItems();

    // Clear transforms
    const clearTransforms = (items) => {
        items.forEach(item => {
            item.style.transform = '';
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
            return;
        }

        useInfiniteScroll = true;
        $menu.classList.remove('simple-layout');
        $menu.classList.remove('filtered-layout');

        // Calculate dimensions
        itemHeight = allItems[0].clientHeight || 400;
        wrapHeight = (allItems.length / 2) * itemHeight;

        // Initial positioning
        dispose(0, $items);
        dispose(0, $items2);

        // Make all visible
        allItems.forEach(item => item.classList.add('is-visible'));

        // Handle mouse wheel
        const handleMouseWheel = (e) => {
            if (isMobile() || !useInfiniteScroll || isFiltered) return;
            scrollY -= e.deltaY;
        };

        if ($scroller) {
            $scroller.removeEventListener('wheel', handleMouseWheel);
            $scroller.addEventListener('wheel', handleMouseWheel, { passive: true });
        }

        // Animation loop
        const render = () => {
            if (isMobile() || !useInfiniteScroll || isFiltered) {
                setTimeout(() => requestAnimationFrame(render), 500);
                return;
            }
            requestAnimationFrame(render);

            const allItems = cachedItems;
            const $items = cachedItemsCol1;
            const $items2 = cachedItemsCol2;

            // Update dimensions if changed
            if (cachedItems[0]) {
                const newHeight = cachedItems[0].clientHeight;
                if (newHeight !== itemHeight && newHeight > 0) {
                    itemHeight = newHeight;
                    wrapHeight = (cachedItems.length / 2) * itemHeight;
                }
            }

            // Lerp for smooth animation
            y = y + (scrollY - y) * 0.09;
            y2 = y2 + (scrollY - y2) * 0.08;

            dispose(y, $items);
            dispose(y2, $items2);
        };
        render();
    };

    // ============================================
    // CATEGORY FILTER
    // ============================================

    const filterItems = document.querySelectorAll('.grid-toggle_item[data-filter]');

    const enableFilteredMode = () => {
        if (!$menu) return;
        isFiltered = true;
        clearTransforms($allItems);
        $menu.classList.add('filtered-layout');
        $menu.classList.remove('simple-layout');
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
            }
        }
    };

    filterItems.forEach(filterItem => {
        filterItem.addEventListener('click', function(e) {
            const filter = this.getAttribute('data-filter');

            if (filter !== 'all' && this.tagName.toLowerCase() === 'a') {
                return;
            }

            e.preventDefault();

            // Update active state
            filterItems.forEach(f => f.classList.remove('is-active'));
            this.classList.add('is-active');

            const allItems = Array.from(document.querySelectorAll('.inf-work_item'));

            if (filter === 'all') {
                // Show all items
                allItems.forEach(item => {
                    item.style.display = '';
                });
                disableFilteredMode();
            } else {
                // Enable filtered mode (CSS grid)
                enableFilteredMode();

                // Hide/show items based on category
                allItems.forEach(item => {
                    if (item.classList.contains('category-' + filter)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            // Update counter
            const counter = document.querySelector('.photos_total');
            if (counter) {
                const visibleItems = document.querySelectorAll('.inf-work_item:not([style*="display: none"])');
                counter.textContent = visibleItems.length;
            }
        });
    });

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

            // Mobile click to toggle description
            item.addEventListener('click', function(e) {
                if (!isMobile()) return;

                const isShowingDescription = this.classList.contains('show-description');
                if (!isShowingDescription) {
                    e.preventDefault();
                    infItems.forEach(otherItem => {
                        otherItem.classList.remove('show-description');
                    });
                    this.classList.add('show-description');
                }
            });
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
            if (lenis) lenis.stop();
        }
    }

    function closeMenu() {
        if (menuOverlay) {
            menuOverlay.classList.remove('is-open');
            document.body.style.overflow = '';
            if (lenis) lenis.start();
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
    // PAGE TRANSITION
    // ============================================

    const pageTransition = document.querySelector('.page-transition');

    const setupPageTransition = () => {
        const projectLinks = document.querySelectorAll('.inf-work_link');

        projectLinks.forEach(link => {
            if (link.dataset.transitionSetup) return;
            link.dataset.transitionSetup = 'true';

            link.addEventListener('click', function(e) {
                if (isMobile()) {
                    const parentItem = this.closest('.inf-work_item');
                    if (!parentItem || !parentItem.classList.contains('show-description')) {
                        return;
                    }
                }

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

    // ============================================
    // DYNAMIC IMAGE LOADING OPTIMIZATION
    // ============================================

    const optimizeImages = () => {
        const allImages = document.querySelectorAll('.inf-work_item img');
        const viewportHeight = window.innerHeight;

        allImages.forEach(img => {
            const rect = img.getBoundingClientRect();
            if (rect.top < viewportHeight && rect.bottom > 0) {
                img.setAttribute('fetchpriority', 'high');
                img.removeAttribute('loading');
            }
        });
    };

    // ============================================
    // FADE-IN IMAGES (Intersection Observer for mobile)
    // ============================================

    const setupFadeInObserver = () => {
        const fadeInObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    fadeInObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.15,
            rootMargin: '0px 0px -50px 0px'
        });

        // Observe all work items (for mobile fade-in effect)
        const allItems = document.querySelectorAll('.inf-work_item');
        allItems.forEach(item => {
            // Only observe if not already visible (desktop infinite scroll makes them visible)
            if (!item.classList.contains('is-visible')) {
                fadeInObserver.observe(item);
            }
        });
    };

    // ============================================
    // INITIALIZATION
    // ============================================

    optimizeImages();
    initInfiniteScroll();
    setupHoverEffects();
    setupPageTransition();
    setupFadeInObserver();

    // Handle resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            if (isMobile()) {
                clearTransforms($allItems);
                $menu?.classList.add('simple-layout');
            } else if (!isFiltered) {
                updateCachedItems();
                if (cachedItems[0]) {
                    itemHeight = cachedItems[0].clientHeight;
                    wrapHeight = (cachedItems.length / 2) * itemHeight;
                }
            }
        }, 100);
    });

});
