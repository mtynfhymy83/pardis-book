CREATE TABLE best_selling_products (
    id bigserial PRIMARY KEY,
    public_id varchar(40) UNIQUE NOT NULL,
    title varchar(250) NOT NULL,
    cover_url text NOT NULL,
    cover_alt varchar(250),
    price bigint NOT NULL,
    discounted_price bigint NOT NULL,
    discount_percent smallint NOT NULL,
    remaining_percent smallint NOT NULL,
    sort_order int NOT NULL DEFAULT 0,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT best_selling_price_positive CHECK (price > 0),
    CONSTRAINT best_selling_discounted_price_valid CHECK (discounted_price >= 0 AND discounted_price <= price),
    CONSTRAINT best_selling_discount_percent_valid CHECK (discount_percent BETWEEN 0 AND 100),
    CONSTRAINT best_selling_remaining_percent_valid CHECK (remaining_percent BETWEEN 0 AND 100),
    CONSTRAINT best_selling_sort_order_valid CHECK (sort_order >= 0)
);

CREATE INDEX best_selling_products_public_order_idx
    ON best_selling_products(sort_order, id)
    WHERE active = true;
