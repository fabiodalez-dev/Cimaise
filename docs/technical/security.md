# Sicurezza - Cimaise

## Panoramica

Cimaise implementa molteplici livelli di sicurezza per proteggere da vulnerabilità comuni (OWASP Top 10).

---

## 1. SQL Injection Prevention

### Prepared Statements (OBBL IGATORIO)

**✅ Corretto**:
```php
$stmt = $db->prepare("SELECT * FROM albums WHERE id = ?");
$stmt->execute([$id]);
```

**❌ MAI fare**:
```php
$db->query("SELECT * FROM albums WHERE id = $id");  // VULNERABILE!
```

### PDO Configuration

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,  // True prepared statements
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
```

**Policy**: TUTTE le query usano prepared statements, zero eccezioni.

---

## 2. Cross-Site Scripting (XSS) Prevention

### Output Escaping (Twig)

Twig auto-escape ON by default:

```twig
{# Auto-escaped #}
<h1>{{ album.title }}</h1>

{# Raw HTML (solo per contenuto sanitizzato) #}
<div>{{ album.body|raw }}</div>
```

### HTML Sanitization

**Sanitizer.php** (HTMLPurifier):

```php
use App\Support\Sanitizer;

$cleanHtml = Sanitizer::clean($userInput);
// Permette: <p>, <a>, <strong>, <em>, <ul>, <li>, <img>
// Rimuove: <script>, <iframe>, event handlers, javascript:
```

**Whitelist tags**:
- Formatting: `<p>`, `<br>`, `<strong>`, `<em>`, `<u>`
- Links: `<a href="...">`
- Lists: `<ul>`, `<ol>`, `<li>`
- Images: `<img src="..." alt="...">`
- Headers: `<h1>` - `<h6>`

**Blacklist**:
- `<script>`
- `<iframe>`, `<object>`, `<embed>`
- Event handlers (`onclick`, `onerror`)
- `javascript:` protocol
- `data:` URLs (except images)

---

## 3. Cross-Site Request Forgery (CSRF) Protection

### CsrfMiddleware

**Generazione token**:
```php
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

**Validazione POST/PUT/DELETE**:
```php
$submittedToken = $data['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    throw new SecurityException('Invalid CSRF token');
}
```

**Template**:
```twig
<form method="POST">
    <input type="hidden" name="csrf" value="{{ csrf }}">
    ...
</form>
```

**Protezione**: Tutti i form POST/PUT/DELETE richiedono CSRF token.

---

## 4. Password Security

### Hashing

**Admin Users** (Argon2id):
```php
$hash = password_hash($password, PASSWORD_ARGON2ID);
// Verifica
if (password_verify($inputPassword, $hash)) {
    // Login success
}
```

**Album Protection** (bcrypt):
```php
$hash = password_hash($albumPassword, PASSWORD_BCRYPT, ['cost' => 12]);
```

**Policy**:
- Min 8 caratteri
- Complessità raccomandata (ma non enforced)
- No password in plaintext (mai)
- Hash non reversibili

### Session Security

```php
// Dopo login
session_regenerate_id(true);  // Previeni session fixation

// Session config
session_set_cookie_params([
    'lifetime' => 0,  // Scade con browser
    'path' => '/',
    'domain' => '',
    'secure' => true,  // Solo HTTPS in produzione
    'httponly' => true,  // No accesso JavaScript
    'samesite' => 'Lax'  // CSRF extra protection
]);
```

---

## 5. File Upload Security

### UploadService Validation Pipeline

1. **Upload Error Check**:
```php
if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new UploadException('Upload failed');
}
```

2. **File Size**:
```php
if ($file['size'] > 50 * 1024 * 1024) {  // 50MB
    throw new UploadException('File too large');
}
```

3. **MIME Type Detection** (finfo):
```php
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed)) {
    throw new UploadException('Invalid file type');
}
```

4. **Magic Number Validation**:
```php
$handle = fopen($file['tmp_name'], 'rb');
$bytes = fread($handle, 12);
fclose($handle);

// JPEG: FF D8 FF
// PNG: 89 50 4E 47
if (!$this->validateMagicNumbers($bytes, $mime)) {
    throw new UploadException('File type mismatch');
}
```

5. **Image Validation** (getimagesize):
```php
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    throw new UploadException('Not a valid image');
}
```

6. **Storage Outside Webroot**:
```php
// Salva in storage/originals/ (no direct web access)
$hash = sha1_file($file['tmp_name']);
$path = "storage/originals/{$hash}.{$ext}";
move_uploaded_file($file['tmp_name'], $path);
```

### .htaccess Protection

**storage/originals/.htaccess**:
```apache
Deny from all
```

**public/media/.htaccess**:
```apache
<Files *.php>
  Deny from all
</Files>
Options -Indexes
```

**Policy**: Nessun file PHP eseguibile in directory upload.

---

## 6. Security Headers (SecurityHeadersMiddleware)

```php
return $response
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('X-Frame-Options', 'SAMEORIGIN')
    ->withHeader('X-XSS-Protection', '1; mode=block')
    ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
    ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
    ->withHeader('Content-Security-Policy',
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "font-src 'self'; " .
        "connect-src 'self';"
    );

// HTTPS only (production)
if ($_ENV['APP_ENV'] === 'production') {
    $response = $response->withHeader(
        'Strict-Transport-Security',
        'max-age=31536000; includeSubDomains'
    );
}
```

**Headers spiegati**:

- **X-Content-Type-Options: nosniff** → Previeni MIME sniffing
- **X-Frame-Options: SAMEORIGIN** → Previeni clickjacking
- **X-XSS-Protection** → Browser XSS filter (legacy)
- **Content-Security-Policy (CSP)** → Whitelist risorse consentite
- **HSTS** → Force HTTPS (solo production)
- **Referrer-Policy** → Limita informazioni referrer
- **Permissions-Policy** → Disabilita API pericolose

---

## 7. Rate Limiting

### FileBasedRateLimitMiddleware

**Implementazione**:
```php
class FileBasedRateLimitMiddleware
{
    private string $storageDir;
    private int $maxAttempts;
    private int $decaySeconds;

    public function __invoke($request, $handler): Response
    {
        $ip = $this->getClientIp($request);
        $key = md5($ip . $this->identifier);
        $filePath = "{$this->storageDir}/ratelimit_{$key}.json";

        $data = $this->loadAttempts($filePath);
        $now = time();

        // Cleanup vecchi tentativi
        $data['attempts'] = array_filter($data['attempts'], fn($t) => $t > $now - $this->decaySeconds);

        if (count($data['attempts']) >= $this->maxAttempts) {
            return new JsonResponse(['error' => 'Too many requests'], 429);
        }

        $data['attempts'][] = $now;
        file_put_contents($filePath, json_encode($data));

        return $handler->handle($request);
    }
}
```

**Configurazione**:
```php
// Login: 5 tentativi / 10 minuti
new FileBasedRateLimitMiddleware($storageDir, 5, 600, 'login')

// Analytics: 60 req / 1 minuto
new FileBasedRateLimitMiddleware($storageDir, 60, 60, 'analytics')
```

**Cleanup**: File vecchi eliminati periodicamente (cron o auto-cleanup).

---

## 8. Analytics Privacy (GDPR Compliant)

### IP Anonymization

```php
public function hashIp(string $ip): string
{
    $salt = $_ENV['SESSION_SECRET'];
    return hash('sha256', $ip . $salt);
}
```

**Policy**: IP mai salvati in plaintext, solo hash irreversibile.

### Bot Detection

```php
private function isBot(string $userAgent): bool
{
    $botPatterns = [
        '/bot/i', '/crawler/i', '/spider/i', '/curl/i',
        '/wget/i', '/python/i', '/java/i'
    ];

    foreach ($botPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    return false;
}
```

**Filtro**: Bot traffic escluso dalle analytics (flag `is_bot = 1`).

### Data Retention

```php
// Elimina dati vecchi
DELETE FROM analytics_sessions WHERE started_at < NOW() - INTERVAL ? DAY
```

**Default**: 365 giorni, configurabile in `analytics_settings`.

---

## 9. Dependency Security

### Composer Audit

```bash
composer audit
```

Controlla vulnerabilità note in dipendenze.

**Policy**: Update dipendenze regolarmente, patch security issues ASAP.

### Package Integrity

```json
{
    "config": {
        "secure-http": true,
        "platform-check": true
    }
}
```

---

## 10. Environment Variables Security

### .env Protection

**.htaccess** (root):
```apache
<Files .env>
  Order allow,deny
  Deny from all
</Files>
```

**.gitignore**:
```
.env
.env.local
.env.production
```

**Policy**:
- `.env` NEVER committed a git
- `.env.example` come template (no secrets)
- Secrets rotated periodicamente

### Secrets Management

```php
// ✅ Corretto
$secret = $_ENV['SESSION_SECRET'];

// ❌ Mai hardcodare
$secret = 'my-secret-key';  // VULNERABILE!
```

---

## 11. Diagnostics Security

### Information Disclosure

**DiagnosticsController** (solo admin):
- Verifica AuthMiddleware
- Non esporre password DB
- Non esporre path assoluti sensibili

**Error Messages** (production):
```php
if ($_ENV['APP_ENV'] === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}
```

**Log errors**, don't display.

---

## 12. Database Security

### Foreign Keys

**SQLite**: `PRAGMA foreign_keys = ON`

**MySQL**: Abilitato di default

**Benefit**: Previene orphan records, mantiene integrità referenziale.

### User Permissions

**Production**:
```sql
CREATE USER 'cimaise'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON cimaise.* TO 'cimaise'@'localhost';
-- NO GRANT ALL, NO DROP, NO ALTER
```

**Policy**: Least privilege principle.

---

## 13. Backup Security

### Backup Encryption

```bash
# Backup DB cifrato
mysqldump cimaise | gzip | openssl enc -aes-256-cbc -salt -out backup.sql.gz.enc
```

### Access Control

```bash
chmod 600 backup.sql.gz.enc
chown cimaise:cimaise backup.sql.gz.enc
```

**Storage**: Off-site, access limitato.

---

## 14. Secure Coding Checklist

### Pre-Commit

- [ ] Tutte le query usano prepared statements
- [ ] Output Twig escaped (o sanitized se raw)
- [ ] CSRF token su tutti i form POST/PUT/DELETE
- [ ] File upload validati (MIME + magic number)
- [ ] Password hashe con Argon2id/bcrypt
- [ ] No secrets hardcoded
- [ ] Rate limiting su endpoint sensibili
- [ ] Security headers configurati
- [ ] Input validation server-side (mai solo client)

### Pre-Deploy

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] HTTPS abilitato
- [ ] Session secure cookies (`secure=true`)
- [ ] Database credentials rotated
- [ ] `.env` permessi 600
- [ ] `storage/` e `database/` non web-accessible
- [ ] Composer audit run
- [ ] Security headers verificati

---

## 15. Incident Response

### In caso di compromissione:

1. **Isola**:
   - Metti sito in manutenzione
   - Blocca accessi sospetti via firewall

2. **Investiga**:
   - Analizza log (`storage/logs/`)
   - Controlla file modificati (`find . -mtime -1`)
   - Verifica query sospette in DB

3. **Remediate**:
   - Cambia TUTTE le password (DB, admin, .env secrets)
   - Regenera session keys
   - Patch vulnerabilità
   - Restore da backup pulito se necessario

4. **Previeni**:
   - Analizza causa root
   - Implementa fix
   - Update security checklist

---

## 16. Security Testing

### Manual Tests

```bash
# SQL Injection test
curl "http://localhost/album/test' OR '1'='1"

# CSRF test
curl -X POST http://localhost/admin/albums/1/delete

# XSS test
# Upload file con nome: <script>alert('xss')</script>.jpg
```

**Expected**: Tutti bloccati.

### Automated

```bash
# PHP Security Checker
composer require sensiolabs/security-checker
php vendor/bin/security-checker security:check

# Static Analysis
composer require phpstan/phpstan
vendor/bin/phpstan analyse app
```

---

## 17. Compliance

### GDPR

- [ ] IP anonymization abilitata
- [ ] Data retention configurata
- [ ] Cookie consent (frontend - implementare)
- [ ] Privacy policy aggiornata
- [ ] Right to be forgotten (delete analytics data on request)

### Best Practices

- OWASP Top 10 addressed
- CWE/SANS Top 25 considered
- Regular security audits
- Penetration testing (production)

---

## Risorse

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [Slim Framework Security](https://www.slimframework.com/docs/v4/concepts/middleware.html)

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
