/**
 * Cimaise Analytics Tracker
 * Lightweight, cookieless analytics tracking
 */
class CimaiseAnalytics {
    constructor(options = {}) {
        this.options = {
            endpoint: '/api/analytics/track',
            sessionTimeout: 30 * 60 * 1000, // 30 minutes
            trackPageViews: true,
            trackEvents: true,
            debug: false,
            ...options
        };

        // Guard state to auto-disable after repeated failures
        this.failureCount = 0;
        this.disabled = false;

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
        // Quick health check to avoid noisy errors if endpoint is misrouted
        try {
            fetch(this.options.endpoint.replace(/\/track$/, '/ping'), { method: 'GET', cache: 'no-store' })
                .then(r => {
                    if (!r.ok && r.status !== 204) {
                        this.disabled = true;
                        this.log('Analytics disabled: ping failed', { status: r.status });
                    }
                })
                .catch(() => { this.disabled = true; });
        } catch(e) {}

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
        let sessionId = sessionStorage.getItem('cimaise_session_id');
        
        if (!sessionId) {
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('cimaise_session_id', sessionId);
            sessionStorage.setItem('cimaise_session_start', Date.now().toString());
        }

        // Check session timeout
        const sessionStart = parseInt(sessionStorage.getItem('cimaise_session_start') || '0');
        if (Date.now() - sessionStart > this.options.sessionTimeout) {
            // Session expired, create new one
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('cimaise_session_id', sessionId);
            sessionStorage.setItem('cimaise_session_start', Date.now().toString());
        }

        return sessionId;
    }

    /**
     * Get current page data
     */
    getPageData() {
        const url = new URL(window.location.href);
        
        return {
            page_url: url.pathname + url.search,
            page_title: document.title,
            page_type: this.detectPageType(),
            album_id: this.extractAlbumId(),
            category_id: this.extractCategoryId(),
            tag_id: this.extractTagId(),
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight,
            referrer: document.referrer,
            user_agent: navigator.userAgent,
            landing_page: sessionStorage.getItem('cimaise_landing_page') || url.pathname
        };
    }

    /**
     * Detect page type from URL
     */
    detectPageType() {
        const path = window.location.pathname;
        
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
        if (!sessionStorage.getItem('cimaise_landing_page')) {
            sessionStorage.setItem('cimaise_landing_page', window.location.pathname);
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
                eventData.category = 'file';
                eventData.action = 'image_download';
                eventData.image_id = target.dataset.imageId;
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
    }

    /**
     * Bind page unload events
     */
    bindUnloadEvents() {
        const self = this;
        const sendFinalData = () => {
            if (self.disabled) return;
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
     * Send data to server
     */
    async sendData(data) {
        try {
            if (this.disabled) return;
            const response = await fetch(this.options.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                keepalive: true
            });

            if (!response.ok) {
                // Increase failure count on 4xx/5xx; auto-disable after 2 failures
                this.failureCount += 1;
                if (this.failureCount >= 2) {
                    this.disabled = true;
                    this.options.trackEvents = false;
                    if (this.timeOnPageInterval) { clearInterval(this.timeOnPageInterval); this.timeOnPageInterval = null; }
                    this.log('Analytics disabled after repeated errors', { status: response.status });
                    return;
                }
                throw new Error(`HTTP error! status: ${response.status}`);
            } else {
                // Reset failures on success
                this.failureCount = 0;
            }

            this.log('Data sent successfully', data);
        } catch (error) {
            this.log('Error sending data', error);
        }
    }

    /**
     * Log debug information
     */
    log(message, data = null) {
        if (this.options.debug) {
            console.log(`[Cimaise Analytics] ${message}`, data);
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
    if (window.CimaiseAnalyticsEnabled !== false) {
        window.CimaiseAnalytics = new CimaiseAnalytics(window.CimaiseAnalyticsConfig || {});
    }
});
