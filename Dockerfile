# Dockerfile
FROM php:8.2-apache

# Install PHP extensions you need (example: mysqli, pdo_mysql)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libzip-dev \
  && docker-php-ext-install pdo_mysql mysqli zip \
  && rm -rf /var/lib/apt/lists/*

# Enable apache modules if required
RUN a2enmod rewrite

# Copy application files
COPY src/. /var/www/html/

# Set correct owner/permissions (adjust as needed)
RUN chown -R www-data:www-data /var/www/html

# If you use composer, install it and run install
# (uncomment the lines below if using composer)
# RUN php -r "copy('https://getcomposer.org/installer','composer-setup.php');" \
#     && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
#     && rm composer-setup.php
# RUN composer install --no-dev --optimize-autoloader --working-dir=/var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
