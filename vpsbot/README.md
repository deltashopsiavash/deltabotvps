# vpsbot (Hetzner Cloud + Telegram)

این پروژه یک ربات تلگرام پایتونی روی Ubuntu 22.04 (root) است که:
- خرید VPS (فعلاً دیتاسنتر Hetzner Cloud)
- انتخاب کشور/لوکیشن، سیستم عامل، پلن (CX22/CX23/…)
- پرداخت با موجودی کیف پول یا کارت‌به‌کارت (ثبت فاکتور)
- تحویل مشخصات سرور (IP / user=root / password)
- منوی سفارش‌های من: ریبلد، بازیابی پسورد، خاموش/روشن، نمایش ترافیک مصرفی
- پنل مدیریت: افزودن پلن، مدیریت کاربران، پیام همگانی، سرورهای فعال، تنظیم دکمه‌ها، روشن/خاموش کردن گزینه‌ها
- مانیتور ترافیک و قطع سرویس در صورت رسیدن به سقف (بر اساس داده‌های Metrics در API هتزنر)
- حالت ساعتی (اختیاری) با کسر خودکار از موجودی

> نکته: "شیشه‌ای" واقعی در تلگرام وجود ندارد. ما از دکمه‌های اینلاین + آیکن‌ها + جداکننده‌ها استفاده کرده‌ایم تا UI شبیه شیشه‌ای/Glass باشد.

---

## نصب از صفر تا صد (Ubuntu 22.04, root)

### 1) پیش‌نیازها
```bash
apt update -y
apt install -y python3 python3-pip python3-venv git unzip
```

### 2) ساخت پوشه و کپی فایل‌ها
پیشنهاد: ربات را در `/root/vpsbot` نگه داریم:
```bash
mkdir -p /root/vpsbot
cd /root/vpsbot
# فایل‌های پروژه را اینجا کپی کنید (bot.py, db.py, requirements.txt, ...)
```

### 3) ساخت محیط مجازی و نصب کتابخانه‌ها
```bash
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
```

### 4) تنظیم فایل .env
```bash
cp .env.example .env
nano .env
```

- `BOT_TOKEN`: توکن ربات تلگرام
- `ADMIN_IDS`: آیدی عددی مدیر/مدیران، جدا با کاما
- `HCLOUD_TOKEN`: توکن Hetzner Cloud (در کنسول هتزنر: Project → Security → API Tokens)
- متن شماره کارت: `CARD_NUMBER_TEXT`

### 5) اجرای تستی
```bash
source /root/vpsbot/.venv/bin/activate
python3 /root/vpsbot/bot.py
```

### 6) اجرای دائمی با systemd
```bash
cp /root/vpsbot/vpsbot.service /etc/systemd/system/vpsbot.service
systemctl daemon-reload
systemctl enable --now vpsbot.service
systemctl status vpsbot.service --no-pager
```

لاگ‌ها:
```bash
journalctl -u vpsbot.service -f
```


## پشتیبانی (تیکت)
- کاربر می‌تواند تیکت جدید ثبت کند و پیام بدهد.
- مدیر از «پنل مدیریت → تیکت‌ها» تیکت‌های باز را می‌بیند، پاسخ می‌دهد یا می‌بندد.
