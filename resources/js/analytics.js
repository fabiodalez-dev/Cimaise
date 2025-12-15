/**
 * photoCMS Analytics Tracker
 * Lightweight, cookieless analytics tracking
 */
class PhotoCMSAnalytics {
    constructor(options = {}) {
        this.options = {
            endpoint: '/api/analytics/track',
            sessionTimeout: 30 * 60 * 1000, // 30 minutes
            trackPageViews: true,
            trackEvents: true,
            debug: false,
            ...options
        };

        this.sessionId = this.generateSessionId();
        this.startTime = Date.now();
        this.lastActivity = Date.now();
        this.pageViewId = null;
        
        this.init();
    }

    /**
     * Initialize tracking
     */
    init() {
        if (this.options.trackPageViews) {
            this.trackPageView();
        }

        // Track user activity
        this.bindEvents();
        
        // Send data on page unload
        this.bindUnloadEvents();

        this.log('Analytics initialized', { sessionId: this.sessionId });
    }

    /**
     * Generate session ID (no cookies)
     */
    generateSessionId() {
        // Use sessionStorage for session persistence across page loads
        let sessionId = sessionStorage.getItem('photocms_session_id');
        
        if (!sessionId) {
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('photocms_session_id', sessionId);
            sessionStorage.setItem('photocms_session_start', Date.now().toString());
        }

        // Check session timeout
        const sessionStart = parseInt(sessionStorage.getItem('photocms_session_start') || '0');
        if (Date.now() - sessionStart > this.options.sessionTimeout) {
            // Session expired, create new one
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('photocms_session_id', sessionId);
            sessionStorage.setItem('photocms_session_start', Date.now().toString());
        }

        return sessionId;
    }

    /**
     * Get current page data
     */
    getPageData() {
        const url = new URL(window.location.href);
        const pageType = this.detectPageType();
        
        return {
            page_url: url.pathname + url.search,
            page_title: document.title,
            page_type: pageType,
            album_id: this.extractAlbumId(),
            category_id: this.extractCategoryId(),
            tag_id: this.extractTagId(),
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight,
            referrer: document.referrer,
            user_agent: navigator.userAgent,
            landing_page: sessionStorage.getItem('photocms_landing_page') || url.pathname,
            is_404: pageType === '404' || document.title.includes('404') || document.title.includes('Not Found')
        };
    }

    /**
     * Detect page type from URL
     */
    detectPageType() {
        const path = window.location.pathname;
        
        // Check if this is a 404 page
        if (document.title.includes('404') || document.title.includes('Not Found') || 
            document.querySelector('.error-404') || document.querySelector('[data-page="404"]')) {
            return '404';
        }
        
        if (path === '/' || path === '/index.php') return 'home';
        if (path.includes('/album/')) return 'album';
        if (path.includes('/category/')) return 'category';
        if (path.includes('/tag/')) return 'tag';
        if (path.includes('/about')) return 'about';
        if (path.includes('/gallery')) return 'gallery';
        if (path.includes('/galleries')) return 'galleries';
        
        return 'page';
    }

    /**
     * Extract album ID from URL or meta tags
     */
    extractAlbumId() {
        // Try to get from meta tag
        const metaAlbumId = document.querySelector('meta[name="album-id"]');
        if (metaAlbumId) return parseInt(metaAlbumId.content);

        // Try to extract from URL
        const albumMatch = window.location.pathname.match(/\/album\/[^\/]+/);
        if (albumMatch) {
            // Get album ID from data attribute or global variable
            return window.albumId || null;
        }

        return null;
    }

    /**
     * Extract category ID from URL or meta tags
     */
    extractCategoryId() {
        const metaCategoryId = document.querySelector('meta[name="category-id"]');
        if (metaCategoryId) return parseInt(metaCategoryId.content);
        return window.categoryId || null;
    }

    /**
     * Extract tag ID from URL or meta tags
     */
    extractTagId() {
        const metaTagId = document.querySelector('meta[name="tag-id"]');
        if (metaTagId) return parseInt(metaTagId.content);
        return window.tagId || null;
    }

    /**
     * Track page view
     */
    async trackPageView() {
        // Store landing page for new sessions
        if (!sessionStorage.getItem('photocms_landing_page')) {
            sessionStorage.setItem('photocms_landing_page', window.location.pathname);
        }

        const data = {
            type: 'pageview',
            session_id: this.sessionId,
            timestamp: Date.now(),
            ...this.getPageData()
        };

        await this.sendData(data);
        this.log('Page view tracked', data);
    }

    /**
     * Track custom event
     */
    async trackEvent(eventData) {
        if (!this.options.trackEvents) return;

        const data = {
            type: 'event',
            session_id: this.sessionId,
            timestamp: Date.now(),
            event_type: eventData.type || 'custom',
            event_category: eventData.category || '',
            event_action: eventData.action || '',
            event_label: eventData.label || '',
            event_value: eventData.value || null,
            page_url: window.location.pathname,
            album_id: this.extractAlbumId(),
            image_id: eventData.image_id || null
        };

        await this.sendData(data);
        this.log('Event tracked', data);
    }

    /**
     * Bind activity tracking events
     */
    bindEvents() {
        // Track scroll depth
        let maxScrollDepth = 0;
        const trackScroll = () => {
            const scrollDepth = Math.round(
                (window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100
            );

            if (scrollDepth > maxScrollDepth) {
                maxScrollDepth = scrollDepth;
            }

            this.lastActivity = Date.now();
        };

        // Track clicks on images and links
        const trackClicks = (e) => {
            const target = e.target.closest('a, img, button');
            if (!target) return;

            const eventData = {
                type: 'click',
                category: 'engagement',
                action: target.tagName.toLowerCase(),
                label: target.getAttribute('alt') || target.textContent?.trim() || target.href
            };

            // Special handling for image downloads
            if (target.hasAttribute('download') || target.href?.includes('download')) {
                eventData.type = 'download';
                eventData.category = 'conversion';
                eventData.action = 'image_download';
                eventData.image_id = target.dataset.imageId || this.extractImageIdFromUrl(target.href);
                eventData.album_id = this.extractAlbumId();
            }

            this.trackEvent(eventData);
        };

        // Throttled scroll tracking
        let scrollTimeout;
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(trackScroll, 100);
        }, { passive: true });

        // Click tracking
        document.addEventListener('click', trackClicks, { passive: true });

        // Track time on page
        this.timeOnPageInterval = setInterval(() => {
            this.updateTimeOnPage();
        }, 15000); // Update every 15 seconds

        // Bind lightbox/PhotoSwipe tracking
        this.bindLightboxTracking();
    }

    /**
     * Bind lightbox/PhotoSwipe tracking
     */
    bindLightboxTracking() {
        // Deduplication: track last lightbox open timestamp to avoid duplicate events
        let lastLightboxOpen = 0;
        const DEDUP_THRESHOLD_MS = 500;

        // Track PhotoSwipe lightbox opens via click
        document.addEventListener('click', (e) => {
            const lightboxTrigger = e.target.closest('[data-pswp-src], .gallery-item a, .pswp-gallery a');
            if (lightboxTrigger) {
                const now = Date.now();
                if (now - lastLightboxOpen < DEDUP_THRESHOLD_MS) {
                    return; // Skip duplicate event
                }
                lastLightboxOpen = now;

                const imageId = lightboxTrigger.dataset.imageId ||
                               lightboxTrigger.closest('[data-image-id]')?.dataset.imageId ||
                               this.extractImageIdFromUrl(lightboxTrigger.href);

                this.trackEvent({
                    type: 'lightbox_open',
                    category: 'engagement',
                    action: 'lightbox_open',
                    label: lightboxTrigger.getAttribute('alt') || lightboxTrigger.title || '',
                    image_id: imageId,
                    album_id: this.extractAlbumId()
                });
            }
        }, { passive: true });

        // Listen for PhotoSwipe custom events if available
        window.addEventListener('pswp:open', (e) => {
            const now = Date.now();
            if (now - lastLightboxOpen < DEDUP_THRESHOLD_MS) {
                return; // Skip duplicate event
            }
            lastLightboxOpen = now;

            const detail = e.detail || {};
            this.trackEvent({
                type: 'lightbox_open',
                category: 'engagement',
                action: 'lightbox_open',
                image_id: detail.imageId || null,
                album_id: this.extractAlbumId()
            });
        });

        window.addEventListener('pswp:close', (e) => {
            const detail = e.detail || {};
            this.trackEvent({
                type: 'lightbox_close',
                category: 'engagement',
                action: 'lightbox_close',
                value: detail.viewedImages || 1,
                album_id: this.extractAlbumId()
            });
        });
    }

    /**
     * Extract image ID from URL
     */
    extractImageIdFromUrl(url) {
        if (!url) return null;
        // Try to extract numeric ID from URL patterns like /image/123 or image_123.jpg
        const match = url.match(/(?:image[_\/]?)(\d+)/i) || url.match(/\/(\d+)(?:_\w+)?\.(?:jpg|jpeg|png|webp|avif)/i);
        return match ? parseInt(match[1]) : null;
    }

    /**
     * Public method to track lightbox open (can be called from PhotoSwipe init)
     */
    trackLightboxOpen(imageId, albumId) {
        this.trackEvent({
            type: 'lightbox_open',
            category: 'engagement',
            action: 'lightbox_open',
            image_id: imageId,
            album_id: albumId || this.extractAlbumId()
        });
    }

    /**
     * Public method to track image download
     */
    trackDownload(imageId, albumId, filename) {
        this.trackEvent({
            type: 'download',
            category: 'conversion',
            action: 'image_download',
            label: filename || '',
            image_id: imageId,
            album_id: albumId || this.extractAlbumId()
        });
    }

    /**
     * Bind page unload events
     */
    bindUnloadEvents() {
        const sendFinalData = () => {
            const timeOnPage = Date.now() - this.startTime;
            const data = {
                type: 'page_end',
                session_id: this.sessionId,
                time_on_page: Math.round(timeOnPage / 1000),
                timestamp: Date.now()
            };

            // Use sendBeacon for reliable delivery
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
                navigator.sendBeacon(this.options.endpoint, blob);
            }
        };

        window.addEventListener('beforeunload', sendFinalData);
        window.addEventListener('pagehide', sendFinalData);
        
        // Also track visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                sendFinalData();
            }
        });
    }

    /**
     * Update time on page
     */
    updateTimeOnPage() {
        const timeOnPage = Date.now() - this.startTime;
        const data = {
            type: 'heartbeat',
            session_id: this.sessionId,
            time_on_page: Math.round(timeOnPage / 1000),
            timestamp: Date.now()
        };

        this.sendData(data);
    }

    /**
     * Send data to server with retry logic
     */
    async sendData(data, retryCount = 0) {
        try {
            const response = await fetch(this.options.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                keepalive: true
            });

            // For 404 pages, we want to track them even if the endpoint returns an error
            // But we should still log the error for debugging
            if (!response.ok) {
                // Special handling for 404 pages - log but don't treat as critical error
                if (data.page_type === '404' || data.is_404) {
                    this.log('404 page tracked (endpoint returned ' + response.status + ')', data);
                } else {
                    // For non-404 errors, we still want to know about them but not show them prominently
                    this.log('Data sent (endpoint returned ' + response.status + ')', data);
                }
                
                // Retry logic for server errors (5xx) but not for client errors (4xx)
                if (response.status >= 500 && retryCount < 2) {
                    setTimeout(() => {
                        this.sendData(data, retryCount + 1);
                    }, Math.pow(2, retryCount) * 1000); // Exponential backoff
                }
            } else {
                this.log('Data sent successfully', data);
            }
        } catch (error) {
            // For 404 pages, we don't want to show errors to users
            if (data.page_type !== '404' && !data.is_404) {
                // For non-404 errors, log them but use console.debug to reduce visibility
                console.debug('[PhotoCMS Analytics] Network error sending data:', error.message);
                
                // Retry logic for network errors
                if (retryCount < 2) {
                    setTimeout(() => {
                        this.sendData(data, retryCount + 1);
                    }, Math.pow(2, retryCount) * 1000); // Exponential backoff
                }
            } else {
                // For 404 pages, use even less visibility
                if (this.options.debug) {
                    console.debug('[PhotoCMS Analytics] 404 page tracked (network error)', error.message);
                }
            }
        }
    }

    /**
     * Log debug information
     */
    log(message, data = null) {
        if (this.options.debug) {
            // For 404 tracking, use console.debug instead of console.log to reduce visibility
            if (message.includes('404')) {
                console.debug(`[PhotoCMS Analytics] ${message}`, data);
            } else {
                console.log(`[PhotoCMS Analytics] ${message}`, data);
            }
        }
    }

    /**
     * Public method to track custom events
     */
    track(eventType, eventData = {}) {
        return this.trackEvent({
            type: eventType,
            ...eventData
        });
    }

    /**
     * Destroy analytics instance
     */
    destroy() {
        if (this.timeOnPageInterval) {
            clearInterval(this.timeOnPageInterval);
        }
        this.log('Analytics destroyed');
    }
}

// Auto-initialize if enabled
window.addEventListener('DOMContentLoaded', () => {
    // Check if analytics is enabled (can be set by server)
    if (window.photoCMSAnalyticsEnabled !== false) {
        try {
            window.photoCMSAnalytics = new PhotoCMSAnalytics(window.photoCMSAnalyticsConfig || {});
        } catch (error) {
            // Silently fail if analytics initialization fails
            if (window.photoCMSAnalyticsConfig && window.photoCMSAnalyticsConfig.debug) {
                console.debug('[PhotoCMS Analytics] Failed to initialize:', error.message);
            }
        }
    }
});