# Guida Installazione - photoCMS

Questa guida ti accompagna passo-passo nell'installazione di photoCMS sul tuo server.

---

## Opzioni di Installazione

Ci sono due modi per installare photoCMS:

1. **🌐 Wizard Web** (raccomandato) - Interfaccia grafica user-friendly
2. **⚡ CLI** (avanzato) - Installazione tramite terminale

---

## Preparazione

### 1. Verifica Requisiti Server

**PHP Versione**:
```bash
php -v
# Deve essere 8.2 o superiore
```

**Estensioni PHP**:
```bash
php -m
# Verifica presenti: pdo_mysql, gd (o imagick), exif, mbstring, fileinfo
```

**Database**:
- MySQL 8.0+ / MariaDB 10.6+ (raccomandato per produzione)
- SQLite 3.x (ok per sviluppo e piccoli siti)

### 2. Upload Files

**Via FTP/SFTP**:
1. Scarica photoCMS da [link repository]
2. Estrai lo ZIP
3. Upload tutti i file nella directory del tuo sito (es. `public_html/`)

**Via Git** (se hai accesso SSH):
```bash
git clone https://github.com/yourusername/photoCMS.git /var/www/photoCMS
cd /var/www/photoCMS
```

### 3. Imposta Permessi

**Linux/Mac**:
```bash
chmod -R 775 storage/
chmod -R 775 public/media/
chmod -R 775 database/
chmod 600 .env
```

**Windows**: Click destro → Proprietà → Sicurezza → Permetti "Modifica" per IIS_IUSRS

---

## Installazione via Wizard Web

### Passo 1: Avvia il Wizard

Apri il browser e naviga a:
```
https://tuosito.com/install
```

Vedrai la schermata di benvenuto del wizard.

### Passo 2: Configurazione Database

**Opzione A: SQLite** (più semplice):
- Seleziona "SQLite"
- Il wizard creerà automaticamente il file database
- Click "Avanti"

**Opzione B: MySQL**:
- Seleziona "MySQL"
- Inserisci i dati:
  - **Host**: `localhost` (o IP server MySQL)
  - **Porta**: `3306`
  - **Nome Database**: `photocms` (crealo prima via phpMyAdmin)
  - **Username**: Il tuo utente MySQL
  - **Password**: La password MySQL
- Click "Testa Connessione"
- Se OK, click "Avanti"

**Come creare il database MySQL**:

Via phpMyAdmin:
1. Login a phpMyAdmin
2. Click "Nuovo" (New)
3. Nome: `photocms`
4. Collation: `utf8mb4_unicode_ci`
5. Click "Crea" (Create)

Via CLI:
```sql
CREATE DATABASE photocms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Passo 3: Crea Utente Admin

Compila il form:
- **Email**: Il tuo indirizzo email (login)
- **Password**: Password sicura (min 8 caratteri)
- **Conferma Password**: Ripeti la password
- **Nome**: (opzionale)
- **Cognome**: (opzionale)

**Nota**: Ricorda bene queste credenziali! Le userai per accedere all'admin panel.

### Passo 4: Impostazioni Sito

Configura:
- **Titolo Sito**: Nome del tuo portfolio (es. "John Doe Photography")
- **Descrizione**: Breve descrizione del sito
- **Email Contatto**: Email per form contatto

### Passo 5: Conferma e Installa

Rivedi tutte le impostazioni:
- Configurazione database ✓
- Credenziali admin ✓
- Info sito ✓

Click **"Installa Ora"**

Il wizard eseguirà automaticamente:
1. ✅ Creazione tabelle database (migrations)
2. ✅ Inserimento dati demo (seed)
3. ✅ Creazione utente amministratore
4. ✅ Configurazione impostazioni
5. ✅ Generazione sitemap

**Durata**: ~30 secondi

### Passo 6: Completamento

🎉 **Installazione Completata!**

Opzioni:
- **Vai al Sito** → Visualizza il frontend
- **Login Admin** → Accedi al pannello amministrativo

---

## Installazione via CLI

### 1. Install Dependencies

```bash
cd /var/www/photoCMS

# PHP dependencies
composer install --no-dev --optimize-autoloader

# JavaScript dependencies
npm install
npm run build
```

### 2. Configure Environment

```bash
cp .env.example .env
nano .env  # o usa il tuo editor preferito
```

**Modifica .env**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tuosito.com

DB_CONNECTION=mysql  # o sqlite
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=photocms
DB_USERNAME=tuo_user
DB_PASSWORD=tua_password

SESSION_SECRET=genera_stringa_random_64_caratteri
```

**Genera SESSION_SECRET**:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 3. Initialize Database

```bash
# Esegue migrazioni + seed + crea user + sitemap
php bin/console init
```

Segui le istruzioni interattive per creare l'admin user.

**Oppure manualmente**:
```bash
php bin/console migrate         # Crea tabelle
php bin/console seed            # Dati demo
php bin/console user:create     # Crea admin
php bin/console sitemap:generate
```

### 4. Set Permissions

```bash
chown -R www-data:www-data /var/www/photoCMS
chmod -R 775 storage/ public/media/ database/
chmod 600 .env
```

### 5. Configure Web Server

Vedi [Deployment Guide](../technical/deployment.md) per configurazione Apache/Nginx.

---

## Post-Installazione

### 1. Login Admin

Vai a: `https://tuosito.com/admin/login`

Inserisci le credenziali create durante installazione.

### 2. Configura Impostazioni

**Menu Admin → Impostazioni**:

- **Image Processing**:
  - Formati abilitati: AVIF ✓, WebP ✓, JPEG ✓
  - Qualità: Default va bene (AVIF 50, WebP 75, JPEG 85)

- **Site Settings**:
  - Titolo sito
  - Logo (upload)
  - Email contatto
  - Copyright

### 3. Configura SEO

**Menu Admin → SEO**:
- Site title (per meta tags)
- Robots default: `index,follow`
- Author name (tuo nome)
- Photographer job title

### 4. Genera Immagini

Se hai caricato immagini durante seed/demo:

**Menu Admin → Impostazioni → Genera Immagini**

Questo crea le varianti responsive (può richiedere tempo se molte immagini).

### 5. Verifica Frontend

Apri `https://tuosito.com` e verifica:
- Home page carica correttamente ✓
- Immagini visibili ✓
- Navigazione funziona ✓
- Album accessibili ✓

---

## Setup HTTPS (SSL)

**Raccomandato per produzione!**

### Via Let's Encrypt (Gratuito)

**Apache**:
```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d tuosito.com -d www.tuosito.com
```

**Nginx**:
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d tuosito.com -d www.tuosito.com
```

**Auto-rinnovo**:
```bash
# Testa rinnovo
sudo certbot renew --dry-run

# Cron automatico (già configurato da certbot)
```

### Via Hosting Provider

Se usi hosting condiviso:
1. Login pannello hosting (cPanel, Plesk, ecc.)
2. Cerca "SSL/TLS" o "Let's Encrypt"
3. Seleziona il dominio
4. Click "Installa Certificato"

---

## Rimozione Dati Demo

Se vuoi rimuovere gli album/immagini demo:

**Via Admin**:
1. **Albums** → Seleziona album demo
2. Click "Elimina" per ciascuno
3. **Categories** → Elimina categorie demo (opzionale)
4. **Tags** → Elimina tag demo (opzionale)

**Via CLI**:
```sql
-- Backup prima!
DELETE FROM albums WHERE id IN (SELECT id FROM albums LIMIT 10);
-- Cancellati anche le immagini associate (CASCADE)
```

---

## Troubleshooting Installazione

### Database Connection Failed

**Verifica credenziali**:
```bash
mysql -u tuo_user -p -h localhost photocms
```

Se fallisce, verifica username/password in `.env`

**Check esistenza database**:
```sql
SHOW DATABASES;
```

### 500 Internal Server Error

**Check permessi**:
```bash
ls -la storage/
# Deve essere writable (775 o 777)
```

**Check error log**:
```bash
tail -f /var/log/apache2/error.log
# o
tail -f /var/log/nginx/error.log
```

**Enable debug temporaneamente**:
```env
# .env
APP_DEBUG=true
```

Ricarica pagina, leggi errore, fixxa, **ridisabilita debug**.

### Images Not Showing

**Verifica GD/Imagick**:
```bash
php -m | grep -E 'gd|imagick'
```

Se mancante:
```bash
sudo apt install php8.2-gd php8.2-imagick
sudo systemctl restart apache2
```

**Genera varianti**:
```bash
php bin/console images:generate
```

### Wizard Non Accessibile

Se vedi 404 su `/install`:

**Check .htaccess** (Apache):
```bash
cat public/.htaccess
```

Deve contenere regole rewrite.

**Enable mod_rewrite**:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**Nginx**: Verifica config try_files (vedi deployment guide)

---

## Prossimi Passi

✅ Installazione completata!

Ora puoi:

1. **[Primi Passi](./getting-started.md)** - Crea il tuo primo album
2. **[Gestione Album](./albums-management.md)** - Impara a gestire contenuti
3. **[Impostazioni](./settings.md)** - Personalizza il sito

---

**Versione**: 1.0.0
**Ultimo aggiornamento**: 17 Novembre 2025
