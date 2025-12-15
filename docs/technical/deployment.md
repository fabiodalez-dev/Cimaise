# Installazione e Deployment - Cimaise

## Requisiti di Sistema

### Server Requirements

**PHP**:
- Versione: 8.2 o superiore
- Estensioni obbligatorie:
  - `pdo_mysql` o `pdo_sqlite`
  - `gd` o `imagick` (processing immagini)
  - `exif` (metadata)
  - `mbstring`
  - `fileinfo`
  - `zip`
  - `json`

**Database**:
- **MySQL**: 8.0+ o **MariaDB**: 10.6+
- **SQLite**: 3.x (development/small sites)

**Web Server**:
- **Apache**: 2.4+ con `mod_rewrite`
- **Nginx**: 1.18+
- **Caddy**: 2.x (alternativa moderna)

**PHP Configuration** (raccomandato):
```ini
memory_limit = 256M
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
```

**Spazio Disco**:
- Applicazione: ~100MB
- Database: ~10MB (base) + crescita
- Media storage: Variabile (dipende da quante immagini)

---

## Installazione Guidata (Wizard)

### 1. Upload Files

```bash
# Via FTP/SFTP
upload Cimaise/* to /public_html/

# Via Git
git clone https://github.com/yourusername/Cimaise.git /var/www/Cimaise
cd /var/www/Cimaise
```

### 2. Set Permissions

```bash
# Storage directories (writable)
chmod -R 775 storage/
chmod -R 775 public/media/
chmod -R 775 database/

# .env (readable solo da owner)
chmod 600 .env

# Eseguibili
chmod +x bin/console
```

**Owner** (se condiviso con webserver):
```bash
chown -R www-data:www-data /var/www/Cimaise
# o
chown -R nginx:nginx /var/www/Cimaise
```

### 3. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader

npm install
npm run build
```

### 4. Configure Environment

```bash
cp .env.example .env
nano .env
```

**Production .env**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cimaise_prod
DB_USERNAME=cimaise_user
DB_PASSWORD=STRONG_RANDOM_PASSWORD

SESSION_SECRET=GENERATE_RANDOM_64_CHAR_STRING
```

**Genera SESSION_SECRET**:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 5. Setup Database

**MySQL**:
```sql
CREATE DATABASE cimaise_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cimaise_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON cimaise_prod.* TO 'cimaise_user'@'localhost';
FLUSH PRIVILEGES;
```

### 6. Run Installer

**Opzione A: Web Wizard**

Naviga a: `https://yourdomain.com/install`

Il wizard ti guiderà attraverso:
1. Database config
2. Admin user creation
3. Site settings
4. Conferma e install

**Opzione B: CLI**

```bash
php bin/console init

# O manualmente:
php bin/console migrate
php bin/console user:create
php bin/console sitemap:generate
```

---

## Configurazione Web Server

### Apache

**Document Root**: `/var/www/Cimaise/public`

**VirtualHost** (`/etc/apache2/sites-available/cimaise.conf`):
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/Cimaise/public

    <Directory /var/www/Cimaise/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/cimaise_error.log
    CustomLog ${APACHE_LOG_DIR}/cimaise_access.log combined

    # Security
    <FilesMatch "\.(?:env|git|htaccess|htpasswd)$">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

**Enable modules**:
```bash
a2enmod rewrite
a2enmod headers
a2enmod expires
```

**Enable site**:
```bash
a2ensite cimaise.conf
systemctl reload apache2
```

**HTTPS** (SSL via Let's Encrypt):
```bash
apt install certbot python3-certbot-apache
certbot --apache -d yourdomain.com -d www.yourdomain.com
```

---

### Nginx

**Config** (`/etc/nginx/sites-available/cimaise`):
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com www.yourdomain.com;

    root /var/www/Cimaise/public;
    index index.php;

    # Gzip compression
    gzip on;
    gzip_types text/css application/javascript image/svg+xml;
    gzip_min_length 1000;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|webp|avif|svg|ico|css|js|woff|woff2|ttf)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to sensitive files
    location ~ /\.(env|git|htaccess) {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny PHP execution in storage
    location ~* ^/storage/.+\.php$ {
        deny all;
    }
}
```

**Enable site**:
```bash
ln -s /etc/nginx/sites-available/cimaise /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

**HTTPS**:
```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

---

### Caddy

**Caddyfile**:
```
yourdomain.com {
    root * /var/www/Cimaise/public
    encode gzip
    php_fastcgi unix//var/run/php/php8.2-fpm.sock

    # Rewrite
    try_files {path} {path}/ /index.php?{query}

    # Security
    header X-Frame-Options SAMEORIGIN
    header X-Content-Type-Options nosniff

    # Static caching
    @static {
        path *.jpg *.jpeg *.png *.gif *.webp *.avif *.css *.js *.woff *.woff2
    }
    header @static Cache-Control "public, max-age=31536000, immutable"

    # Deny sensitive files
    @denied {
        path *.env .git/* .htaccess
    }
    respond @denied 404
}
```

**Start**:
```bash
caddy run --config /etc/caddy/Caddyfile
```

---

## Ottimizzazioni Produzione

### 1. PHP OPcache

**php.ini**:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0  ; Disabilita in prod (richiede reload dopo deploy)
opcache.revalidate_freq=0
opcache.interned_strings_buffer=16
```

### 2. Image Processing

**Imagick** (preferito vs GD per qualità):
```bash
apt install php-imagick
```

**Config PHP**:
```ini
[imagick]
imagick.memory_limit=512M
imagick.map_limit=1024M
```

### 3. Database Tuning

**MySQL my.cnf**:
```ini
[mysqld]
innodb_buffer_pool_size=1G  ; 70% RAM disponibile
innodb_log_file_size=256M
innodb_flush_log_at_trx_commit=2
query_cache_type=1
query_cache_size=64M
```

**Indici** (già creati dalle migrations, verifica):
```sql
SHOW INDEX FROM albums;
SHOW INDEX FROM images;
```

### 4. Caching (Future)

**Redis** (per session/cache):
```bash
apt install redis-server php-redis
```

**Config PHP session** (opzionale):
```ini
session.save_handler=redis
session.save_path="tcp://127.0.0.1:6379"
```

---

## Cron Jobs

### Analytics Cleanup (giornaliero)

```bash
# crontab -e
0 2 * * * cd /var/www/Cimaise && php bin/console analytics:cleanup --days 365 >> /var/log/cimaise_analytics.log 2>&1
```

### Analytics Summarize (giornaliero)

```bash
0 3 * * * cd /var/www/Cimaise && php bin/console analytics:summarize >> /var/log/cimaise_analytics.log 2>&1
```

### Image Generation (on-demand, o schedulato)

```bash
# Genera varianti mancanti ogni notte
0 4 * * * cd /var/www/Cimaise && php bin/console images:generate --missing >> /var/log/cimaise_images.log 2>&1
```

### Sitemap Regeneration (settimanale)

```bash
0 5 * * 0 cd /var/www/Cimaise && php bin/console sitemap:generate >> /var/log/cimaise_sitemap.log 2>&1
```

---

## Backup

### Database Backup

**Script** (`backup_db.sh`):
```bash
#!/bin/bash
DATE=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_DIR="/var/backups/cimaise"
DB_NAME="cimaise_prod"

mkdir -p $BACKUP_DIR

# Backup MySQL
mysqldump -u root -p$DB_PASSWORD $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Retention (30 giorni)
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete

echo "Backup completed: $BACKUP_DIR/db_$DATE.sql.gz"
```

**Cron**:
```bash
0 1 * * * /var/www/Cimaise/backup_db.sh >> /var/log/cimaise_backup.log 2>&1
```

### Media Files Backup

**rsync** a storage remoto:
```bash
#!/bin/bash
rsync -avz --delete \
  /var/www/Cimaise/storage/originals/ \
  user@backup-server:/backups/cimaise/originals/
```

**Alternative**:
- AWS S3: `aws s3 sync storage/originals/ s3://my-bucket/originals/`
- Rclone (multi-cloud)

---

## Monitoring

### Log Files

**Locations**:
- Apache: `/var/log/apache2/cimaise_*.log`
- Nginx: `/var/log/nginx/access.log`, `/var/log/nginx/error.log`
- PHP: `/var/log/php_errors.log` (configure in php.ini)
- Application: `storage/logs/` (implementare logger)

**Monitoring**:
```bash
# Real-time
tail -f /var/log/apache2/cimaise_error.log

# Analizza errori
grep "500" /var/log/apache2/cimaise_error.log | tail -20
```

### Uptime Monitoring

**Tools**:
- **UptimeRobot**: https://uptimerobot.com/
- **Pingdom**
- **StatusCake**

**Healthcheck endpoint**: `/api/analytics/ping` (HTTP 204)

### Performance Monitoring

**New Relic** (APM):
```bash
apt install newrelic-php5
newrelic-install install
```

**Alternative**: Blackfire, Tideways

---

## Security Hardening

### 1. File Permissions (strict)

```bash
# Owner: www-data (webserver user)
chown -R www-data:www-data /var/www/Cimaise

# Directories: 755
find /var/www/Cimaise -type d -exec chmod 755 {} \;

# Files: 644
find /var/www/Cimaise -type f -exec chmod 644 {} \;

# Writable storage: 775
chmod -R 775 /var/www/Cimaise/storage
chmod -R 775 /var/www/Cimaise/public/media

# .env: 600 (readable solo da owner)
chmod 600 /var/www/Cimaise/.env

# bin/console: 755 (executable)
chmod 755 /var/www/Cimaise/bin/console
```

### 2. Firewall (UFW)

```bash
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS
ufw enable
```

### 3. Fail2Ban (brute-force protection)

**Install**:
```bash
apt install fail2ban
```

**Config** (`/etc/fail2ban/jail.local`):
```ini
[apache-auth]
enabled = true
port = http,https
filter = apache-auth
logpath = /var/log/apache2/*error.log
maxretry = 5
bantime = 3600
```

### 4. Hide PHP Version

**php.ini**:
```ini
expose_php = Off
```

**Apache** (.htaccess già presente):
```apache
ServerSignature Off
ServerTokens Prod
```

---

## Deployment Automatizzato

### Deployer (PHP)

**Install**:
```bash
composer require deployer/deployer --dev
```

**deploy.php**:
```php
<?php
namespace Deployer;

require 'recipe/common.php';

set('application', 'Cimaise');
set('repository', 'git@github.com:user/Cimaise.git');

host('production')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '/var/www/Cimaise');

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'build:assets',
    'deploy:publish',
]);

task('build:assets', function () {
    run('cd {{release_path}} && npm install && npm run build');
});

after('deploy:failed', 'deploy:unlock');
```

**Deploy**:
```bash
vendor/bin/dep deploy production
```

---

## Subdirectory Installation

Se Cimaise è in sottodirectory (es. `https://example.com/photos/`):

### 1. Update .env

```env
APP_URL=https://example.com/photos
```

### 2. Update .htaccess

**public/.htaccess** (già configurato con RewriteBase auto-detection)

### 3. Assets Path

Vite gestisce automaticamente base path se `APP_URL` include subdirectory.

---

## Migration da Development a Production

### 1. Export Database

**Development**:
```bash
# SQLite
cp database/database.sqlite backups/dev_backup.sqlite

# MySQL
mysqldump cimaise_dev > backups/dev_backup.sql
```

### 2. Import in Production

```bash
mysql -u root -p cimaise_prod < backups/dev_backup.sql
```

### 3. Sync Media Files

```bash
rsync -avz storage/originals/ production-server:/var/www/Cimaise/storage/originals/
rsync -avz public/media/ production-server:/var/www/Cimaise/public/media/
```

### 4. Regenerate Images (production)

```bash
# Su production server
php bin/console images:generate
```

---

## Troubleshooting Deployment

### 500 Internal Server Error

```bash
# Check Apache/Nginx error log
tail -f /var/log/apache2/cimaise_error.log

# Check PHP error log
tail -f /var/log/php_errors.log

# Enable debug temporaneamente
# .env: APP_DEBUG=true (poi disabilitare subito dopo fix)
```

### Database Connection Failed

```bash
# Test connessione
php bin/console db:test

# Verifica credenziali
mysql -u cimaise_user -p cimaise_prod

# Check .env
cat .env | grep DB_
```

### Permissions Issues

```bash
# Reimposta permessi
chown -R www-data:www-data /var/www/Cimaise
chmod -R 775 storage/ public/media/
```

### Assets Not Loading

```bash
# Rebuild
npm run build

# Check ownership
ls -la public/assets/

# Check manifest
cat public/assets/manifest.json
```

---

## Checklist Pre-Lancio

- [ ] `.env` configurato correttamente (`APP_ENV=production`, `APP_DEBUG=false`)
- [ ] Database creato e migrato
- [ ] Admin user creato
- [ ] HTTPS abilitato (SSL certificate)
- [ ] File permissions corretti (755/644, storage 775)
- [ ] OPcache abilitato
- [ ] Cron jobs configurati (analytics, backup)
- [ ] Backup automatizzati attivi
- [ ] Monitoring configurato (uptime, logs)
- [ ] Firewall configurato
- [ ] Sitemap generata (`/sitemap.xml`)
- [ ] SEO settings configurati
- [ ] Analytics testato
- [ ] Immagini generate (`php bin/console images:generate`)
- [ ] Test upload immagini
- [ ] Test creazione album
- [ ] Test frontend (home, album, categorie)
- [ ] Test form contatto (About page)
- [ ] Verifica security headers (https://securityheaders.com/)
- [ ] Performance test (GTmetrix, PageSpeed Insights)

---

## Performance Baseline

**Targets**:
- **Homepage**: < 2s LCP (Largest Contentful Paint)
- **Album page**: < 3s LCP
- **Admin panel**: < 1s TTFB (Time To First Byte)
- **Lighthouse Score**: > 90 (Performance, Accessibility, SEO)

**Tools**:
- Google PageSpeed Insights
- GTmetrix
- WebPageTest
- Chrome DevTools Lighthouse

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
