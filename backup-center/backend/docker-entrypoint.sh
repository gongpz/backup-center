#!/bin/sh
set -e

cd /var/www/html

# 首次启动准备 SQLite 数据库（存到 database/data/ 子目录以避开卷遮罩迁移文件的坑）
mkdir -p database/data
if [ ! -f database/data/database.sqlite ]; then
    echo "[entrypoint] 创建 SQLite 数据库文件"
    # 迁移旧路径（如果存在）
    if [ -f database/database.sqlite ]; then
        cp database/database.sqlite database/data/database.sqlite
        echo "[entrypoint] 从旧路径 database/database.sqlite 迁移数据"
    else
        touch database/data/database.sqlite
    fi
fi
chown -R www-data:www-data database/data

# 迁移
echo "[entrypoint] 运行迁移"
php artisan migrate --force --graceful

# 首次运行时植入默认管理员
if ! php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -n 1 | grep -qE '^[1-9]'; then
    echo "[entrypoint] 首次启动，植入默认管理员"
    php artisan db:seed --force
fi

# 生产环境优化缓存
echo "[entrypoint] 生成配置/路由缓存"
php artisan config:cache
php artisan route:cache

# 权限
chown -R www-data:www-data storage bootstrap/cache database

# 后台跑 Laravel scheduler（每分钟）
(
    while true; do
        php artisan schedule:run >/dev/null 2>&1
        sleep 60
    done
) &

echo "[entrypoint] 启动 PHP-FPM"
exec php-fpm
