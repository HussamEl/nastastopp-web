# Nästa Stopp: الموقع والخادم

| المجلد | العنوان | ما هو |
|---|---|---|
| `site/` | nastastopp.se | الصفحة العامة |
| `api/` | api.nastastopp.se | خادم المفاتيح: الأجهزة المسجّلة تطلب الطرق وأزمنة السفر، والخادم يسأل Google أو mapmap.ai بمفاتيحك |
| `admin/` | admin.nastastopp.se | صفحتك: رموز للأجهزة الجديدة، الأجهزة وطلباتها اليومية، وإيقاف أي جهاز |

## التركيب على Hostinger (مرة واحدة)

1. **قاعدة البيانات:** من hPanel ← Databases ← MySQL Databases أنشئ قاعدة ومستخدماً.
2. **النشر من GitHub:** من hPanel ← Advanced ← Git، أضف المستودع `HussamEl/nastastopp-web`، الفرع `main`، ومسار التركيب `nastastopp` (أي `public_html/nastastopp`)، وفعّل النشر التلقائي (Auto deployment) والصق رابط الـ webhook في GitHub كما يطلب.
3. **الدومينات الفرعية:** من Domains ← Subdomains:
   - `api` ومجلده `public_html/nastastopp/api`
   - `admin` ومجلده `public_html/nastastopp/admin`
   - والدومين الرئيسي `nastastopp.se`: اجعل مجلده `public_html/nastastopp/site` (أو انسخ `site/index.html` إلى `public_html`).
4. **SSL** للثلاثة من Security ← SSL.
5. **ملف الأسرار:** من File Manager، انسخ `config.example.php` إلى المجلد **فوق** `public_html` باسم `nastastopp-config.php`، واملأه بنفسك: بيانات القاعدة، مفتاح Google للطرق، مفتاح Google للخريطة، مفتاح mapmap. **لا تلصق أي مفتاح في المحادثة.**
6. **كلمة سر الإدارة:** افتح `https://admin.nastastopp.se/`: أول مرة يطلب كلمة سر مرتين ويعطيك سطراً واحداً تضعه في الملف مكان `admin_password_hash`. ثم ادخل بها.
7. افتح `https://api.nastastopp.se/v1/health`: يجب أن يظهر `{"ok":true}`.

## الاستعمال
- في `admin.nastastopp.se` أنشئ رمزاً لكل جهاز (8 أحرف، صالح 7 أيام، مرة واحدة)، ويكتبه السائق مرة في التطبيق.
- لكل جهاز حدّ يومي لكل خدمة (`daily_limits` في ملف الأسرار)، وزر «أوقف» يقطعه فوراً.
- الخادم لا يحفظ أي موقع أو عنوان أو اسم، ولا يسجّل الطلبات؛ فقط عددها لكل جهاز في اليوم.

## الاختبارات
```bash
php tests/run.php
```
