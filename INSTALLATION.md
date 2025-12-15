# Cimaise Installation Guide

Cimaise now includes improved installer support that works in any server environment, including subdirectories.

## Quick Installation Options

### Option 1: Simple Installer (Recommended)
1. Upload all files to your server
2. Visit: `https://yourdomain.com/path/to/cimaise/public/simple-installer.php`
3. Fill in the form and click "Install Cimaise"

### Option 2: Main Installer
1. Upload all files to your server  
2. Visit: `https://yourdomain.com/path/to/cimaise/public/installer.php`
3. Follow the step-by-step wizard

### Option 3: Automatic Detection
1. Upload all files to your server
2. Visit: `https://yourdomain.com/path/to/cimaise/public/index.php`
3. You'll be automatically redirected to the installer

## Installation Environments

### Root Domain Installation
- Files in: `/public_html/` or `/var/www/html/`
- URL: `https://yourdomain.com/`
- Document root: `public/` folder

### Subdirectory Installation  
- Files in: `/public_html/myphoto/` or `/var/www/html/portfolio/`
- URL: `https://yourdomain.com/myphoto/` or `https://yourdomain.com/portfolio/`
- Document root: `public/` folder within the subdirectory

### Server Examples

#### cPanel/Shared Hosting
```
/public_html/cimaise/
├── app/
├── database/
├── public/          <- Set as document root or access via /cimaise/public/
├── resources/
└── ...
```

#### VPS/Dedicated Server
```
/var/www/html/portfolio/
├── app/
├── database/  
├── public/          <- Set as document root
├── resources/
└── ...
```

## Troubleshooting

### Route Conflicts
If you see "Cannot register two routes" errors:
1. Clear any existing `.env` file
2. Delete `database/app.db` if it exists
3. Use the simple installer: `/public/simple-installer.php`

### Path Detection Issues
The installer automatically detects:
- Protocol (HTTP/HTTPS)
- Domain name
- Base path (subdirectory)
- Database location

### File Permissions
Ensure these directories are writable:
- `database/` folder
- Root directory (for `.env` file)

## Post-Installation

1. **Admin Login**: `/public/index.php/admin/login`
2. **Site**: `/public/index.php` 
3. **Delete Installers**: Remove installer files for security:
   - `public/installer.php`
   - `public/simple-installer.php`

## Security Notes

- Change default admin password after installation
- Remove installer files after successful installation
- Set proper file permissions (644 for files, 755 for directories)
- Enable HTTPS if available

## Support

If you encounter issues:
1. Check file permissions
2. Verify PHP version (8.2+ required)
3. Ensure SQLite extension is enabled
4. Check server error logs

The installer has been tested on:
- Shared hosting (cPanel, DirectAdmin)
- VPS/Cloud servers  
- Subdirectory installations
- Various PHP configurations