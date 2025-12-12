# Guida Sviluppo Plugin - photoCMS

Impara a creare plugin potenti per photoCMS usando il sistema di hooks.

---

## Indice

1. [Introduzione](#introduzione)
2. [Struttura Plugin](#struttura-plugin)
3. [Creare il Primo Plugin](#creare-il-primo-plugin)
4. [Hook API](#hook-api)
5. [Best Practices](#best-practices)
6. [Testing](#testing)
7. [Distribuzione](#distribuzione)

---

## Introduzione

Il sistema di plugin di photoCMS permette di estendere funzionalità senza modificare il core. Basato su **hooks** (punti di aggancio), simile a WordPress ma modernizzato.

**Vantaggi**:
- ✅ Estendibilità senza toccare core
- ✅ Aggiornamenti core senza conflitti
- ✅ Isolamento errori (plugin crash non affetta core)
- ✅ Enable/disable runtime
- ✅ Priorità esecuzione configurabile

---

## Struttura Plugin

### Directory Structure

```
plugins/
└── my-awesome-plugin/
    ├── plugin.php              # File principale (required)
    ├── README.md               # Documentazione
    ├── composer.json           # Dipendenze PHP (opzionale)
    ├── package.json            # Dipendenze JS (opzionale)
    ├── src/                    # Classi PHP
    │   ├── MyPlugin.php
    │   └── Settings.php
    ├── assets/                 # Asset frontend
    │   ├── css/
    │   │   └── style.css
    │   └── js/
    │       └── script.js
    ├── admin/                  # Asset admin
    │   ├── css/
    │   └── js/
    ├── views/                  # Template Twig custom
    │   └── settings.twig
    └── languages/              # Traduzioni (future)
        └── it_IT.po
```

### File `plugin.php`

```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Description: Does awesome things with photoCMS
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: MIT
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('PHOTOCMS_VERSION')) {
    die('Direct access not allowed');
}

use App\Support\Hooks;

// Your plugin code here
require_once __DIR__ . '/src/MyPlugin.php';

// Initialize plugin
$myPlugin = new MyAwesomePlugin\MyPlugin();
$myPlugin->init();
```

---

## Creare il Primo Plugin

### Esempio: Custom Watermark Plugin

**Goal**: Aggiungere watermark automatico alle immagini caricate.

#### Step 1: Crea Directory

```bash
mkdir -p plugins/watermark-plugin/src
cd plugins/watermark-plugin
```

#### Step 2: `plugin.php`

```php
<?php
/**
 * Plugin Name: Watermark Plugin
 * Description: Add automatic watermark to uploaded images
 * Version: 1.0.0
 * Author: John Doe
 */

declare(strict_types=1);

use App\Support\Hooks;

class WatermarkPlugin
{
    private string $watermarkPath;

    public function __construct()
    {
        $this->watermarkPath = __DIR__ . '/assets/watermark.png';
    }

    public function init(): void
    {
        // Hook: Dopo upload immagine
        Hooks::addAction('image_after_upload', [$this, 'addWatermark'], 10, 'watermark-plugin');

        // Hook: Admin menu item
        Hooks::addFilter('admin_menu_items', [$this, 'addMenuItems'], 10, 'watermark-plugin');

        // Hook: Settings tab
        Hooks::addFilter('settings_tabs', [$this, 'addSettingsTab'], 10, 'watermark-plugin');
    }

    public function addWatermark(int $imageId, array $imageData, string $filePath): void
    {
        // Check if watermark enabled in settings
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $image = new Imagick($filePath);
            $watermark = new Imagick($this->watermarkPath);

            // Get watermark position from settings
            $position = $this->getPosition(); // 'bottom-right', 'center', ecc.
            $opacity = $this->getOpacity(); // 0.0 - 1.0

            // Resize watermark proportionally (10% of image width)
            $imageWidth = $image->getImageWidth();
            $watermarkWidth = $imageWidth * 0.1;
            $watermark->scaleImage($watermarkWidth, 0);

            // Set opacity
            $watermark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);

            // Calculate position
            [$x, $y] = $this->calculatePosition($image, $watermark, $position);

            // Composite watermark
            $image->compositeImage($watermark, Imagick::COMPOSITE_OVER, $x, $y);

            // Save
            $image->writeImage($filePath);

            // Cleanup
            $image->destroy();
            $watermark->destroy();

            error_log("Watermark added to image {$imageId}");
        } catch (\Exception $e) {
            error_log("Watermark plugin error: " . $e->getMessage());
        }
    }

    public function addMenuItems(array $menuItems): array
    {
        $menuItems[] = [
            'title' => 'Watermark',
            'url' => '/admin/watermark',
            'icon' => 'droplet',
            'position' => 45
        ];
        return $menuItems;
    }

    public function addSettingsTab(array $tabs): array
    {
        $tabs['watermark'] = [
            'title' => 'Watermark',
            'icon' => 'droplet',
            'fields' => [
                'watermark_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable Watermark',
                    'default' => false
                ],
                'watermark_position' => [
                    'type' => 'select',
                    'label' => 'Position',
                    'options' => [
                        'bottom-right' => 'Bottom Right',
                        'bottom-left' => 'Bottom Left',
                        'center' => 'Center',
                        'top-right' => 'Top Right'
                    ],
                    'default' => 'bottom-right'
                ],
                'watermark_opacity' => [
                    'type' => 'range',
                    'label' => 'Opacity',
                    'min' => 0,
                    'max' => 100,
                    'default' => 50
                ]
            ]
        ];
        return $tabs;
    }

    private function isEnabled(): bool
    {
        // Get from settings (to implement: SettingsService integration)
        return true;
    }

    private function getPosition(): string
    {
        return 'bottom-right';
    }

    private function getOpacity(): float
    {
        return 0.5;
    }

    private function calculatePosition(Imagick $image, Imagick $watermark, string $position): array
    {
        $imageWidth = $image->getImageWidth();
        $imageHeight = $image->getImageHeight();
        $watermarkWidth = $watermark->getImageWidth();
        $watermarkHeight = $watermark->getImageHeight();

        $padding = 20; // px

        return match($position) {
            'bottom-right' => [
                $imageWidth - $watermarkWidth - $padding,
                $imageHeight - $watermarkHeight - $padding
            ],
            'bottom-left' => [
                $padding,
                $imageHeight - $watermarkHeight - $padding
            ],
            'center' => [
                ($imageWidth - $watermarkWidth) / 2,
                ($imageHeight - $watermarkHeight) / 2
            ],
            'top-right' => [
                $imageWidth - $watermarkWidth - $padding,
                $padding
            ],
            default => [0, 0]
        };
    }
}

// Initialize
$plugin = new WatermarkPlugin();
$plugin->init();
```

#### Step 3: Assets

Aggiungi watermark image:
```bash
mkdir -p assets
cp /path/to/your/watermark.png assets/
```

#### Step 4: Attiva Plugin

Il plugin viene caricato automaticamente al boot dell'applicazione!

---

## Hook API

### Registrare Hook

#### Action (no return value)

```php
use App\Support\Hooks;

Hooks::addAction('hook_name', function($arg1, $arg2) {
    // Do something
    echo "Action executed!";
}, $priority = 10, 'my-plugin');
```

#### Filter (with return value)

```php
Hooks::addFilter('hook_name', function($value, $arg) {
    // Modify value
    $value['new_field'] = 'added by plugin';
    return $value;
}, $priority = 10, 'my-plugin');
```

### Eseguire Hook

#### Action

```php
Hooks::doAction('my_custom_action', $arg1, $arg2);
```

#### Filter

```php
$result = Hooks::applyFilter('my_custom_filter', $initialValue, $arg1);
```

### Rimuovere Hook

```php
$callback = function() { /* ... */ };

Hooks::addAction('hook_name', $callback);

// Later...
Hooks::removeHook('hook_name', $callback);
```

### Check Hook Exists

```php
if (Hooks::hasHook('album_after_create')) {
    echo "Hook is registered!";
}
```

---

## Best Practices

### 1. Namespace & Autoloading

```php
// plugin.php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoload

// src/MyPlugin.php
namespace MyCompany\PhotCMSPlugins\MyPlugin;

use App\Support\Hooks;

class MyPlugin
{
    // ...
}
```

### 2. Dependency Injection

```php
class MyPlugin
{
    private PDO $db;
    private SettingsService $settings;

    public function __construct(PDO $db, SettingsService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function init(): void
    {
        Hooks::addAction('photocms_init', [$this, 'setup']);
    }

    public function setup(): void
    {
        // Plugin initialization after core loaded
    }
}
```

### 3. Error Handling

```php
public function myCallback(): void
{
    try {
        // Plugin code
    } catch (\Exception $e) {
        error_log("Plugin error: " . $e->getMessage());
        // Fail gracefully, don't break site
    }
}
```

### 4. Settings Storage

```php
class MyPlugin
{
    private const SETTINGS_KEY = 'my_plugin_settings';

    public function saveSettings(array $settings): void
    {
        // Use core SettingsService
        $this->settingsService->set(self::SETTINGS_KEY, $settings);
    }

    public function getSettings(): array
    {
        return $this->settingsService->get(self::SETTINGS_KEY, [
            'enabled' => false,
            'option1' => 'default'
        ]);
    }
}
```

### 5. Database Tables (if needed)

```php
public function install(): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS plugin_my_table (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ";

    $this->db->exec($sql);
}

// Hook
Hooks::addAction('plugin_activated_my-plugin', [$this, 'install']);
```

### 6. Uninstall Cleanup

```php
public function uninstall(): void
{
    // Remove settings
    $this->db->exec("DELETE FROM settings WHERE key LIKE 'my_plugin_%'");

    // Drop tables
    $this->db->exec("DROP TABLE IF EXISTS plugin_my_table");

    // Remove files
    $this->removePluginFiles();
}

Hooks::addAction('plugin_deactivated_my-plugin', [$this, 'uninstall']);
```

---

## Testing

### Unit Tests (PHPUnit)

```php
// tests/MyPluginTest.php
use PHPUnit\Framework\TestCase;

class MyPluginTest extends TestCase
{
    public function testWatermarkAdded(): void
    {
        $plugin = new WatermarkPlugin();

        // Mock image
        $imageId = 123;
        $imageData = ['width' => 1920, 'height' => 1080];
        $filePath = '/tmp/test-image.jpg';

        // Execute
        $plugin->addWatermark($imageId, $imageData, $filePath);

        // Assert watermark added
        $this->assertFileExists($filePath);
        // ... more assertions
    }
}
```

### Integration Tests

```bash
# Install test dependencies
composer require --dev phpunit/phpunit

# Run tests
vendor/bin/phpunit plugins/my-plugin/tests
```

---

## Esempi Avanzati

### Custom Field Manager

```php
class CustomFieldsPlugin
{
    public function init(): void
    {
        // Add custom fields to albums
        Hooks::addFilter('album_metadata_fields', [$this, 'addFields'], 10, 'custom-fields');

        // Save custom fields
        Hooks::addAction('album_after_update', [$this, 'saveFields'], 10, 'custom-fields');

        // Display in frontend
        Hooks::addFilter('album_view_data', [$this, 'injectFieldsToView'], 10, 'custom-fields');
    }

    public function addFields(array $fields, int $albumId): array
    {
        // Load custom fields definition
        $customFields = $this->getCustomFieldsDefinition();

        foreach ($customFields as $fieldKey => $fieldConfig) {
            $fields[$fieldKey] = [
                'type' => $fieldConfig['type'],
                'label' => $fieldConfig['label'],
                'group' => 'Custom Fields',
                'value' => $this->getFieldValue($albumId, $fieldKey)
            ];
        }

        return $fields;
    }

    public function saveFields(int $albumId, array $albumData): void
    {
        $customFields = $this->getCustomFieldsDefinition();

        foreach ($customFields as $fieldKey => $config) {
            if (isset($albumData[$fieldKey])) {
                $this->saveFieldValue($albumId, $fieldKey, $albumData[$fieldKey]);
            }
        }
    }

    private function getCustomFieldsDefinition(): array
    {
        return [
            'custom_client_name' => [
                'type' => 'text',
                'label' => 'Client Name'
            ],
            'custom_project_date' => [
                'type' => 'date',
                'label' => 'Project Date'
            ],
            'custom_budget' => [
                'type' => 'number',
                'label' => 'Budget ($)'
            ]
        ];
    }

    private function getFieldValue(int $albumId, string $fieldKey): mixed
    {
        $stmt = $this->db->prepare("
            SELECT value FROM plugin_custom_fields
            WHERE entity_type = 'album' AND entity_id = ? AND field_key = ?
        ");
        $stmt->execute([$albumId, $fieldKey]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : null;
    }

    private function saveFieldValue(int $albumId, string $fieldKey, $value): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO plugin_custom_fields (entity_type, entity_id, field_key, value)
            VALUES ('album', ?, ?, ?)
        ");
        $stmt->execute([$albumId, $fieldKey, $value]);
    }
}
```

---

## Distribuzione

### Packaging

```bash
cd plugins/my-awesome-plugin

# Remove dev files
rm -rf tests/ .git/

# Create zip
cd ..
zip -r my-awesome-plugin-v1.0.0.zip my-awesome-plugin/
```

### Installation (User)

1. Download plugin ZIP
2. Extract to `plugins/` directory
3. Plugin auto-loaded on next request

### Plugin Repository (Future)

```json
{
  "name": "my-awesome-plugin",
  "version": "1.0.0",
  "description": "Does awesome things",
  "author": "John Doe",
  "homepage": "https://example.com/plugin",
  "license": "MIT",
  "requires": {
    "photocms": ">=1.0.0",
    "php": ">=8.2"
  },
  "download_url": "https://example.com/downloads/my-plugin-1.0.0.zip"
}
```

---

## Debugging

### Enable Debug Mode

```php
// .env
PLUGIN_DEBUG=true
```

### Debug Output

```php
if (defined('PLUGIN_DEBUG') && PLUGIN_DEBUG) {
    error_log("Plugin debug: " . print_r($data, true));
}
```

### Get Hook Stats

```php
$stats = PluginManager::getInstance()->getStats();
/*
[
    'total_hooks' => 45,
    'total_callbacks' => 120,
    'hooks_list' => ['user_before_create', 'album_after_create', ...],
    'plugins_count' => 8
]
*/
```

---

## Resources

- **Hooks Reference**: [HOOKS_REFERENCE.md](./HOOKS_REFERENCE.md)
- **Plugin Examples**: [PLUGIN_EXAMPLES.md](./PLUGIN_EXAMPLES.md)
- **Core Code**: `app/Support/PluginManager.php`
- **Community Plugins**: https://photocms.com/plugins (future)

---

## Support

- **Issues**: GitHub repository issues
- **Forum**: Community forum (future)
- **Email**: plugins@photocms.com

---

Happy Plugin Development! 🎉

**Versione**: 1.0.0
**Ultimo Aggiornamento**: 17 Novembre 2025
