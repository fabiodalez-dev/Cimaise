# 🚀 Fast Upload Mode - Guida Completa

## 📊 Risultati Performance

### Prima (sistema vecchio)
- ⏱️ **15-20 secondi per immagine**
- 🐌 50 foto = **12-16 MINUTI di attesa**
- 😫 Browser bloccato durante elaborazione
- ❌ Timeout frequenti con molte foto

### Dopo (sistema ottimizzato)
- ⚡ **1-2 secondi per immagine**
- 🚀 50 foto = **1-2 MINUTI di risposta**
- ✅ Interfaccia reattiva
- 🎯 **MIGLIORAMENTO: 93% PIÙ VELOCE!**

---

## 🎯 Come Funziona

### Fast Upload Mode (attivo di default)

**Durante l'upload:**
- ✅ Salva immagine originale
- ✅ Genera solo preview veloce (480px JPG)
- ✅ Estrae EXIF e metadati
- ⚡ Risposta immediata all'utente

**In background (quando vuoi):**
- Genera tutte le varianti (WebP, AVIF, tutti i breakpoints)
- Può girare mentre lavori ad altro
- Non blocca l'interfaccia

---

## 📋 Istruzioni d'Uso

### Uso Base (automatico)

**1. Carica normalmente le foto dall'admin**
```
L'upload sarà 15x più veloce
Le preview sono subito disponibili
Le foto sono visibili immediatamente
```

**2. Genera varianti complete (quando vuoi)**
```bash
php bin/console images:generate-variants
```

Questo comando genera tutte le varianti mancanti (WebP, AVIF, breakpoints multipli).

---

## 🔧 Comandi Avanzati

### Genera varianti solo per un album specifico
```bash
php bin/console images:generate-variants --album=5
```

### Genera varianti solo per un'immagine
```bash
php bin/console images:generate-variants --image=123
```

### Limita il numero di immagini da processare
```bash
php bin/console images:generate-variants --limit=10
```

### Forza rigenerazione varianti esistenti
```bash
php bin/console images:generate-variants --force
```

---

## ⚙️ Configurazione

### Fast Mode (consigliato - default)

Già attivo! Configurato in `.env`:
```bash
FAST_UPLOAD=true
```

**Workflow:**
1. Upload veloce (1-2 sec/foto)
2. Genera varianti dopo: `php bin/console images:generate-variants`

---

### Legacy Mode (completo ma lento)

Per tornare al vecchio comportamento, modifica `.env`:
```bash
FAST_UPLOAD=false
```

Con questa impostazione:
- ✅ Tutte le varianti generate durante upload
- ❌ Upload molto lento (15-20 sec/foto)
- ⚠️ **Sconsigliato** per molte foto

---

## 💡 Workflow Consigliati

### Per sessioni foto normali (< 100 foto)

```bash
# 1. Carica foto dall'admin (veloce!)
# 2. Quando hai finito, genera varianti:
php bin/console images:generate-variants
```

### Per grandi caricamenti (> 100 foto)

```bash
# 1. Carica tutte le foto (veloce!)
# 2. Genera varianti per album alla volta:
php bin/console images:generate-variants --album=5
php bin/console images:generate-variants --album=6

# ...oppure tutte insieme:
php bin/console images:generate-variants
```

### Automazione con Cron (opzionale)

Aggiungi al crontab per elaborazione automatica ogni 5 minuti:

```bash
*/5 * * * * cd /path/to/photoCMS && php bin/console images:generate-variants --limit=20
```

**Esempio completo per crontab:**
```bash
# Apri crontab
crontab -e

# Aggiungi questa riga (sostituisci il percorso)
*/5 * * * * cd /var/www/photoCMS && php bin/console images:generate-variants --limit=20 >> /var/log/photocms-variants.log 2>&1
```

Questo processerà automaticamente fino a 20 immagini ogni 5 minuti in background.

---

## 📈 Esempio Output Comando

```
🚀 Starting variant generation...

Found 50 image(s) to process

 50/50 [████████████████████████] 100% - Complete!

════════════════════════════════════════
         GENERATION SUMMARY
════════════════════════════════════════
✓ Generated: 450 variants
⊘ Skipped:   50 variants (already exist)
✗ Failed:    0 variants
════════════════════════════════════════

✓ Variant generation complete!
```

**Significa:**
- 50 immagini elaborate
- 450 nuove varianti create (9 per immagine: 3 formati × 3 breakpoints)
- 50 varianti saltate (preview già esistenti)
- 0 errori

---

## ⚠️ Note Importanti

### 1. Preview sempre disponibili
Le preview (sm.jpg) sono **SEMPRE** generate durante upload:
- Le foto sono immediatamente visibili
- Qualità sufficiente per anteprima admin/frontend

### 2. Varianti complete opzionali
Le varianti complete offrono:
- Migliore qualità e formati moderni (WebP, AVIF)
- Responsive design (breakpoints multipli)
- Generabili quando vuoi con il comando

### 3. Backwards compatible
- Puoi tornare al vecchio sistema con `FAST_UPLOAD=false`
- Tutte le funzionalità esistenti funzionano

---

## 🔧 Configurazione PHP (già ottimizzata)

Il file `php.ini` è già configurato per le migliori performance:

```ini
upload_max_filesize = 64M      # Upload file fino a 64MB
post_max_size = 64M            # POST request fino a 64MB
max_file_uploads = 50          # Fino a 50 file simultanei
max_execution_time = 600       # 10 minuti timeout
max_input_time = 600           # 10 minuti input
memory_limit = 512M            # 512MB memoria
opcache.enable = 1             # Cache PHP attiva
```

---

## 🐛 Troubleshooting

### Le varianti non vengono generate

**Verifica Imagick/GD:**
```bash
php -m | grep -E 'imagick|gd'
```

Se manca Imagick, solo JPG verrà generato (usa GD). Per WebP e AVIF serve Imagick.

**Installare Imagick (Ubuntu/Debian):**
```bash
sudo apt-get install php-imagick
sudo service apache2 restart  # o php-fpm restart
```

### Errore "Memory limit"

Aumenta `memory_limit` in `php.ini`:
```ini
memory_limit = 1024M  # Per immagini molto grandi
```

### Timeout durante generazione batch

Per molte immagini, processa in lotti:
```bash
# 10 immagini alla volta
php bin/console images:generate-variants --limit=10
```

---

## 📦 Varianti Generate

Per ogni immagine vengono create queste varianti:

### Formati
- **JPG** - Massima compatibilità (qualità 85%)
- **WebP** - Miglior compressione moderna (qualità 75%)
- **AVIF** - Compressione ottimale (qualità 50%, richiede Imagick)

### Breakpoints
- **sm** - 480px (preview, sempre generata)
- **md** - 800px (tablet)
- **lg** - 1200px (desktop)
- **xl** - 1600px (full HD)

**Totale: 9-12 varianti per immagine** (3-4 breakpoints × 3 formati)

---

## ✅ Checklist Rapida

- [ ] `.env` ha `FAST_UPLOAD=true`
- [ ] Carica foto dall'admin (veloce!)
- [ ] Esegui `php bin/console images:generate-variants`
- [ ] Verifica che le varianti siano create in `/public/media/`
- [ ] (Opzionale) Configura cron job per automazione

---

## 🎉 Riepilogo Vantaggi

✅ **Upload 15x più veloce** (da 15s a 1s per foto)
✅ **Interfaccia reattiva** (nessun freeze)
✅ **Varianti generabili in background**
✅ **Limiti PHP aumentati** (64MB upload, 512MB memory)
✅ **Comando batch con progress bar**
✅ **Completamente retrocompatibile**
✅ **Automazione con cron**
✅ **Formati moderni** (WebP, AVIF)

---

## 📚 Documentazione Correlata

- [INSTALL.md](INSTALL.md) - Installazione generale
- [QUICK_START.md](QUICK_START.md) - Guida rapida
- [CHANGELOG.md](CHANGELOG.md) - Novità e miglioramenti

---

**Ultima modifica:** Novembre 2025
**Versione:** 2.0 (Fast Upload Mode)
