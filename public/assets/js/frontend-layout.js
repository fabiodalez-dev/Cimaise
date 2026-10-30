/*
 * Frontend layout runtime (menus, header, GSAP entrance animations, PhotoSwipe
 * lightbox + EXIF panel, simple lightbox fallback).
 *
 * Extracted from app/Views/frontend/_layout.twig so browsers cache it instead
 * of downloading and re-parsing ~110 KB of inline JS on every page view.
 * Per-page values come from window.CimaiseLayout, printed inline by the layout.
 * Classic (non-module) script on purpose: other inline scripts rely on the
 * globals it declares (loadPhotoSwipe, fallbackClose, ...).
 */
const __cimaiseLayout = Object.assign({ basePath: '', allowDownloads: false, i18n: {} }, window.CimaiseLayout || {});
function __cimaiseHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
// Enhanced Mobile menu with animations
document.addEventListener('DOMContentLoaded', function() {
    // 1. Menu Toggle
    const menuToggle = document.getElementById('mobile-menu-toggle');
    const menu = document.getElementById('mobile-menu');
    const headerSearchPanel = document.getElementById('header-search-panel');
    const headerSearchToggle = document.getElementById('header-search-toggle');
    const mobileSearchToggle = document.getElementById('mobile-search-toggle');
    if (menuToggle && menu) {
        // Pending close-animation timeout; cleared on every toggle so a
        // rapid re-open can't let a stale timer re-hide the open menu
        // (which also left the scroll-lock class inconsistent).
        let closeMenuTimer = null;
        menuToggle.addEventListener('click', function() {
            if (closeMenuTimer) { clearTimeout(closeMenuTimer); closeMenuTimer = null; }
            const isOpening = menu.classList.contains('hidden');
            if (isOpening) {
                if (headerSearchPanel) {
                    headerSearchPanel.classList.add('hidden');
                }
                if (headerSearchToggle) {
                    headerSearchToggle.setAttribute('aria-expanded', 'false');
                }
                if (mobileSearchToggle) {
                    mobileSearchToggle.setAttribute('aria-expanded', 'false');
                }
                menu.classList.remove('hidden');
                menuToggle.setAttribute('aria-expanded', 'true');
                document.documentElement.classList.add('mobile-menu-open');
                requestAnimationFrame(() => {
                    // Cap the open height to the viewport so the menu scrolls
                    // INTERNALLY when its content is taller than the screen.
                    // Anchor to the menu's OWN top edge, NOT #main-header.offsetHeight:
                    // #mobile-menu is nested INSIDE the header here, so the header's
                    // offsetHeight already includes the expanding menu (circular →
                    // cap collapsed to a ~23px sliver, a thin bar needing extra taps).
                    // Never use scrollHeight either (stale in this first rAF).
                    // max-height only caps: shorter content sizes to itself, taller
                    // content scrolls (#mobile-menu is overflow-y-auto).
                    const top = menu.getBoundingClientRect().top;
                    menu.style.maxHeight = Math.max(0, window.innerHeight - top) + 'px';
                    menu.style.opacity = '1';
                });
            } else {
                menu.style.maxHeight = '0px';
                menu.style.opacity = '0';
                menuToggle.setAttribute('aria-expanded', 'false');
                document.documentElement.classList.remove('mobile-menu-open');
                closeMenuTimer = setTimeout(() => {
                    menu.classList.add('hidden');
                    closeMenuTimer = null;
                }, 300);
            }
        });
        // Safety: if the viewport grows to the lg breakpoint (the menu
        // is lg:hidden) while it's open, release the scroll lock so the
        // desktop page isn't left frozen.
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024 && document.documentElement.classList.contains('mobile-menu-open')) {
                if (closeMenuTimer) { clearTimeout(closeMenuTimer); closeMenuTimer = null; }
                document.documentElement.classList.remove('mobile-menu-open');
                menu.style.maxHeight = '0px';
                menu.style.opacity = '0';
                menuToggle.setAttribute('aria-expanded', 'false');
                menu.classList.add('hidden');
            }
        });
    }

    // 2. Sub-category Toggles
    document.querySelectorAll('.mobile-category-toggle').forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const group = button.closest('.mobile-category-group');
            if (!group) return;

            const subcategories = group.querySelector('.mobile-subcategories');
            const icon = button.querySelector('i');
            
            if (subcategories) {
                if (subcategories.classList.contains('hidden')) {
                    subcategories.classList.remove('hidden');
                    requestAnimationFrame(() => {
                        subcategories.style.maxHeight = subcategories.scrollHeight + 'px';
                    });
                    if (icon) icon.style.transform = 'rotate(180deg)';
                } else {
                    subcategories.style.maxHeight = '0px';
                    if (icon) icon.style.transform = 'rotate(0deg)';
                    setTimeout(() => subcategories.classList.add('hidden'), 300);
                }
            }
        });
    });

    // 3. Active Page Indicator
    const currentUrl = window.location.href.split('?')[0].split('#')[0];
    const mobileMenuLinks = menu ? menu.querySelectorAll('a') : [];
    mobileMenuLinks.forEach(link => {
        const linkUrl = link.href.split('?')[0].split('#')[0];
        if (link.href && linkUrl === currentUrl) {
            link.classList.add('bg-white', 'text-black');
            link.setAttribute('aria-current', 'page');
        }
    });
});




// Enhanced Desktop categories mega menu with animations
(function(){
    const toggle = document.getElementById('categories-toggle');
    const menu = document.getElementById('categories-mega-menu');
    const icon = document.getElementById('categories-icon');
    let isOpen = false;
    let hoverTimeout;
    let hideTimeout;
    
    if (!toggle || !menu) return;
    
    function closeMenu() {
        clearTimeout(hoverTimeout);
        if (!isOpen) return;

        menu.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        menu.style.opacity = '0';
        menu.style.transform = 'translateX(-50%) translateY(-10px)';

        hideTimeout = setTimeout(() => {
            if (isOpen) return;
            menu.classList.add('opacity-0', 'invisible');
            menu.classList.remove('opacity-100', 'visible');
        }, 200);

        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }

        toggle.setAttribute('aria-expanded', 'false');
        isOpen = false;
    }

    function openMenu() {
        clearTimeout(hoverTimeout);
        clearTimeout(hideTimeout);
        if (isOpen) return;
        isOpen = true;

        menu.classList.remove('opacity-0', 'invisible');
        menu.classList.add('opacity-100', 'visible');

        requestAnimationFrame(() => {
            if (!isOpen) return;
            menu.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            menu.style.opacity = '1';
            menu.style.transform = 'translateX(-50%) translateY(0)';
        });

        if (icon) {
            icon.style.transform = 'rotate(180deg)';
        }

        toggle.setAttribute('aria-expanded', 'true');
    }
    
    // Mouse enter/leave for smooth mega menu (keep open across whitespace)
    const dropdown = document.getElementById('categories-dropdown');
    const scheduleClose = () => {
        clearTimeout(hoverTimeout);
        hoverTimeout = setTimeout(closeMenu, 280);
    };
    const clearClose = () => clearTimeout(hoverTimeout);
    const isHoveringTarget = (target) => {
        if (!target) return false;
        return (menu.contains(target) || toggle.contains(target));
    };

    [toggle, menu].forEach((el) => {
        if (!el) return;
        el.addEventListener('mouseenter', () => {
            clearClose();
            openMenu();
        });
        el.addEventListener('mouseleave', (e) => {
            if (isHoveringTarget(e.relatedTarget)) return;
            scheduleClose();
        });
    });
    if (dropdown) {
        dropdown.addEventListener('focusin', () => {
            clearClose();
            openMenu();
        });
        dropdown.addEventListener('focusout', (e) => {
            if (isHoveringTarget(e.relatedTarget)) return;
            scheduleClose();
        });
    }
    
    // Click toggle for mobile/accessibility
    toggle.addEventListener('click', (e) => {
        e.preventDefault();
        isOpen ? closeMenu() : openMenu();
    });
    
    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!isOpen) return;
        if (!dropdown?.contains(e.target)) {
            closeMenu();
        }
    });
})();

// Tags dropdown menu
(function(){
    const toggle = document.getElementById('tags-toggle');
    const menu = document.getElementById('tags-menu');
    const icon = document.getElementById('tags-icon');
    let isOpen = false;
    let hoverTimeout;

    if (!toggle || !menu) return;

    function closeMenu() {
        if (!isOpen) return;

        menu.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        menu.style.opacity = '0';
        menu.style.transform = 'translateY(-10px)';

        setTimeout(() => {
            menu.classList.add('opacity-0', 'invisible');
            menu.classList.remove('opacity-100', 'visible');
        }, 200);

        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }

        toggle.setAttribute('aria-expanded', 'false');
        isOpen = false;
    }

    function openMenu() {
        if (isOpen) return;

        menu.classList.remove('opacity-0', 'invisible');
        menu.classList.add('opacity-100', 'visible');

        requestAnimationFrame(() => {
            menu.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            menu.style.opacity = '1';
            menu.style.transform = 'translateY(0)';
        });

        if (icon) {
            icon.style.transform = 'rotate(180deg)';
        }

        toggle.setAttribute('aria-expanded', 'true');
        isOpen = true;
    }

    // Mouse enter/leave for smooth dropdown
    const dropdown = document.getElementById('tags-dropdown');
    if (dropdown) {
        dropdown.addEventListener('mouseenter', () => {
            clearTimeout(hoverTimeout);
            openMenu();
        });

        dropdown.addEventListener('mouseleave', () => {
            hoverTimeout = setTimeout(closeMenu, 150);
        });
    }

    // Click toggle for mobile/accessibility
    toggle.addEventListener('click', (e) => {
        e.preventDefault();
        isOpen ? closeMenu() : openMenu();
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!isOpen) return;
        if (!dropdown?.contains(e.target)) {
            closeMenu();
        }
    });
})();

// Header search -> galleries filters (AJAX) or redirect
(function() {
    const basePath = __cimaiseLayout.basePath;

    const debounce = (fn, delay) => {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(null, args), delay);
        };
    };

    const buildCoverUrl = (album) => {
        const cover = album && album.cover_image ? album.cover_image : null;
        const path = (cover && (cover.preview_path || cover.blur_path)) ? (cover.preview_path || cover.blur_path) : '';
        if (!path) return '';
        return path.startsWith('/') ? `${basePath}${path}` : path;
    };

    const appendPlaceholderIcon = (container) => {
        const icon = document.createElement('i');
        icon.className = 'fas fa-image text-gray-400 text-lg';
        container.appendChild(icon);
    };

    const setupSearch = (opts) => {
        const form = document.getElementById(opts.formId);
        const input = document.getElementById(opts.inputId);
        const clearBtn = document.getElementById(opts.clearId);
        const suggestionBox = document.getElementById(opts.suggestionId);
        if (!form || !input) return;

        const clearSuggestions = () => {
            if (!suggestionBox) return;
            suggestionBox.innerHTML = '';
            suggestionBox.classList.add('hidden');
        };

        const updateClearButton = () => {
            if (!clearBtn) return;
            clearBtn.classList.toggle('hidden', input.value.trim().length === 0);
        };

        const syncToFilters = () => {
            const filterInput = document.getElementById('layout-filter-search')
                || document.getElementById('filter-search');
            if (!filterInput) return false;
            filterInput.value = input.value;
            if (typeof window.applyFiltersDebounced === 'function') {
                window.applyFiltersDebounced();
            } else if (typeof window.applyFilters === 'function') {
                window.applyFilters();
            } else {
                setTimeout(() => {
                    if (typeof window.applyFiltersDebounced === 'function') {
                        window.applyFiltersDebounced();
                        return;
                    }
                    if (typeof window.applyFilters === 'function') {
                        window.applyFilters();
                        return;
                    }
                    filterInput.dispatchEvent(new Event('change', { bubbles: true }));
                    filterInput.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                }, 80);
            }
            return true;
        };

        const renderSuggestions = (albums) => {
            if (!suggestionBox) return;
            suggestionBox.innerHTML = '';
            if (!albums.length) {
                suggestionBox.classList.add('hidden');
                return;
            }
            albums.slice(0, 6).forEach((album) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'group w-full text-left px-4 py-4 text-sm hover:bg-gray-50 transition-colors duration-150 flex items-center gap-4';
                btn.setAttribute('data-album-slug', album.slug || '');
                const imgWrap = document.createElement('div');
                imgWrap.className = 'w-20 h-16 rounded-lg bg-gray-100 ring-1 ring-gray-200/60 flex items-center justify-center overflow-hidden flex-shrink-0';
                const coverUrl = buildCoverUrl(album);
                if (coverUrl) {
                    const img = document.createElement('img');
                    img.src = coverUrl;
                    img.alt = album.title || '';
                    img.loading = 'lazy';
                    img.className = 'w-full h-full object-cover';
                    img.addEventListener('error', () => {
                        img.remove();
                        appendPlaceholderIcon(imgWrap);
                    });
                    imgWrap.appendChild(img);
                } else {
                    appendPlaceholderIcon(imgWrap);
                }

                const details = document.createElement('div');
                details.className = 'min-w-0 flex-1';
                const title = document.createElement('div');
                title.className = 'font-semibold text-gray-900 text-sm sm:text-base truncate';
                title.textContent = album.title || '';
                const meta = document.createElement('div');
                meta.className = 'text-xs text-gray-500 mt-1 truncate';
                meta.textContent = album.category_name || '';

                details.appendChild(title);
                if (meta.textContent) {
                    details.appendChild(meta);
                }

                btn.appendChild(imgWrap);
                btn.appendChild(details);
                btn.addEventListener('click', () => {
                    const slug = btn.getAttribute('data-album-slug');
                    if (slug) {
                        window.location.href = `${basePath}/album/${encodeURIComponent(slug)}`;
                        return;
                    }
                    input.value = title.textContent || '';
                    syncToFilters();
                    clearSuggestions();
                });
                suggestionBox.appendChild(btn);
            });
            suggestionBox.classList.remove('hidden');
        };

        let suggestController = null;
        const fetchSuggestions = debounce(() => {
            const query = input.value.trim();
            if (query.length < 1) {
                clearSuggestions();
                return;
            }
            if (suggestController) {
                suggestController.abort();
            }
            suggestController = new AbortController();
            fetch(`${basePath}/galleries/filter?search=${encodeURIComponent(query)}&limit=6`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: suggestController.signal
            }).then((resp) => {
                if (!resp.ok) {
                    throw new Error('Failed to fetch suggestions');
                }
                return resp.json();
            }).then((data) => {
                renderSuggestions(Array.isArray(data?.albums) ? data.albums : []);
            }).catch((err) => {
                if (err.name !== 'AbortError') {
                    clearSuggestions();
                }
            });
        }, 140);

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const query = input.value.trim();
            if (syncToFilters()) return;
            if (!query) return;
            window.location.href = `${basePath}/search?q=${encodeURIComponent(query)}`;
        });

        input.addEventListener('input', debounce(() => {
            updateClearButton();
            if (input.value.trim().length === 0) {
                if (syncToFilters()) return;
            } else if (syncToFilters()) {
                return;
            }
        }, 80));
        input.addEventListener('input', fetchSuggestions);
        input.addEventListener('focus', fetchSuggestions);
        input.addEventListener('blur', () => {
            setTimeout(() => {
                clearSuggestions();
            }, 150);
        });
        document.addEventListener('click', (ev) => {
            if (!form.contains(ev.target)) {
                clearSuggestions();
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                input.value = '';
                updateClearButton();
                clearSuggestions();
                if (syncToFilters()) return;
            });
        }

        const filterInput = document.getElementById('layout-filter-search')
            || document.getElementById('filter-search');
        if (filterInput && filterInput.value) {
            input.value = filterInput.value;
        }
        updateClearButton();
    };

    setupSearch({
        formId: 'header-search-form',
        inputId: 'header-search',
        clearId: 'header-search-clear',
        suggestionId: 'header-search-suggestions'
    });

    const headerToggle = document.getElementById('header-search-toggle');
    const headerPanel = document.getElementById('header-search-panel');
    const headerInput = document.getElementById('header-search');
    const mobileToggle = document.getElementById('mobile-search-toggle');
    const searchToggles = [headerToggle, mobileToggle].filter(Boolean);
    if (headerPanel && searchToggles.length) {
        const closeHeaderPanel = () => {
            headerPanel.classList.add('hidden');
            searchToggles.forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
        };
        searchToggles.forEach((toggle) => {
            toggle.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const isHidden = headerPanel.classList.contains('hidden');
                if (isHidden) {
                    headerPanel.classList.remove('hidden');
                    searchToggles.forEach((t) => t.setAttribute('aria-expanded', 'true'));
                    setTimeout(() => {
                        headerInput?.focus();
                    }, 50);
                } else {
                    closeHeaderPanel();
                }
            });
        });
        document.addEventListener('click', (ev) => {
            if (!headerPanel.contains(ev.target) && !searchToggles.some((toggle) => toggle.contains(ev.target))) {
                closeHeaderPanel();
            }
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                closeHeaderPanel();
            }
        });
    }
})();

// Enhanced smooth animations with GSAP
if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
    gsap.registerPlugin(ScrollTrigger);
}

// Handle bfcache (back-forward cache) restoration for album cards
// When user navigates with back/forward, page is restored from cache
// but JavaScript re-runs which can cause flickering
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Page was restored from bfcache - immediately show all album cards
        const albumCards = document.querySelectorAll('main .album-card');
        albumCards.forEach(function(card) {
            card.style.opacity = '1';
            card.style.transform = 'none';
            card.setAttribute('data-animated', '1');
        });

        // Also handle gallery items
        const galleryItems = document.querySelectorAll('#images-gallery > .masonry-item, #images-gallery > .image-item, #images-gallery > .grid-minimal-item, #images-gallery > .polaroid-item, #images-gallery > .mosaic-item');
        galleryItems.forEach(function(item) {
            item.style.opacity = '1';
            item.style.transform = 'none';
            item.setAttribute('data-animated', '1');
        });

        // Clear any GSAP inline styles
        if (typeof gsap !== 'undefined') {
            if (albumCards.length > 0) {
                gsap.set(albumCards, { clearProps: 'all', opacity: 1 });
            }
            if (galleryItems.length > 0) {
                gsap.set(galleryItems, { clearProps: 'all', opacity: 1 });
            }
        }
    }
});

// Animate album cards with explicit start/end values
// CSS hides cards initially (opacity:0, translateY:20px)
if (typeof gsap !== 'undefined') {
    const albumCards = gsap.utils.toArray('main .album-card:not([data-animated])');
    if (albumCards.length > 0) {
        // Mark as animated IMMEDIATELY to prevent CSS conflict during animation
        albumCards.forEach(el => el.setAttribute('data-animated', '1'));
        // Use fromTo with explicit values for smooth animation
        gsap.fromTo(albumCards,
            { opacity: 0, y: 20 },
            {
                opacity: 1,
                y: 0,
                duration: 0.5,
                stagger: {
                    amount: Math.min(0.4, albumCards.length * 0.05),
                    from: 'start'
                },
                ease: "power2.out"
            }
        );
    }
} else {
    // GSAP not available - show cards immediately via CSS
    document.querySelectorAll('main .album-card').forEach(card => {
        card.setAttribute('data-animated', '1');
    });
}

// One-time entrance animation for gallery items
// CSS hides specific item types initially (opacity:0, scale:0.9)
// This function animates them with explicit start/end values
window.animateGalleryOnce = function() {
    if (typeof gsap === 'undefined') return;

    const container = document.getElementById('images-gallery');
    if (!container) return;

    // Select only animatable item types (exclude Magazine template wrappers)
    const selector = '.masonry-item, .image-item, .grid-minimal-item, .polaroid-item, .mosaic-item';
    const nodes = Array.from(container.querySelectorAll(':scope > ' + selector.split(', ').join(', :scope > ')))
        .filter(n => !n.hasAttribute('data-animated'));
    if (!nodes.length) return;

    // Mark as animated IMMEDIATELY to prevent CSS conflict during animation
    nodes.forEach(n => n.setAttribute('data-animated', '1'));
    // Use fromTo with explicit values for smooth animation
    gsap.fromTo(nodes,
        { opacity: 0, scale: 0.9 },
        {
            opacity: 1,
            scale: 1,
            duration: 0.35,
            stagger: {
                amount: Math.min(0.25, nodes.length * 0.02),
                from: 'start'
            },
            ease: 'power3.out'
        }
    );
};

// Run gallery animation on load
window.animateGalleryOnce();

// Fallback: if GSAP fails, show elements after 2 seconds
setTimeout(() => {
    // Gallery items fallback
    const gallery = document.getElementById('images-gallery');
    if (gallery) {
        const hidden = Array.from(gallery.children).filter(n => !n.hasAttribute('data-animated'));
        if (hidden.length > 0) {
            gallery.classList.add('gsap-failed');
        }
    }
    // Album cards fallback
    document.querySelectorAll('main .album-card:not([data-animated])').forEach(card => {
        card.setAttribute('data-animated', '1');
    });
}, 2000);

// Enhanced header scroll behavior with smooth transitions
let lastScroll = 0;
const header = document.querySelector('#main-header');
// Promote the header to its own compositor layer so sliding it on scroll
// doesn't repaint (and flicker) the photos underneath. Set once, not per frame.
if (header) {
    header.style.willChange = 'transform';
}
let ticking = false;

function updateHeader() {
    if (!header) {
        return;
    }
    // Skip header hide for gallery wall template
    if (document.body.classList.contains('gallery-wall-page')) return;

    const currentScroll = window.pageYOffset;
    const hasGsap = typeof gsap !== 'undefined';

    // Hysteresis: ignore tiny scroll jitter (common with trackpads / momentum)
    // so the header doesn't rapidly toggle hide/show — that toggling was the
    // white "flicker bar" over the photos. Only react to deltas > 8px.
    const delta = currentScroll - lastScroll;
    if (Math.abs(delta) > 8) {
        if (delta > 0 && currentScroll > 100) {
            // Scrolling down — hide
            if (hasGsap) {
                gsap.to(header, { y: -100, duration: 0.3, ease: "power2.out" });
            } else {
                header.style.transition = 'transform 0.3s ease';
                header.style.transform = 'translateY(-100px)';
            }
        } else if (delta < 0) {
            // Scrolling up — show
            if (hasGsap) {
                gsap.to(header, { y: 0, duration: 0.3, ease: "power2.out" });
            } else {
                header.style.transition = 'transform 0.3s ease';
                header.style.transform = 'translateY(0)';
            }
        }
        lastScroll = currentScroll;
    }

    // NOTE: backdrop blur / background are set once via the static Tailwind
    // classes (bg-white/95 backdrop-blur-md). They are deliberately NOT
    // re-assigned on every scroll frame: animating backdrop-filter forced a
    // full re-rasterization of the blurred region each frame, which is what
    // produced the white flicker bar across the photos while scrolling.

    ticking = false;
}

window.addEventListener('scroll', () => {
    if (!ticking) {
        requestAnimationFrame(updateHeader);
        ticking = true;
    }
});

// Enhanced image loading animations
document.addEventListener('DOMContentLoaded', () => {
    const skipImageAnimations = document.querySelector(
        '.home-infinite-section, .gallery-wall, .masonry-grid, .snap-gallery, [data-parallax-gallery]'
    );
    if (skipImageAnimations) {
        skipImageAnimations.querySelectorAll('img[data-src]').forEach(img => {
            const dataSrc = img.getAttribute('data-src');
            if (dataSrc) {
                img.setAttribute('src', dataSrc);
                img.removeAttribute('data-src');
                img.style.opacity = '1';
            }
        });
        return;
    }

    const images = document.querySelectorAll('img[data-src], img:not([data-src])');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                
                // Skip mega menu and header images to avoid conflicts
                if (img.closest('#categories-mega-menu, header, #mobile-menu')) {
                    observer.unobserve(img);
                    return;
                }
                
                // Skip if image is already complete and loaded
                if (img.complete && img.naturalHeight !== 0) {
                    // Image is already loaded, ensure it's visible
                    img.style.opacity = '1';
                    img.style.transform = 'scale(1)';
                    observer.unobserve(img);
                    return;
                }
                
                // Set initial animation state for loading images
                img.style.opacity = '0';
                img.style.transform = 'scale(1.05)';
                img.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                
                const showImage = () => {
                    img.style.opacity = '1';
                    img.style.transform = 'scale(1)';
                };
                
                // Handle both onload and onerror cases
                img.onload = showImage;
                img.onerror = () => {
                    // Even on error, make sure the fallback is visible
                    img.style.opacity = '1';
                    img.style.transform = 'scale(1)';
                };
                
                // Handle lazy loading
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                } else if (img.complete) {
                    // Image was already loaded before we set up the handler
                    showImage();
                }
                
                observer.unobserve(img);
            }
        });
    }, {
        rootMargin: '50px 0px',
        threshold: 0.1
    });
    
    images.forEach(img => {
        imageObserver.observe(img);
    });
    
    // Ensure mega menu and header images are always visible
    setTimeout(() => {
        const headerImages = document.querySelectorAll('header img, #categories-mega-menu img, #mobile-menu img');
        headerImages.forEach(img => {
            img.style.opacity = '1';
            img.style.transform = 'scale(1)';
        });
    }, 100);
});

// Local PhotoSwipe loader - use only local files to avoid CORB issues
let __psLoading = false;
let __psWarned = false;
function loadScriptSequential(urls, done) {
  let idx = 0;
  const next = () => {
    if (idx >= urls.length) return done(false);
    const s = document.createElement('script');
    s.src = urls[idx++];
    s.async = true;
    s.onload = () => done(true);
    s.onerror = next;
    document.head.appendChild(s);
  };
  next();
}
function loadPhotoSwipe() {
    return new Promise((resolve, reject) => {
        if (window.PhotoSwipe) return resolve();
        // Use only local PhotoSwipe files to avoid CORB issues
        const coreCandidates = [
          __cimaiseLayout.basePath + '/assets/photoswipe/dist/umd/photoswipe.umd.min.js',
          __cimaiseLayout.basePath + '/assets/photoswipe/dist/photoswipe.esm.min.js'
        ];
        loadScriptSequential(coreCandidates, (okCore) => {
          if (!okCore && !window.PhotoSwipe) {
            console.warn('PhotoSwipe could not be loaded from local files');
            return reject(new Error('PhotoSwipe unavailable'));
          }
          const lbCandidates = [
            __cimaiseLayout.basePath + '/assets/photoswipe/dist/umd/photoswipe-lightbox.umd.min.js',
            __cimaiseLayout.basePath + '/assets/photoswipe/dist/photoswipe-lightbox.esm.min.js'
          ];
          loadScriptSequential(lbCandidates, () => resolve());
        });
    });
}

// Global flag: animate gallery items once on scroll
window.galleryAnimateOnScroll = true;

// Build PS items from anchors in current gallery
function buildItemsFromAnchors(){
    // Prefer anchors in the currently visible gallery wrapper and ignore duplicates
    const hasMagazine = document.querySelector('#images-gallery .m-desktop-wrap, #images-gallery .m-mobile-wrap');
    const isDesktop = window.matchMedia && window.matchMedia('(min-width: 768px)').matches;
    const container = hasMagazine
      ? (isDesktop && document.querySelector('#images-gallery .m-desktop-wrap')
          ? '#images-gallery .m-desktop-wrap'
          : '#images-gallery .m-mobile-wrap')
      : '#images-gallery';
    const anchors = Array.from(document.querySelectorAll(`${container} a.pswp-link:not(.pswp-is-duplicate)`));
    return anchors.map(x=>({
      src: x.getAttribute('href'),
      width: parseInt(x.getAttribute('data-pswp-width')||'1600',10),
      height: parseInt(x.getAttribute('data-pswp-height')||'1067',10),
      element: x, // Preserve anchor reference for data attributes (EXIF, equipment)
      caption_html: (function(){
        // Build caption from photo title and equipment data attributes
        const title = x.getAttribute('data-title') || '';
        const camera = x.getAttribute('data-camera') || '';
        const lens = x.getAttribute('data-lens') || '';
        const film = x.getAttribute('data-film') || '';
        const developer = x.getAttribute('data-developer') || '';
        const lab = x.getAttribute('data-lab') || '';
        const location = x.getAttribute('data-location') || '';
        // EXIF data
        const iso = x.getAttribute('data-iso') || '';
        const shutter = x.getAttribute('data-shutter') || '';
        const aperture = x.getAttribute('data-aperture') || '';
        const focalLength = x.getAttribute('data-focal-length') || '';
        const exifMake = x.getAttribute('data-exif-make') || '';
        const exifModel = x.getAttribute('data-exif-model') || '';
        const exifLens = x.getAttribute('data-exif-lens') || '';
        const gpsLat = x.getAttribute('data-gps-lat') || '';
        const gpsLng = x.getAttribute('data-gps-lng') || '';
        const dateOriginal = x.getAttribute('data-date-original') || '';
        const artist = x.getAttribute('data-artist') || '';
        const copyright = x.getAttribute('data-copyright') || '';

        let html = '';
        const esc = (s) => String(s || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;');

        // Add photo title if present
        if (title.trim()) {
          html += '<div class="mb-2 font-medium">' + esc(title) + '</div>';
        }

        // Build equipment items with icons (user custom data takes priority)
        const items = [];
        // Camera: prefer custom, then EXIF make+model
        const cameraDisplay = camera.trim() || ((exifMake.trim() || exifModel.trim()) ? (exifMake + ' ' + exifModel).trim() : '');
        if (cameraDisplay) items.push('<span class="cc-item"><i class="fas fa-camera mr-1"></i>' + esc(cameraDisplay) + '</span>');
        // Lens: prefer custom, then EXIF lens
        const lensDisplay = lens.trim() || exifLens.trim();
        if (lensDisplay) items.push('<span class="cc-item"><i class="fas fa-circle-notch mr-1"></i>' + esc(lensDisplay) + '</span>');
        if (film.trim()) items.push('<span class="cc-item"><i class="fas fa-film mr-1"></i>' + esc(film) + '</span>');
        if (developer.trim()) items.push('<span class="cc-item"><i class="fas fa-flask mr-1"></i>' + esc(developer) + '</span>');
        if (lab.trim()) items.push('<span class="cc-item"><i class="fas fa-store mr-1"></i>' + esc(lab) + '</span>');
        if (location.trim()) items.push('<span class="cc-item"><i class="fas fa-map-marker-alt mr-1"></i>' + esc(location) + '</span>');

        if (items.length > 0) {
          html += '<div class="flex flex-wrap gap-2 justify-center text-sm opacity-90">' + items.join('') + '</div>';
        }

        // Build EXIF exposure items
        const exifItems = [];
        if (focalLength.trim()) exifItems.push('<span class="cc-item"><i class="fas fa-arrows-alt-h mr-1"></i>' + esc(focalLength) + 'mm</span>');
        if (aperture.trim()) exifItems.push('<span class="cc-item"><i class="fas fa-dot-circle mr-1"></i>f/' + esc(aperture) + '</span>');
        if (shutter.trim()) exifItems.push('<span class="cc-item"><i class="far fa-clock mr-1"></i>' + esc(shutter) + '</span>');
        if (iso.trim()) exifItems.push('<span class="cc-item"><i class="fas fa-signal mr-1"></i>ISO ' + esc(iso) + '</span>');

        if (exifItems.length > 0) {
          html += '<div class="flex flex-wrap gap-2 justify-center text-xs opacity-75 mt-1">' + exifItems.join('') + '</div>';
        }

        // Additional info row (date, GPS, copyright)
        const infoItems = [];
        if (dateOriginal.trim()) {
          const d = new Date(dateOriginal);
          const dateStr = !isNaN(d.getTime()) ? d.toLocaleDateString() : dateOriginal;
          infoItems.push('<span class="cc-item"><i class="far fa-calendar mr-1"></i>' + esc(dateStr) + '</span>');
        }
        if (gpsLat.trim() && gpsLng.trim()) {
          infoItems.push('<span class="cc-item"><i class="fas fa-globe mr-1"></i>' + esc(gpsLat) + ', ' + esc(gpsLng) + '</span>');
        }
        if (artist.trim()) infoItems.push('<span class="cc-item"><i class="fas fa-user mr-1"></i>' + esc(artist) + '</span>');
        if (copyright.trim()) infoItems.push('<span class="cc-item"><i class="far fa-copyright mr-1"></i>' + esc(copyright) + '</span>');

        if (infoItems.length > 0) {
          html += '<div class="flex flex-wrap gap-2 justify-center text-xs opacity-60 mt-1">' + infoItems.join('') + '</div>';
        }

        return html;
      })()
    }));
}
function destroyLightbox(){
    if (window.__pswpLightbox && typeof window.__pswpLightbox.destroy === 'function') {
        try { window.__pswpLightbox.destroy(); } catch(e){}
        window.__pswpLightbox = null;
    }
}

// Shared PhotoSwipe helper: centered zoom toggle
function pswpToggleZoomCentered(pswp) {
    const slide = pswp?.currSlide;
    if (!slide || !slide.zoomLevels) return;
    const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
    const max = slide.zoomLevels.max ?? fit;
    const current = slide.currZoomLevel ?? fit;
    const target = current <= fit * 1.05
      ? Math.min(max, Math.max(fit * 1.75, fit * 3))
      : fit;
    const root = pswp.element;
    if (root) {
        root.classList.add('pswp--zooming');
        if (target > fit * 1.05) {
            root.classList.add('pswp--zoomed-in');
        } else {
            root.classList.remove('pswp--zoomed-in');
        }
    }
    const vw = window.innerWidth / 2;
    const vh = window.innerHeight / 2;
    if (pswp.currSlide) {
        pswp.currSlide.zoomTo(target, { x: vw, y: vh }, 280, false);
    }
}

// Shared PhotoSwipe helper: setup tap/zoom handling for lightbox.
// Must use PhotoSwipe's native action hooks, NOT a DOM pointerup
// listener: stopPropagation() on pointerup swallows the gesture-end
// PhotoSwipe listens for on window, so drags are never finalized
// (no spring-back into pan bounds) and the image can be dragged
// off-screen permanently on touch devices.
function pswpSetupTapZoom(pswp, toggleZoomFn) {
    if (pswp._customZoomBound) return;

    // Zoom button click handler
    const zoomBtn = pswp.element?.querySelector('.pswp__button--zoom');
    if (zoomBtn) {
        zoomBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            toggleZoomFn(pswp);
        }, { capture: true });
    }

    const isZoomedIn = () => {
        const slide = pswp.currSlide;
        if (!slide || !slide.zoomLevels) return false;
        const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
        const current = slide.currZoomLevel ?? fit;
        return current > fit * 1.05;
    };
    const tappedImage = (ev) => !!(ev && ev.target && ev.target.closest && ev.target.closest('.pswp__img'));
    const navigateByHalf = (point) => {
        if (point.x < pswp.viewportSize.x / 2) {
            pswp.prev();
        } else {
            pswp.next();
        }
    };
    const toggleControls = () => {
        pswp.element?.classList.toggle('pswp--ui-visible');
    };

    // Mouse click on the image: navigate at fit level, zoom back out
    // at the clicked point when zoomed in.
    pswp.options.imageClickAction = (point, ev) => {
        if (!tappedImage(ev)) return;
        if (isZoomedIn()) {
            pswp.currSlide?.toggleZoom(point);
            return;
        }
        navigateByHalf(point);
    };

    // Touch single tap: PhotoSwipe fires this only for real taps
    // (drag releases go through its drag-end handling) and debounces
    // it against double taps natively.
    pswp.options.tapAction = (point, ev) => {
        if (!tappedImage(ev) || isZoomedIn()) {
            toggleControls();
            return;
        }
        navigateByHalf(point);
    };

    // Touch double tap: centered zoom toggle. No on-image check —
    // like PhotoSwipe's native 'zoom' action, the letterbox area
    // around the image counts too (didTapOnMainContent already
    // restricts this to the slide container).
    pswp.options.doubleTapAction = (point, ev) => {
        toggleZoomFn(pswp);
    };

    pswp._customZoomBound = true;
}

// Initialize PhotoSwipe Lightbox bound to gallery anchors
function initLightbox() {
    // Check if there are any pswp-link elements before initializing
    const galleryElement = document.getElementById('images-gallery');
    if (!galleryElement || !galleryElement.querySelectorAll('a.pswp-link').length) {
        return;
    }
    
    loadPhotoSwipe().then(()=>{
        destroyLightbox();
        if (window.PhotoSwipeLightbox) {
            const psCfg = (window.templateSettings && window.templateSettings.photoswipe) || {};
            // Pick appropriate gallery root for Magazine template wrappers
            let gallerySelector = '#images-gallery';
            if (document.querySelector('#images-gallery .m-desktop-wrap, #images-gallery .m-tablet-wrap, #images-gallery .m-mobile-wrap')) {
              if (document.querySelector('#images-gallery .m-desktop-wrap')) {
                gallerySelector = '#images-gallery .m-desktop-wrap';
              } else if (document.querySelector('#images-gallery .m-tablet-wrap')) {
                gallerySelector = '#images-gallery .m-tablet-wrap';
              } else if (document.querySelector('#images-gallery .m-mobile-wrap')) {
                gallerySelector = '#images-gallery .m-mobile-wrap';
              }
            }

            const lb = new PhotoSwipeLightbox({
                gallery: gallerySelector,
                // Exclude duplicates that are rendered for infinite scroll columns
                children: 'a.pswp-link:not(.pswp-is-duplicate)',
                pswpModule: PhotoSwipe,
                
                // Modern image sizing and layout
                bgOpacity: 0.95,
                spacing: (typeof psCfg.spacing === 'number') ? psCfg.spacing : 0.08,
                // Prevent accidental swipes while zooming
                allowPanToNext: false,
                loop: !!psCfg.loop,
                wheelToZoom: true,
                pinchToClose: false,
                closeOnVerticalDrag: false,

                // Responsive padding: smaller on mobile for larger images
                // Bottom padding increased on mobile to avoid overlapping with captions on vertical photos
                padding: window.innerWidth < 768
                    ? { top: 44, bottom: 90, left: 8, right: 8 }
                    : { top: 60, bottom: 100, left: 48, right: 48 },

                // Modern smooth animations
                showHideAnimationType: 'none',
                hideAnimationDuration: 0,
                showAnimationDuration: 0,
                zoomAnimationDuration: 300,
                easing: 'cubic-bezier(0.4, 0, 0.2, 1)',

                // Click/tap interactions: enable double-tap zoom on mobile
                imageClickAction: 'zoom-or-close',
                bgClickAction: 'close',
                tapAction: 'toggle-controls',
                doubleTapAction: 'zoom',
                
                // Performance optimizations
                preloadFirstSlide: true,
                preload: [1, 2],
                preloaderDelay: 0,
                
                // Better accessibility (template-controlled)
                escKey: (psCfg.escKey === undefined ? true : !!psCfg.escKey),
                arrowKeys: (psCfg.arrowKeys === undefined ? true : !!psCfg.arrowKeys),
                trapFocus: true,
                returnFocus: true,
                
                // Modern UI
                clickToCloseNonZoomable: true,
                maxWidthToAnimate: 2000,
                
                
                // Enhanced UI with icons (share/counter driven by template)
                closeTitle: 'Chiudi (Esc)',
                zoomTitle: 'Zoom',
                arrowPrevTitle: '← Precedente',
                arrowNextTitle: 'Successiva →',
                errorMsg: 'Impossibile caricare l\'immagine',
                indexIndicatorSep: ' di ',
                
                // Enable advanced features
                zoom: true,
                counter: true,
                mouseUsed: false,
                
            });

            // Hide placeholder images when content is appended
            lb.on('contentAppend', (e) => {
                if (e.content?.element) {
                    e.content.element.querySelectorAll('.pswp__img--placeholder').forEach(img => {
                        img.style.display = 'none';
                    });
                }
            });

            // Prevent background scrolling and hide placeholders
            // Scroll lock helpers
            let prevHtmlOverflow = '';
            let prevBodyOverflow = '';
            let prevHtmlPadding = '';
            let prevBodyPadding = '';
            let scrollLocked = false;
            let wakeLock = null;
            const lockScroll = () => {
                if (scrollLocked) return;
                scrollLocked = true;
                prevHtmlOverflow = document.documentElement.style.overflow;
                prevBodyOverflow = document.body.style.overflow;
                prevHtmlPadding = document.documentElement.style.paddingRight;
                prevBodyPadding = document.body.style.paddingRight;
                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                document.documentElement.style.overflow = 'hidden';
                document.body.style.overflow = 'hidden';
                if (scrollbarWidth > 0) {
                    document.documentElement.style.paddingRight = `${scrollbarWidth}px`;
                    document.body.style.paddingRight = `${scrollbarWidth}px`;
                }
                document.documentElement.classList.add('pswp-open');
                document.body.classList.add('pswp-open');
                if (window.lenisInstance && typeof window.lenisInstance.stop === 'function') {
                    window.lenisInstance.stop();
                }
                // Screen Wake Lock - keep screen on during lightbox viewing
                if ('wakeLock' in navigator) {
                    navigator.wakeLock.request('screen').then(lock => {
                        wakeLock = lock;
                    }).catch(() => {
                        // Wake lock request failed (page not visible or permission denied)
                    });
                }
            };
            const unlockScroll = () => {
                if (!scrollLocked) return;
                scrollLocked = false;
                document.documentElement.classList.remove('pswp-open');
                document.body.classList.remove('pswp-open');
                document.documentElement.style.overflow = prevHtmlOverflow;
                document.body.style.overflow = prevBodyOverflow;
                document.documentElement.style.paddingRight = prevHtmlPadding;
                document.body.style.paddingRight = prevBodyPadding;
                if (window.lenisInstance && typeof window.lenisInstance.start === 'function') {
                    window.lenisInstance.start();
                }
                // Release Screen Wake Lock
                if (wakeLock) {
                    wakeLock.release().then(() => {
                        wakeLock = null;
                    }).catch(() => {
                        wakeLock = null;
                    });
                }
            };

            lb.on('openingAnimationStart', () => {
                showPswpOverlay();
                lockScroll();
                document.querySelectorAll('.pswp__img--placeholder, .pswp__img--initial').forEach(el => {
                    el.style.display = 'none';
                    el.style.visibility = 'hidden';
                    el.style.opacity = '0';
                });
            });

            lb.on('openingAnimationEnd', () => {
                hidePswpOverlay();
            });

            lb.on('closingAnimationEnd', () => {
                hidePswpOverlay();
                unlockScroll();
            });

            // Centered zoom toggle for zoom button only
            const toggleZoomCentered = (pswp) => {
                const slide = pswp?.currSlide;
                if (!slide || !slide.zoomLevels) return;
                const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
                const max = slide.zoomLevels.max ?? fit;
                const current = slide.currZoomLevel ?? fit;
                const target = current <= fit * 1.05
                  ? Math.min(max, Math.max(fit * 1.75, fit * 3))
                  : fit;
                const root = pswp.element;
                if (root) {
                    root.classList.add('pswp--zooming');
                    if (target > fit * 1.05) {
                        root.classList.add('pswp--zoomed-in');
                    } else {
                        root.classList.remove('pswp--zoomed-in');
                    }
                    if (root._zoomingTimer) {
                        clearTimeout(root._zoomingTimer);
                    }
                    root._zoomingTimer = setTimeout(() => {
                        root.classList.remove('pswp--zooming');
                    }, 450);
                }
                const center = { x: pswp.viewportSize.x / 2, y: pswp.viewportSize.y / 2 };
                if (pswp.currSlide) {
                    pswp.currSlide.zoomTo(target, center, 350);
                }
            };

            // NOTE: PhotoSwipe 5 has no 'open' event — binding to it makes
            // this whole block dead code. 'afterInit' fires at the end of
            // open() with the UI already built (zoom button exists).
            lb.on('afterInit', () => {
                const pswp = lb.pswp;
                if (pswp && !pswp._customZoomBound) {
                    const updateZoomClasses = () => {
                        const root = pswp.element;
                        const slide = pswp.currSlide;
                        if (!root || !slide || !slide.zoomLevels) {
                            return;
                        }
                        const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
                        const current = slide.currZoomLevel ?? fit;
                        if (current > fit * 1.05) {
                            root.classList.add('pswp--zoomed-in');
                        } else {
                            root.classList.remove('pswp--zoomed-in');
                        }
                        root.classList.add('pswp--zooming');
                        if (root._zoomingTimer) {
                            clearTimeout(root._zoomingTimer);
                        }
                        root._zoomingTimer = setTimeout(() => {
                            root.classList.remove('pswp--zooming');
                        }, 250);
                    };
                    pswp.on('zoomPanUpdate', updateZoomClasses);
                    pswp.on('change', updateZoomClasses);
                    updateZoomClasses();
                    // Use shared tap/zoom handler
                    pswpSetupTapZoom(pswp, toggleZoomCentered);
                }
            });

            // Enhanced Caption UI with metadata
            lb.on('uiRegister', () => {
                // EXIF Panel - slides in from right with real EXIF data
                if (window.__showExifInLightbox) {
                    // State for EXIF panel
                    let exifCache = {}; // Cache fetched EXIF data by image ID
                    let currentExifRequest = null; // Track current AJAX request

                    // Register EXIF panel element
                    lb.pswp.ui.registerElement({
                        name: 'exif-panel',
                        order: 99,
                        appendTo: 'root',
                        onInit: (panelEl, pswp) => {
                            panelEl.className = 'pswp__exif-panel pswp__hide-on-close';
                            panelEl.innerHTML = `
                                <div class="pswp__exif-panel-header">
                                    <h3>${__cimaiseHtml(__cimaiseLayout.i18n.exifTitle)}</h3>
                                    <button type="button" class="pswp__exif-panel-close" aria-label="Close">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="pswp__exif-panel-content">
                                    <div class="pswp__exif-loading">
                                        <i class="fas fa-spinner"></i>
                                        <span>${__cimaiseHtml(__cimaiseLayout.i18n.loading)}</span>
                                    </div>
                                </div>
                            `;

                            // Close button handler
                            panelEl.querySelector('.pswp__exif-panel-close').onclick = () => {
                                panelEl.classList.remove('visible');
                                const infoBtn = pswp.element.querySelector('.pswp__button--info-button');
                                if (infoBtn) infoBtn.classList.remove('active');
                            };

                            // Close panel on slide change
                            pswp.on('change', () => {
                                panelEl.classList.remove('visible');
                                const infoBtn = pswp.element.querySelector('.pswp__button--info-button');
                                if (infoBtn) infoBtn.classList.remove('active');
                            });
                        }
                    });

                    // Info button to toggle EXIF panel
                    lb.pswp.ui.registerElement({
                        name: 'info-button',
                        order: 9,
                        isButton: true,
                        appendTo: 'bar',
                        html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 32 32" width="32" height="32"><path d="M16 4C9.373 4 4 9.373 4 16s5.373 12 12 12 12-5.373 12-12S22.627 4 16 4zm0 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm2 14h-4v-2h1v-5h-1v-2h3v7h1v2z" fill="currentColor"/></svg>',
                        onInit: (el, pswp) => {
                            el.setAttribute('title', __cimaiseLayout.i18n.infoButton);
                            el.setAttribute('aria-label', __cimaiseLayout.i18n.infoButton);

                            const renderExifContent = (data, contentEl) => {
                                // XSS prevention: escape HTML entities
                                const escapeHtml = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                // Whitelist: FontAwesome safe classes (alphanumeric, spaces, hyphen, underscore)
                                const sanitizeIcon = (icon) => {
                                    let safeIcon = String(icon || '').replace(/[^a-z0-9\-\s_]/gi, '').trim();
                                    return safeIcon || 'fas fa-circle';
                                };

                                if (!data.success || !data.sections || data.sections.length === 0) {
                                    contentEl.innerHTML = `
                                        <div class="pswp__exif-empty">
                                            <i class="fas fa-image"></i>
                                            <p>${escapeHtml(data.message) || __cimaiseHtml(__cimaiseLayout.i18n.noExif)}</p>
                                        </div>
                                    `;
                                    return;
                                }

                                let html = '';
                                data.sections.forEach(section => {
                                    html += `<div class="pswp__exif-section">`;
                                    html += `<div class="pswp__exif-section-title">${escapeHtml(section.title)}</div>`;
                                    section.items.forEach(item => {
                                        const iconClass = sanitizeIcon(item.icon);
                                        let valueHtml = escapeHtml(item.value);
                                        if (item.isLink && String(item.value || '').startsWith('http')) {
                                            // Validate URL protocol for safety
                                            const safeUrl = escapeHtml(item.value);
                                            valueHtml = `<a href="${safeUrl}" target="_blank" rel="noopener">${__cimaiseHtml(__cimaiseLayout.i18n.viewMap)}</a>`;
                                        }
                                        html += `
                                            <div class="pswp__exif-item">
                                                <div class="pswp__exif-icon"><i class="${iconClass}"></i></div>
                                                <div class="pswp__exif-item-content">
                                                    <div class="pswp__exif-label">${escapeHtml(item.label)}</div>
                                                    <div class="pswp__exif-value">${valueHtml}</div>
                                                </div>
                                            </div>
                                        `;
                                    });
                                    html += `</div>`;
                                });
                                contentEl.innerHTML = html;
                            };

                            const fetchExifData = (imageId, contentEl) => {
                                // Check cache first
                                if (exifCache[imageId]) {
                                    renderExifContent(exifCache[imageId], contentEl);
                                    return;
                                }

                                // Show loading
                                contentEl.innerHTML = `
                                    <div class="pswp__exif-loading">
                                        <i class="fas fa-spinner"></i>
                                        <span>${__cimaiseHtml(__cimaiseLayout.i18n.loading)}</span>
                                    </div>
                                `;

                                // Cancel previous request if any
                                if (currentExifRequest) {
                                    currentExifRequest.abort();
                                }

                                // Fetch EXIF data
                                const controller = new AbortController();
                                currentExifRequest = controller;
                                const basePath = window.basePath || __cimaiseLayout.basePath;

                                fetch(`${basePath}/api/image/${imageId}/exif`, {
                                    signal: controller.signal,
                                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                })
                                .then(response => response.json())
                                .then(data => {
                                    currentExifRequest = null;
                                    exifCache[imageId] = data;
                                    renderExifContent(data, contentEl);
                                })
                                .catch(err => {
                                    if (err.name !== 'AbortError') {
                                        contentEl.innerHTML = `
                                            <div class="pswp__exif-empty">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <p>${__cimaiseHtml(__cimaiseLayout.i18n.exifError)}</p>
                                            </div>
                                        `;
                                    }
                                });
                            };

                            el.onclick = () => {
                                const panel = pswp.element.querySelector('.pswp__exif-panel');
                                if (!panel) return;

                                const isVisible = panel.classList.toggle('visible');
                                el.classList.toggle('active', isVisible);
                                const captionEl = pswp.element.querySelector('.pswp__custom-caption');
                                if (captionEl && captionEl.innerHTML.trim()) {
                                    captionEl.classList.toggle('visible', isVisible);
                                }

                                if (isVisible) {
                                    // Get image ID from current slide
                                    const curr = pswp.currSlide?.data?.element;
                                    const imageId = curr?.dataset?.imageId;
                                    const contentEl = panel.querySelector('.pswp__exif-panel-content');

                                    // Validate imageId is numeric
                                    if (imageId && /^\d+$/.test(imageId) && contentEl) {
                                        fetchExifData(imageId, contentEl);
                                    } else if (contentEl) {
                                        contentEl.innerHTML = `
                                            <div class="pswp__exif-empty">
                                                <i class="fas fa-image"></i>
                                                <p>${__cimaiseHtml(__cimaiseLayout.i18n.noExif)}</p>
                                            </div>
                                        `;
                                    }
                                }
                            };
                        }
                    });
                }

                // Custom caption inline (transparent background), includes meta info
                lb.pswp.ui.registerElement({
                    name: 'custom-caption', order: 9, isButton: false, appendTo: 'root',
                    onInit: (el, pswp) => {
                        el.className = 'pswp__custom-caption pswp__hide-on-close';
                        // ALL styling lives in CSS (.pswp__custom-caption + its
                        // responsive @media). Do NOT set inline styles here: an
                        // inline style="" overrides the stylesheet (including the
                        // mobile rule), which is exactly what hid the mobile
                        // background/padding before.
                        const renderCaption = () => {
                          const curr = lb.pswp.currSlide && lb.pswp.currSlide.data && lb.pswp.currSlide.data.element;
                          if (!curr) {
                            el.innerHTML = '';
                            el.classList.remove('visible');
                            const infoBtn = lb.pswp.element.querySelector('.pswp__button--info-button');
                            if (infoBtn) infoBtn.classList.remove('active');
                            return;
                          }
                          const ds = curr.dataset || {};
                          const esc = (s)=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                          // Title: use alt text as the image title
                          const title = esc(ds.alt || '');
                          // Caption: use data-title which maps to image.caption
                          const caption = esc(ds.title || '');
                          const piece = (icon, val) => {
                            if (!val) return '';
                            // Whitelist: FontAwesome safe classes (alphanumeric, spaces, hyphen, underscore)
                            let safeIcon = String(icon || '').replace(/[^a-z0-9\-\s_]/gi, '').trim();
                            if (!safeIcon) safeIcon = 'fas fa-tag';
                            return `<span class="inline-flex items-center"><i class="${safeIcon} mr-1"></i>${esc(val)}</span>`;
                          };
                          // Custom assigned equipment (camera, lens, film, etc.) - NOT EXIF
                          const equipment = [
                            piece('fas fa-camera', ds.camera),
                            piece('fas fa-dot-circle', ds.lens),
                            piece('fas fa-film', ds.film),
                            piece('fas fa-flask', ds.developer),
                            piece('fas fa-industry', ds.lab),
                            piece('fas fa-map-marker-alt', ds.location)
                          ].filter(Boolean);
                          // Album custom fields (from album/image with override logic)
                          let customFieldsHtml = [];
                          if (ds.customFields) {
                            try {
                              const cf = JSON.parse(ds.customFields);
                              Object.values(cf).forEach(field => {
                                // Check show_in_lightbox if present (raw format), otherwise assume it should show (filtered format)
                                const shouldShow = field.show_in_lightbox === undefined || field.show_in_lightbox;
                                if (shouldShow && field.values && field.values.length > 0) {
                                  // Add 'fas' prefix if icon doesn't have a prefix
                                  let icon = field.icon || 'fa-tag';
                                  if (icon && !icon.startsWith('fa-') && !icon.includes(' ')) {
                                    icon = 'fas ' + icon;
                                  } else if (icon && icon.startsWith('fa-') && !icon.includes(' ')) {
                                    icon = 'fas ' + icon;
                                  }
                                  const values = field.values.join(', ');
                                  customFieldsHtml.push(piece(icon, values));
                                }
                              });
                            } catch(e) { /* ignore parse errors */ }
                          }
                          // Build caption: Title - Caption - Custom Fields (no EXIF)
                          const allItems = [];
                          if (title) allItems.push(`<span class="caption-title font-semibold">${title}</span>`);
                          if (caption && caption !== title) allItems.push(`<span class="caption-text opacity-80">${caption}</span>`);
                          allItems.push(...equipment, ...customFieldsHtml);
                          const html = allItems.length ? `<div class="caption-content flex flex-wrap gap-x-3 gap-y-1 items-center justify-center text-sm mt-2">${allItems.join(' ')}</div>` : '';
                          el.innerHTML = html;
                          // If no content, reset visibility state
                          if (!html.trim()) {
                            el.classList.remove('visible');
                            const infoBtn = lb.pswp.element.querySelector('.pswp__button--info-button');
                            if (infoBtn) infoBtn.classList.remove('active');
                          } else {
                            const infoBtn = lb.pswp.element.querySelector('.pswp__button--info-button');
                            if (window.__showExifInLightbox && infoBtn) {
                              el.classList.toggle('visible', infoBtn.classList.contains('active'));
                            } else {
                              el.classList.add('visible');
                            }
                          }
                        };
                        // Render as early as possible so the caption is
                        // already present when the photo finishes opening
                        // (firstUpdate fires before the open animation;
                        // currSlide.data/dataset is available by then). The
                        // other two events keep it correct on navigation and
                        // as a fallback if firstUpdate was too early.
                        lb.pswp.on('firstUpdate', renderCaption);
                        lb.pswp.on('change', renderCaption);
                        lb.pswp.on('openingAnimationEnd', renderCaption);
                    }
                });

                // Zoom indicator bottom-right
                lb.pswp.ui.registerElement({
                  name: 'zoom-indicator-br', order: 6, appendTo: 'root',
                  onInit: (el, pswp) => {
                    el.className = 'pswp__zoom-indicator-br pswp__hide-on-close';
                    el.style.cssText = 'position:absolute; bottom:18px; right:12px; color:#fff; font-size:12px; pointer-events:none; opacity:0; transition:opacity .2s; background:rgba(0,0,0,0.72); padding:4px 8px; border-radius:999px;';
                    const update = () => { const z = Math.round((pswp.currSlide?.currZoomLevel || 1) * 100); el.textContent = 'zoom: ' + z + '%'; el.style.opacity = '1'; };
                    pswp.on('zoomPanUpdate', (e) => { if (e.slide === pswp.currSlide) update(); });
                    pswp.on('change', update);
                    pswp.on('openingAnimationEnd', update);
                  }
                });

                // Bullets navigation
                lb.pswp.ui.registerElement({
                  name: 'bulletsIndicator', className: 'pswp__bullets-indicator', appendTo: 'wrapper',
                  onInit: (el, pswp) => {
                    const bullets = []; let prev = -1;
                    for (let i = 0; i < pswp.getNumItems(); i++) {
                      const b = document.createElement('div'); b.className = 'pswp__bullet';
                      b.onclick = () => pswp.goTo(bullets.indexOf(b)); el.appendChild(b); bullets.push(b);
                    }
                    pswp.on('change', () => { if (prev>=0) bullets[prev].classList.remove('pswp__bullet--active'); bullets[pswp.currIndex].classList.add('pswp__bullet--active'); prev = pswp.currIndex; });
                  }
                });

                // Hide any meta-panel (not used)
                lb.pswp.ui.registerElement({ name: 'meta-panel', order: 99, appendTo: 'root', onInit: (el)=>{ el.style.display='none'; el.className='pswp__meta-panel'; } });
            
                if (__cimaiseLayout.allowDownloads) {
                // Download button - SVG structure matching PhotoSwipe
                lb.pswp.ui.registerElement({
                    name: 'download-button', order: 8, isButton: true, appendTo: 'bar',
                    html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 32 32" width="32" height="32"><path d="M16 18l-6-6h4V6h4v6h4l-6 6zm-8 2v4h16v-4h2v6H6v-6h2z" fill="currentColor"/></svg>',
                    onInit: (el, pswp) => {
                        el.setAttribute('title', __cimaiseLayout.i18n.download);
                        el.setAttribute('aria-label', __cimaiseLayout.i18n.download);
                        const resolveDownloadUrl = () => {
                            if (!window.allowDownloads) return null;
                            const data = pswp?.currSlide?.data || {};
                            const src = data.src || '';
                            let imageId = null;
                            const match = src.match(/\/media\/(?:protected\/)?(\d+)(?:_|\/)/);
                            if (match) {
                              imageId = match[1];
                            } else if (data.element && data.element.dataset && data.element.dataset.imageId) {
                              imageId = data.element.dataset.imageId;
                            }
                            if (!imageId) return null;
                            const base = (window.basePath || __cimaiseLayout.basePath).replace(/\/$/, '');
                            return `${base}/download/image/${imageId}`;
                        };
                        const updateVisibility = () => {
                            const url = resolveDownloadUrl();
                            el.style.display = url ? '' : 'none';
                        };
                        el.onclick = () => window.downloadOriginal(resolveDownloadUrl(), el);
                        pswp.on('change', updateVisibility);
                        pswp.on('openingAnimationEnd', updateVisibility);
                        updateVisibility();
                    }
                });
                }

                // Fullscreen button - SVG structure matching PhotoSwipe
                lb.pswp.ui.registerElement({
                    name: 'fullscreen-button', order: 7, isButton: true, appendTo: 'bar',
                    html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 32 32" width="32" height="32"><path d="M6 6v8h2V8h6V6H6zm18 0h-8v2h6v6h2V6zm0 20v-8h-2v6h-6v2h8zM6 26h8v-2H8v-6H6v8z" fill="currentColor" class="pswp__icn-fullscreen-expand"/><path d="M14 14V6h-2v6H6v2h8zm4 0h8v-2h-6V6h-2v8zm8 4h-8v8h2v-6h6v-2zM6 18v2h6v6h2v-8H6z" fill="currentColor" class="pswp__icn-fullscreen-compress" style="display:none"/></svg>',
                    onInit: (el, pswp) => {
                        el.setAttribute('title', 'Schermo intero');
                        el.setAttribute('aria-label', 'Schermo intero');
                        const expandPath = el.querySelector('.pswp__icn-fullscreen-expand');
                        const compressPath = el.querySelector('.pswp__icn-fullscreen-compress');
                        el.onclick = () => {
                            if (document.fullscreenElement) {
                                document.exitFullscreen();
                            } else {
                                pswp.template.requestFullscreen();
                            }
                        };
                        const updateIcon = () => {
                            if (document.fullscreenElement) {
                                expandPath.style.display = 'none';
                                compressPath.style.display = '';
                                el.setAttribute('title', 'Esci da schermo intero');
                            } else {
                                expandPath.style.display = '';
                                compressPath.style.display = 'none';
                                el.setAttribute('title', 'Schermo intero');
                            }
                        };
                        document.addEventListener('fullscreenchange', updateIcon);
                    }
                });

                // Share button - SVG structure matching PhotoSwipe
                lb.pswp.ui.registerElement({
                    name: 'share-button', order: 6, isButton: true, appendTo: 'bar',
                    html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 32 32" width="32" height="32"><path d="M20 22c-1.18 0-2.24.51-2.97 1.32l-7.54-4.4A4.007 4.007 0 0 0 10 17c0-.67-.17-1.3-.46-1.85l7.5-4.38A3.98 3.98 0 0 0 20 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4c0 .67.17 1.3.46 1.85l-7.5 4.38A3.98 3.98 0 0 0 6 13c-2.21 0-4 1.79-4 4s1.79 4 4 4c1.18 0 2.24-.51 2.97-1.32l7.54 4.4c-.29.55-.51 1.18-.51 1.92 0 2.21 1.79 4 4 4s4-1.79 4-4-1.79-4-4-4z" fill="currentColor"/></svg>',
                    onInit: (el, pswp) => {
                        if (psCfg.share === false) { el.style.display = 'none'; return; }
                        el.setAttribute('title', 'Condividi');
                        el.setAttribute('aria-label', 'Condividi');
                        el.onclick = async () => {
                            if (pswp.currSlide && pswp.currSlide.data) {
                                const url = window.location.href;
                                if (navigator.share) {
                                    try {
                                        await navigator.share({ title: document.title, url: url });
                                    } catch(e) {
                                        navigator.clipboard.writeText(url);
                                    }
                                } else {
                                    navigator.clipboard.writeText(url);
                                }
                            }
                        };
                    }
                });
            });

            lb.init();
            // Hide counter if disabled in template
            if (psCfg.counter === false) {
                try {
                    const counterEl = document.querySelector('.pswp__counter');
                    if (counterEl) counterEl.style.display = 'none';
                } catch(e) {}
            }
            window.__pswpLightbox = lb;
        }
    }).catch((err)=>{
        console.warn('Failed to initialize PhotoSwipe:', err);
    });
}
function openLightboxAt(index) {
    const items = buildItemsFromAnchors();
    const startIndex = Math.max(0, Math.min(index, items.length-1));
    showPswpOverlay();
    schedulePswpOverlayFailSafe();

    loadPhotoSwipe().then(() => {
        const psCfg = (window.templateSettings && window.templateSettings.photoswipe) || {};
        // If PhotoSwipeLightbox is available, use it to get full UI features
        if (window.PhotoSwipeLightbox) {
          const lb = new PhotoSwipeLightbox({
            dataSource: items,
            pswpModule: PhotoSwipe,

            // Modern configuration matching main lightbox
            bgOpacity: 0.97,
            spacing: 0.08,
            allowPanToNext: false,
            loop: !!psCfg.loop,
            wheelToZoom: true,
            pinchToClose: false,
            closeOnVerticalDrag: false,

            // Responsive padding: smaller on mobile for larger images
            // Bottom padding increased on mobile to avoid overlapping with captions on vertical photos
            padding: window.innerWidth < 768
                ? { top: 44, bottom: 90, left: 8, right: 8 }
                : { top: 60, bottom: 100, left: 48, right: 48 },

            // Modern animations
            showHideAnimationType: 'none',
            hideAnimationDuration: 0,
            showAnimationDuration: 0,
            zoomAnimationDuration: 300,
            easing: 'cubic-bezier(0.4, 0, 0.2, 1)',

            // Click/tap interactions: enable double-tap zoom on mobile
            imageClickAction: 'zoom-or-close',
            bgClickAction: 'close',
            tapAction: 'toggle-controls',
            doubleTapAction: 'zoom'
          });
          let psCaptionEl;
          const toggleZoomCentered = (pswp) => {
            const slide = pswp?.currSlide;
            if (!slide || !slide.zoomLevels) return;
            const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
            const max = slide.zoomLevels.max ?? fit;
            const current = slide.currZoomLevel ?? fit;
            const target = current <= fit * 1.05
              ? Math.min(max, Math.max(fit * 1.75, fit * 3))
              : fit;
            const root = pswp.element;
            if (root) {
              root.classList.add('pswp--zooming');
              if (target > fit * 1.05) {
                root.classList.add('pswp--zoomed-in');
              } else {
                root.classList.remove('pswp--zoomed-in');
              }
              if (root._zoomingTimer) {
                clearTimeout(root._zoomingTimer);
              }
              root._zoomingTimer = setTimeout(() => {
                root.classList.remove('pswp--zooming');
              }, 450);
            }
            const center = { x: pswp.viewportSize.x / 2, y: pswp.viewportSize.y / 2 };
            if (pswp.currSlide) {
                pswp.currSlide.zoomTo(target, center, 350);
            }
          };
          // 'afterInit', not 'open': PhotoSwipe 5 never dispatches 'open'.
          lb.on('afterInit', () => {
            const pswp = lb.pswp;
            // Custom zoom: toggle between fit and zoom using built-in toggleZoom
            if (pswp && !pswp._customZoomBound) {
              const updateZoomClasses = () => {
                const root = pswp.element;
                const slide = pswp.currSlide;
                if (!root || !slide || !slide.zoomLevels) {
                  return;
                }
                const fit = slide.zoomLevels.fit ?? slide.zoomLevels.initial ?? 1;
                const current = slide.currZoomLevel ?? fit;
                if (current > fit * 1.05) {
                  root.classList.add('pswp--zoomed-in');
                } else {
                  root.classList.remove('pswp--zoomed-in');
                }
                root.classList.add('pswp--zooming');
                if (root._zoomingTimer) {
                  clearTimeout(root._zoomingTimer);
                }
                root._zoomingTimer = setTimeout(() => {
                  root.classList.remove('pswp--zooming');
                }, 250);
              };
              pswp.on('zoomPanUpdate', updateZoomClasses);
              pswp.on('change', updateZoomClasses);
              updateZoomClasses();
              // Use shared tap/zoom handler
              pswpSetupTapZoom(pswp, toggleZoomCentered);
            }
            psCaptionEl = document.createElement('div');
            psCaptionEl.style.cssText = 'position:fixed; bottom:40px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.8); color:white; padding:10px 15px; border-radius:5px; z-index:10001; text-align:center; font-size:14px; max-width:80%;';
            pswp.on('change', () => {
              const it = items[pswp.currIndex];
              if (!it) return;
              
              // Remove previous caption
              if (psCaptionEl.parentNode) psCaptionEl.parentNode.removeChild(psCaptionEl);
              
              // Set caption content
              const captionContent = it.caption_html || (it.caption ? `<div>${it.caption}</div>` : '');
              if (captionContent) {
                psCaptionEl.innerHTML = captionContent;
                document.body.appendChild(psCaptionEl);
              }
            });
            pswp.on('close', () => { if (psCaptionEl && psCaptionEl.parentNode) psCaptionEl.parentNode.removeChild(psCaptionEl); });
          });

          // Prevent background scroll when lightbox is open (fallback)
          let fbPrevHtmlOverflow = '';
          let fbPrevBodyOverflow = '';
          let fbPrevHtmlPadding = '';
          let fbPrevBodyPadding = '';
          let fbScrollLocked = false;
          lb.on('openingAnimationStart', () => {
              showPswpOverlay();
              if (!fbScrollLocked) {
                  fbScrollLocked = true;
                  fbPrevHtmlOverflow = document.documentElement.style.overflow;
                  fbPrevBodyOverflow = document.body.style.overflow;
                  fbPrevHtmlPadding = document.documentElement.style.paddingRight;
                  fbPrevBodyPadding = document.body.style.paddingRight;
                  const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                  document.documentElement.style.overflow = 'hidden';
                  document.body.style.overflow = 'hidden';
                  if (scrollbarWidth > 0) {
                      document.documentElement.style.paddingRight = `${scrollbarWidth}px`;
                      document.body.style.paddingRight = `${scrollbarWidth}px`;
                  }
              }
              document.documentElement.classList.add('pswp-open');
              document.body.classList.add('pswp-open');
              if (window.lenisInstance && typeof window.lenisInstance.stop === 'function') {
                  window.lenisInstance.stop();
              }
          });
          lb.on('openingAnimationEnd', () => {
              hidePswpOverlay();
          });
          lb.on('closingAnimationEnd', () => {
              hidePswpOverlay();
              document.documentElement.classList.remove('pswp-open');
              document.body.classList.remove('pswp-open');
              document.documentElement.style.overflow = fbPrevHtmlOverflow;
              document.body.style.overflow = fbPrevBodyOverflow;
              document.documentElement.style.paddingRight = fbPrevHtmlPadding;
              document.body.style.paddingRight = fbPrevBodyPadding;
              fbScrollLocked = false;
              if (window.lenisInstance && typeof window.lenisInstance.start === 'function') {
                  window.lenisInstance.start();
              }
          });

          lb.init();
          // Manually open at index
          lb.loadAndOpen(startIndex);
        } else {
          // Fallback to base PhotoSwipe class (still local JS)
          const lightbox = new PhotoSwipe({
              dataSource: items,
              index: startIndex,
              showHideAnimationType: 'none',
              allowPanToNext: !!psCfg.allowPanToNext,
              bgOpacity: 0.94,
              loop: !!psCfg.loop,
              showAnimationDuration: 0,
              hideAnimationDuration: 0,
              zoomAnimationDuration: 0,
              easing: 'linear'
          });
          // Custom caption overlay for PhotoSwipe
          let psCaptionEl = document.createElement('div');
          psCaptionEl.style.cssText = 'position:fixed; bottom:40px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.8); color:white; padding:10px 15px; border-radius:5px; z-index:10001; text-align:center; font-size:14px; max-width:80%;';
          function updatePsCaption(){
            const it = items[lightbox.currIndex];
            if (!it) return;
            
            // Remove previous caption
            if (psCaptionEl.parentNode) psCaptionEl.parentNode.removeChild(psCaptionEl);
            
            // Set caption content
            const captionContent = it.caption_html || (it.caption ? `<div>${it.caption}</div>` : '');
            if (captionContent) {
              psCaptionEl.innerHTML = captionContent;
              document.body.appendChild(psCaptionEl);
            }
          }
          lightbox.on('change', updatePsCaption);
          lightbox.on('close', ()=>{ if (psCaptionEl.parentNode) psCaptionEl.parentNode.removeChild(psCaptionEl); });
          // Prevent background scroll when lightbox is open
          lightbox.on('openingAnimationStart', () => {
              showPswpOverlay();
              document.documentElement.classList.add('pswp-open');
              document.body.classList.add('pswp-open');
              if (window.lenisInstance && typeof window.lenisInstance.stop === 'function') {
                  window.lenisInstance.stop();
              }
          });
          lightbox.on('openingAnimationEnd', () => {
              hidePswpOverlay();
          });
          lightbox.on('closingAnimationEnd', () => {
              hidePswpOverlay();
              document.documentElement.classList.remove('pswp-open');
              document.body.classList.remove('pswp-open');
              if (window.lenisInstance && typeof window.lenisInstance.start === 'function') {
                  window.lenisInstance.start();
              }
          });
          lightbox.init();
          updatePsCaption();
          
          // Adjust image to fit in a container
          const fitToContainer = () => {
            const pswp = lightbox;
            const s = pswp.currSlide;
            if (!s || !s.width || !s.height) return;
            
            // Define max dimensions for the container
            const maxWidth = Math.min(1200, pswp.viewportSize.x * 0.6);
            const maxHeight = Math.min(800, pswp.viewportSize.y * 0.7);
            
            // Calculate scale to fit within container
            const scaleW = maxWidth / s.width;
            const scaleH = maxHeight / s.height;
            const scale = Math.min(scaleW, scaleH, 1); // Don't upscale
            
            // Center the image
            const x = (pswp.viewportSize.x - (s.width * scale)) / 2;
            const y = (pswp.viewportSize.y - (s.height * scale)) / 2;
            
            // Apply zoom and pan
            s.zoomLevels.initial = scale;
            s.zoomLevels.secondary = scale;
            s.zoomLevels.max = scale;
            s.zoomLevels.min = scale;
            s.pan.x = x;
            s.pan.y = y;
            
            // Update content
            s.updateContentSize(true);
          };
          lightbox.on('change', fitToContainer);
          lightbox.on('resize', fitToContainer);
          
                setTimeout(fitToContainer, 100); // Initial sizing
        }
    }).catch((err) => {
        hidePswpOverlay();
        if (!__psWarned) {
          console.warn('PhotoSwipe not available:', err);
          __psWarned = true;
        }
    });
}

/* PhotoSwipe custom UI styles (minimal BN silver) */
const pswpStyle = document.createElement('style');
pswpStyle.innerHTML = `
  .pswp__bullets-indicator { display:flex; gap:8px; position:absolute; bottom:24px; left:50%; transform:translateX(-50%); z-index:30; }
  .pswp__bullet { width:8px; height:8px; border-radius:50%; background:#C0C0C0; }
  .pswp__bullet--active { background:#000; }
  html.dark .pswp__bullet--active { background:#fff; }
  /* Must stay ABOVE the zoomed image (z-index:30) so the caption is
     never hidden behind the photo at full screen / during the open
     animation. This injected <style> is the effective rule (appended to
     <head> after the linked CSS), so the value lives here too. */
  /* !important: beats PhotoSwipe core .pswp .pswp__hide-on-close
     (z-index:10, specificity 0,2,0) so the caption stays above the
     zoomed image (z-index:30) and is never hidden behind the photo.
     NOTE: no backticks in this comment — this whole block is a JS
     template literal, backticks would terminate it. */
  .pswp__custom-caption { z-index: 35 !important; }
  .pswp__custom-caption .cc-title { font-weight:600; margin-bottom:6px; }
  .pswp__custom-caption .cc-line { display:inline-flex; flex-wrap:wrap; gap:8px; justify-content:center; }
  .pswp__custom-caption .cc-item { display:inline-flex; gap:6px; align-items:center; color:inherit; }
  .pswp__custom-caption .cc-item i { color:inherit; opacity:0.7; }
  .pswp__custom-caption .cc-sep { opacity:.6; margin: 0 6px; }
`;
document.head.appendChild(pswpStyle);

const PSWP_OVERLAY_ID = 'pswp-open-overlay';
const ensurePswpOverlay = () => {
    let el = document.getElementById(PSWP_OVERLAY_ID);
    if (!el) {
        el = document.createElement('div');
        el.id = PSWP_OVERLAY_ID;
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
    }
    return el;
};
let pswpOverlayFailSafeTimer = null;
const showPswpOverlay = () => {
    const el = ensurePswpOverlay();
    el.classList.add('is-visible');
};
const hidePswpOverlay = () => {
    const el = document.getElementById(PSWP_OVERLAY_ID);
    if (el) el.classList.remove('is-visible');
};
const schedulePswpOverlayFailSafe = () => {
    if (pswpOverlayFailSafeTimer) {
        clearTimeout(pswpOverlayFailSafeTimer);
    }
    pswpOverlayFailSafeTimer = setTimeout(() => {
        if (!document.documentElement.classList.contains('pswp-open')) {
            hidePswpOverlay();
        }
    }, 600);
};

// Delegated click for anchors: open PhotoSwipe
document.addEventListener('click', (e) => {
    const a = e.target.closest('a.pswp-link');
    if (!a) return;
    e.preventDefault();
    const scrollY = window.scrollY || window.pageYOffset || 0;
    showPswpOverlay();
    schedulePswpOverlayFailSafe();
    window.requestAnimationFrame(() => {
        if (!document.documentElement.classList.contains('pswp-open')) {
            window.scrollTo({ top: scrollY, left: 0, behavior: 'auto' });
        }
    });
    // Pick the container based on clicked anchor context to ensure correct indexing
    const wrap = a.closest('.m-desktop-wrap, .m-tablet-wrap, .m-mobile-wrap');
    const containerEl = wrap || document.getElementById('images-gallery');
    let anchors = Array.from(containerEl.querySelectorAll('a.pswp-link:not(.pswp-is-duplicate)'));
    let target = a;
    if (a.classList.contains('pswp-is-duplicate')) {
      // Map duplicate to its corresponding original by index within the same container
      const dups = Array.from(containerEl.querySelectorAll('a.pswp-link.pswp-is-duplicate'));
      const di = dups.indexOf(a);
      if (di > -1 && anchors[di]) target = anchors[di];
    }
    const idx = Math.max(0, anchors.indexOf(target));
    if (window.__pswpLightbox && typeof window.__pswpLightbox.loadAndOpen === 'function') {
        window.__pswpLightbox.loadAndOpen(idx);
    } else {
        // Fallback if lightbox isn't initialized properly
        const items = anchors.map(anchor => ({
            src: anchor.getAttribute('href'),
            width: parseInt(anchor.getAttribute('data-pswp-width') || '1600', 10),
            height: parseInt(anchor.getAttribute('data-pswp-height') || '1067', 10),
            element: anchor, // Preserve anchor reference for data attributes
            caption_html: (function(){
              const dp = anchor.getAttribute('data-pswp-caption');
              if (dp && dp.trim()) return dp;
              const h = anchor.querySelector('.hidden-caption-content');
              if (h && h.innerHTML.trim()) return h.innerHTML;
              const img = anchor.querySelector('img');
              return (img && img.getAttribute('alt')) || '';
            })()
        }));
        openLightboxAt(idx);
    }
});
// Initialize PhotoSwipe on page load
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure DOM is fully ready
    setTimeout(initLightbox, 100);
});

// Simple Lightbox fallback implementation
let __slbItems = []; let __slbIndex = 0;
function ensureSlbDom() {
    if (document.getElementById('slb-overlay')) return;
    const wrap = document.createElement('div');
    wrap.id = 'slb-overlay';
    wrap.innerHTML = `
      <div id="slb-content">
        <button class="slb-close" aria-label="${__cimaiseHtml(__cimaiseLayout.i18n.close)}">✕</button>
        <button class="slb-btn slb-prev" aria-label="${__cimaiseHtml(__cimaiseLayout.i18n.previous)}">‹</button>
        <img id="slb-img" alt="" />
        <button class="slb-btn slb-next" aria-label="${__cimaiseHtml(__cimaiseLayout.i18n.next)}">›</button>
        <div id="slb-caption"></div>
      </div>`;
    document.body.appendChild(wrap);
    wrap.addEventListener('click', (e)=>{ if (e.target.id === 'slb-overlay') fallbackClose(); });
    wrap.querySelector('.slb-close').addEventListener('click', fallbackClose);
    wrap.querySelector('.slb-prev').addEventListener('click', ()=> fallbackShowIndex(__slbIndex-1));
    wrap.querySelector('.slb-next').addEventListener('click', ()=> fallbackShowIndex(__slbIndex+1));
    document.addEventListener('keydown', (e)=>{
      if (!wrap.classList.contains('active')) return;
      if (e.key === 'Escape') fallbackClose();
      if (e.key === 'ArrowLeft') fallbackShowIndex(__slbIndex-1);
      if (e.key === 'ArrowRight') fallbackShowIndex(__slbIndex+1);
    });
}
function fallbackOpenLightbox(items, startIndex){
    ensureSlbDom(); __slbItems = items || []; __slbIndex = Math.max(0, startIndex||0);
    document.getElementById('slb-overlay').classList.add('active');
    fallbackShowIndex(__slbIndex);
}
function fallbackShowIndex(idx){
    if (!__slbItems.length) return; __slbIndex = (idx + __slbItems.length) % __slbItems.length;
    const it = __slbItems[__slbIndex];
    const img = document.getElementById('slb-img'); img.src = it.src; img.alt = it.alt || '';
    const cap = document.getElementById('slb-caption');
    cap.innerHTML = it.caption_html || (it.caption ? '<div>' + it.caption + '</div>' : '');
}
function fallbackClose(){ const o = document.getElementById('slb-overlay'); if (o) o.classList.remove('active'); }
