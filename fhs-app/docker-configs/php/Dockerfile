FROM php:8.5-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y \
        git \
        curl \
        zip \
        unzip \
        libzip-dev \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_pgsql pgsql zip pcntl && docker-php-ext-enable pdo_pgsql

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/pear

RUN apt-get update && apt-get install -y autoconf \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/bin --filename=composer

COPY docker-configs/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Realign www-data to the host user so files written into the bind mount stay
# owned by the developer. On macOS the host GID is often 20 (dialout in Debian),
# so join the existing group rather than renumbering www-data's.
RUN set -eux; \
    if getent group "${GID}" >/dev/null; then \
        usermod -u "${UID}" -g "${GID}" www-data; \
    else \
        groupmod -g "${GID}" www-data && usermod -u "${UID}" www-data; \
    fi

WORKDIR /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
