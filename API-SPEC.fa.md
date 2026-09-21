# قرارداد API پروژه کتابسرای پردیس

نسخه سند: `0.1.0`  
وضعیت: پیش‌نویس قابل پیاده‌سازی  
دامنه: وب‌سایت فروش عمده کتاب‌های آموزش زبان  
روش خرید: قیمت پلکانی، سفارش مستقیم و پرداخت آنلاین

---

# ۱. هدف و مرزبندی

این سند APIهای موردنیاز Backend «کتابسرای پردیس» را تعریف می‌کند و باید مبنای ساخت OpenAPI نهایی، Backend، Frontend، پنل مدیریت و تست QA قرار گیرد.

```text
مشاهده کاتالوگ بدون ورود
    ↓
انتخاب SKU و تعداد
    ↓
محاسبه قیمت پلکانی و کنترل موجودی
    ↓
سبد مهمان یا کاربر
    ↓
ورود با OTP و اتصال سبد
    ↓
نشانی و روش ارسال
    ↓
ساخت سفارش و رزرو موجودی
    ↓
پرداخت آنلاین و تأیید سمت سرور
    ↓
فاکتور، ارسال و رهگیری
```

## داخل MVP

- کاتالوگ، مجموعه، ناشر، دسته‌بندی و جستجو
- SKU مستقل برای Level، Edition، نوع کتاب و بسته
- قیمت پلکانی، حداقل سفارش و موجودی در سطح SKU
- سبد مهمان و سبد کاربر
- ورود با شماره موبایل و OTP
- پروفایل شخصی/تجاری و چند نشانی
- محاسبه ارسال و کد تخفیف اختیاری
- پرداخت آنلاین و Payment Attempt
- سفارش، فاکتور، مرسوله و رهگیری
- تیکت پشتیبانی و پیوست تصویر
- پنل مدیریت و Audit Log

## خارج از MVP

- Marketplace چندفروشنده‌ای
- مذاکره یا درخواست دستی قیمت
- کیف پول، اعتبار و خرید اقساطی
- قیمت قراردادی اختصاصی هر مشتری
- چندارزی و فروش بین‌المللی
- مرجوعی و بازپرداخت کاملاً خودکار

---

# ۲. استاندارد عمومی قرارداد

## آدرس و نسخه

```text
Production: https://api.pardisbook.ir/api/v1
Staging:    https://staging-api.pardisbook.ir/api/v1
Admin:      /api/v1/admin
```

- JSON با UTF-8؛ Upload به‌صورت `multipart/form-data` یا Presigned Upload.
- زمان ISO 8601 و UTC باشد.
- پیشنهاد سند: همه مبلغ‌ها Integer و برحسب **تومان** باشند.
- تغییر Breaking نیازمند `/api/v2` است.

## Headerهای مشترک

| Header | کاربرد |
|---|---|
| `Authorization: Bearer <accessToken>` | APIهای نیازمند ورود |
| `X-Request-Id` | Trace درخواست |
| `X-Guest-Cart-Token` | سبد مهمان |
| `Idempotency-Key` | ساخت سفارش و پرداخت |
| `Accept-Language: fa-IR` | زبان پیام |
| `X-Client-Version` | نسخه Frontend |
| `X-Device-Id` | شناسه نصب مرورگر |

## Envelope موفق

```json
{
  "data": {},
  "meta": {
    "requestId": "req_01J7...",
    "serverTime": "2026-09-09T08:35:20Z"
  }
}
```

برای Collection، `page`, `pageSize`, `total`, `totalPages` داخل `meta` قرار گیرند.

## Envelope خطا

```json
{
  "error": {
    "code": "CART_MINIMUM_NOT_MET",
    "message": "تعداد این کالا کمتر از حداقل سفارش عمده است.",
    "fields": {"quantity": "حداقل تعداد این کالا ۵ جلد است."},
    "details": {
      "skuId": "sku_ff3_sb_2e",
      "minimumQuantity": 5,
      "requestedQuantity": 3
    }
  },
  "meta": {"requestId": "req_01J7..."}
}
```

`error.code` پایدار و مناسب منطق Frontend است؛ `message` قابل تغییر و ترجمه است.

## HTTP Status

| Status | کاربرد |
|---|---|
| `200/201/204` | موفق |
| `202` | پردازش ادامه‌دار، مانند پرداخت نامشخص |
| `400` | درخواست نامعتبر |
| `401/403` | احراز هویت/مجوز |
| `404` | منبع یافت نشد |
| `409` | تعارض قیمت، موجودی، نسخه یا State |
| `422` | Validation قابل اصلاح |
| `429` | Rate Limit |
| `500/503` | خطای داخلی/وابستگی خارجی |

## Pagination و Sort

```text
?page=1&pageSize=24
?sort=bestSelling
?filter[seriesId]=ser_family_friends
?filter[inStock]=true
```

- `pageSize` عمومی حداکثر ۶۰ و پنل حداکثر ۱۰۰ باشد.
- فیلدهای Sort در هر Endpoint Whitelist شوند.

## Idempotency و همزمانی

- `POST /orders` و `POST /payments/attempts` به `Idempotency-Key` نیاز دارند.
- Key تکراری با Payload یکسان همان پاسخ قبلی را می‌دهد.
- Key تکراری با Payload متفاوت: `409 IDEMPOTENCY_KEY_REUSED`.
- Cart و Address دارای `version` باشند.
- ویرایش نسخه قدیمی: `409 RESOURCE_VERSION_CONFLICT`.

---

# ۳. احراز هویت و نشست

| Method | Path | Auth | کاربرد |
|---|---|---|---|
| `POST` | `/auth/otp/request` | عمومی | درخواست OTP |
| `POST` | `/auth/otp/verify` | عمومی | تأیید و ورود/ثبت‌نام |
| `POST` | `/auth/admin/login` | عمومی | ورود کارکنان پنل با نام کاربری و رمز عبور |
| `POST` | `/auth/token/refresh` | Refresh Token | تمدید نشست |
| `POST` | `/auth/logout` | کاربر | خروج نشست جاری |
| `POST` | `/auth/logout-all` | کاربر | خروج همه نشست‌ها |
| `GET` | `/auth/sessions` | کاربر | نشست‌های فعال |
| `DELETE` | `/auth/sessions/{sessionId}` | کاربر | لغو نشست دیگر |

### درخواست OTP

```json
{
  "phone": "+989121234567",
  "purpose": "login"
}
```

```json
{
  "data": {
    "challengeId": "otp_01J7...",
    "expiresInSeconds": 120,
    "resendAfterSeconds": 60,
    "maskedPhone": "0912•••4567"
  }
}
```

- شماره به E.164 تبدیل شود.
- پاسخ وجود حساب را افشا نکند.
- OTP Hash شود و متن خام ذخیره نشود.
- تعداد ارسال و تلاش محدود باشد.

### تأیید OTP و ادغام سبد

```json
{
  "challengeId": "otp_01J7...",
  "code": "247109",
  "guestCartToken": "gct_01J7..."
}
```

Response شامل Access/Refresh Token، User، `isNewUser`, `profileCompleted` و نتیجه `cartMerge` است. ادغام سبد Server-side انجام می‌شود؛ Quantity تکراری جمع و سپس حداقل، موجودی و قیمت دوباره محاسبه می‌شوند.

### ورود پنل مدیریت

```json
{
  "username": "admin",
  "password": "a-strong-password"
}
```

این مسیر فقط برای کاربری که حداقل یک نقش غیرمشتری دارد نشست صادر می‌کند. پیام خطای نام
کاربری ناموجود و رمز اشتباه یکسان است. نشست پنل به‌صورت پیش‌فرض ۳۰ روز اعتبار دارد؛
Access Token کوتاه‌عمر است و با Refresh Token چرخشی تمدید می‌شود.

---

# ۴. Bootstrap، Home و محتوای عمومی

| Method | Path | Auth | کاربرد |
|---|---|---|---|
| `GET` | `/bootstrap` | اختیاری | تنظیمات شروع برنامه |
| `GET` | `/navigation` | عمومی | منوی دسته‌ها |
| `GET` | `/home` | عمومی | Aggregate صفحه اصلی |
| `GET` | `/series/featured` | عمومی | مجموعه‌های منتخب |
| `GET` | `/products/best-selling?limit=12` | عمومی | پرفروش‌های انتخاب‌شده در پنل ادمین |
| `GET` | `/products/best-selling/search?q=&limit=12` | عمومی | جست‌وجوی پرفروش‌های فعال بر اساس نام کتاب |
| `GET` | `/products/fast-dispatch` | عمومی | آماده ارسال سریع |
| `GET` | `/content/pages/{slug}` | عمومی | قوانین، درباره و راهنما |
| `GET` | `/health/live` | داخلی | Liveness |
| `GET` | `/health/ready` | داخلی | Readiness |

`GET /home` بهتر است Aggregate Cacheable باشد و Hero، مجموعه‌های منتخب، پرفروش‌ها، ارسال سریع و مراحل خرید عمده را یکجا برگرداند.

---

# ۵. کاتالوگ، Taxonomy و جستجو

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/products` | لیست و فیلتر محصولات |
| `GET` | `/products/{productSlug}` | جزئیات محصول |
| `GET` | `/products/{productSlug}/related` | محصولات مرتبط |
| `GET` | `/search/suggestions?q=` | پیشنهاد زنده |
| `GET` | `/search?q=` | نتایج کامل |
| `GET` | `/series` | مجموعه‌ها |
| `GET` | `/series/{seriesSlug}` | Landing مجموعه و Levelها |
| `GET` | `/publishers` | ناشران |
| `GET` | `/categories` | دسته‌ها و گروه سنی |
| `GET` | `/catalog/facets` | Facet و Count فیلترها |

پارامترهای فیلتر:

```text
q, seriesId, categoryId, ageGroup, level, publisherId, edition,
bookType, inStock, fastDispatch, minUnitPrice, maxUnitPrice,
sort, page, pageSize
```

مقادیر Sort: `bestSelling`, `newest`, `unitPriceAsc`, `discountDesc`, `fastDispatch`.

### Product Summary

```json
{
  "id": "prd_01J7...",
  "slug": "family-and-friends-3-second-edition",
  "title": "Family and Friends 3",
  "subtitle": "Second Edition — Student Book",
  "series": {"id": "ser_ff", "name": "Family and Friends"},
  "publisher": {"id": "pub_oxford", "name": "Oxford University Press"},
  "cover": {"url": "https://cdn.pardisbook.ir/...", "alt": "جلد کتاب"},
  "defaultSku": {
    "id": "sku_ff3_sb_2e",
    "minimumQuantity": 5,
    "startingUnitPrice": 285000,
    "availability": "in_stock",
    "fastDispatch": true
  }
}
```

Search روی عنوان فارسی/انگلیسی، Series، Level، ISBN و SKU Code انجام شود. نیم‌فاصله، ارقام فارسی/انگلیسی و `ی/ي` و `ک/ك` Normalize شوند.

---

# ۶. قیمت پلکانی و موجودی

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/skus/{skuId}/pricing` | جدول قیمت جاری |
| `POST` | `/pricing/quote-line` | قیمت یک SKU و تعداد |
| `POST` | `/availability/check` | کنترل همزمان چند SKU |

`POST /pricing/quote-line`

```json
{
  "skuId": "sku_ff3_sb_2e",
  "quantity": 10
}
```

```json
{
  "data": {
    "skuId": "sku_ff3_sb_2e",
    "quantity": 10,
    "minimumQuantity": 5,
    "selectedTier": {"min": 10, "max": 24, "unitPrice": 245000},
    "unitPrice": 245000,
    "referenceUnitPrice": 285000,
    "lineSubtotal": 2450000,
    "lineSaving": 400000,
    "availableQuantity": 120,
    "canPurchase": true,
    "pricingVersion": "pv_4872"
  }
}
```

## قواعد Pricing Engine

1. قیمت Line فقط براساس Quantity همان SKU محاسبه شود.
2. Frontend حق تعیین `unitPrice` ندارد.
3. Tierها بدون هم‌پوشانی باشند.
4. Quantity کمتر از حداقل یا بیشتر از موجودی قابل Checkout نیست.
5. تمام محاسبات Integer و بدون Float باشند.
6. Snapshot قیمت هنگام ساخت Order ذخیره شود.

وضعیت موجودی:

```text
in_stock
limited
out_of_stock
coming_soon
archived
```

```text
sellable = onHand - reserved - safetyStock
```

موجودی دقیق عمومی نیست مگر مدیر اجازه دهد. API عمومی می‌تواند `maximumPurchasableQuantity` را برگرداند.

---

# ۷. سبد خرید

| Method | Path | Auth | کاربرد |
|---|---|---|---|
| `POST` | `/carts` | عمومی | ساخت سبد مهمان |
| `GET` | `/cart` | اختیاری | سبد جاری |
| `POST` | `/cart/items` | اختیاری | افزودن SKU |
| `PATCH` | `/cart/items/{itemId}` | اختیاری | تغییر تعداد |
| `DELETE` | `/cart/items/{itemId}` | اختیاری | حذف Line |
| `DELETE` | `/cart/items` | اختیاری | خالی‌کردن سبد |
| `POST` | `/cart/validate` | اختیاری | Reprice و Validation |
| `POST` | `/cart/merge` | کاربر | ادغام سبد مهمان |

`POST /cart/items`

```json
{
  "skuId": "sku_ff3_sb_2e",
  "quantity": 10
}
```

Response باید کل Cart محاسبه‌شده را برگرداند.

### مدل Cart

```json
{
  "id": "cart_01J7...",
  "version": 7,
  "currency": "TOMAN",
  "items": [
    {
      "id": "ci_01J7...",
      "skuId": "sku_ff3_sb_2e",
      "product": {"title": "Family and Friends 3", "cover": {}},
      "variant": {"level": "3", "edition": "2nd Edition", "bookType": "student_book"},
      "quantity": 10,
      "minimumQuantity": 5,
      "unitPrice": 245000,
      "referenceUnitPrice": 285000,
      "lineSubtotal": 2450000,
      "lineSaving": 400000,
      "availability": "in_stock",
      "warnings": []
    }
  ],
  "summary": {
    "lineCount": 1,
    "totalQuantity": 10,
    "referenceSubtotal": 2850000,
    "merchandiseSubtotal": 2450000,
    "wholesaleSaving": 400000,
    "couponDiscount": 0,
    "shipping": null,
    "payable": 2450000
  },
  "isValidForCheckout": true,
  "warnings": []
}
```

`POST /cart/validate` فعال‌بودن SKU، حداقل تعداد، موجودی، قیمت، Coupon و محدودیت خطوط/وزن را بررسی می‌کند؛ این Endpoint موجودی را رزرو نمی‌کند.

---

# ۸. حساب خریدار و نشانی‌ها

## پروفایل

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/me` | اطلاعات کاربر |
| `PATCH` | `/me` | نام و نوع مشتری |
| `GET` | `/me/business-profile` | اطلاعات تجاری |
| `PUT` | `/me/business-profile` | ایجاد/ویرایش اطلاعات تجاری |
| `DELETE` | `/me` | درخواست حذف حساب |

مقادیر `customerType`:

```text
individual
teacher
language_school
bookstore
school
other_business
```

## نشانی‌ها

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/me/addresses` | فهرست |
| `POST` | `/me/addresses` | ایجاد |
| `PATCH` | `/me/addresses/{addressId}` | ویرایش |
| `DELETE` | `/me/addresses/{addressId}` | حذف |
| `POST` | `/me/addresses/{addressId}/default` | پیش‌فرض |

```json
{
  "title": "دفتر مرکزی",
  "recipientName": "علی محمدی",
  "recipientPhone": "+989121234567",
  "provinceId": "prv_tehran",
  "cityId": "city_tehran",
  "postalCode": "1234567890",
  "addressLine": "خیابان ...",
  "buildingNumber": "12",
  "unit": "3",
  "isDefault": true
}
```

نشانی هنگام سفارش Snapshot می‌شود؛ ویرایش بعدی سفارش تاریخی را تغییر نمی‌دهد.

---

# ۹. ارسال و لجستیک

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/locations/provinces` | استان‌ها |
| `GET` | `/locations/cities?provinceId=` | شهرها |
| `POST` | `/shipping/options` | محاسبه روش ارسال |
| `GET` | `/orders/{orderId}/shipments` | مرسوله‌ها |
| `GET` | `/shipments/{shipmentId}/tracking` | رهگیری Normalize‌شده |

```json
{
  "cartId": "cart_01J7...",
  "addressId": "adr_01J7..."
}
```

Response شامل `options` با شناسه، عنوان، هزینه، حداقل/حداکثر روز کاری، پشتیبانی چندمرسوله‌ای و `quoteExpiresAt` است.

- هزینه ارسال از Frontend پذیرفته نشود.
- Weight و Dimension از SKUها محاسبه شوند.
- Order بتواند چند Shipment داشته باشد.
- Tracking Provider پشت Adapter داخلی باشد.

---

# ۱۰. کد تخفیف

| Method | Path | کاربرد |
|---|---|---|
| `POST` | `/cart/coupon` | اعمال Coupon |
| `DELETE` | `/cart/coupon` | حذف Coupon |

Coupon باید بازه زمانی، حداقل مبلغ/تعداد، سقف تخفیف، محدودیت SKU/Series، نوع مشتری و تعداد مصرف را پشتیبانی کند. تخفیف عمده همیشه جدا از Coupon نمایش داده شود.

---

# ۱۱. Checkout و سفارش

| Method | Path | کاربرد |
|---|---|---|
| `POST` | `/checkout/quote` | Quote کامل قابل پرداخت |
| `POST` | `/orders` | ساخت سفارش و رزرو موجودی |
| `GET` | `/orders/{orderId}` | دریافت سفارش |

### Checkout Quote

```json
{
  "cartId": "cart_01J7...",
  "addressId": "adr_01J7...",
  "shippingOptionId": "ship_standard",
  "invoiceRequested": true
}
```

Response شامل `quoteId`, `cartVersion`, Lineها، Snapshot نشانی، Shipping، Summary، Warning و `expiresAt` است.

### ساخت سفارش

Header اجباری: `Idempotency-Key`

```json
{
  "quoteId": "quo_01J7...",
  "acceptTermsVersion": "terms-2026-08",
  "customerNote": null
}
```

```json
{
  "data": {
    "id": "ord_01J7...",
    "orderNumber": "PD-20260909-78156",
    "status": "awaiting_payment",
    "paymentStatus": "unpaid",
    "payable": 8050000,
    "inventoryReservationExpiresAt": "2026-09-09T09:20:20Z"
  }
}
```

ساخت Order در Transaction:

1. Quote و Cart Version بررسی شوند.
2. قیمت دوباره محاسبه شود.
3. موجودی با Lock مناسب رزرو شود.
4. Snapshot Line، Address، Shipping و Discount ذخیره شود.
5. Order با `awaiting_payment` ساخته شود.
6. Domain Event در Outbox ثبت شود.

---

# ۱۲. پرداخت آنلاین

| Method | Path | کاربرد |
|---|---|---|
| `POST` | `/payments/attempts` | ساخت Attempt و Redirect |
| `GET` | `/payments/attempts/{attemptId}` | وضعیت Attempt |
| `POST` | `/payments/attempts/{attemptId}/verify` | بررسی پس از بازگشت |
| `GET` | `/orders/{orderId}/payment` | وضعیت پرداخت سفارش |

`POST /payments/attempts`

```json
{
  "orderId": "ord_01J7...",
  "provider": "default",
  "returnUrl": "https://pardisbook.ir/payment/result"
}
```

Response شامل `attemptId`, `status`, `redirectUrl` و `expiresAt` است.

- مبلغ فقط از Order خوانده شود.
- `returnUrl` از Allowlist پذیرفته شود.
- پرداخت موفق فقط با Verify سمت سرور معتبر است.
- Browser Callback به‌تنهایی Order را Paid نمی‌کند.

Stateها:

```text
initiated
redirected
pending_verification
succeeded
failed
expired
cancelled
```

Order Paid، Attempt جدید نمی‌پذیرد: `409 ORDER_ALREADY_PAID`.

---

# ۱۳. Callback و Webhook پرداخت

```text
GET|POST /payments/callback/{provider}
POST     /webhooks/payments/{provider}
```

فرآیند اجباری:

1. Signature/Authority با مستند رسمی Provider بررسی شود.
2. Attempt از Reference داخلی پیدا شود.
3. مبلغ، Merchant و Order تطبیق داده شوند.
4. Verify Server-to-Server انجام شود.
5. Attempt و Order فقط یک‌بار موفق شوند.
6. رزرو موجودی قطعی شود.
7. Outbox Event ثبت شود.

Webhook باید Idempotent باشد و Payload حساس به‌صورت Redacted نگهداری شود.

---

# ۱۴. سفارش‌ها، فاکتور و رهگیری

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/orders` | فهرست سفارش‌های کاربر |
| `GET` | `/orders/{orderId}` | جزئیات |
| `GET` | `/orders/{orderId}/timeline` | تاریخچه وضعیت |
| `GET` | `/orders/{orderId}/invoice` | Metadata فاکتور |
| `GET` | `/orders/{orderId}/invoice.pdf` | PDF مجاز |
| `GET` | `/orders/{orderId}/shipments` | مرسوله‌ها |
| `POST` | `/orders/{orderId}/cancel` | لغو مجاز |

وضعیت سفارش:

```text
awaiting_payment
payment_review
paid
preparing
partially_shipped
shipped
delivered
completed
cancelled
refunded
```

Transitionها Server-side کنترل شوند. مدیر نباید آزادانه از هر وضعیت به هر وضعیت برود.

### Order Line Snapshot

```json
{
  "skuId": "sku_ff3_sb_2e",
  "skuCode": "FF3-SB-2E",
  "productTitle": "Family and Friends 3",
  "variantTitle": "Student Book — Second Edition",
  "isbn": "9780194808653",
  "quantity": 10,
  "unitPrice": 245000,
  "referenceUnitPrice": 285000,
  "lineSubtotal": 2450000,
  "lineSaving": 400000,
  "tax": 0
}
```

فاکتور فقط برای مالک سفارش یا Staff مجاز قابل دریافت است. اطلاعات آن از Snapshot سفارش تولید و شماره آن یکتا و غیرقابل تغییر است.

---

# ۱۵. پشتیبانی و Upload

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/support/categories` | دسته‌بندی درخواست |
| `GET` | `/support/tickets` | تیکت‌های کاربر |
| `POST` | `/support/tickets` | ایجاد تیکت |
| `GET` | `/support/tickets/{ticketId}` | گفتگو |
| `POST` | `/support/tickets/{ticketId}/messages` | پاسخ |
| `POST` | `/support/uploads` | Presigned Upload |
| `POST` | `/support/tickets/{ticketId}/close` | بستن |

```json
{
  "categoryId": "shipping_delay",
  "orderId": "ord_01J7...",
  "title": "تأخیر در ارسال سفارش",
  "description": "...",
  "attachmentIds": ["upl_01J7..."]
}
```

وضعیت تیکت: `new`, `in_review`, `waiting_for_customer`, `answered`, `closed`.

قواعد Upload:

- MIME، Extension و Magic Byte کنترل شوند.
- تصویر Decode/Re-encode یا Scan شود.
- EXIF حساس حذف شود.
- پیشنهاد: حداکثر ۵ مگابایت و ۳ پیوست.
- فایل Private باشد و URL دائمی عمومی نداشته باشد.
- کاربر فقط Order متعلق به خود را به تیکت متصل کند.

---

# ۱۶. نقش‌ها و مجوزها

نقش‌های پیشنهادی:

```text
customer
support_agent
catalog_manager
inventory_manager
order_operator
finance_operator
content_manager
admin
super_admin
```

Permissionهای نمونه:

```text
catalog.read
catalog.write
pricing.write
inventory.adjust
orders.read
orders.update_status
payments.read
payments.refund_request
tickets.respond
customers.read_limited
reports.read
staff.manage
audit.read
```

RBAC سمت Backend اجباری است؛ مخفی‌کردن دکمه در Frontend کنترل امنیتی نیست.

---

# ۱۷. APIهای پنل مدیریت: کاتالوگ

| Method | Path | کاربرد |
|---|---|---|
| `GET/POST` | `/admin/products` | فهرست/ایجاد Product |
| `GET/PATCH` | `/admin/products/{id}` | مشاهده/ویرایش |
| `POST` | `/admin/products/{id}/publish` | انتشار |
| `POST` | `/admin/products/{id}/archive` | آرشیو |
| `GET/POST` | `/admin/skus` | SKUها |
| `PATCH` | `/admin/skus/{id}` | Variant و مشخصات |
| `GET/POST` | `/admin/series` | مجموعه‌ها |
| `GET/POST` | `/admin/publishers` | ناشران |
| `GET/POST` | `/admin/categories` | دسته‌ها |
| `POST` | `/admin/media/presign` | Upload جلد |
| `POST` | `/admin/catalog/import` | Import CSV |
| `GET` | `/admin/catalog/import/{jobId}` | نتیجه Import |
| `GET/POST` | `/admin/best-selling-products` | فهرست/افزودن پرفروش‌های دستی |
| `POST` | `/admin/best-selling-products/cover` | آپلود تصویر جلد روی S3 |
| `PATCH/DELETE` | `/admin/best-selling-products/{id}` | ویرایش/حذف پرفروش دستی |

- Draft و Published جدا باشند.
- Slug یکتا باشد.
- Product دارای سفارش فقط Archive می‌شود، نه حذف فیزیکی.
- تغییر ISBN یا SKU Code حساس و Audit شود.

### پرفروش‌های دستی

بدنه ساخت/ویرایش شامل `title`، `coverUrl`، `coverAlt` (اختیاری)، `price`،
`discountedPrice`، `discountPercent`، `remainingPercent`، `sortOrder` و `active` است.
قیمت‌ها عدد صحیح برحسب تومان و درصدها بین ۰ تا ۱۰۰ هستند. API عمومی فقط آیتم‌های
فعال را طبق `sortOrder` برمی‌گرداند. نمونه پاسخ عمومی:

برای تصویر جلد، پنل ابتدا یک درخواست `multipart/form-data` به
`POST /admin/best-selling-products/cover` می‌فرستد. نام فیلد فایل `image` است؛
فرمت‌های JPG، PNG و WebP تا سقف ۵ مگابایت پذیرفته می‌شوند. پاسخ موفق:

```json
{
  "data": {
    "coverUrl": "https://cdn.example.com/public/catalog/covers/cover_....webp"
  }
}
```

سپس مقدار `coverUrl` در درخواست ساخت/ویرایش محصول استفاده می‌شود.

```json
{
  "id": "bsp_...",
  "title": "نام کتاب",
  "coverUrl": "https://cdn.example.com/book.jpg",
  "coverAlt": "جلد نام کتاب",
  "price": 250000,
  "discountedPrice": 200000,
  "discountPercent": 20,
  "remainingPercent": 35
}
```

برای جست‌وجو، پارامتر `q` الزامی و حداقل ۲ کاراکتر است. پنل ادمین نیز می‌تواند
فهرست مدیریتی را با `GET /admin/best-selling-products?q=نام‌کتاب` فیلتر کند.

---

# ۱۸. APIهای پنل مدیریت: قیمت و موجودی

| Method | Path | کاربرد |
|---|---|---|
| `GET/PUT` | `/admin/skus/{skuId}/pricing-tiers` | قیمت پلکانی |
| `GET` | `/admin/inventory` | موجودی SKUها |
| `POST` | `/admin/inventory/adjustments` | اصلاح موجودی با علت |
| `GET` | `/admin/inventory/movements` | گردش موجودی |
| `GET/POST` | `/admin/warehouses` | انبارها |
| `GET` | `/admin/reservations` | رزروها |
| `POST` | `/admin/reservations/{id}/release` | آزادسازی کنترل‌شده |

```json
{
  "skuId": "sku_ff3_sb_2e",
  "minimumOrderQuantity": 5,
  "tiers": [
    {"minQuantity": 5, "maxQuantity": 9, "unitPrice": 285000},
    {"minQuantity": 10, "maxQuantity": 24, "unitPrice": 245000},
    {"minQuantity": 25, "maxQuantity": 49, "unitPrice": 230000},
    {"minQuantity": 50, "maxQuantity": null, "unitPrice": 220000}
  ],
  "effectiveFrom": "2026-09-10T00:00:00Z"
}
```

- Tierها مرتب و بدون هم‌پوشانی باشند.
- Adjustment موجودی همیشه Reason و Actor داشته باشد.
- تغییرات قیمت Cache Invalidation و Audit می‌خواهند.

---

# ۱۹. APIهای پنل مدیریت: سفارش، پرداخت و ارسال

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/admin/orders` | جستجو و فیلتر سفارش |
| `GET` | `/admin/orders/{id}` | جزئیات کامل |
| `POST` | `/admin/orders/{id}/transitions` | تغییر وضعیت مجاز |
| `POST` | `/admin/orders/{id}/notes` | یادداشت داخلی |
| `GET` | `/admin/payments` | Payment Attemptها |
| `POST` | `/admin/payments/{id}/reverify` | Verify مجدد کنترل‌شده |
| `POST` | `/admin/refund-requests` | درخواست بازپرداخت |
| `POST` | `/admin/orders/{id}/shipments` | ساخت مرسوله |
| `PATCH` | `/admin/shipments/{id}` | ویرایش رهگیری |
| `POST` | `/admin/shipments/{id}/dispatch` | تحویل به حمل |
| `POST` | `/admin/orders/{id}/invoice` | تولید فاکتور |

هر Transition باید `reason`, `actorId`, `previousStatus`, `newStatus` و Timestamp داشته باشد.

---

# ۲۰. APIهای پنل مدیریت: مشتری و پشتیبانی

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/admin/customers` | جستجوی محدود مشتریان |
| `GET` | `/admin/customers/{id}` | پروفایل و سفارش‌ها |
| `PATCH` | `/admin/customers/{id}` | ویرایش محدود با Audit |
| `GET` | `/admin/tickets` | صف تیکت‌ها |
| `GET` | `/admin/tickets/{id}` | گفتگو |
| `POST` | `/admin/tickets/{id}/messages` | پاسخ Staff |
| `POST` | `/admin/tickets/{id}/assign` | تخصیص |
| `POST` | `/admin/tickets/{id}/status` | تغییر وضعیت |

نمایش شماره موبایل و اطلاعات فاکتور براساس Permission و نیاز کاری محدود شود.

---

# ۲۱. محتوا، Coupon و تنظیمات پنل

| Method | Path | کاربرد |
|---|---|---|
| `GET/PUT` | `/admin/home` | مدیریت Home |
| `GET/POST` | `/admin/content/pages` | صفحات ثابت |
| `GET/POST` | `/admin/coupons` | Couponها |
| `PATCH` | `/admin/coupons/{id}` | تغییر/غیرفعال‌سازی |
| `GET/PUT` | `/admin/settings/commerce` | تنظیمات فروش |
| `GET/PUT` | `/admin/settings/support` | پشتیبانی |
| `GET/PUT` | `/admin/settings/shipping` | ارسال |

---

# ۲۲. گزارش‌ها

| Method | Path | کاربرد |
|---|---|---|
| `GET` | `/admin/reports/sales-summary` | فروش بازه |
| `GET` | `/admin/reports/top-products` | SKUهای پرفروش |
| `GET` | `/admin/reports/inventory-risk` | کمبود موجودی |
| `GET` | `/admin/reports/customers` | مشتریان فعال |
| `POST` | `/admin/reports/exports` | خروجی غیرهمزمان |
| `GET` | `/admin/reports/exports/{jobId}` | وضعیت فایل |

گزارش مالی براساس Payment موفق باشد، نه صرفاً Order ساخته‌شده.

---

# ۲۳. مدل‌های داده اصلی

```text
User, Session, OtpChallenge, BusinessProfile, Address,
Category, Publisher, Series, Product, Sku, ProductMedia,
PricingTier, Warehouse, InventoryBalance, InventoryMovement,
InventoryReservation, Cart, CartItem, Coupon, CouponRedemption,
CheckoutQuote, Order, OrderLine, OrderStatusHistory,
PaymentAttempt, PaymentWebhook, Invoice, Shipment, ShipmentItem,
SupportTicket, SupportMessage, Upload, StaffUser, Role, Permission,
AuditLog, OutboxEvent
```

روابط حیاتی:

```text
Series 1 ── N Product
Product 1 ── N Sku
Sku 1 ── N PricingTier
Sku 1 ── N InventoryBalance
Cart 1 ── N CartItem
Order 1 ── N OrderLine
Order 1 ── N PaymentAttempt
Order 1 ── N Shipment
Shipment N ── N OrderLine (through ShipmentItem)
User 1 ── N Address / Order / SupportTicket
```

Snapshotهای اجباری Order:

- عنوان، Variant، SKU Code و ISBN
- Quantity، قیمت واحد و Tier
- تخفیف عمده و Coupon
- نشانی و گیرنده
- روش و هزینه ارسال
- اطلاعات فاکتور
- نسخه قوانین پذیرفته‌شده

---

# ۲۴. رویدادهای دامنه و Jobها

رویدادهای پیشنهادی:

```text
user.registered
cart.merged
order.created
order.payment_succeeded
order.payment_failed
order.status_changed
inventory.reserved
inventory.released
inventory.adjusted
shipment.created
shipment.dispatched
shipment.delivered
invoice.generated
ticket.created
ticket.answered
```

Jobهای پس‌زمینه:

- آزادسازی Reservation سفارش پرداخت‌نشده
- Reconcile پرداخت Pending
- تولید PDF فاکتور
- ارسال SMS وضعیت سفارش
- Sync رهگیری مرسوله
- پردازش تصویر Upload
- Import کاتالوگ و Export گزارش
- پاک‌سازی OTP و Session منقضی
- ارسال Outbox Event

Jobها Idempotent، Retryable و دارای Failed/Dead-letter قابل مشاهده باشند.

---

# ۲۵. کدهای خطای پایدار

## Auth

```text
OTP_RATE_LIMITED
OTP_EXPIRED
OTP_INVALID
OTP_MAX_ATTEMPTS_REACHED
AUTH_TOKEN_EXPIRED
AUTH_SESSION_REVOKED
```

## Catalog و Pricing

```text
PRODUCT_NOT_FOUND
SKU_NOT_FOUND
SKU_ARCHIVED
PRICING_NOT_AVAILABLE
PRICING_CHANGED
INVALID_PRICING_TIER
```

## Cart و Inventory

```text
CART_NOT_FOUND
CART_ITEM_NOT_FOUND
CART_MINIMUM_NOT_MET
CART_MAXIMUM_EXCEEDED
CART_TOO_MANY_LINES
CART_VERSION_CONFLICT
INSUFFICIENT_STOCK
ITEM_BECAME_UNAVAILABLE
```

## Coupon و Checkout

```text
COUPON_NOT_FOUND
COUPON_EXPIRED
COUPON_NOT_ELIGIBLE
COUPON_USAGE_LIMIT_REACHED
CHECKOUT_QUOTE_EXPIRED
CHECKOUT_QUOTE_CHANGED
SHIPPING_OPTION_UNAVAILABLE
ADDRESS_NOT_SERVICEABLE
```

## Order و Payment

```text
ORDER_NOT_FOUND
ORDER_NOT_PAYABLE
ORDER_ALREADY_PAID
ORDER_CANNOT_BE_CANCELLED
PAYMENT_ATTEMPT_NOT_FOUND
PAYMENT_PROVIDER_UNAVAILABLE
PAYMENT_VERIFICATION_FAILED
PAYMENT_STATUS_PENDING
IDEMPOTENCY_KEY_REQUIRED
IDEMPOTENCY_KEY_REUSED
```

## عمومی

```text
VALIDATION_FAILED
FORBIDDEN
RESOURCE_VERSION_CONFLICT
RATE_LIMITED
UPLOAD_TOO_LARGE
UPLOAD_TYPE_NOT_ALLOWED
INTERNAL_ERROR
DEPENDENCY_UNAVAILABLE
```

---

# ۲۶. امنیت و حریم خصوصی

## احراز هویت

- Access Token کوتاه‌عمر؛ پیشنهاد ۱۵ دقیقه.
- Refresh Token چرخشی و قابل لغو.
- OTP Hash‌شده، یک‌بارمصرف و دارای Attempt Limit.
- محافظت CSRF در Cookie-based Auth.

## پرداخت

- Secret درگاه فقط در Secret Manager.
- Verify سمت سرور و Idempotency کامل Webhook.
- عدم Log داده حساس بانکی.
- Allowlist برای Return URL.
- Reconciliation منظم با Provider.

## Authorization و داده شخصی

- Ownership تمام Order، Address، Ticket و Upload بررسی شود.
- Admin با RBAC و ترجیحاً MFA محافظت شود.
- شماره موبایل، نشانی و شناسه تجاری در Log Redact شوند.
- Backup رمزگذاری‌شده و Retention داده‌ها مشخص باشد.

## Rate Limit پیشنهادی

| عملیات | حد اولیه |
|---|---|
| درخواست OTP | ۳ بار در ۱۰ دقیقه برای شماره و IP |
| تأیید OTP | ۵ تلاش برای Challenge |
| Search Suggestion | ۶۰ درخواست در دقیقه/IP |
| Cart Mutation | ۱۲۰ درخواست در دقیقه/Cart |
| ساخت Order | ۱۰ درخواست در دقیقه/User |
| Payment Attempt | ۵ درخواست در ۱۰ دقیقه/Order |
| Ticket | ۱۰ درخواست در روز/User |

این مقادیر با Monitoring واقعی تنظیم شوند.

---

# ۲۷. Cache و کارایی

- `bootstrap`, Navigation، Series، Category و Product Summary قابل Cache هستند.
- قیمت و موجودی Cache کوتاه‌عمر یا Versioned می‌خواهند.
- Cart، Checkout و Order از Cache عمومی پاسخ داده نشوند.
- CDN برای جلد و فایل عمومی استفاده شود.
- ETag برای Product Detail و صفحات محتوا مفید است.

هدف P95 پیشنهادی:

| عملیات | هدف |
|---|---|
| Search Suggestion | کمتر از ۲۰۰ms |
| Product List | کمتر از ۴۰۰ms |
| Cart Reprice | کمتر از ۵۰۰ms |
| Checkout Quote | کمتر از ۸۰۰ms بدون تأخیر Provider |

Indexهای ضروری: `sku.code`, `sku.isbn`, `product.slug`, `series.slug`, `order.user_id+created_at`, `order.order_number`, `payment_attempt.provider_reference`, `inventory_balance.sku_id+warehouse_id`.

---

# ۲۸. سرویس‌های خارجی موردنیاز

1. **SMS Provider** برای OTP و اعلان سفارش
2. **Payment Gateway** برای ایجاد پرداخت و Verify
3. **Object Storage/CDN** برای جلد، پیوست و فاکتور
4. **Shipping Provider** یا تعرفه داخلی برای ارسال و رهگیری
5. **Email Provider** در صورت ارسال فاکتور ایمیلی
6. **Observability** برای Log، Metric، Trace و Alert

هر سرویس پشت Adapter داخلی باشد تا تعویض Provider، API عمومی را تغییر ندهد.

---

# ۲۹. APIهایی که عمداً ساخته نمی‌شوند

- تغییر مستقیم قیمت Line از API عمومی
- تغییر آزادانه وضعیت Order از Frontend
- اعتماد به مبلغ ارسال‌شده از مرورگر
- دانلود عمومی اطلاعات مشتریان
- حذف فیزیکی Product دارای سفارش
- نمایش عمومی موجودی همه انبارها
- Verify پرداخت فقط با Query مرورگر
- Generic Admin CRUD بدون Permission و Audit

---

# ۳۰. تست‌های پذیرش حیاتی

## Auth

- OTP صحیح فقط یک بار مصرف شود.
- OTP منقضی و تلاش زیاد کد خطای پایدار دهند.
- Login سبد مهمان را بدون حذف اقلام Merge کند.

## Pricing و Cart

- مرزهای ۵، ۹، ۱۰، ۲۴، ۲۵، ۴۹ و ۵۰ Tier صحیح بگیرند.
- قیمت ارسال‌شده از Frontend نادیده گرفته شود.
- تعداد کمتر از حداقل Checkout را مسدود کند.
- دو سفارش همزمان Oversell ایجاد نکنند.
- تغییر قیمت، `PRICING_CHANGED` و Diff قابل نمایش بدهد.

## Checkout و Payment

- Quote منقضی قابل Order نباشد.
- Idempotency Key تکراری Order دوم نسازد.
- Callback تکراری دوبار موجودی کم نکند.
- Payment موفق با مبلغ متفاوت رد و Alert شود.
- Order Paid، Attempt جدید نپذیرد.
- Payment Pending کاربر را به پرداخت تکراری نبرد.

## Order و Admin

- Snapshot سفارش با تغییر Product ثابت بماند.
- Address و Invoice فقط برای مالک قابل مشاهده باشند.
- Order چند Shipment را پشتیبانی کند.
- Transition نامعتبر رد شود.
- Staff بدون Permission قیمت/موجودی را تغییر ندهد.
- Adjustment بدون Reason رد شود.
- عملیات حساس Before/After و Actor داشته باشند.

---

# ۳۱. ترتیب پیشنهادی پیاده‌سازی

## فاز صفر: زیرساخت

- Migration، Config، Secret و Error Envelope
- Request ID، Log، Metric و Trace
- RBAC، Audit، Outbox و Job Runner

## فاز یک: کاتالوگ

- Product، SKU، Series، Publisher و Upload جلد
- Search، Facet، Pricing Tier و Inventory Read
- Home Aggregate

## فاز دو: خرید

- Guest Cart و Merge
- OTP، Profile و Address
- Pricing Engine و Cart Validation
- Shipping، Checkout Quote، Order و Reservation

## فاز سه: پرداخت و عملیات

- Payment Adapter و Callback/Webhook
- Order Timeline، Shipment، Tracking و Invoice
- اعلان‌ها

## فاز چهار: پنل و پشتیبانی

- Catalog/Pricing/Inventory Admin
- عملیات سفارش و ارسال
- Ticket، Report، Export، Hardening و Load Test

---

# ۳۲. تصمیم‌های باقیمانده پیش از OpenAPI نهایی

1. واحد قطعی مبلغ: تومان یا ریال
2. درگاه پرداخت نسخه نخست
3. زمان رزرو موجودی سفارش پرداخت‌نشده
4. روش محاسبه ارسال و Provider رهگیری
5. انبار واحد یا چندانباره
6. حداقل سفارش پیش‌فرض و Override هر SKU
7. سیاست مالیات و فاکتور رسمی
8. شرایط لغو، مرجوعی و بازپرداخت
9. Provider پیامک و Templateها
10. Tech Stack، Database، Queue و Object Storage
11. دامنه‌های Production/Staging و Allowlist
12. Retention داده مالی و شخصی

---

# ۳۳. Definition of Done

- OpenAPI 3.1 همه Endpointهای MVP را پوشش دهد.
- Request/Response و Errorها Schema داشته باشند.
- Auth و Permission هر Endpoint مشخص باشد.
- Idempotency عملیات مالی تست شود.
- State Machine سفارش و پرداخت مستند باشد.
- Race Condition موجودی تست همزمانی داشته باشد.
- Migration و Seed نمونه Product/SKU/Tier وجود داشته باشد.
- Collection تست و محیط Staging آماده باشد.
- Webhook با Payload واقعی Sandbox درگاه تست شود.
- Audit عملیات حساس قابل جستجو باشد.
- Alert برای خطای پرداخت، Queue و Reservation ساخته شود.
- اطلاعات حساس در Log و Error نباشد.
- تست End-to-End از Cart تا Payment و Invoice موفق باشد.

---

# جمع‌بندی Surface ضروری MVP

```text
/auth/*
/bootstrap
/home
/products/*
/series/*
/search/*
/pricing/*
/availability/*
/cart/*
/me/*
/locations/*
/shipping/*
/checkout/*
/orders/*
/payments/*
/webhooks/payments/*
/shipments/*
/support/*
/uploads/*
/admin/*
```

اولویت معماری باید حفظ صحت **قیمت، موجودی و پرداخت** باشد. Snapshot سفارش، Idempotency، رزرو موجودی و Verify سمت سرور نباید به رفتار مرورگر وابسته باشند.
