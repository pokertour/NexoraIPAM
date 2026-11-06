FROM php:8.5-fpm-alpine AS php-base

RUN apk add --no-cache libldap \
    && apk add --no-cache --virtual .php-build-deps $PHPIZE_DEPS openldap-dev \
    && docker-php-ext-install -j "$(nproc)" ldap pdo_mysql pcntl \
    && apk del .php-build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

FROM php-base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache unzip
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
RUN mkdir -p storage/framework/views \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-interaction

FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js .gitignore ./
COPY app/ app/
COPY resources/ resources/
COPY --from=dependencies /app/vendor/ vendor/
RUN npm run build

FROM debian:trixie-slim AS ieee

# The IEEE servers regularly refuse CI runners: fall back on the Debian
# ieee-data package, which ships the same registries, when a download fails.
WORKDIR /registries
RUN apt-get update \
    && apt-get install -y --no-install-recommends ieee-data wget ca-certificates \
    && for file in oui/oui oui28/mam oui36/oui36 iab/iab; do \
        name="${file##*/}.csv"; \
        if wget -q --tries=3 --waitretry=5 --timeout=30 -O "$name.tmp" "https://standards-oui.ieee.org/$file.csv" \
            && head -n 1 "$name.tmp" | grep -q '^Registry,'; then \
            mv "$name.tmp" "$name"; \
        else \
            echo "IEEE download failed for $name, using the Debian ieee-data copy"; \
            rm -f "$name.tmp"; cp "/usr/share/ieee-data/$name" "$name"; \
        fi; \
    done \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS runtime

ARG APP_VERSION=local-dev
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    APP_URL=http://localhost:8080 \
    DB_DATABASE=/var/lib/nexora/database.sqlite \
    LOG_CHANNEL=stderr

LABEL org.opencontainers.image.title="NexoraIPAM" \
      org.opencontainers.image.source="https://github.com/pokertour/NexoraIPAM" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.licenses="LicenseRef-NexoraIPAM-Source-Available-1.0"

RUN apk add --no-cache nginx iproute2-minimal iputils-ping net-snmp-tools \
    && mkdir -p /var/lib/nexora \
    && touch /var/lib/nexora/database.sqlite \
    && chown -R www-data:www-data /var/lib/nexora

COPY --from=dependencies /app/app/ app/
COPY --from=dependencies /app/bootstrap/ bootstrap/
COPY --from=dependencies /app/config/ config/
COPY --from=dependencies /app/database/ database/
COPY --from=dependencies /app/lang/ lang/
COPY --from=dependencies /app/public/ public/
COPY --from=dependencies /app/resources/views/ resources/views/
COPY --from=dependencies /app/routes/ routes/
COPY --from=dependencies /app/vendor/ vendor/
COPY --from=dependencies /app/artisan /app/composer.json /app/LICENSE ./
COPY --from=assets /app/public/build/ public/build/
COPY --from=ieee /registries/ /usr/share/ieee-data/
COPY docker/nginx.conf /etc/nginx/nexora.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-nexora.conf
COPY --chmod=755 docker/start.sh /usr/local/bin/nexora-web

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=4s --start-period=10s \
    CMD wget -q -T 3 -O /dev/null http://127.0.0.1:8080/up || exit 1

CMD ["nexora-web"]
