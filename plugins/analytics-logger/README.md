# Analytics Logger Plugin

Advanced analytics extension for photoCMS with custom event tracking and detailed logging.

## Features

- ✅ Custom events tracking (login, album creation, image uploads)
- ✅ Enhanced pageview data (referrer analysis, device fingerprint, time buckets)
- ✅ Dashboard widget with event summary
- ✅ Export enhancements (custom columns in CSV)
- ✅ Dedicated database table for custom events
- ✅ Automatic table creation on first run

## Installation

Auto-loaded from `plugins/` directory.

## Database Schema

Creates table: `plugin_analytics_custom_events`

```sql
CREATE TABLE plugin_analytics_custom_events (
    id INTEGER PRIMARY KEY,
    session_id VARCHAR(64),
    event_type VARCHAR(50),
    event_category VARCHAR(100),
    event_action VARCHAR(100),
    event_label VARCHAR(255),
    event_value INTEGER,
    user_id INTEGER,
    metadata TEXT, -- JSON
    created_at DATETIME
);
```

## Custom Events Tracked

### User Events
- **user_login**: User authentication
  - Category: `authentication`
  - Metadata: role, timestamp

### Content Events
- **album_created**: New album creation
  - Category: `content`
  - Metadata: category_id, published status

### Media Events
- **image_uploaded**: Image upload
  - Category: `media`
  - Metadata: album_id, dimensions, file size, MIME type

## Enhanced Pageview Data

Standard pageview events are enhanced with:

- **referrer_type**: `direct`, `social`, `search`, `referral`
- **device_fingerprint**: Unique device identifier (hashed)
- **time_bucket**: `morning`, `afternoon`, `evening`, `night`

## Dashboard Widget

Shows summary of custom events for last 7 days:
- Event type
- Count
- Top 10 most frequent

## Hooks Used

- `photocms_init` - Database setup
- `user_after_login` - Track logins
- `album_after_create` - Track album creation
- `image_after_upload` - Track uploads
- `analytics_track_pageview` (filter) - Enhance pageview data
- `analytics_export_data` (filter) - Add custom columns
- `admin_dashboard_widgets` (filter) - Add widget

## Querying Custom Events

```php
// Get all login events last 24h
$sql = "
    SELECT * FROM plugin_analytics_custom_events
    WHERE event_type = 'user_login'
    AND created_at >= datetime('now', '-1 day')
    ORDER BY created_at DESC
";

// Get image upload stats by user
$sql = "
    SELECT user_id, COUNT(*) as uploads
    FROM plugin_analytics_custom_events
    WHERE event_type = 'image_uploaded'
    GROUP BY user_id
    ORDER BY uploads DESC
";
```

## Extending

Add your own custom events:

```php
Hooks::addAction('my_custom_action', function() {
    $logger = new AnalyticsLoggerPlugin();
    $logger->logCustomEvent('my_event', [
        'category' => 'custom',
        'action' => 'something_happened',
        'label' => 'Custom Event',
        'metadata' => ['key' => 'value']
    ]);
});
```

## Performance

- Indexed columns for fast queries
- Async logging (doesn't block main thread)
- Cleanup old events (>90 days) recommended via cron

## Future Enhancements

- [ ] Real-time event streaming (WebSocket)
- [ ] Machine learning event prediction
- [ ] Anomaly detection (unusual patterns)
- [ ] Integration with external analytics (GA4, Matomo)

## License

MIT

## Author

photoCMS Team
