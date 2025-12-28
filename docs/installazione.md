# Installazione di Cimaise

Guida completa all'installazione di Cimaise sul tuo server.

## Requisiti di Sistema

Prima di iniziare, assicurati che il tuo server soddisfi i [requisiti minimi](./requisiti.md):

- **PHP 8.2+** con estensioni: `pdo_sqlite` o `pdo_mysql`, `gd`, `curl`, `mbstring`, `json`
- **Composer 2.x**
- **Node.js 18+** e npm (solo per build frontend)
- **Web server**: Apache 2.4+, Nginx 1.18+, o Caddy 2.0+
- **Database**: SQLite 3.x (default, zero config) oppure MySQL 8.0+

## Metodo 1: Installazione Rapida (5 minuti)

### 1. Clona il Repository

```bash
git clone https://github.com/yourusername/cimaise.git
cd cimaise
```

### 2. Installa le Dipendenze

```bash
# Backend (PHP)
composer install

# Frontend (JavaScript/CSS)
npm install
npm run build
```

### 3. Avvia l'Installer Web

Avvia il server di sviluppo PHP:

```bash
php -S localhost:8080 -t public public/router.php
```

Apri il browser e vai su: **http://localhost:8080/install**

### 4. Segui il Wizard di Installazione

L'installer ti guiderà attraverso 4 step:

#### Step 1: Scelta Database
- **SQLite** (consigliato): zero configurazione, perfetto per siti piccoli/medi
- **MySQL**: per siti ad alto traffico o installazioni condivise

#### Step 2: Account Amministratore
- Email
- Password (minimo 8 caratteri)
- Nome visualizzato

#### Step 3: Configurazione Sito
- Titolo del sito
- Descrizione (per SEO)
- Lingua (Italiano o Inglese)
- Logo (opzionale, puoi caricarlo dopo)

#### Step 4: Completamento
- Il sistema crea il database
- Genera le tabelle
- Inserisce i dati di default
- Crea il file di configurazione

### 5. Login e Inizia!

Al termine dell'installazione verrai reindirizzato al login:

```
http://localhost:8080/admin/login
```

Inserisci le credenziali create nello step 2 e inizia a caricare le tue foto!

---

## Metodo 2: Installazione CLI (per esperti)

Se preferisci usare il terminale:

```bash
# 1. Clona e installa dipendenze
git clone https://github.com/yourusername/cimaise.git
cd cimaise
composer install
npm install && npm run build

# 2. Lancia l'installer interattivo
php bin/console install
```

L'installer CLI ti farà le stesse domande del wizard web attraverso prompt interattivi.

### Opzioni CLI Avanzate

```bash
# Installazione non interattiva (per automation)
php bin/console install \
  --db-type=sqlite \
  --admin-email=admin@example.com \
  --admin-password=SecureP@ss123 \
  --site-title="My Portfolio" \
  --site-language=it \
  --non-interactive

# Installazione con MySQL
php bin/console install \
  --db-type=mysql \
  --db-host=localhost \
  --db-name=cimaise \
  --db-user=root \
  --db-password=password
```

---

## Configurazione Database

### SQLite (Consigliato per Iniziare)

**Vantaggi:**
- Zero configurazione
- Nessun server database da gestire
- Perfetto per siti fino a 10.000 immagini
- Backup semplice: copia un file

**Svantaggi:**
- Performance inferiori su concorrenza molto alta
- Non ideale per hosting condiviso con limiti di I/O

Il database viene creato automaticamente in:
```
storage/database/cimaise.db
```

### MySQL/MariaDB

**Vantaggi:**
- Performance superiori con molte query concorrenti
- Meglio per installazioni multi-utente
- Standard per hosting condiviso

**Configurazione manuale:**

```bash
# 1. Crea il database
mysql -u root -p
CREATE DATABASE cimaise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cimaise'@'localhost' IDENTIFIED BY 'your-secure-password';
GRANT ALL PRIVILEGES ON cimaise.* TO 'cimaise'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# 2. Configura .env
cp .env.example .env
nano .env
```

Nel file `.env`:

```env
DB_TYPE=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=cimaise
DB_USER=cimaise
DB_PASSWORD=your-secure-password
```

```bash
# 3. Esegui le migrazioni
php bin/console migrate
php bin/console seed
```

---

## Configurazione Web Server

### Apache

Cimaise include già un file `.htaccess` completo. Assicurati che `mod_rewrite` sia abilitato:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Configurazione VirtualHost consigliata:

```apache
<VirtualHost *:80>
    ServerName myphotography.com
    DocumentRoot /var/www/cimaise/public

    <Directory /var/www/cimaise/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Log (opzionali)
    ErrorLog ${APACHE_LOG_DIR}/cimaise-error.log
    CustomLog ${APACHE_LOG_DIR}/cimaise-access.log combined
</VirtualHost>
```

### Nginx

Esempio di configurazione:

```nginx
server {
    listen 80;
    server_name myphotography.com;
    root /var/www/cimaise/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Main router
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Cache static assets aggressively
    location ~* \.(avif|webp|jpg|jpeg|png|gif|ico|css|js|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /(storage|database|vendor)/ {
        deny all;
    }
}
```

Dopo aver salvato la configurazione:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Caddy (Più Semplice)

Crea un file `Caddyfile`:

```caddy
myphotography.com {
    root * /var/www/cimaise/public
    php_fastcgi unix//var/run/php/php8.2-fpm.sock
    file_server

    # Rewrite per router PHP
    try_files {path} {path}/ /index.php?{query}

    # Cache automatico con header ottimali
    @static {
        path *.avif *.webp *.jpg *.jpeg *.png *.gif *.ico *.css *.js *.woff2
    }
    header @static Cache-Control "public, max-age=31536000, immutable"

    # HTTPS automatico con Let's Encrypt (built-in!)
}
```

Avvia Caddy:

```bash
caddy run
```

---

## Installazione in Sottodirectory

Se installi Cimaise in una sottodirectory (es. `yoursite.com/portfolio/`), Cimaise rileva automaticamente il base path. **Non serve configurazione aggiuntiva!**

Esempio Apache:

```apache
Alias /portfolio /var/www/cimaise/public

<Directory /var/www/cimaise/public>
    AllowOverride All
    Require all granted
</Directory>
```

Esempio Nginx:

```nginx
location /portfolio {
    alias /var/www/cimaise/public;
    try_files $uri $uri/ /portfolio/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        include fastcgi_params;
    }
}
```

---

## Permessi File e Cartelle

Imposta i permessi corretti per sicurezza e funzionalità:

```bash
# Proprietario web server (esempio con www-data su Ubuntu/Debian)
sudo chown -R www-data:www-data /var/www/cimaise

# Permessi base
sudo find /var/www/cimaise -type f -exec chmod 644 {} \;
sudo find /var/www/cimaise -type d -exec chmod 755 {} \;

# Cartelle scrivibili
sudo chmod -R 775 storage/
sudo chmod -R 775 public/media/
sudo chmod -R 775 public/fonts/

# File di configurazione
sudo chmod 600 .env
sudo chmod 600 config/database.php
```

---

## Post-Installazione: Primi Passi

### 1. Carica il Logo

```
Admin → Impostazioni → Identità Sito → Logo
```

Formati supportati: PNG, JPG, SVG
Dimensioni consigliate: 200-300px larghezza, sfondo trasparente

### 2. Configura le Impostazioni Base

- **Titolo sito**: Il nome del tuo portfolio
- **Descrizione**: Breve bio (usata per SEO)
- **Email**: Dove ricevere messaggi dal form contatti
- **Copyright**: Testo footer (usa `{year}` per anno automatico)

### 3. Scegli la Home Page

```
Admin → Pagine → Home Page
```

Prova i 7 template disponibili e scegli quello che rappresenta meglio il tuo stile.

### 4. Crea la Prima Galleria

```
Admin → Album → Nuovo Album
```

- Carica 5-10 foto
- Assegna categoria e tag
- Scegli il template di visualizzazione
- Pubblica!

### 5. Personalizza la Tipografia

```
Admin → Impostazioni → Tipografia
```

Scegli i font per headings, body, captions. Oltre 40 font disponibili con anteprima live.

---

## Risoluzione Problemi Comuni

### Errore 500 dopo l'installazione

**Causa**: Permessi file o mod_rewrite mancante

**Soluzione**:
```bash
# Verifica permessi
ls -la storage/
ls -la public/media/

# Apache: abilita mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### "Database not found"

**Causa**: File `.env` mancante o configurazione database errata

**Soluzione**:
```bash
# Verifica che .env esista
ls -la .env

# Se manca, copialo da .env.example
cp .env.example .env

# Controlla configurazione DB
cat .env | grep DB_
```

### Immagini non si caricano

**Causa**: Permessi scrittura su `public/media/` e `storage/originals/`

**Soluzione**:
```bash
sudo chown -R www-data:www-data public/media/
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 public/media/ storage/
```

### CSS/JS non funzionano

**Causa**: Asset non buildati

**Soluzione**:
```bash
npm install
npm run build

# Verifica che siano stati creati
ls -la public/assets/
```

### Timeout durante upload grandi

**Causa**: Limiti PHP troppo bassi

**Soluzione** (modifica `php.ini`):
```ini
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
memory_limit = 256M
```

Riavvia PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

---

## Aggiornamenti

Per aggiornare Cimaise a una nuova versione:

```bash
# 1. Backup del database
php bin/console backup:database

# 2. Backup dei file
tar -czf backup-$(date +%Y%m%d).tar.gz storage/ public/media/ .env

# 3. Pull nuova versione
git pull origin main

# 4. Aggiorna dipendenze
composer install --no-dev
npm install && npm run build

# 5. Esegui migrazioni database
php bin/console migrate

# 6. Cancella cache
rm -rf storage/cache/*
```

---

## Prossimi Passi

✅ Installazione completata!

Ora puoi:
- [Configurare le impostazioni generali](./impostazioni.md)
- [Personalizzare la tipografia](./tipografia.md)
- [Creare il tuo primo album](./album-gallerie.md)
- [Abilitare il dark mode](./dark-mode.md)
- [Proteggere album con password](./album-password.md)

Hai bisogno di aiuto? Consulta le [FAQ](./faq.md) o apri una [discussione su GitHub](https://github.com/yourusername/cimaise/discussions).
