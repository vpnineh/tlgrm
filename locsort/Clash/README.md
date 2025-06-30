# 🏭 کارخانه کانفیگ کلش | Clash Config Factory

[![Workflowname](https://github.com/DiDiten/ScrapeAndCategorize/actions/workflows/main.yml/badge.svg)](https://github.com/DiDiten/ScrapeAndCategorize/actions/workflows/main.yml)

این مخزن به صورت خودکار، فایل‌های کانفیگ پایدار و به‌روز برای **Clash** از روی لیستی از لینک‌های اشتراک (Subscription) شما می‌سازد. هدف اصلی این پروژه، داشتن کانفیگ‌هایی است که حتی در صورت از دسترس خارج شدن لینک اشتراک اصلی، همچنان فعال باقی بمانند.

---

<!-- START_LINKS -->

## 🔗 لینک‌های کانفیگ آماده (Raw)

برای استفاده، لینک‌های زیر را مستقیما در کلش کپی کنید.

* **diditen-fetcher**: `https://raw.githubusercontent.com/DiDiten/ScrapeAndCategorize/main/Clash/output/diditen-fetcher.yaml`
* **diditen-mix**: `https://raw.githubusercontent.com/DiDiten/ScrapeAndCategorize/main/Clash/output/diditen-mix.yaml`
* **mci**: `https://raw.githubusercontent.com/DiDiten/ScrapeAndCategorize/main/Clash/output/mci.yaml`
* **scrape-iran**: `https://raw.githubusercontent.com/DiDiten/ScrapeAndCategorize/main/Clash/output/scrape-iran.yaml`

<!-- END_LINKS -->

---

## ✨ نحوه عملکرد

این سیستم تماماً خودکار است و با استفاده از **GitHub Actions** کار می‌کند. فرآیند به شرح زیر است:

1.  **خواندن قالب**: در هر بار اجرا، اسکریپت ابتدا **قالب کلی** کانفیگ شما را از فایل `template.yaml` (واقع در `Clash/template.yaml`) می‌خواند.
2.  **خواندن فرمت لینک**: سپس به فایل `format.txt` (واقع در `Clash/format.txt`) مراجعه کرده و **قالب پردازش لینک** را از آنجا می‌خواند. این قالب مشخص می‌کند که لینک‌های اشتراک شما چگونه باید پردازش شوند (مثلاً با استفاده از یک سرویس مبدل).
3.  **پردازش لینک‌ها**: اسکریپت به سراغ فایل `subscriptions.txt` (واقع در `Clash/subscriptions.txt`) رفته و لینک‌های اشتراک شما را یک به یک برمی‌دارد. هر لینک را طبق فرمت مرحله قبل، پردازش کرده و **لینک نهایی** را می‌سازد.
4.  **دانلود و ذخیره محتوا**: محتوای هر لینک نهایی دانلود شده و به صورت یک فایل متنی مجزا در پوشه `Clash/providers/` ذخیره می‌شود. این کار باعث **پایداری ابدی** کانفیگ شما می‌شود.
5.  **تولید کانفیگ نهایی**: در نهایت، اسکریپت با استفاده از قالب مرحله اول، یک فایل کانفیگ کامل در پوشه `Clash/output/` می‌سازد که مستقیماً به محتوای دانلود شده در مخزن خودتان اشاره می‌کند.
6.  **به‌روزرسانی README**: این فایل `Clash/README.md` به طور خودکار با لیست جدید کانفیگ‌های تولید شده به‌روز می‌شود.

---

## 🚀 نحوه استفاده و شخصی‌سازی

شما می‌توانید این مخزن را به سادگی برای خودتان شخصی‌سازی کنید.

### ۱. افزودن لینک‌های اشتراک

فایل `subscriptions.txt` (واقع در `Clash/subscriptions.txt`) را باز کرده و لینک‌های اشتراک خود را در آن وارد کنید.

* **حالت عادی (نام خودکار):**
    ```
    [https://example.com/your-sub-link.txt](https://example.com/your-sub-link.txt)
    ```
* **با نام دلخواه (توصیه می‌شود):**
    ```
    [https://example.com/your-sub-link.txt,My-Custom-Name](https://example.com/your-sub-link.txt,My-Custom-Name)
    ```

### ۲. تغییر سرویس مبدل لینک (اختیاری)

اگر می‌خواهید از یک سرویس دیگر برای پردازش لینک‌ها استفاده کنید، فایل `format.txt` (واقع در `Clash/format.txt`) را ویرایش کنید. فقط مطمئن شوید که محل قرارگیری لینک اشتراک اصلی را با `[URL]` مشخص کرده‌اید.

**مثال `format.txt`:**

https://example.com/convert?url=[URL]&param=value