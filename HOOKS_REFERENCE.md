# photoCMS Hooks Reference

Riferimento completo di tutti i 60 hooks disponibili in photoCMS per lo sviluppo di plugin.

---

## Indice per Categoria

1. [User Management](#user-management) - 8 hooks
2. [Album Management](#album-management) - 10 hooks
3. [Image Processing](#image-processing) - 8 hooks
4. [Frontend Display](#frontend-display) - 8 hooks
5. [Admin Panel](#admin-panel) - 8 hooks
6. [Settings & Configuration](#settings--configuration) - 6 hooks
7. [Analytics](#analytics) - 5 hooks
8. [Security & Authentication](#security--authentication) - 4 hooks
9. [Database Operations](#database-operations) - 3 hooks

**Totale: 60 hooks**

---

## User Management

### 1. `user_before_create`
**Type**: Action
**When**: Prima della creazione di un nuovo utente
**Parameters**: `array $userData`
**Use Case**: Validazione custom, invio notifiche

```php
Hooks::addAction('user_before_create', function($userData) {
    // Send welcome email preparation
    error_log("Creating user: " . $userData['email']);
}, 10, 'my-plugin');
```

### 2. `user_after_create`
**Type**: Action
**When**: Dopo la creazione dell'utente
**Parameters**: `int $userId, array $userData`
**Use Case**: Log, email benvenuto, setup iniziale

### 3. `user_before_update`
**Type**: Action
**When**: Prima dell'aggiornamento dati utente
**Parameters**: `int $userId, array $newData, array $oldData`

### 4. `user_after_update`
**Type**: Action
**When**: Dopo l'aggiornamento
**Parameters**: `int $userId, array $userData`

### 5. `user_before_delete`
**Type**: Action
**When**: Prima dell'eliminazione utente
**Parameters**: `int $userId, array $userData`
**Use Case**: Backup dati, reassign content

### 6. `user_after_delete`
**Type**: Action
**When**: Dopo eliminazione
**Parameters**: `int $userId`

### 7. `user_profile_fields`
**Type**: Filter
**When**: Rendering form profilo utente
**Parameters**: `array $fields`
**Returns**: `array` (campi modificati)
**Use Case**: Aggiungere campi custom al profilo

```php
Hooks::addFilter('user_profile_fields', function($fields) {
    $fields['bio'] = [
        'type' => 'textarea',
        'label' => 'Biography',
        'required' => false
    ];
    return $fields;
});
```

### 8. `user_permissions`
**Type**: Filter
**When**: Verifica permessi utente
**Parameters**: `array $permissions, int $userId`
**Returns**: `array`
**Use Case**: Sistema permessi custom, ruoli aggiuntivi

---

## Album Management

### 9. `album_before_create`
**Type**: Action
**When**: Prima creazione album
**Parameters**: `array $albumData`

### 10. `album_after_create`
**Type**: Action
**When**: Dopo creazione album
**Parameters**: `int $albumId, array $albumData`
**Use Case**: Notifiche, auto-categorization, SEO automation

### 11. `album_before_update`
**Type**: Action
**When**: Prima aggiornamento
**Parameters**: `int $albumId, array $newData, array $oldData`

### 12. `album_after_update`
**Type**: Action
**When**: Dopo aggiornamento
**Parameters**: `int $albumId, array $albumData`

### 13. `album_before_delete`
**Type**: Action
**When**: Prima eliminazione album
**Parameters**: `int $albumId, array $albumData`
**Use Case**: Backup, cleanup relazioni custom

### 14. `album_after_delete`
**Type**: Action
**When**: Dopo eliminazione
**Parameters**: `int $albumId`

### 15. `album_publish`
**Type**: Action
**When**: Quando album viene pubblicato
**Parameters**: `int $albumId, array $albumData`
**Use Case**: Notifiche follower, social auto-post, sitemap regen

### 16. `album_unpublish`
**Type**: Action
**When**: Quando album viene nascosto
**Parameters**: `int $albumId`

### 17. `album_metadata_fields`
**Type**: Filter
**When**: Rendering form metadati album
**Parameters**: `array $fields, int $albumId`
**Returns**: `array`
**Use Case**: Campi custom album (client name, project budget, ecc.)

```php
Hooks::addFilter('album_metadata_fields', function($fields, $albumId) {
    $fields['client_name'] = [
        'type' => 'text',
        'label' => 'Client Name',
        'group' => 'Project Info'
    ];
    $fields['project_budget'] = [
        'type' => 'number',
        'label' => 'Budget ($)',
        'group' => 'Project Info'
    ];
    return $fields;
}, 10, 'project-manager-plugin');
```

### 18. `album_query`
**Type**: Filter
**When**: Query album per liste
**Parameters**: `string $sql, array $params`
**Returns**: `array` ['sql' => string, 'params' => array]
**Use Case**: Filtri custom, ordinamento avanzato

---

## Image Processing

### 19. `image_before_upload`
**Type**: Action
**When**: Prima dell'upload immagine
**Parameters**: `array $fileData`
**Use Case**: Pre-validazione, resize preventivo

### 20. `image_after_upload`
**Type**: Action
**When**: Dopo upload completato
**Parameters**: `int $imageId, array $imageData, string $filePath`
**Use Case**: Watermarking, face detection, auto-tagging

### 21. `image_before_delete`
**Type**: Action
**When**: Prima eliminazione immagine
**Parameters**: `int $imageId, array $imageData`

### 22. `image_after_delete`
**Type**: Action
**When**: Dopo eliminazione
**Parameters**: `int $imageId`

### 23. `image_variants_config`
**Type**: Filter
**When**: Generazione varianti responsive
**Parameters**: `array $config`
**Returns**: `array` (breakpoints, formati, qualità modificati)
**Use Case**: Breakpoints custom, formati aggiuntivi (JPEG XL, HEIF)

```php
Hooks::addFilter('image_variants_config', function($config) {
    // Aggiungi breakpoint ultra-wide
    $config['breakpoints']['ultrawide'] = 5120;

    // Abilita formato JPEG XL (future)
    $config['formats']['jxl'] = true;
    $config['quality']['jxl'] = 60;

    return $config;
});
```

### 24. `image_resize_algorithm`
**Type**: Filter
**When**: Algoritmo resize immagine
**Parameters**: `string $algorithm` (default: 'lanczos')
**Returns**: `string`
**Use Case**: Algoritmi custom (bicubic, mitchell, ecc.)

### 25. `image_metadata_extract`
**Type**: Filter
**When**: Estrazione metadati EXIF
**Parameters**: `array $metadata, string $filePath`
**Returns**: `array`
**Use Case**: Metadata aggiuntivi, parsing custom

### 26. `image_watermark_config`
**Type**: Filter
**When**: Configurazione watermark (se plugin watermark attivo)
**Parameters**: `array $config, int $imageId`
**Returns**: `array` (posizione, opacità, immagine watermark)

---

## Frontend Display

### 27. `frontend_before_render`
**Type**: Action
**When**: Prima del rendering di qualsiasi pagina frontend
**Parameters**: `string $page, array $data`
**Use Case**: Inject analytics scripts, modals globali

### 28. `frontend_after_render`
**Type**: Action
**When**: Dopo rendering (output già inviato)
**Parameters**: `string $page, array $data`

### 29. `home_gallery_images`
**Type**: Filter
**When**: Selezione immagini per home gallery
**Parameters**: `array $images`
**Returns**: `array`
**Use Case**: Algoritmi curazione custom, AI selection

### 30. `album_view_images`
**Type**: Filter
**When**: Rendering immagini in pagina album
**Parameters**: `array $images, int $albumId`
**Returns**: `array`
**Use Case**: Ordinamento custom, filtri live

### 31. `lightbox_config`
**Type**: Filter
**When**: Configurazione lightbox PhotoSwipe
**Parameters**: `array $config`
**Returns**: `array`
**Use Case**: Options custom (fullscreen auto, slide duration)

```php
Hooks::addFilter('lightbox_config', function($config) {
    $config['fullscreenEl'] = false; // Disabilita fullscreen
    $config['shareEl'] = true;       // Abilita sharing
    $config['bgOpacity'] = 0.95;     // Opacità background
    return $config;
});
```

### 32. `breadcrumb_items`
**Type**: Filter
**When**: Generazione breadcrumb
**Parameters**: `array $items, string $currentPage`
**Returns**: `array`
**Use Case**: Breadcrumb custom, struttura gerarchica custom

### 33. `menu_items`
**Type**: Filter
**When**: Rendering menu frontend
**Parameters**: `array $menuItems`
**Returns**: `array`
**Use Case**: Aggiungere voci menu custom

### 34. `footer_content`
**Type**: Filter
**When**: Rendering footer
**Parameters**: `string $html`
**Returns**: `string`
**Use Case**: Widget footer, newsletter form

---

## Admin Panel

### 35. `admin_menu_items`
**Type**: Filter
**When**: Rendering menu admin sidebar
**Parameters**: `array $menuItems`
**Returns**: `array`
**Use Case**: Aggiungere voci menu custom, sezioni plugin

```php
Hooks::addFilter('admin_menu_items', function($menuItems) {
    $menuItems[] = [
        'title' => 'My Plugin',
        'url' => '/admin/my-plugin',
        'icon' => 'plugin-icon',
        'position' => 50, // Ordinamento
        'submenu' => [
            ['title' => 'Settings', 'url' => '/admin/my-plugin/settings'],
            ['title' => 'Reports', 'url' => '/admin/my-plugin/reports']
        ]
    ];
    return $menuItems;
});
```

### 36. `admin_dashboard_widgets`
**Type**: Filter
**When**: Rendering dashboard admin
**Parameters**: `array $widgets`
**Returns**: `array`
**Use Case**: Widget custom dashboard (stats, quick actions)

### 37. `admin_list_columns`
**Type**: Filter
**When**: Colonne tabella lista (albums, images, ecc.)
**Parameters**: `array $columns, string $entityType`
**Returns**: `array`
**Use Case**: Colonne aggiuntive custom

### 38. `admin_bulk_actions`
**Type**: Filter
**When**: Azioni bulk in liste admin
**Parameters**: `array $actions, string $entityType`
**Returns**: `array`
**Use Case**: Azioni batch custom (export, assign tags bulk)

### 39. `admin_form_fields`
**Type**: Filter
**When**: Rendering form edit entity
**Parameters**: `array $fields, string $entityType, ?int $entityId`
**Returns**: `array`
**Use Case**: Campi form aggiuntivi

### 40. `admin_css`
**Type**: Filter
**When**: CSS caricato in admin
**Parameters**: `array $cssFiles`
**Returns**: `array`
**Use Case**: Aggiungere CSS custom plugin

### 41. `admin_js`
**Type**: Filter
**When**: JavaScript caricato in admin
**Parameters**: `array $jsFiles`
**Returns**: `array`
**Use Case**: Script custom plugin

### 42. `admin_toolbar_items`
**Type**: Filter
**When**: Rendering toolbar admin (top bar)
**Parameters**: `array $items`
**Returns**: `array`
**Use Case**: Quick links custom

---

## Settings & Configuration

### 43. `settings_tabs`
**Type**: Filter
**When**: Tab pagina settings
**Parameters**: `array $tabs`
**Returns**: `array`
**Use Case**: Tab settings custom plugin

```php
Hooks::addFilter('settings_tabs', function($tabs) {
    $tabs['email'] = [
        'title' => 'Email Settings',
        'icon' => 'mail',
        'fields' => [
            'smtp_host' => ['type' => 'text', 'label' => 'SMTP Host'],
            'smtp_port' => ['type' => 'number', 'label' => 'SMTP Port'],
            // ...
        ]
    ];
    return $tabs;
});
```

### 44. `settings_validation`
**Type**: Filter
**When**: Validazione settings prima del save
**Parameters**: `array $errors, array $data`
**Returns**: `array` (errori)
**Use Case**: Validazione custom settings

### 45. `settings_after_save`
**Type**: Action
**When**: Dopo salvataggio settings
**Parameters**: `array $settings, array $oldSettings`
**Use Case**: Cache clear, regen config files

### 46. `seo_meta_tags`
**Type**: Filter
**When**: Generazione meta tags SEO
**Parameters**: `array $metaTags, string $pageType, ?array $entity`
**Returns**: `array`
**Use Case**: Meta tags custom, Schema.org custom

### 47. `sitemap_urls`
**Type**: Filter
**When**: Generazione sitemap XML
**Parameters**: `array $urls`
**Returns**: `array`
**Use Case**: Aggiungere URL custom al sitemap

### 48. `robots_txt`
**Type**: Filter
**When**: Generazione robots.txt
**Parameters**: `string $content`
**Returns**: `string`
**Use Case**: Regole custom crawling

---

## Analytics

### 49. `analytics_track_pageview`
**Type**: Action
**When**: Tracking pageview
**Parameters**: `array $data` (session_id, page_url, page_type, ecc.)
**Use Case**: Tracking custom, invio a servizi esterni (GA, Matomo)

### 50. `analytics_track_event`
**Type**: Action
**When**: Tracking evento custom
**Parameters**: `string $eventType, array $eventData`
**Use Case**: Eventi custom (video play, form submit, ecc.)

### 51. `analytics_dashboard_charts`
**Type**: Filter
**When**: Rendering charts dashboard analytics
**Parameters**: `array $charts`
**Returns**: `array`
**Use Case**: Grafici custom, metriche aggiuntive

### 52. `analytics_export_data`
**Type**: Filter
**When**: Export dati analytics
**Parameters**: `array $data, string $format, array $filters`
**Returns**: `array`
**Use Case**: Colonne aggiuntive export, formati custom

### 53. `analytics_privacy_config`
**Type**: Filter
**When**: Configurazione privacy analytics
**Parameters**: `array $config`
**Returns**: `array`
**Use Case**: Livelli privacy custom, IP mask algorithms

---

## Security & Authentication

### 54. `auth_login_attempt`
**Type**: Action
**When**: Tentativo login
**Parameters**: `string $email, bool $success, ?int $userId`
**Use Case**: 2FA, logging, ban automation

### 55. `auth_session_validate`
**Type**: Filter
**When**: Validazione sessione
**Parameters**: `bool $isValid, array $sessionData`
**Returns**: `bool`
**Use Case**: Validazione custom (IP check, device fingerprint)

### 56. `password_requirements`
**Type**: Filter
**When**: Definizione requisiti password
**Parameters**: `array $requirements`
**Returns**: `array`
**Use Case**: Policy password custom

```php
Hooks::addFilter('password_requirements', function($requirements) {
    $requirements['min_length'] = 12; // Minimo 12 caratteri
    $requirements['require_special'] = true;
    $requirements['require_number'] = true;
    $requirements['require_uppercase'] = true;
    return $requirements;
});
```

### 57. `security_headers`
**Type**: Filter
**When**: Generazione security headers HTTP
**Parameters**: `array $headers`
**Returns**: `array`
**Use Case**: Headers custom, CSP policy custom

---

## Database Operations

### 58. `db_query_before`
**Type**: Action
**When**: Prima di eseguire qualsiasi query
**Parameters**: `string $sql, array $params`
**Use Case**: Query logging, performance monitoring

### 59. `db_query_after`
**Type**: Action
**When**: Dopo esecuzione query
**Parameters**: `string $sql, array $params, float $executionTime, ?array $result`
**Use Case**: Slow query detection, caching

### 60. `db_migration_after`
**Type**: Action
**When**: Dopo esecuzione migration
**Parameters**: `string $migrationName, bool $success`
**Use Case**: Setup automatico plugin dopo migration

---

## Hook Naming Conventions

**Pattern**: `{entity}_{timing}_{action}`

- **entity**: user, album, image, settings, ecc.
- **timing**: before, after (per actions), n/a (per filters)
- **action**: create, update, delete, render, query, ecc.

**Filter vs Action**:
- **Action**: Non ritorna valore, solo esegue side effects
- **Filter**: Modifica e ritorna un valore

---

## Priority System

**Priority range**: 1-100 (default: 10)

- **1-5**: Critical, eseguiti per primi (validazione, security)
- **10**: Default (maggior parte plugin)
- **15-20**: Post-processing
- **50+**: Bassa priorità (logging, non-critical)

**Esempio**:
```php
// Alta priorità - validazione critica
Hooks::addAction('user_before_create', $validator, 5, 'security-plugin');

// Priorità normale
Hooks::addAction('user_before_create', $emailSender, 10, 'email-plugin');

// Bassa priorità - logging
Hooks::addAction('user_before_create', $logger, 50, 'log-plugin');
```

---

## Best Practices

1. **Naming Plugin**: Usa nome univoco (es. 'my-company-feature-plugin')
2. **Error Handling**: Wrap callback in try-catch, log errori
3. **Performance**: Evita query pesanti in hooks frequenti
4. **Documentation**: Documenta ogni hook che il tuo plugin usa
5. **Backwards Compatibility**: Non rimuovere hook in aggiornamenti
6. **Testing**: Testa con e senza plugin attivi

---

## Debugging Hooks

```php
// Get stats
$stats = PluginManager::getInstance()->getStats();
var_dump($stats);

// Check if hook exists
if (Hooks::hasHook('album_after_create')) {
    echo "Hook registered!";
}

// Get all callbacks for a hook
$callbacks = PluginManager::getInstance()->getHooks('album_after_create');
foreach ($callbacks as $hook) {
    echo "Plugin: {$hook['plugin']}, Priority: {$hook['priority']}\n";
}
```

---

**Prossimi Step**:
- Vedi [PLUGIN_DEVELOPMENT.md](./PLUGIN_DEVELOPMENT.md) per creare plugin
- Vedi [PLUGIN_EXAMPLES.md](./PLUGIN_EXAMPLES.md) per esempi completi
- Vedi [plugins/](./plugins/) per plugin inclusi

---

**Versione**: 1.0.0
**Ultimo Aggiornamento**: 17 Novembre 2025
