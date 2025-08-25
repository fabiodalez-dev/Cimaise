# photoCMS Installation Guide

## System Requirements

- PHP 8.0 or higher
- PDO extension with either MySQL or SQLite support
- GD extension for image processing
- OpenSSL extension
- mbstring extension
- Write permissions for:
  - `.env` file
  - `database/` directory
  - `storage/` directory
  - `public/media/` directory

## Installation Methods

### Web Installer (Recommended)

1. Upload all files to your web server
2. Make sure the required directories are writable
3. Navigate to your site in a web browser
4. Follow the on-screen installation wizard

The web installer will guide you through:
- Checking system requirements
- Configuring database connection (MySQL or SQLite)
- Creating your first admin user
- Setting up initial site configuration
- Installing default templates and categories

### Command Line Installation

For advanced users or automated deployments:

```bash
php bin/console install
```

This will prompt you for all required configuration details.

To force reinstallation (will overwrite existing installation):
```bash
php bin/console install --force
```

## Post-Installation

After successful installation:

1. **Remove installer files (optional but recommended for production):**
   ```bash
   rm -rf app/Installer/
   rm app/Controllers/InstallerController.php
   rm -rf app/Views/installer/
   ```

2. **Set up your web server:**
   - Document root should point to the `public/` directory
   - Enable URL rewriting (mod_rewrite for Apache, try_files for Nginx)

3. **Log in to the admin panel:**
   - Visit `/admin/login`
   - Use the admin credentials you created during installation

4. **Configure your site:**
   - Visit `/admin/settings` to adjust image processing settings
   - Create your first album at `/admin/albums/create`
   - Customize templates and categories as needed

## Troubleshooting

### Common Issues

1. **500 Internal Server Error**
   - Check PHP error logs
   - Ensure all required PHP extensions are installed
   - Verify directory permissions

2. **Database Connection Failed**
   - Double-check database credentials
   - Ensure the database server is running
   - For SQLite, verify the database file path is correct and writable

3. **Installer Not Loading**
   - Make sure `.env` file doesn't exist (it indicates an existing installation)
   - Check that the `public/` directory is correctly set as the document root

### Getting Help

If you encounter issues not covered in this guide:

1. Check the application logs in `storage/logs/`
2. Enable debug mode by setting `APP_DEBUG=true` in your `.env` file
3. Review the [documentation](README.md) for additional information
4. Open an issue on the project's GitHub repository

## Security Recommendations

For production deployments:

1. **Change the session secret** in `.env`
2. **Set proper file permissions**:
   ```bash
   chmod 644 .env
   chmod 644 database/database.sqlite  # if using SQLite
   ```
3. **Remove installer files** after installation
4. **Use HTTPS** for admin access
5. **Regularly update** the application and its dependencies