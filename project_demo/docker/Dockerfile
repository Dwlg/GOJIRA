# Imagem do project_demo para desenvolvimento local e para o smoke test da CI
# (.github/workflows/ci.yml, job demo-smoke). Build context: a raiz do
# repositório (precisa de system/ e composer.json junto com project_demo/).
#
#   docker compose -f project_demo/docker-compose.yml up -d --build

ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache

RUN docker-php-ext-install mysqli \
 && a2enmod rewrite

COPY . /var/www/html

# Replica o layout de hospedagem compartilhada (um só system/ para vários
# projetos, ligado por symlink): o .htaccess do project_demo é testado contra
# o mesmo caminho que existiria em produção, não contra um diretório ausente.
RUN ln -s ../system /var/www/html/project_demo/system \
 && ln -s ../system/vendor /var/www/html/project_demo/vendor \
 && cp /var/www/html/project_demo/.env.example /var/www/html/project_demo/.env \
 && chown -R www-data:www-data /var/www/html

COPY project_demo/docker/vhosts.conf /etc/apache2/sites-available/000-default.conf
