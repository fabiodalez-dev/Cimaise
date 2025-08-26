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
            landing_page: sessionStorage.getItem('photocms_landing_page') || url.pathname
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
     * Send data to server
     */
    async sendData(data) {
        try {
            const response = await fetch(this.options.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                keepalive: true
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
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
            console.log(`[PhotoCMS Analytics] ${message}`, data);
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
        window.photoCMSAnalytics = new PhotoCMSAnalytics(window.photoCMSAnalyticsConfig || {});
    }
});