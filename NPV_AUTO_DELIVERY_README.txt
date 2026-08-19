تحویل خودکار NPVTSUB فعال شد.

برای پلن پاسارگارد نامحدود/npv_lock:
1) ربات Device ID را از کاربر می‌گیرد.
2) بعد از تایید کارت‌به‌کارت یا پرداخت از موجودی، ساب ساخته می‌شود.
3) فایل .npvtsub به‌صورت خودکار ساخته و با نام اشتراک ارسال می‌شود.

ابزار داخلی:
tools/npvtsub-maker

اگر روی سرور اجرا نشد:
chmod +x tools/npvtsub-maker

اگر سرورت ARM باشد و باینری اجرا نشود، سورس ابزار هم داخل tools/npvtsub-maker.go هست و می‌توانی روی همان سرور build کنی:
go build -o tools/npvtsub-maker tools/npvtsub-maker.go
chmod +x tools/npvtsub-maker
