CREATE TABLE product_media (
    id bigserial PRIMARY KEY,
    public_id varchar(40) UNIQUE NOT NULL,
    product_id bigint NOT NULL REFERENCES products ON DELETE CASCADE,
    url text NOT NULL,
    alt varchar(250),
    is_primary boolean NOT NULL DEFAULT false,
    sort_order int NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX product_media_product_sort
    ON product_media(product_id, is_primary DESC, sort_order, id);

CREATE UNIQUE INDEX product_media_one_primary_per_product
    ON product_media(product_id)
    WHERE is_primary = true;

INSERT INTO settings(key, value)
VALUES (
    'home',
    '{
      "hero": {
        "eyebrow": "انتخاب حرفه‌ای آموزشگاه‌ها و کتاب‌فروشی‌ها",
        "title": "کتاب‌های آموزش زبان، با قیمت عمده شفاف",
        "description": "مجموعه‌های اصلی و پرفروش آموزش زبان را با موجودی به‌روز، تخفیف پلکانی و ارسال سریع تهیه کنید.",
        "primaryAction": {"label": "مشاهده مجموعه‌ها", "href": "/series"},
        "secondaryAction": {"label": "راهنمای خرید عمده", "href": "/content/pages/wholesale-guide"},
        "stats": [
          {"value": "+۵۰۰", "label": "عنوان موجود"},
          {"value": "+۱۲۰۰", "label": "مشتری عمده"},
          {"value": "۲۴ ساعت", "label": "زمان آماده‌سازی"}
        ]
      },
      "benefits": [
        {"title": "ارسال سریع", "description": "به سراسر ایران"},
        {"title": "خرید عمده آسان", "description": "بدون فرایند پیچیده"},
        {"title": "پرداخت آنلاین امن", "description": "از درگاه معتبر"},
        {"title": "پشتیبانی واقعی", "description": "پیش و پس از خرید"}
      ],
      "wholesaleSteps": [
        {"step": 1, "title": "کتاب‌ها را انتخاب کنید", "description": "عنوان و تعداد مورد نیازتان را به سبد اضافه کنید."},
        {"step": 2, "title": "تخفیف را ببینید", "description": "قیمت پلکانی به‌صورت خودکار محاسبه می‌شود."},
        {"step": 3, "title": "سفارش را تحویل بگیرید", "description": "پرداخت آنلاین و ارسال سریع به نشانی شما."}
      ],
      "contact": {
        "title": "برای خرید سازمانی نیاز به مشاوره دارید؟",
        "description": "کارشناسان پردیس برای انتخاب کتاب و ثبت سفارش کنار شما هستند.",
        "phone": "02191000000"
      }
    }'::jsonb
)
ON CONFLICT (key) DO NOTHING;
