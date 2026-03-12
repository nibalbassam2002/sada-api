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
#!/bin/bash

echo "Starting deployment..."

# تنظيف الكاش
php artisan config:clear
php artisan cache:clear

# تشغيل الميغريشن
echo "Running migrations..."
php artisan migrate --force

# تشغيل السيدر
echo "Running Seeders..."
php artisan db:seed --class=SuperAdminSeeder --force

# --- السطر الجديد هنا: تشغيل Reverb في الخلفية ---
echo "Starting Reverb..."
php artisan reverb:start --host=0.0.0.0 --port=8080 &

# تشغيل Nginx
echo "Starting Nginx..."
service nginx start

# تشغيل PHP-FPM
echo "Starting PHP-FPM..."
php-fpm
php-fpm