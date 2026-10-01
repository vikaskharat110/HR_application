# DEVELOPMENT ONLY — a Hostinger-like stack (Apache + PHP 8.2, mod_rewrite,
# .htaccess allowed) for testing. Never deployed.
FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
# Hostinger-like production php.ini (display_errors off)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# Recommended layout: code in /home/app, web root /home/app/public_html
ENV APACHE_DOCUMENT_ROOT=/home/app/public_html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && printf '<Directory /home/app/>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf
