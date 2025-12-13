# Plugin System & Hooks Documentation

photoCMS includes a comprehensive plugin system with hooks that allow you to extend functionality without modifying core files. This document describes all available hooks and how to use them.

## Overview

The plugin system uses a WordPress-style hook architecture with two types of hooks:

- **Actions**: Execute custom code at specific points (side effects)
- **Filters**: Modify data as it passes through the system

## Using Hooks in Plugins

### Registering Actions

```php
use App\Support\Hooks;

// Add action with default priority (10)
Hooks::addAction('hook_name', function($context) {
    // Your code here
    echo '<div>Custom content</div>';
});

// Add action with custom priority (lower = earlier)
Hooks::addAction('hook_name', function($context) {
    // Your code here
}, 5);
```

### Registering Filters

```php
use App\Support\Hooks;

// Filter to modify data
Hooks::addFilter('filter_name', function($value, $context) {
    // Modify and return the value
    return $modified_value;
});
```

## Available Hooks

### Admin Sidebar Hooks

Located in: `app/Views/admin/_layout.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `admin_sidebar_before` | Before all sidebar sections | `base_path` |
| `admin_sidebar_navigation` | Add custom navigation items | `base_path` |
| `admin_sidebar_metadata` | Add custom metadata menu items | `base_path` |
| `admin_sidebar_section` | Add a completely new sidebar section | `base_path` |
| `admin_sidebar_system` | Add custom system menu items | `base_path` |
| `admin_sidebar_after` | After all sidebar sections | `base_path` |

**Example - Add custom sidebar navigation:**

```php
Hooks::addAction('admin_sidebar_navigation', function($ctx) {
    $basePath = $ctx['base_path'] ?? '';
    echo '<li class="nav-item">';
    echo '<a href="' . $basePath . '/admin/my-plugin" class="nav-link">';
    echo '<i class="fas fa-plug mr-3"></i>My Plugin';
    echo '</a></li>';
});
```

---

### Album Edit Page Hooks

Located in: `app/Views/admin/albums/edit.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `admin_album_edit_before` | Before the entire edit page | `album`, `base_path` |
| `admin_album_edit_header_actions` | Add custom header buttons | `album`, `base_path` |
| `admin_album_form_basic_info_fields` | Add fields inside basic info card | `album`, `base_path` |
| `admin_album_form_basic_info_after` | Add cards after basic info | `album`, `base_path` |
| `admin_album_form_images_after` | After images section | `album`, `images`, `base_path` |
| `admin_album_form_main_after` | After all main content cards | `album`, `base_path` |
| `admin_album_sidebar_before` | Before sidebar cards | `album`, `base_path` |
| `admin_album_sidebar_publishing_after` | After publishing settings | `album`, `base_path` |
| `admin_album_equipment_fields` | Add custom equipment fields | `album`, `base_path`, `cameras`, `lenses`, `films`, `developers`, `labs` |
| `admin_album_sidebar_equipment_after` | After equipment card | `album`, `base_path` |
| `admin_album_sidebar_after` | Add sidebar cards at end | `album`, `base_path` |
| `admin_album_form_end` | Add hidden inputs to form | `album`, `base_path` |
| `admin_album_footer_actions` | Add custom footer buttons | `album`, `base_path` |
| `admin_album_image_modal_fields` | Add fields to image modal | `album`, `base_path` |
| `admin_album_edit_after` | After entire page | `album`, `base_path` |

**Example - Add custom equipment field (e.g., Film Format):**

```php
Hooks::addAction('admin_album_equipment_fields', function($ctx) {
    $album = $ctx['album'] ?? null;
    $selectedFormat = $album ? ($album->film_format ?? '') : '';
    ?>
    <div>
        <label class="block text-sm font-medium text-black mb-2">Film Format</label>
        <select class="form-input" name="film_format">
            <option value="">Select format...</option>
            <option value="35mm" <?= $selectedFormat === '35mm' ? 'selected' : '' ?>>35mm</option>
            <option value="120" <?= $selectedFormat === '120' ? 'selected' : '' ?>>120 Medium Format</option>
            <option value="4x5" <?= $selectedFormat === '4x5' ? 'selected' : '' ?>>4x5 Large Format</option>
        </select>
    </div>
    <?php
});
```

---

### Album Create Page Hooks

Located in: `app/Views/admin/albums/create.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `admin_album_create_before` | Before the entire create page | `base_path` |
| `admin_album_create_header_actions` | Add custom header buttons | `base_path` |
| `admin_album_create_form_basic_info_fields` | Add fields inside basic info | `base_path` |
| `admin_album_create_form_basic_info_after` | Add cards after basic info | `base_path` |
| `admin_album_create_form_main_after` | After all main content cards | `base_path` |
| `admin_album_create_sidebar_before` | Before sidebar cards | `base_path` |
| `admin_album_create_sidebar_publishing_after` | After publishing settings | `base_path` |
| `admin_album_create_equipment_fields` | Add custom equipment fields | `base_path`, `cameras`, `lenses`, `films`, `developers`, `labs` |
| `admin_album_create_sidebar_equipment_after` | After equipment card | `base_path` |
| `admin_album_create_sidebar_after` | Add sidebar cards at end | `base_path` |
| `admin_album_create_form_end` | Add hidden inputs to form | `base_path` |
| `admin_album_create_footer_actions` | Add custom footer buttons | `base_path` |
| `admin_album_create_after` | After entire page | `base_path` |

---

### Frontend Home Page Hooks

Located in: `app/Views/frontend/home.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `frontend_home_before` | Before all home content | `base_path` |
| `frontend_home_hero_before` | Before hero section | `base_path` |
| `frontend_home_hero_after` | After hero section | `base_path` |
| `frontend_home_gallery_before` | Before infinite gallery | `base_path` |
| `frontend_home_gallery_after` | After infinite gallery | `base_path` |
| `frontend_home_carousel_before` | Before albums carousel | `base_path` |
| `frontend_home_carousel_after` | After albums carousel | `base_path` |
| `frontend_home_after` | After all home content | `base_path` |

**Example - Add announcement banner:**

```php
Hooks::addAction('frontend_home_before', function($ctx) {
    echo '<div class="bg-blue-600 text-white text-center py-2">';
    echo 'New gallery available! Check out our latest work.';
    echo '</div>';
});
```

---

### Frontend Album Page Hooks

Located in: `app/Views/frontend/album.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `frontend_album_before` | Before entire album page | `album`, `images`, `base_path` |
| `frontend_album_header_meta` | Add metadata to album header | `album`, `base_path` |
| `frontend_album_header_after` | After album header section | `album`, `base_path` |
| `frontend_album_content_after` | After album body content | `album`, `base_path` |
| `frontend_album_gallery_before` | Before images gallery | `album`, `images`, `base_path` |
| `frontend_album_gallery_after` | After images gallery | `album`, `images`, `base_path` |
| `frontend_album_related_before` | Before related albums | `album`, `related_albums`, `base_path` |
| `frontend_album_related_after` | After related albums | `album`, `related_albums`, `base_path` |
| `frontend_album_after` | After entire album page | `album`, `images`, `base_path` |

**Example - Display equipment info in frontend:**

```php
Hooks::addAction('frontend_album_content_after', function($ctx) {
    $album = $ctx['album'] ?? null;
    if (!$album) return;

    // Assuming you have custom equipment data
    $cameras = $album->cameras ?? [];
    $films = $album->films ?? [];

    if (empty($cameras) && empty($films)) return;

    echo '<div class="max-w-3xl mx-auto mb-12">';
    echo '<h3 class="text-lg font-medium mb-4">Equipment Used</h3>';
    echo '<div class="flex flex-wrap gap-4 text-sm text-neutral-600">';

    foreach ($cameras as $camera) {
        echo '<span class="bg-neutral-100 px-3 py-1 rounded">';
        echo '<i class="fas fa-camera mr-2"></i>' . htmlspecialchars($camera['make'] . ' ' . $camera['model']);
        echo '</span>';
    }

    foreach ($films as $film) {
        echo '<span class="bg-neutral-100 px-3 py-1 rounded">';
        echo '<i class="fas fa-film mr-2"></i>' . htmlspecialchars($film['brand'] . ' ' . $film['name']);
        echo '</span>';
    }

    echo '</div></div>';
});
```

---

### Frontend Category Page Hooks

Located in: `app/Views/frontend/category.twig`

| Hook Name | Description | Context Variables |
|-----------|-------------|-------------------|
| `frontend_category_before` | Before entire category page | `category`, `albums`, `base_path` |
| `frontend_category_header_before` | Before category header | `category`, `base_path` |
| `frontend_category_header_meta` | Add metadata to header | `category`, `base_path` |
| `frontend_category_header_after` | After category header | `category`, `base_path` |
| `frontend_category_albums_before` | Before albums grid | `category`, `albums`, `base_path` |
| `frontend_category_albums_after` | After albums grid | `category`, `albums`, `base_path` |
| `frontend_category_other_before` | Before other categories | `category`, `categories`, `base_path` |
| `frontend_category_other_after` | After other categories | `category`, `categories`, `base_path` |
| `frontend_category_after` | After entire category page | `category`, `albums`, `base_path` |

---

## Creating a Plugin

### Plugin Structure

```text
plugins/
└── my-plugin/
    ├── plugin.json
    └── MyPlugin.php
```

### plugin.json

```json
{
    "name": "My Plugin",
    "version": "1.0.0",
    "description": "A custom plugin for photoCMS",
    "author": "Your Name",
    "main": "MyPlugin.php"
}
```

### MyPlugin.php

```php
<?php
declare(strict_types=1);

namespace Plugins\MyPlugin;

use App\Support\Hooks;
use App\Support\PluginManager;

class MyPlugin
{
    public function __construct()
    {
        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        // Add sidebar navigation
        Hooks::addAction('admin_sidebar_navigation', [$this, 'addSidebarNav']);

        // Add equipment fields to album
        Hooks::addAction('admin_album_equipment_fields', [$this, 'addEquipmentFields']);

        // Display equipment in frontend
        Hooks::addAction('frontend_album_content_after', [$this, 'displayEquipment']);
    }

    public function addSidebarNav(array $ctx): void
    {
        $basePath = $ctx['base_path'] ?? '';
        echo '<li class="nav-item">';
        echo '<a href="' . $basePath . '/admin/my-plugin" class="nav-link">';
        echo '<i class="fas fa-plug mr-3"></i>My Plugin';
        echo '</a></li>';
    }

    public function addEquipmentFields(array $ctx): void
    {
        // Add custom form fields
    }

    public function displayEquipment(array $ctx): void
    {
        // Display in frontend
    }
}
```

---

## Using Hooks in Twig Templates

The following Twig functions are available for hooks:

```twig
{# Execute an action hook #}
{{ do_action('hook_name', {key: value}) }}

{# Apply a filter to a value #}
{{ apply_filter('filter_name', value, context) }}

{# Check if a hook has registered handlers #}
{% if has_hook('hook_name') %}
    {# Hook has handlers #}
{% endif %}

{# Alternative syntax for action hooks #}
{{ hook('hook_name', {key: value}) }}
```

---

## Security Considerations

> ⚠️ **Important**: Plugins can execute arbitrary PHP code. Always follow security best practices when developing or installing plugins.

### Preventing XSS (Cross-Site Scripting)

All plugin output that includes user-supplied or database content **must be properly escaped** to prevent XSS attacks:

```php
// ❌ DANGEROUS - Never do this
echo '<div>' . $userInput . '</div>';

// ✅ SAFE - Always escape output
echo '<div>' . htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8') . '</div>';

// ✅ SAFE - Using short function
echo '<div>' . e($userInput) . '</div>';
```

### Plugin Security Checklist

- [ ] Escape all dynamic content with `htmlspecialchars()` or `e()`
- [ ] Validate and sanitize all user inputs
- [ ] Use prepared statements for database queries
- [ ] Never trust data from `$_GET`, `$_POST`, or `$_REQUEST`
- [ ] Verify user permissions before sensitive operations
- [ ] Only install plugins from trusted sources

---

## Best Practices

1. **Use descriptive hook names** with prefixes indicating the area (e.g., `admin_`, `frontend_`)

2. **Pass relevant context** - Always include useful data in the context array

3. **Use appropriate priorities** - Lower numbers run first (default is 10)

4. **Clean output** - Always escape HTML output to prevent XSS

5. **Check for data existence** - Always verify context variables exist before using them

6. **Keep hooks focused** - Each hook should do one thing well

7. **Document your hooks** - If creating custom hooks, document them clearly
