FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
      hunspell \
      hunspell-de-de \
      apt-utils \
      libpng-dev \
      libzip-dev \
      ca-certificates \
      zip unzip \
      git && \
      docker-php-ext-install pdo && \
      docker-php-ext-install bcmath && \
      docker-php-ext-install gd && \
      docker-php-ext-install zip && \
      apt-get clean && \
      rm -rf /var/lib/apt/lists/*

RUN apt-get update && apt-get install -y \
      libbz2-dev \
      && docker-php-ext-install bz2 \
      && rm -rf /var/lib/apt/lists/*

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

# Install PostgreSQL extension
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql

# Install MySQL extension
# RUN apt-get update && apt-get install -y libmysqlclient-dev \
# && docker-php-ext-install pdo_mysql

# Install SQLite extension
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite

# Install phpredis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY ./_docker/app/php.ini /usr/local/etc/php/conf.d/php.ini

#Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- \
    --filename=composer \
    --install-dir=/usr/local/bin

RUN useradd -u 1000 -m -s /bin/sh eugene
USER eugene

WORKDIR /var/www
