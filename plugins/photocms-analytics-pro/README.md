# photoCMS Analytics Pro

Sistema di analytics professionale e completo per photoCMS con tracking avanzato, dashboard interattiva, report personalizzabili e real-time monitoring.

## 📊 Caratteristiche

### Tracking Completo
- **Eventi Personalizzati**: Track di qualsiasi evento nell'applicazione
- **Sessioni Utente**: Monitoring completo delle sessioni con durata e attività
- **User Journey**: Visualizza il percorso completo degli utenti
- **Device Detection**: Desktop, Mobile, Tablet detection automatica
- **Browser Detection**: Chrome, Firefox, Safari, Edge, Opera
- **Geolocalizzazione**: Country tracking (se disponibile)

### Dashboard & Reporting
- **Real-time Statistics**: Utenti attivi, eventi in tempo reale
- **Custom Reports**: Report personalizzabili per periodo
- **Funnel Analysis**: Analisi di conversione multi-step
- **Device Analytics**: Statistiche per tipo di dispositivo
- **Browser Analytics**: Statistiche per browser
- **Export Data**: Esportazione CSV per analisi esterne

### Eventi Tracciati Automaticamente

#### Autenticazione
- Login utente (con role e metadati)
- Logout utente

#### Content Management
- Creazione album
- Modifica album (con tracking delle modifiche)
- Eliminazione album
- Upload immagine
- Eliminazione immagine
- Creazione categoria
- Creazione tag

#### Engagement Frontend
- Visualizzazione pagine
- Visualizzazione album
- Apertura lightbox immagini
- Download immagini
- Ricerche (con risultati)

## 🚀 Installazione

1. Vai in **Admin > Plugin**
2. Trova **photoCMS Analytics Pro** nella lista
3. Clicca su **Installa**
4. Clicca su **Attiva**

Il plugin creerà automaticamente le tabelle necessarie al primo avvio.

## 📁 Struttura Database

### `analytics_pro_events`
Registra tutti gli eventi tracciati:
- `event_name`: Nome dell'evento
- `category`: Categoria (authentication, content, engagement, etc.)
- `action`: Azione specifica
- `label`: Label descrittiva
- `value`: Valore numerico (opzionale)
- `user_id`: ID utente (se autenticato)
- `session_id`: ID sessione
- `metadata`: JSON con dati aggiuntivi

### `analytics_pro_sessions`
Traccia le sessioni utente:
- `session_id`: ID univoco sessione
- `user_id`: ID utente
- `device_type`: desktop/mobile/tablet
- `browser`: Browser utilizzato
- `duration`: Durata sessione in secondi
- `pageviews`: Numero di pageviews
- `events_count`: Numero di eventi

### `analytics_pro_funnels`
Definisce funnel di conversione personalizzati

### `analytics_pro_dimensions`
Custom dimensions per eventi

## 💻 Utilizzo Programmatico

### Track Custom Event

```php
use PhotoCMSAnalyticsPro\AnalyticsPro;

$analytics = new AnalyticsPro($db);

// Track evento semplice
$analytics->trackEvent('button_click', [
    'category' => 'engagement',
    'action' => 'click',
    'label' => 'download_button',
    'value' => 1
]);

// Track evento con metadata
$analytics->trackEvent('purchase', [
    'category' => 'ecommerce',
    'action' => 'purchase',
    'label' => 'Premium Plan',
    'value' => 99,
    'metadata' => [
        'plan_type' => 'premium',
        'billing_cycle' => 'annual',
        'currency' => 'EUR'
    ]
]);

// Track evento con custom dimensions
$analytics->trackEvent('video_play', [
    'category' => 'engagement',
    'action' => 'play',
    'label' => 'Tutorial Video',
    'dimensions' => [
        'video_id' => 123,
        'video_duration' => 180,
        'video_quality' => '1080p'
    ]
]);
```

### Get Real-time Statistics

```php
$stats = $analytics->getRealtimeStats();

echo "Utenti attivi: " . $stats['active_users'];
echo "Eventi oggi: " . $stats['events_today'];
echo "Durata media: " . $stats['avg_session_duration'] . "s";
```

### Get Event Statistics by Period

```php
// Eventi per giorno (ultimi 30 giorni)
$dailyStats = $analytics->getEventStats('day', 30);

// Eventi per ora (ultime 24 ore)
$hourlyStats = $analytics->getEventStats('hour', 24);

// Eventi per settimana
$weeklyStats = $analytics->getEventStats('week', 12);

// Eventi per mese
$monthlyStats = $analytics->getEventStats('month', 12);
```

### Funnel Analysis

```php
$funnel = $analytics->getFunnelAnalysis([
    'page_view',
    'album_view',
    'lightbox_open',
    'image_download'
]);

foreach ($funnel as $step) {
    echo "{$step['step']}: {$step['count']} ({$step['conversion_rate']}%)\n";
}
```

### Get User Journey

```php
$sessionId = 'abc123...';
$journey = $analytics->getUserJourney($sessionId);

foreach ($journey as $event) {
    echo "[{$event['created_at']}] {$event['event_name']}: {$event['label']}\n";
}
```

### Device & Browser Statistics

```php
// Device stats (ultimi 30 giorni)
$deviceStats = $analytics->getDeviceStats(30);
foreach ($deviceStats as $device) {
    echo "{$device['device_type']}: {$device['count']} sessions\n";
}

// Browser stats
$browserStats = $analytics->getBrowserStats(30);
foreach ($browserStats as $browser) {
    echo "{$browser['browser']}: {$browser['count']} sessions\n";
}
```

### Export Data to CSV

```php
$csv = $analytics->exportToCSV([
    'date_from' => '2024-01-01',
    'date_to' => '2024-12-31',
    'event_name' => 'page_view',
    'category' => 'engagement'
]);

// Save to file
file_put_contents('analytics_export.csv', $csv);

// Or send as download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="analytics_export.csv"');
echo $csv;
```

### Cleanup Old Data

```php
// Elimina eventi più vecchi di 90 giorni
$deleted = $analytics->cleanup(90);
echo "Eliminati {$deleted} record";
```

## 🎯 Use Cases Avanzati

### Tracking Ecommerce

```php
// Product view
Hooks::doAction('product_view', $productId, [
    'name' => $product['name'],
    'price' => $product['price'],
    'category' => $product['category']
]);

// Add to cart
Hooks::doAction('add_to_cart', $productId, [
    'quantity' => $quantity,
    'price' => $price
]);

// Purchase complete
Hooks::doAction('purchase_complete', $orderId, [
    'total' => $orderTotal,
    'items_count' => $itemsCount,
    'payment_method' => $paymentMethod
]);
```

### A/B Testing Tracking

```php
$variant = rand(0, 1) === 0 ? 'A' : 'B';

$analytics->trackEvent('ab_test_view', [
    'category' => 'experiment',
    'action' => 'view',
    'label' => 'homepage_layout',
    'dimensions' => [
        'variant' => $variant,
        'experiment_id' => 'homepage_v2_test'
    ]
]);
```

### Form Analytics

```php
// Form started
Hooks::doAction('form_started', 'contact_form');

// Field interaction
Hooks::doAction('form_field_focus', 'contact_form', ['field' => 'email']);

// Form submitted
Hooks::doAction('form_submitted', 'contact_form', [
    'fields_filled' => 5,
    'time_spent' => 45, // seconds
    'validation_errors' => 0
]);
```

## 📈 Report Examples

### Top Eventi per Categoria

```php
$topEngagement = $analytics->getTopEventsByCategory('engagement', 10);
$topContent = $analytics->getTopEventsByCategory('content', 10);
$topEcommerce = $analytics->getTopEventsByCategory('ecommerce', 10);
```

### Custom Time Period Analysis

```php
$events = $analytics->getEventStats('day', 7); // Ultima settimana

$groupedByEvent = [];
foreach ($events as $row) {
    $groupedByEvent[$row['event_name']][] = $row;
}

// Ora hai i dati raggruppati per tipo di evento
```

## 🔧 Hooks Disponibili

Il plugin utilizza i seguenti hooks per il tracking automatico:

- `photocms_init` - Inizializzazione plugin
- `user_login_success` - Track login
- `user_logout` - Track logout
- `album_created` - Track creazione album
- `album_updated` - Track modifica album
- `album_deleted` - Track eliminazione album
- `image_uploaded` - Track upload immagine
- `image_deleted` - Track eliminazione immagine
- `category_created` - Track creazione categoria
- `tag_created` - Track creazione tag
- `frontend_page_view` - Track pageview frontend
- `frontend_album_view` - Track visualizzazione album
- `image_lightbox_open` - Track apertura lightbox
- `image_download` - Track download immagine
- `search_performed` - Track ricerca

### Aggiungere Tracking Custom

```php
// In qualsiasi punto dell'applicazione
Hooks::doAction('custom_event_name', $param1, $param2);

// Nel plugin, aggiungi l'hook
Hooks::addAction('custom_event_name', function($param1, $param2) use ($analytics) {
    $analytics->trackEvent('custom_event_name', [
        'category' => 'custom',
        'label' => $param1,
        'value' => $param2
    ]);
}, 10, 'photocms-analytics-pro');
```

## 🎨 Dashboard Widget

Il plugin aggiunge automaticamente un widget alla dashboard admin con statistiche real-time:
- Utenti attivi (ultimi 5 minuti)
- Eventi oggi
- Pageviews oggi
- Durata media sessioni

## ⚙️ Configurazione

Il plugin funziona out-of-the-box senza configurazione. Tutti i dati sono salvati automaticamente nel database SQLite/MySQL.

### Opzioni Avanzate

Per customizzazioni avanzate, puoi modificare i metodi nel file `src/AnalyticsPro.php`:

- `ensureTables()` - Schema database
- `trackEvent()` - Logica tracking eventi
- `getRealtimeStats()` - Calcolo statistiche real-time
- `exportToCSV()` - Formato export

## 🔒 Privacy & GDPR

Il plugin traccia:
- ✅ Eventi anonimi
- ✅ Sessioni
- ✅ Device type e browser
- ✅ IP address (può essere anonimizzato)
- ✅ User agent

**Note GDPR**:
- Assicurati di avere il consenso degli utenti per il tracking
- Implementa cookie banner se necessario
- Offri opt-out per gli utenti
- Anonimizza IP se richiesto dalla normativa

## 📊 Performance

Il plugin è ottimizzato per alte performance:
- **Indici database** su colonne critiche
- **Query ottimizzate** con limit e filtri
- **Cleanup automatico** (configurabile)
- **Overhead minimo** (~1-2ms per evento)

### Cleanup Automatico

Programma un cron job per cleanup periodico:

```bash
# Cleanup dati più vecchi di 90 giorni, ogni domenica alle 3:00
0 3 * * 0 php /path/to/cleanup.php
```

```php
// cleanup.php
require_once 'vendor/autoload.php';
$db = new Database(...);
$analytics = new AnalyticsPro($db);
$deleted = $analytics->cleanup(90);
echo "Cleaned up {$deleted} records\n";
```

## 🤝 Integrations

### Google Analytics Export

```php
// Esporta eventi in formato compatibile con Google Analytics
$events = $analytics->getEventStats('day', 30);

foreach ($events as $event) {
    // Invia a GA via Measurement Protocol
    sendToGA($event);
}
```

### Slack Notifications

```php
// Notifica su Slack per eventi critici
Hooks::addAction('purchase_complete', function($orderId, $data) {
    $message = "Nuovo ordine #{$orderId} - €{$data['total']}";
    sendToSlack($message);
});
```

## 📝 Changelog

### 1.0.0 (2024-11-17)
- Release iniziale
- Sistema di tracking eventi completo
- Dashboard real-time
- Funnel analysis
- Export CSV
- Device & Browser analytics
- Session tracking
- Custom dimensions support

## 🆘 Supporto

Per supporto e segnalazione bug:
- **Issues**: GitHub Issues
- **Documentation**: README.md
- **Examples**: Vedi sezione Use Cases

## 📄 Licenza

MIT License - Usa liberamente in progetti personali e commerciali

---

**Made with ❤️ for photoCMS**
