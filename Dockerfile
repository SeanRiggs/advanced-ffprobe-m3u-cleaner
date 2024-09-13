FROM php:8.1-cli

# Install necessary dependencies: curl, ffmpeg for ffprobe, and git for composer
RUN apt-get update && apt-get install -y \
    curl \
    ffmpeg \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set up the working directory
WORKDIR /app

# Copy the current directory into the container
COPY . /app

# Install PHP dependencies using composer
RUN composer install

# Create necessary directories and set permissions
RUN mkdir -p /app/m3u /app/output /var/log \
    && chown -R www-data:www-data /app /var/log \
    && chmod -R 755 /app /var/log

# Set the entrypoint to the PHP script
ENTRYPOINT ["php", "/app/validator.php"]
