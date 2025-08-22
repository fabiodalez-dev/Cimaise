# Quick Start - photoCMS

Avvia il tuo CMS fotografico in 5 minuti! ⚡

## Setup Veloce

### 1. Database (Docker - Consigliato)

```bash
# Avvia MySQL con Docker
docker run --name photocms-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=photocms \
  -e MYSQL_USER=photocms \
  -e MYSQL_PASSWORD=photocms123 \
  -p 3306:3306 -d mysql:8

# Aspetta che il container si avvii (30 secondi circa)
```

### 2. Configura App

```bash
# Copia configurazione
cp .env.example .env

# Installa dipendenze
composer install

# Inizializza tutto automaticamente
php bin/console init
```

Il comando `init` fa tutto automaticamente:
- ✅ Testa connessione database
- ✅ Crea tabelle (migrate)
- ✅ Inserisce dati demo (seed)
- ✅ Crea utente admin
- ✅ Crea cartelle necessarie
- ✅ Genera sitemap

### 3. Avvia Server

```bash
# Server PHP locale
php -S 127.0.0.1:8000 -t public
```

### 4. Inizia a Usarlo! 🚀

- **Frontend:** http://127.0.0.1:8000/
- **Admin:** http://127.0.0.1:8000/admin/login
- **Login:** Email e password creati durante init

## Comandi Utili Admin

Vai su **Admin → Commands** per eseguire tramite interfaccia web:

- **Generate Images:** Crea varianti AVIF/WebP/JPEG
- **Build Sitemap:** Aggiorna SEO
- **Run Diagnostics:** Verifica sistema

## File di Configurazione

### .env
```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=photocms
DB_USER=photocms
DB_PASS=photocms123
```

## Cosa Trovi Dopo l'Init

- 📁 **3 Categorie:** Ritratti, Street, Paesaggi
- 🏷️ **Tag:** Analogico, 35mm, B/W, C-41, E-6...
- 📷 **Lookup:** Camere, lenti, pellicole di esempio
- 📖 **1 Album** per ogni categoria

## Prossimi Passi

1. **Carica Immagini:** Admin → Albums → Edit → Upload
2. **Personalizza:** Admin → Settings (qualità immagini)
3. **Organizza:** Crea nuove categorie e tag
4. **Metadati:** Gestisci camere/lenti/pellicole in Admin

## Troubleshooting

### Database non si connette?
```bash
php bin/console db:test
```

### Errori permissions?
```bash
chmod -R 755 storage/ public/media/
```

### Immagini non si caricano?
```bash
php bin/console diagnostics
```

## Guide Complete

- 📖 **PREVIEW_GUIDE.md** - Guida completa setup
- 🖥️ **CLI_GUIDE.md** - Tutti i comandi disponibili
- 📋 **AGENTS.md** - Documentazione tecnica completa

---

**Pronto in 5 minuti!** 🎉 Vai su http://127.0.0.1:8000/ per vedere il tuo CMS fotografico!