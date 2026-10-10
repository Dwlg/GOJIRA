# Imagem de teste do GOJIRA: PHP CLI com mysqli (mysqlnd) e Composer.
#
# git e unzip só são instalados quando COMPOSER_DEPS=1. A imagem do PHP 8.0
# usa Debian 11, cujo repositório de segurança não serve mais os pacotes;
# ela roda os testes com o vendor instalado pela imagem do PHP 8.4.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

ARG COMPOSER_DEPS=1

RUN if [ "$COMPOSER_DEPS" = "1" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends git unzip \
        && rm -rf /var/lib/apt/lists/*; \
    fi \
    && docker-php-ext-install mysqli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
