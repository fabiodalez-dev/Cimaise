# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║  Cimaise — Professional Photography Portfolio CMS                          ║
# ║  Multi-stage production image: PHP 8.3 + Apache, SQLite (default) / MySQL  ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

# ─── Stage 1: frontend assets ───────────────────────────────────────────────
# Mirrors the project's CI build (Node 18 + Vite + Tailwind + FA subset) so the
# served JS/CSS are produced from source, not copied from a developer machine.
FROM node:18-bookworm-slim AS assets
WORKDIR /app

# Note: build-fa-subset.mjs can additionally emit *subsetted* webfont files when
# python3 + fonttools (pyftsubset) are present, but it degrades gracefully to a
# CSS-only subset (referencing the full webfonts) otherwise. We deliberately do
# NOT install python here — it keeps the build lean and avoids a heavy, slow
# dependency for a marginal font-size gain.

COPY package.json package-lock.json ./
# --ignore-scripts: the postinstall hook (copy-vendor + FA subset) needs the
# project source, which isn't in the layer yet. Install deps only here, then
# run those steps explicitly once the source has been copied in.
RUN npm ci --no-audit --no-fund --ignore-scripts

# Source needed by Vite + the FA-subset scanner (it greps views/JS for fa-*).
COPY vite.config.js postcss.config.js tailwind*.js ./
COPY scripts ./scripts
COPY resources ./resources
COPY js ./js
COPY app ./app
COPY plugins ./plugins
COPY database ./database
COPY public ./public

# Vendor copy (normally postinstall) + production asset build.
RUN node scripts/copy-vendor.js \
 && npm run build


# ─── Stage 2: PHP dependencies ──────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --ignore-platform-reqs: the runtime image (stage 3) provides the ext-* deps;
# the slim composer image need not have them just to resolve the lock file.
RUN composer install \
      --no-dev --optimize-autoloader --no-interaction --no-progress \
      --ignore-platform-reqs --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


# ─── Stage 3: runtime ───────────────────────────────────────────────────────
FROM php:8.3-apache-bookworm AS runtime

LABEL org.opencontainers.image.title="Cimaise" \
      org.opencontainers.image.description="Minimalist photography portfolio CMS (PHP 8.3, Slim 4, Twig) — SQLite or MySQL." \
      org.opencontainers.image.source="https://github.com/fabiodalez-dev/cimaise" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.documentation="https://github.com/fabiodalez-dev/cimaise/blob/main/DOCKER.md"

# PHP extensions (gd with AVIF/WebP, Imagick, exif, intl, zip, opcache, PDO
# drivers, bcmath). install-php-extensions resolves & cleans up all the system
# libraries automatically — far more reliable than hand-rolling apt/pecl.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
      gd \
      imagick \
      exif \
      intl \
      zip \
      bcmath \
      opcache \
      pdo_mysql \
      pdo_sqlite

# Apache: rewrite + headers for the project's .htaccess contract.
RUN a2enmod rewrite headers \
 && rm -f /etc/apache2/sites-enabled/000-default.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/cimaise.conf
RUN a2ensite cimaise
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-cimaise.ini

WORKDIR /var/www/html

# Application code (vendor + optimized autoloader come from the composer stage;
# the .dockerignore keeps user media, .git and dev cruft out of the context).
COPY --chown=www-data:www-data . .
COPY --from=vendor   --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets   --chown=www-data:www-data /app/public/assets ./public/assets

# Entrypoint prepares the writable runtime tree (volumes) and starts Apache.
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/cimaise-entrypoint
RUN chown -R www-data:www-data /var/www/html

# Persisted, writable runtime state. Declared as volumes so user data survives
# image upgrades; the entrypoint chowns them on boot.
VOLUME ["/var/www/html/storage", "/var/www/html/database", "/var/www/html/public/media"]

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["cimaise-entrypoint"]
CMD ["apache2-foreground"]
