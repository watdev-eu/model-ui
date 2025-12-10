# Dockerfile
FROM php:8.2-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libzip-dev \
  && docker-php-ext-install pdo_pgsql pgsql zip \
  && rm -rf /var/lib/apt/lists/*

# Increase upload / post limits
RUN { \
      echo "file_uploads=On"; \
      echo "upload_max_filesize=512M"; \
      echo "post_max_size=512M"; \
      echo "max_file_uploads=20"; \
      echo "memory_limit=1G"; \
      echo "max_execution_time=600"; \
   } > /usr/local/etc/php/conf.d/uploads.ini

RUN a2enmod rewrite

COPY src/. /var/www/html/
RUN chown -R www-data:www-data /var/www/html

RUN mkdir -p /shared/uploads \
 && chown -R www-data:www-data /shared/uploads

EXPOSE 80
CMD ["apache2-foreground"]