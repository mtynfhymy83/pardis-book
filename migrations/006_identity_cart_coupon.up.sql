CREATE TABLE business_profiles (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL UNIQUE REFERENCES users ON DELETE CASCADE,
    data jsonb NOT NULL DEFAULT '{}'::jsonb,
    version int NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE coupon_redemptions (
    id bigserial PRIMARY KEY,
    public_id varchar(40) UNIQUE NOT NULL,
    coupon_id bigint NOT NULL REFERENCES coupons,
    user_id bigint NOT NULL REFERENCES users,
    order_id bigint UNIQUE REFERENCES orders,
    discount_amount bigint NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE carts ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
ALTER TABLE carts ADD CONSTRAINT carts_status_allowed CHECK (status IN ('active', 'merged', 'converted', 'abandoned')) NOT VALID;

CREATE UNIQUE INDEX IF NOT EXISTS carts_one_active_user_idx
    ON carts(user_id) WHERE status = 'active' AND user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS carts_guest_active_idx
    ON carts(guest_token_hash) WHERE status = 'active' AND guest_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS cart_items_cart_idx ON cart_items(cart_id, id);
CREATE INDEX IF NOT EXISTS addresses_user_active_idx
    ON addresses(user_id, is_default DESC, id DESC) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS addresses_one_default_per_user_idx
    ON addresses(user_id) WHERE is_default = true AND deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS coupon_redemptions_coupon_user_idx ON coupon_redemptions(coupon_id, user_id);
CREATE INDEX IF NOT EXISTS coupons_active_code_idx ON coupons(code) WHERE active = true;
