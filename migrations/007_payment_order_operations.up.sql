ALTER TABLE payment_attempts
    ADD COLUMN IF NOT EXISTS expires_at timestamptz,
    ADD COLUMN IF NOT EXISTS verified_at timestamptz,
    ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();

CREATE TABLE IF NOT EXISTS payment_webhooks (
    id bigserial PRIMARY KEY,
    provider varchar(50) NOT NULL,
    event_key varchar(180) NOT NULL,
    payment_attempt_id bigint REFERENCES payment_attempts ON DELETE SET NULL,
    payload_redacted jsonb NOT NULL DEFAULT '{}'::jsonb,
    processed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(provider, event_key)
);

ALTER TABLE shipments
    ADD COLUMN IF NOT EXISTS version int NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();

CREATE INDEX IF NOT EXISTS payment_attempts_order_created_idx ON payment_attempts(order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS payment_attempts_reference_idx ON payment_attempts(provider, provider_reference);
CREATE INDEX IF NOT EXISTS payment_webhooks_attempt_idx ON payment_webhooks(payment_attempt_id, created_at DESC);
CREATE INDEX IF NOT EXISTS order_status_history_order_created_idx ON order_status_history(order_id, created_at ASC);
CREATE INDEX IF NOT EXISTS shipments_order_idx ON shipments(order_id, id);
