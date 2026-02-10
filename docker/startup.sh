#!/bin/bash

# 1. طباعة جملة للتأكد من السجلات (Logs)
echo "Starting deployment..."

# 2. تنظيف الكاش لضمان عدم وجود إعدادات قديمة
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# 3. تشغيل الميغريشن
# نستخدم --force لأننا في وضع الإنتاج
echo "Running migrations..."
php artisan migrate --force

# ======================================================
# 4. تشغيل السيدر (الإضافة الجديدة)
# سيقوم هذا الأمر بإنشاء الأدمن تلقائياً عند كل تحديث
# بما أننا وضعنا شرط (if check) داخل السيدر، لن يتكرر الأدمن ولن تحدث مشاكل
echo "Running Seeders..."
php artisan db:seed --class=SuperAdminSeeder --force
# ======================================================

# 5. تشغيل Nginx في الخلفية
echo "Starting Nginx..."
service nginx start

# 6. تشغيل PHP-FPM (العملية الرئيسية)
echo "Starting PHP-FPM..."
php-fpm