# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine3.21 AS runtime
RUN apk add --no-cache libpq icu-libs libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl opcache \
    && apk del .build-deps
WORKDIR /app
COPY infra/docker/php.ini /usr/local/etc/php/conf.d/zz-kirh.ini
COPY infra/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-kirh.conf
HEALTHCHECK --interval=20s --timeout=5s --start-period=30s CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

FROM runtime AS deps
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache composer install --no-dev --no-scripts --prefer-dist --no-interaction

FROM deps AS builder
COPY apps/api/ ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && php artisan filament:assets

FROM runtime AS development
COPY infra/docker/php-development.ini /usr/local/etc/php/conf.d/zzz-development.ini
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY apps/api/ ./
RUN --mount=type=cache,target=/root/.composer/cache composer install --prefer-dist --no-interaction
CMD ["php-fpm", "-F"]

FROM runtime AS production
RUN addgroup -S -g 10001 kirh && adduser -S -D -u 10001 -G kirh kirh
COPY --from=builder --chown=10001:10001 /app /app
USER 10001:10001
CMD ["php-fpm", "-F"]

FROM node:22.13-alpine AS share-builder
WORKDIR /share
ARG VITE_API_BASE_URL=https://geo.kirhtarg.ru/api/v1
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
COPY apps/share-web/package.json apps/share-web/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY apps/share-web/ ./
RUN npm run build

FROM nginx:1.28-alpine AS share
COPY infra/nginx/share.conf /etc/nginx/nginx.conf
COPY --from=share-builder /share/dist /usr/share/nginx/html
USER 101:101
EXPOSE 8080
HEALTHCHECK --interval=20s --timeout=5s CMD wget -q -O /dev/null http://127.0.0.1:8080/
ENTRYPOINT ["nginx", "-g", "daemon off;"]

FROM nginx:1.28-alpine AS proxy
COPY infra/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --from=builder /app/public /app/public
USER 101:101
EXPOSE 8080
HEALTHCHECK --interval=20s --timeout=5s CMD wget -q -O /dev/null http://127.0.0.1:8080/up
ENTRYPOINT ["nginx", "-g", "daemon off;"]
