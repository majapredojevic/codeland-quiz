FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libssl-dev \
    libcurl4-openssl-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql sockets \
    && pecl install openswoole \
    && docker-php-ext-enable openswoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

CMD ["php", "server.php"]