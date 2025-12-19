/**
 * Modern Home Template
 * JavaScript: Infinite scroll grid + hover effects + Lenis
 */

document.addEventListener('DOMContentLoaded', function() {

    // ============================================
    // DYNAMIC IMAGE LOADING OPTIMIZATION
    // ============================================

    const allImages = document.querySelectorAll('.inf-work_item img');
    const viewportHeight = window.innerHeight;

    allImages.forEach(img => {
        const rect = img.getBoundingClientRect();
        // If image is in initial viewport, prioritize it
        if (rect.top < viewportHeight && rect.bottom > 0) {
            img.setAttribute('fetchpriority', 'high');
            img.removeAttribute('loading');
        }
    });

    // ============================================
    // LENIS SMOOTH SCROLL
    // ============================================

    let lenis;

    if (typeof Lenis !== 'undefined') {
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
            requestAnimationFrame(raf);
        }

        requestAnimationFrame(raf);
    }

    // ============================================
    // MOBILE DETECTION
    // ============================================

    const MOBILE_BREAKPOINT = 768;
    const isMobile = () => window.innerWidth < MOBILE_BREAKPOINT;

    // ============================================
    // INFINITE SCROLL GRID (like original)
    // Disabled on mobile (< 768px)
    // ============================================

    const $menu = document.querySelector('.inf-work_list');
    const $scroller = document.querySelector('.work-layout');
    const $allItems = document.querySelectorAll('.inf-work_item');
    const $items = document.querySelectorAll('.inf-work_item:nth-child(2n + 1)'); // odd items (left col)
    const $items2 = document.querySelectorAll('.inf-work_item:nth-child(2n + 2)'); // even items (right col)

    // Clear transforms for mobile
    const clearTransforms = () => {
        $allItems.forEach(item => {
            item.style.transform = '';
        });
    };

    if ($menu && $allItems.length > 0) {
        let menuHeight = $menu.clientHeight;
        let itemHeight = $allItems[0].clientHeight;
        let wrapHeight = ($allItems.length / 2) * itemHeight;

        let scrollSpeed = 0;
        let oldScrollY = 0;
        let scrollY = 0;
        let y = 0;
        let y2 = 0;

        // Lerp function for smooth animation
        const lerp = (v0, v1, t) => {
            return v0 * (1 - t) + v1 * t;
        };

        // Dispose items with wrapping
        const dispose = (scroll, items) => {
            if (isMobile()) return; // Skip on mobile
            items.forEach((item, i) => {
                let newY = i * itemHeight + scroll;
                // Wrap around
                const s = ((newY % wrapHeight) + wrapHeight) % wrapHeight;
                const finalY = s - itemHeight;
                item.style.transform = `translate(0px, ${finalY}px)`;
            });
        };

        // Initial positioning (only on desktop)
        if (!isMobile()) {
            dispose(0, $items);
            dispose(0, $items2);
        } else {
            clearTransforms();
        }

        // Handle mouse wheel
        const handleMouseWheel = (e) => {
            if (isMobile()) return; // Skip on mobile
            scrollY -= e.deltaY;
        };

        if ($scroller) {
            $scroller.addEventListener('wheel', handleMouseWheel, { passive: true });
        }

        // Update heights on resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (isMobile()) {
                    clearTransforms();
                } else {
                    menuHeight = $menu.clientHeight;
                    itemHeight = $allItems[0].clientHeight;
                    wrapHeight = ($allItems.length / 2) * itemHeight;
                }
            }, 100);
        });

        // Animation loop
        const render = () => {
            requestAnimationFrame(render);
            if (isMobile()) return; // Skip on mobile
            y = lerp(y, scrollY, 0.09);
            y2 = lerp(y2, scrollY, 0.08); // Slightly different speed for stagger effect
            dispose(y, $items);
            dispose(y2, $items2);
            scrollSpeed = y - oldScrollY;
            oldScrollY = y;
        };
        render();
    }

    // ============================================
    // WORK GRID HOVER EFFECTS (desktop only)
    // ============================================

    const infItems = document.querySelectorAll('[inf-item]');
    const infoHolder = document.querySelector('.image-grid-info_holder');
    const infoTitle = document.querySelector('[image-grid_title]');
    const infoCopy = document.querySelector('[image-grid_copy]');

    infItems.forEach(item => {
        const projectTitle = item.getAttribute('work-title') || '';
        const projectCopy = item.getAttribute('work-copy') || '';

        // Desktop hover effects
        item.addEventListener('mouseenter', function() {
            if (isMobile()) return;

            // Add highlight to this item
            this.classList.add('highlight');

            // Add fadeout to all other items
            infItems.forEach(otherItem => {
                if (otherItem !== this) {
                    otherItem.classList.add('fadeout');
                }
            });

            // Show info holder
            if (infoHolder) {
                infoHolder.classList.add('show');
            }
            if (infoTitle) {
                infoTitle.textContent = projectTitle;
            }
            if (infoCopy) {
                infoCopy.textContent = projectCopy;
            }
        });

        item.addEventListener('mouseleave', function() {
            if (isMobile()) return;

            // Remove highlight
            this.classList.remove('highlight');

            // Remove fadeout from all items
            infItems.forEach(otherItem => {
                otherItem.classList.remove('fadeout');
            });

            // Hide info holder
            if (infoHolder) {
                infoHolder.classList.remove('show');
            }
        });

        // Mobile click to toggle description
        item.addEventListener('click', function(e) {
            if (!isMobile()) return;

            // Prevent navigation if just toggling description
            const isShowingDescription = this.classList.contains('show-description');

            if (!isShowingDescription) {
                e.preventDefault();
                // Close other descriptions
                infItems.forEach(otherItem => {
                    otherItem.classList.remove('show-description');
                });
                // Open this one
                this.classList.add('show-description');
            }
            // If already showing, allow navigation
        });
    });

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

    if (menuBtn) {
        menuBtn.addEventListener('click', openMenu);
    }

    if (menuClose) {
        menuClose.addEventListener('click', closeMenu);
    }

    // Close menu on ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMenu();
        }
    });

    // ============================================
    // FADE-IN IMAGES (Intersection Observer)
    // ============================================

    if ('IntersectionObserver' in window) {
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

        // Observe all work items
        $allItems.forEach(item => {
            fadeInObserver.observe(item);
        });
    } else {
        // Fallback: make all items visible immediately
        $allItems.forEach(item => item.classList.add('is-visible'));
    }

    // ============================================
    // PAGE TRANSITION
    // ============================================

    const pageTransition = document.querySelector('.page-transition');
    const projectLinks = document.querySelectorAll('.inf-work_link');

    projectLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // On mobile, only trigger if description is already showing
            if (isMobile()) {
                const parentItem = this.closest('.inf-work_item');
                if (!parentItem.classList.contains('show-description')) {
                    return; // Let the mobile click handler do its thing
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

    // ============================================
    // CATEGORY FILTER (if clicking filters)
    // ============================================

    const filterItems = document.querySelectorAll('.grid-toggle_item[data-filter]');
    const allFilterItem = document.querySelector('.grid-toggle_item[data-filter="all"]');

    filterItems.forEach(filterItem => {
        filterItem.addEventListener('click', function(e) {
            const filter = this.getAttribute('data-filter');

            // If it's not "all" and it's a link, let it navigate
            if (filter !== 'all' && this.tagName.toLowerCase() === 'a') {
                return; // Allow normal navigation
            }

            e.preventDefault();

            // Update active state
            filterItems.forEach(f => f.classList.remove('is-active'));
            this.classList.add('is-active');

            // Filter items
            if (filter === 'all') {
                $allItems.forEach(item => {
                    item.style.display = '';
                });
            } else {
                $allItems.forEach(item => {
                    if (item.classList.contains('category-' + filter)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            // Update counter
            const counter = document.querySelector('.case-studies_total');
            if (counter) {
                const visibleItems = document.querySelectorAll('.inf-work_item:not([style*="display: none"])');
                counter.textContent = visibleItems.length;
            }
        });
    });

});
