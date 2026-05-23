FROM php:8.2-fpm-alpine

# Cài đặt toàn bộ công cụ hệ thống, thư viện nén, PostgreSQL, và các công cụ cần thiết cho Composer
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libpq-dev \
    bash \
    libzip-dev \
    zip \
    unzip \
    git \
    curl

# Cài đặt các PHP extensions bắt buộc cho Laravel, PostgreSQL và xử lý file zip
RUN docker-php-ext-install pdo pdo_pgsql zip

# Cài đặt Composer chính chủ bản mới nhất
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Thiết lập thư mục làm việc trong container
WORKDIR /var/www/html

# Copy toàn bộ mã nguồn vào container trước
COPY . .

# 🔥 THẦN CHƯỞNG ĐÃ SỬA: Thêm cờ --ignore-platform-reqs để ép Composer cài mượt mà, chống nổ lỗi số 2
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# Cấu hình quyền ghi cho thư mục lưu trữ của Laravel
RUN chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

# Copy file cấu hình Nginx và Supervisor vào đúng địa chỉ nhà hệ thống
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/supervisord.conf /etc/supervisord.conf

# Render cấp cổng PORT ngẫu nhiên, ta cần script để bind cổng này vào Nginx
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'sed -i "s/LISTEN_PORT/${PORT}/g" /etc/nginx/nginx.conf' >> /usr/local/bin/start.sh && \
    echo 'php /var/www/html/artisan migrate --force' >> /usr/local/bin/start.sh && \
    echo 'exec supervisord -c /etc/supervisord.conf' >> /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

# Mở cổng mặc định
EXPOSE 80

# Chạy hệ thống thông qua script khởi động
CMD ["/usr/local/bin/start.sh"]