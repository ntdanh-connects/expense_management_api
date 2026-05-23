FROM php:8.2-fpm-alpine

# Cài đặt các công cụ hệ thống và thư viện cho PostgreSQL, Redis
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libpq-dev \
    bash

# Cài đặt PHP extensions cho PostgreSQL và Redis
RUN docker-php-ext-install pdo pdo_pgsql

# Cài đặt Composer chính chủ
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Thiết lập thư mục làm việc trong container
WORKDIR /var/www/html

# Copy toàn bộ mã nguồn vào container
COPY . .

# Cài đặt các package của Laravel (Bỏ qua dev dependencies để nhẹ file)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Cấu hình quyền ghi cho thư mục lưu trữ của Laravel
RUN chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

# Copy file cấu hình Nginx và Supervisor (Sẽ tạo ở bước sau)
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/supervisord.conf /etc/supervisord.conf

# Render cấp cổng PORT ngẫu nhiên, ta cần script để bind cổng này vào Nginx
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'sed -i "s/LISTEN_PORT/${PORT}/g" /etc/nginx/nginx.conf' >> /usr/local/bin/start.sh && \
    echo 'exec supervisord -c /etc/supervisord.conf' >> /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

# Mở cổng mặc định (Render sẽ ghi đè cổng này qua biến $PORT)
EXPOSE 80

# Chạy hệ thống thông qua script khởi động
CMD ["/usr/local/bin/start.sh"]