# مدیریت آزمایشی برنامه دلتا
- فقط ربات مادر، مالک اصلی و چت خصوصی؛ دستور /delta. شرط الزامی: secret_token وبهوک برابر SHA256 توکن ربات مادر (همان الگوریتم check() موجود). در مسیر webhook فعلی سرور آن را بدون تغییر URL ثبت کنید. توکن را در APK یا Git قرار ندهید.
- /delta جداول مستقل را ایجاد می‌کند. پلن شامل نام، حجم، روز و قیمت است. ساب‌های اختصاصی از میزبان فعلی برنامه ثبت و فقط یک بار به حساب تخصیص داده می‌شوند. ساب در پنل باید از قبل همان حجم و تاریخ را داشته باشد؛ بررسی خودکار و ساخت کاربر پنل هنوز اضافه نشده است.
- مدیریت: ساخت حساب، فهرست، تغییر نام، تولید رمز جدید و غیرفعال کردن ورود. رمز هش می‌شود. غیرفعال کردن ورود/نشست جایگزین قطع حساب upstream نیست. ساب قبلی هرگز به کاربر دیگری تخصیص نمی‌یابد.
- مسیر HTTPS: همان botUrl فعلی + delta_app_api.php. فقط POST JSON، action=login (username/password)، session/logout (token). توکن نشست هش و ۲۴ ساعت اعتبار دارد. HTTPS در وب‌سرور اجباری و log body ممنوع باشد.
- هیچ دکمه خرید یا پرداخت عمومی اضافه نشده است. از فروش واقعی قبل از تست و provisioning/قطع دسترسی سمت پنل استفاده نشود.
- آزمون: /delta برای مالک با webhook secret، رد دیگر کاربران؛ ساخت پلن، ثبت دو ساب، ایجاد دو کاربر، جلوگیری از ثبت تکراری، ورود/نشست/تغییر رمز و انقضا.

API requires actual HTTPS at PHP origin (trusted web-server configuration must set HTTPS=on); do not trust arbitrary forwarded headers. A missing/invalid webhook secret makes /delta silent. No credentials are returned to unverified webhooks.
