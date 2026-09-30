FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Configure & install required PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd zip

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache directory permissions for .htaccess
RUN echo '<Directory /var/www/html/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/duarte.conf \
    && a2enconf duarte

# PHP settings for uploads and timezone
RUN echo "upload_max_filesize = 50M\n\
post_max_size = 50M\n\
memory_limit = 256M\n\
date.timezone = Asia/Manila" > /usr/local/etc/php/conf.d/duarte.ini

WORKDIR /var/www/html

# Copy all project code
COPY . /var/www/html/

# Ensure uploads directory exists and permissions are writable
RUN mkdir -p /var/www/html/uploads \
    && ln -s /var/www/html /var/www/html/duarte \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# Configure startup script
RUN chmod +x /var/www/html/start.sh

EXPOSE 80

CMD ["/bin/bash", "/var/www/html/start.sh"]
