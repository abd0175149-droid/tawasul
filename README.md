# TAWASUL — نظام إدارة العمليات المالية والتشغيلية (شركة التواصل للسياحة والسفر)

نسخة مستقلة من نظام إدارة الوكلاء/العملاء/الفواتير/السندات/المحاسبة/الموارد البشرية
(Laravel 11 + Vue 3 + Inertia + SQLite) مخصّصة لعميل **شركة التواصل للسياحة والسفر**.

| البند | القيمة |
|---|---|
| الرابط | https://tawasul.grade.sbs |
| السيرفر | `mafia-prod` — مجلد `/home/sysadmin/tawasul` |
| الحاوية | `tawasul-app` (compose project `tawasul`) — منفذ `4030` |
| قاعدة البيانات | SQLite داخل volume باسم `tawasul_tawasul-database` |
| الملفات المرفوعة | volume باسم `tawasul_tawasul-storage` |
| المستخدم الافتراضي | `admin@tawasul-jo.com` (دور: مدير عام) |

## التشغيل محلياً

```bash
composer install
npm install
cp .env.example .env   # أو استخدم .env الموجود
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run dev
php artisan serve
```

## النشر (Production)

`.env.production` **غير مُتتبَّع في git** ويجب أن يكون موجوداً على السيرفر بجانب `docker-compose.yml`.

```bash
cd /home/sysadmin/tawasul
git pull                              # أو انسخ التحديثات
docker compose build tawasul-app      # يبني أصول Vite + PHP
docker compose up -d tawasul-app
```

يقوم `docker/entrypoint.sh` تلقائياً عند الإقلاع بتشغيل الترحيلات (migrations)
وتخزين الإعدادات/المسارات/العروض في الكاش وزرع شجرة الحسابات.

للتهيئة الأولى فقط (الأدوار، الإعدادات، المستخدم الافتراضي، شجرة الحسابات):

```bash
docker exec tawasul-app php artisan db:seed --force
```

## التحقق

```bash
docker inspect tawasul-app --format '{{.State.Health.Status}}'   # healthy
docker exec -i tawasul-app sqlite3 /var/www/html/database/database.sqlite "select count(*) from users;"
```
