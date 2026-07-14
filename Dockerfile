FROM php:8.2-fpm-alpine

LABEL maintainer="Transcription Bot Team"

# Install system dependencies
RUN apk add --no-cache \
    curl \
    sqlite \
    ffmpeg \
    && docker-php-ext-install pdo pdo_sqlite

# Configure PHP
RUN php -r "echo 'max_execution_time=3600'; echo PHP_EOL; echo 'memory_limit=512M'; echo PHP_EOL; echo 'upload_max_filesize=500M'; echo PHP_EOL; echo 'post_max_size=500M';" > /usr/local/etc/php/conf.d/app.ini

# Set working directory
WORKDIR /app

# Copy application code
COPY . .

# Create storage directories with permissions
RUN mkdir -p storage/uploads storage/results && \
    chmod 755 storage && \
    chmod 755 storage/uploads && \
    chmod 755 storage/results && \
    chown -R www-data:www-data /app

# Expose port
EXPOSE 9000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:9000/ || exit 1

# Run PHP-FPM
CMD ["php-fpm"]
