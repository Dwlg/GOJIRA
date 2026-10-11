# Imagem de teste do GOJIRA: PHP CLI com mysqli (mysqlnd) e Composer.
#
# git, unzip e a extensão GD (usada pelos testes do thumb.php) só são instalados
# quando COMPOSER_DEPS=1. A imagem do PHP 8.0 usa Debian 11, cujo repositório de
# segurança não serve mais os pacotes; ela roda os testes com o vendor instalado
# pela imagem do PHP 8.4, e os testes que geram imagem são pulados nela.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

ARG COMPOSER_DEPS=1

RUN if [ "$COMPOSER_DEPS" = "1" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends git unzip libpng-dev libjpeg62-turbo-dev \
        && rm -rf /var/lib/apt/lists/* \
        && docker-php-ext-configure gd --with-jpeg \
        && docker-php-ext-install gd; \
    fi \
    && docker-php-ext-install mysqli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
