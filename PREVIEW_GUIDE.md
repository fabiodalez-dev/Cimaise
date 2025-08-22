# Guida Anteprima - photoCMS

## Come vedere l'anteprima dell'app

### 1. Requisiti

Assicurati di avere installato:
- **PHP 8.2+** con estensioni: PDO, pdo_mysql, mbstring, json, fileinfo, gd (raccomandato)
- **MySQL 8.0+** o **MariaDB 10.6+**
- **Composer** per le dipendenze PHP

### 2. Setup Database

#### Opzione A: MySQL/MariaDB locale
```bash
# Crea database
mysql -u root -p
CREATE DATABASE photocms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'photocms'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL ON photocms.* TO 'photocms'@'localhost';
EXIT;
```

#### Opzione B: Docker (più semplice)
```bash
# Avvia MySQL con Docker
docker run --name photocms-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=photocms \
  -e MYSQL_USER=photocms \
  -e MYSQL_PASSWORD=photocms123 \
  -p 3306:3306 -d mysql:8
```

### 3. Configurazione

1. **Copia il file di configurazione:**
```bash
cp .env.example .env
```

2. **Modifica `.env` con i tuoi dati database:**
```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=photocms
DB_USER=photocms
DB_PASS=photocms123
```

3. **Installa dipendenze PHP:**
```bash
composer install
```

### 4. Inizializzazione

Esegui questi comandi in ordine:

```bash
# 1. Crea le tabelle
php bin/console migrate

# 2. Inserisci dati di esempio
php bin/console seed

# 3. Crea utente admin
php bin/console user:create admin@example.com

# 4. Verifica setup
php bin/console diagnostics
```

### 5. Avvia il Server

#### Server PHP Built-in (per sviluppo)
```bash
# Avvia server sulla porta 8000
php -S 127.0.0.1:8000 -t public

# O su porta diversa
php -S 127.0.0.1:8080 -t public
```

#### Apache/Nginx (per produzione)
- Document Root: `/path/to/photocms/public/`
- Index file: `index.php`
- Rewrite: Tutti gli URL verso `index.php`

### 6. Accesso

#### Frontend (Sito Pubblico)
- **URL:** http://127.0.0.1:8000/
- **Pagine:**
  - Home: `/`
  - Album: `/album/{slug}`
  - Categoria: `/category/{slug}`
  - Tag: `/tag/{slug}`

#### Backend (Admin)
- **URL:** http://127.0.0.1:8000/admin/login
- **Credenziali:** Email e password creata con `user:create`

### 7. Test delle Funzionalità

#### Nel Backend Admin:
1. **Dashboard:** Panoramica sistema
2. **Albums:** Crea album, carica immagini
3. **Categorie/Tag:** Organizza contenuti
4. **Lookup:** Gestisci camere, lenti, pellicole
5. **Settings:** Configura qualità immagini
6. **Commands:** Esegui comandi di sistema
7. **Diagnostics:** Verifica stato sistema

#### Nel Frontend:
1. **Griglia album:** Homepage con filtri
2. **Galleria album:** Lightbox con metadati EXIF
3. **Navigazione:** Categorie e tag
4. **Responsive:** Mobile e desktop

### 8. Dati di Test

Il comando `seed` inserisce:
- 3 categorie (Ritratti, Street, Paesaggi)
- Tag comuni (Analogico, 35mm, B/W, etc.)
- Camere/lenti/pellicole di esempio
- 1 album di test per categoria

### 9. Upload Immagini

1. Vai in Admin → Albums
2. Modifica un album o creane uno nuovo
3. Usa l'upload drag&drop
4. Le varianti (AVIF/WebP/JPEG) sono create automaticamente
5. I metadati EXIF vengono estratti automaticamente

### 10. Personalizzazione

#### Stile Frontend:
- File: `app/Views/frontend/`
- Framework: Tailwind CSS via CDN
- Colori: Tema bianco/nero minimalista

#### Configurazioni:
- Settings admin per qualità immagini
- File `.env` per database e config base

### 11. Troubleshooting

#### Errori comuni:

**"Database connection failed"**
```bash
# Verifica database
php bin/console db:test

# Controlla .env
cat .env
```

**"Permission denied"**
```bash
# Permessi cartelle
chmod -R 755 storage/ public/media/
chown -R www-data:www-data storage/ public/media/
```

**"Class not found"**
```bash
# Rigenera autoload
composer dump-autoload
```

**"Images not generating"**
```bash
# Verifica estensioni
php bin/console diagnostics

# Genera manualmente
php bin/console images:generate --missing
```

### 12. Performance

Per migliori performance:
- Usa **Imagick** invece di GD: `apt install php-imagick`
- Configura **Redis** per cache: `apt install redis-server php-redis`
- Server web con **mod_rewrite** abilitato
- PHP **memory_limit** almeno 256M per immagini grandi

### 13. Sitemap e SEO

```bash
# Genera sitemap
php bin/console sitemap:build --base-url=http://127.0.0.1:8000

# Verifica files generati
ls -la public/sitemap*.xml public/robots.txt
```

### 14. Backup

Importante per production:
```bash
# Database
mysqldump -u photocms -p photocms > backup_$(date +%Y%m%d).sql

# Immagini originali
tar -czf images_backup_$(date +%Y%m%d).tar.gz storage/originals/
```

## URL di Test

Dopo il setup, testa questi URL:

- **Frontend Home:** http://127.0.0.1:8000/
- **Admin Login:** http://127.0.0.1:8000/admin/login
- **API Albums:** http://127.0.0.1:8000/api/albums
- **Diagnostics:** http://127.0.0.1:8000/admin/diagnostics
- **Sitemap:** http://127.0.0.1:8000/sitemap.xml