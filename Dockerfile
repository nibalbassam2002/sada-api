# 1. نستخدم نسخة PHP الرسمية
FROM php:8.2-fpm

# 2. تثبيت المكتبات اللازمة للنظام و Nginx + تنصيب Node.js (مهم لـ Vite)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpq-dev \
    nginx

# --- إضافة: تنصيب Node.js و NPM لكي يتمكن السيرفر من بناء ملفات Vite ---
RUN curl -sL https://deb.nodesource.com/setup_18.x | bash - && \
    apt-get install -y nodejs

# 3. تنظيف الكاش
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 4. تثبيت امتدادات PHP
RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

# 5. تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. تحديد مجلد العمل
WORKDIR /var/www

# 7. نسخ ملفات المشروع
COPY . .

# 8. تثبيت مكتبات لارافل (Composer)
RUN composer install --no-interaction --optimize-autoloader --no-dev



# ==========================================
# 9. إعدادات Nginx
RUN rm -rf /etc/nginx/sites-enabled/default
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# 10. صلاحيات الملفات
RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# 11. ملف التشغيل
COPY docker/startup.sh /usr/local/bin/startup.sh
RUN chmod +x /usr/local/bin/startup.sh

# 12. المنفذ
EXPOSE 80

# 13. التشغيل
CMD ["/usr/local/bin/startup.sh"]