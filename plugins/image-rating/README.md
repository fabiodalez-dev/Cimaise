# Image Rating Plugin

Professional 5-star rating system for images with advanced filtering and sorting capabilities.

## Features

- ⭐ **5-Star Rating System**: Rate images from 1 to 5 stars
- 📊 **Statistics Dashboard**: Average rating, distribution charts
- 🔄 **Bulk Operations**: Rate multiple images at once
- 🎯 **Smart Filtering**: Filter by minimum rating
- 📈 **Auto-Sorting**: Sort images by rating automatically
- 💡 **Frontend Display**: Show ratings in lightbox
- 📱 **Responsive UI**: Works on mobile and desktop

## Installation

Auto-loaded from `plugins/` directory.

## Database Schema

Creates table: `plugin_image_ratings`

```sql
CREATE TABLE plugin_image_ratings (
    image_id INTEGER PRIMARY KEY,
    rating INTEGER CHECK(rating >= 0 AND rating <= 5),
    rated_at DATETIME,
    rated_by INTEGER NULL,
    FOREIGN KEY (image_id) REFERENCES images(id)
);
```

## Usage

### Admin Panel

**Single Image Rating**:
1. Edit image
2. Find "Rating" field (Quality group)
3. Select 1-5 stars
4. Save

**Bulk Rating**:
1. Go to Images list
2. Select multiple images (checkbox)
3. Choose bulk action: "Rate 5 Stars", "Rate 4 Stars", etc.
4. Apply

**View Statistics**:
- Dashboard widget shows rating distribution
- Average rating across portfolio
- Top rated images

### Frontend

**Lightbox**:
- Star rating displayed in image caption
- Format: "⭐⭐⭐⭐⭐ (5/5)"

**Filtering** (if enabled in settings):
- Only images with rating >= threshold are shown
- Threshold configurable: 0, 3+, 4+, 5 only

**Sorting** (if enabled in settings):
- Images auto-sorted by rating DESC
- Highest rated images shown first

## Settings

**Enable Rating System**
- Master toggle for plugin
- Default: `true`

**Show Ratings in Frontend**
- Display stars in public galleries
- Default: `true`

**Auto-Sort by Rating**
- Automatically order images by rating
- Default: `false` (manual sort preserved)

**Minimum Rating for Display**
- Filter threshold: 0, 3+, 4+, 5 only
- Default: `0` (show all)

## API

### Get Rating

```php
$rating = $ratingService->getRating($imageId);
// Returns: 0-5 (int)
```

### Set Rating

```php
$success = $ratingService->setRating($imageId, 5, $userId);
// Returns: bool
```

### Bulk Rating

```php
$imageIds = [1, 2, 3, 4, 5];
$count = $ratingService->bulkSetRating($imageIds, 4, $userId);
// Returns: number of successful ratings
```

### Get Top Rated

```php
$topImages = $ratingService->getTopRated(10);
// Returns: array of image IDs
```

### Get Statistics

```php
$stats = $ratingService->getStatistics();
/*
[
    'average' => 4.25,
    'total_rated' => 150,
    'distribution' => [
        ['rating' => 5, 'count' => 60],
        ['rating' => 4, 'count' => 50],
        ['rating' => 3, 'count' => 25],
        ['rating' => 2, 'count' => 10],
        ['rating' => 1, 'count' => 5]
    ]
]
*/
```

### Get Unrated Images

```php
$unrated = $ratingService->getUnratedImages($albumId);
// Returns: array of image IDs without rating
```

## Hooks Used

- `photocms_init` - Setup database
- `admin_list_columns` - Add rating column
- `admin_bulk_actions` - Add bulk rating actions
- `admin_form_fields` - Add rating field to edit form
- `lightbox_config` - Display rating in lightbox
- `album_view_images` - Sort/filter by rating
- `image_after_upload` - Initialize rating
- `settings_tabs` - Add settings tab
- `admin_css` / `admin_js` - Load assets

## Use Cases

### Professional Portfolio
- Showcase only 4-5 star images publicly
- Keep lower rated in archive
- Client selects favorites (5 stars)

### Stock Photography
- Rate image quality
- Sort by quality for quick selection
- Export only top rated

### Photo Editing Workflow
1. Import all photos (unrated)
2. First pass: rate 1-5
3. Second pass: edit 4-5 stars only
4. Publish 5 stars to portfolio

### Client Delivery
- Client rates images they want
- Export 4-5 star selections
- Faster delivery process

## Keyboard Shortcuts (Future)

- `1-5` keys: Quick rate 1-5 stars
- `0` key: Remove rating
- `Shift+Up/Down`: Navigate and rate

## Integrations

### Export by Rating

```php
// Custom export script
$fiveStars = $ratingService->getImagesByRating(5);
$images = $db->query("SELECT * FROM images WHERE id IN (" . implode(',', $fiveStars) . ")");

foreach ($images as $image) {
    copy($image['original_path'], "/export/five-stars/{$image['filename']}");
}
```

### Social Auto-Post

```php
// Post only 5-star images
Hooks::addAction('album_publish', function($albumId) use ($ratingService) {
    $images = $db->query("SELECT * FROM images WHERE album_id = ?", [$albumId]);

    foreach ($images as $image) {
        if ($ratingService->getRating($image['id']) === 5) {
            // Auto-post to Instagram
            SocialPoster::instagram($image['path'], $image['caption']);
        }
    }
});
```

## Performance

- Indexed image_id for fast lookups
- Single query for bulk operations
- Cached statistics (future)

## Future Enhancements

- [ ] AI auto-rating (blur detection, composition analysis)
- [ ] Collaborative rating (multiple users)
- [ ] Rating history/changelog
- [ ] Half-star ratings (0.5 increments)
- [ ] Custom rating scales (1-10, thumbs up/down)

## License

MIT

## Author

photoCMS Team
