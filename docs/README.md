# Cimaise - Documentazione Completa

Benvenuto nella documentazione completa di **Cimaise**, un sistema di gestione contenuti (CMS) professionale per portfolio fotografici.

**Versione**: 1.0.0
**Ultimo aggiornamento**: 17 Novembre 2025

---

## 📚 Indice Documentazione

Questa documentazione è divisa in due sezioni principali:

### 🛠️ [Documentazione Tecnica](./technical/)
*Per sviluppatori, sistemisti e contributor*

1. **[Architettura e Flusso](./technical/architecture.md)**
   - Panoramica del progetto
   - Struttura delle cartelle
   - Stack tecnologico
   - Flusso di una richiesta HTTP
   - Pattern architetturali utilizzati

2. **[Schema Database](./technical/database.md)**
   - Schema completo delle tabelle
   - Relazioni tra entità
   - Indici e ottimizzazioni
   - Migrazioni

3. **[API e Endpoint](./technical/api.md)**
   - API pubbliche (frontend)
   - API amministrative
   - Autenticazione e autorizzazione
   - Rate limiting

4. **[Sicurezza](./technical/security.md)**
   - CSRF Protection
   - XSS Prevention
   - SQL Injection Protection
   - File Upload Security
   - Password Hashing
   - Security Headers
   - Best practices

5. **[Guida Sviluppo](./technical/development.md)**
   - Setup ambiente di sviluppo
   - Struttura del codice
   - Convenzioni di codice
   - Testing
   - Debug e logging
   - Estensioni e customizzazione

6. **[Installazione e Deployment](./technical/deployment.md)**
   - Requisiti di sistema
   - Installazione step-by-step
   - Configurazione server web
   - Deployment in produzione
   - Backup e manutenzione
   - Comandi CLI

---

### 👥 [Manuale Utente](./user-manual/)
*Per amministratori, editori e utilizzatori finali*

1. **[Introduzione](./user-manual/introduction.md)**
   - Cos'è Cimaise
   - Caratteristiche principali
   - Requisiti
   - Supporto e risorse

2. **[Installazione](./user-manual/installation.md)**
   - Installazione guidata (wizard)
   - Configurazione iniziale
   - Configurazione database
   - Creazione primo utente amministratore

3. **[Primi Passi](./user-manual/getting-started.md)**
   - Primo login
   - Panoramica dashboard
   - Creare il primo album
   - Pubblicare contenuti
   - Visualizzare il sito

4. **[Funzionalità Frontend](./user-manual/frontend-features.md)**
   - Home page con galleria infinita
   - Visualizzazione album
   - Navigazione per categorie
   - Navigazione per tag
   - Gallerie con filtri avanzati
   - Pagina About e contatti
   - Lightbox e zoom immagini
   - Download immagini

5. **[Pannello Amministrativo](./user-manual/admin-panel.md)**
   - Panoramica interfaccia admin
   - Dashboard e statistiche
   - Menu di navigazione
   - Gestione profilo utente
   - Cambio password

6. **[Gestione Album e Contenuti](./user-manual/albums-management.md)**
   - Creare un nuovo album
   - Upload immagini (singole e multiple)
   - Modifica metadati album
   - Gestione immagini dell'album
   - Riordinare immagini
   - Impostare immagine di copertina
   - Metadati fotografici (camera, lens, EXIF)
   - Pubblicare/nascondere album
   - Proteggere album con password
   - Gestione categorie
   - Gestione tag
   - Template e layout

7. **[Impostazioni](./user-manual/settings.md)**
   - Impostazioni generali del sito
   - Impostazioni immagini
   - Impostazioni SEO
   - Social media
   - Filtri gallerie
   - Utenti e permessi
   - Equipment (fotocamere, lenti, pellicole)
   - Locations

8. **[Analytics](./user-manual/analytics.md)**
   - Panoramica analytics
   - Visitatori in tempo reale
   - Statistiche per album
   - Grafici e reportistica
   - Export dati
   - Configurazione analytics
   - Privacy e conformità GDPR

9. **[Risoluzione Problemi](./user-manual/troubleshooting.md)**
   - Problemi comuni e soluzioni
   - Diagnostica di sistema
   - Gestione errori
   - FAQ
   - Come ottenere supporto

---

## 🚀 Quick Links

- **Installazione rapida**: [user-manual/installation.md](./user-manual/installation.md)
- **Primi passi**: [user-manual/getting-started.md](./user-manual/getting-started.md)
- **API Reference**: [technical/api.md](./technical/api.md)
- **Deployment**: [technical/deployment.md](./technical/deployment.md)

---

## 📖 Come Utilizzare Questa Documentazione

### Per Amministratori/Utenti Finali
Se sei un amministratore del sito o un editor di contenuti, inizia dalla sezione **[Manuale Utente](./user-manual/)**. Questa sezione ti guiderà attraverso:
- L'installazione del sistema
- La creazione e gestione dei contenuti
- La configurazione delle impostazioni
- L'analisi delle statistiche del sito

### Per Sviluppatori
Se sei uno sviluppatore che vuole contribuire, estendere o personalizzare Cimaise, consulta la sezione **[Documentazione Tecnica](./technical/)**. Questa sezione copre:
- L'architettura interna dell'applicazione
- Lo schema del database
- Le API disponibili
- Le best practices di sicurezza
- Come configurare l'ambiente di sviluppo

---

## 🔧 Caratteristiche Principali

- **CMS Moderno**: Basato su PHP 8.2 + Slim 4 + Vite + Tailwind CSS
- **Gestione Immagini Avanzata**: Supporto AVIF/WebP/JPEG con generazione automatica di varianti responsive
- **Portfolio Fotografico Completo**: Metadati EXIF, equipment tracking, geolocalizzazione
- **Animazioni Fluide**: GSAP + Lenis per scroll smooth ed effetti premium
- **Analytics Integrato**: Tracciamento visitatori, statistiche per album, real-time analytics
- **SEO Ottimizzato**: Meta tags, sitemap XML, Open Graph, Schema.org
- **Multi-Database**: Supporto SQLite e MySQL
- **Sicurezza Avanzata**: CSRF protection, rate limiting, security headers, Argon2id hashing
- **Responsive Design**: Mobile-first, ottimizzato per tutti i dispositivi
- **Interfaccia Intuitiva**: Pannello admin user-friendly con drag-drop

---

## 📄 Licenza

Copyright © 2024 Cimaise. Tutti i diritti riservati.

---

## 🆘 Supporto

Per domande, bug report o richieste di funzionalità, consulta la sezione [Risoluzione Problemi](./user-manual/troubleshooting.md) o contatta il team di sviluppo.

---

**Buona lettura!** 📸
