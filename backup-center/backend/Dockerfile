FROM php:8.2-fpm-alpine

# 安装 Laravel + SQLite 所需扩展
RUN apk add --no-cache \
        git \
        unzip \
        libzip \
        sqlite \
        tzdata \
        curl \
        rclone \
    && apk add --no-cache --virtual .build-deps libzip-dev sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 先装 composer 依赖利于分层缓存
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts --prefer-dist

# 拷贝其他代码
COPY . .

# 优化 autoload
# build 阶段用 array cache/session（内存，不碰 DB）—— Laravel 12 默认 CACHE_STORE=database，
# 而 package:discover 会调 cache，空 SQLite 里没 cache 表就会崩
# 运行时 docker-compose environment 覆盖这些为 file（见 docker-compose.yml）
#
# 目录必须在 dump-autoload 之前建好：dump-autoload 会触发 package:discover，
# 它要往 bootstrap/cache/packages.php 写文件，目录不存在就直接失败（exit 1）。
RUN mkdir -p /var/www/html/database/data \
    && touch /var/www/html/database/database.sqlite /var/www/html/database/data/database.sqlite \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/framework/testing \
    && mkdir -p storage/logs bootstrap/cache database \
    && CACHE_STORE=array SESSION_DRIVER=array composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache database

ENV TZ=Asia/Shanghai

# PHP-FPM 默认 upload_max_filesize=2M / post_max_size=8M，资源池上传（≤100MB）会失败
# 把上传限制拉到 100M，同时把 memory_limit 拉到 256M（rclone shell_exec 期间 PHP 进程要够用）
RUN { \
        echo 'upload_max_filesize = 100M'; \
        echo 'post_max_size = 110M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 600'; \
    } > "$PHP_INI_DIR/conf.d/99-uploads.ini"

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

CMD ["/usr/local/bin/docker-entrypoint.sh"]
