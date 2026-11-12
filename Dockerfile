# syntax=docker/dockerfile:1
# 极验云盘 · 单容器镜像：nginx + php-fpm（supervisor 同时拉起）
FROM php:8.3-fpm-alpine

ENV TZ=Asia/Shanghai \
    PHP_MEMORY_LIMIT=512M

RUN apk add --no-cache nginx supervisor tzdata \
 && docker-php-ext-install pdo_mysql opcache \
 && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pandisk.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
COPY . /var/www/html
RUN rm -rf /var/www/html/docker /var/www/html/.git \
 && mkdir -p /var/www/html/storage \
 && chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
