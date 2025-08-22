# 🎨 Setup Templates System

## ⚠️ IMPORTANTE: Esegui i comandi in questo ordine!

### 1. Esegui migrazioni database
```bash
# Applica TUTTE le migrazioni (inclusa templates)
php bin/console db:migrate
```

### 2. Popola templates di default
```bash
# Carica i template predefiniti (DOPO le migrazioni!)
php bin/console db:seed 0003_templates.sql
```

### 3. Testa il sistema
```bash
# Avvia server locale
php -S 127.0.0.1:8002 -t public

# Vai su http://127.0.0.1:8002/admin/templates
```

## ✅ Setup Completato!

Il sistema templates è ora attivo con **CDN** (non serve Node.js):

## Template Disponibili

### 📑 Grid Classica
- Layout: Griglia 3 colonne
- PhotoSwipe: Base con loop e zoom
- Uso: Album standard

### 🧱 Masonry Portfolio  
- Layout: Masonry dinamico 4 colonne
- PhotoSwipe: Con condivisione abilitata
- Uso: Portfolio fotografici artistici

### 🎭 Slideshow Minimal
- Layout: Slideshow singola immagine
- PhotoSwipe: Controlli minimali
- Uso: Presentazioni narrative

### 🖼️ Gallery Fullscreen
- Layout: 2 colonne immersive
- PhotoSwipe: Esperienza completa
- Uso: Showcase di alta qualità

## Librerie CDN Utilizzate

- **PhotoSwipe 5.4.4**: Lightbox moderno
- **Swiper 11**: Slider/carousel
- **GSAP 3.12**: Animazioni smooth
- **Tailwind CSS**: Framework CSS via CDN

## Come Usare

1. Vai su `/admin/templates` per gestire i template
2. Crea/modifica album in `/admin/albums/create` 
3. Seleziona template dal dropdown "Template"
4. Visualizza risultato nel frontend

## Personalizzazione

Ogni template ha impostazioni JSON configurabili:
- **Layout**: grid, masonry, slideshow, fullscreen  
- **Colonne**: 1-6 per griglie
- **PhotoSwipe**: loop, zoom, share, counter, ecc.

I template sono completamente server-side, **nessuna build richiesta**!