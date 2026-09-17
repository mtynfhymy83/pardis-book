ALTER TABLE skus
    ADD COLUMN IF NOT EXISTS is_default boolean NOT NULL DEFAULT false;

WITH ranked AS (
    SELECT id, row_number() OVER (PARTITION BY product_id ORDER BY id) AS position
    FROM skus
    WHERE status <> 'archived'
)
UPDATE skus
SET is_default = true
FROM ranked
WHERE skus.id = ranked.id
  AND ranked.position = 1
  AND NOT EXISTS (
      SELECT 1 FROM skus current_default
      WHERE current_default.product_id = skus.product_id
        AND current_default.is_default = true
  );

CREATE UNIQUE INDEX IF NOT EXISTS skus_one_default_per_product
    ON skus(product_id) WHERE is_default = true;
CREATE INDEX IF NOT EXISTS products_published_created_idx
    ON products(created_at DESC, id DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS products_series_published_idx
    ON products(series_id, created_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS products_category_published_idx
    ON products(category_id, created_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS products_publisher_published_idx
    ON products(publisher_id, created_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS products_title_trgm_idx
    ON products USING gin(title gin_trgm_ops) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS series_name_trgm_idx
    ON series USING gin(name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS publishers_name_trgm_idx
    ON publishers USING gin(name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS skus_product_active_idx
    ON skus(product_id, is_default DESC, id) WHERE status <> 'archived';
CREATE INDEX IF NOT EXISTS skus_attributes_gin_idx
    ON skus USING gin(attributes jsonb_path_ops) WHERE status <> 'archived';
CREATE INDEX IF NOT EXISTS skus_code_trgm_idx
    ON skus USING gin(code gin_trgm_ops);
CREATE INDEX IF NOT EXISTS skus_isbn_idx
    ON skus(isbn) WHERE isbn IS NOT NULL;
CREATE INDEX IF NOT EXISTS pricing_tiers_current_lookup_idx
    ON pricing_tiers(sku_id, min_quantity, max_quantity, effective_from, effective_to);
CREATE INDEX IF NOT EXISTS inventory_balances_sku_idx
    ON inventory_balances(sku_id);
CREATE INDEX IF NOT EXISTS order_lines_sku_order_idx
    ON order_lines(sku_id, order_id);
CREATE INDEX IF NOT EXISTS orders_paid_idx
    ON orders(id) WHERE payment_status = 'paid' AND status NOT IN ('cancelled', 'refunded');

INSERT INTO content_pages(public_id,slug,title,body,published) VALUES
    ('page_wholesale_guide','wholesale-guide','راهنمای خرید عمده','برای خرید عمده، کتاب و تعداد را انتخاب کنید؛ قیمت پلکانی به‌صورت خودکار محاسبه می‌شود و پس از انتخاب نشانی و روش ارسال می‌توانید آنلاین پرداخت کنید.',true),
    ('page_about','about','درباره کتابسرای پردیس','کتابسرای پردیس تأمین‌کننده تخصصی کتاب‌های آموزش زبان برای آموزشگاه‌ها، مدارس، کتاب‌فروشی‌ها و مدرسین است.',true),
    ('page_contact','contact','تماس با ما','برای راهنمایی خرید و پیگیری سفارش از راه‌های ارتباطی درج‌شده در وب‌سایت استفاده کنید.',true)
ON CONFLICT (slug) DO NOTHING;
