# TAWASUL — نظام إدارة العمليات المالية والتشغيلية (شركة التواصل للسياحة والسفر)

نسخة مستقلة من نظام إدارة الوكلاء/العملاء/الفواتير/السندات/المحاسبة/الموارد البشرية
(Laravel 11 + Vue 3 + Inertia + SQLite) مخصّصة لعميل **شركة التواصل للسياحة والسفر**.

| البند | القيمة |
|---|---|
| الرابط | https://tawasul.masaros.net — وhttps://tawasul.grade.sbs متى عاد النطاق |
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

## النطاقات

التطبيق محايد تجاه النطاق: يخدم أي مضيف يوجَّه إليه، ولا يلزم إعادة بناء
لإضافة نطاق. ما يضمن ذلك:

| الإعداد | القيمة | لماذا |
|---|---|---|
| `SESSION_DOMAIN` | `null` | الكوكي تُربط بالمضيف الذي خدم الطلب، فتعمل الجلسة على كل نطاق. تثبيتها على نطاق واحد يمنع الدخول من غيره |
| `VITE_REVERB_HOST` | فارغ | `echo.js` يرجع إلى `window.location.hostname`، فالـWebSocket يتبع النطاق الحالي |
| `APP_URL` | في `.env.production` فقط | أُزيل من `environment:` في `docker-compose.yml` لأنه كان يتجاوز ملف البيئة. ولا يؤثر على روابط القوالب: `Storage::url()` يُعيد مساراً نسبياً |

الجلسة تستعمل كوكي آمنة، و`docker/nginx.conf` يحوّل `http` إلى `https`
اعتماداً على `X-Forwarded-Proto` الذي يمرّره النفق — فالدخول عبر رابط
`http` لا يُفقد الجلسة. فحص الصحة الداخلي لا يحمل الترويسة فلا يتأثر.

### إضافة نطاق جديد

```bash
python3 scripts/tunnel-hostname.py <hostname> 4030            # معاينة
python3 scripts/tunnel-hostname.py <hostname> 4030 --apply    # كتابة
cloudflared tunnel route dns b8f315ec-a311-4b7a-8891-7b9e37a43f73 <hostname>
```

السكربت يُدرج القاعدة **قبل** أي قاعدة عامة تلتقط النطاق — وهذا لازم على
`masaros.net` إذ توجد قاعدة `*.masaros.net` تلتقط كل ما تحتها.
