FROM wordpress:6.4-php8.1-fpm

# Install necessary PHP extensions and tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy custom php.ini if exists
# Define default php.ini location (commented out to fix parse error)
# COPY php.ini /usr/local/etc/php/

# Install WP-CLI
RUN curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp
