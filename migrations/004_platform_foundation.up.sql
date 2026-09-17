ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS last_used_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS device_id varchar(150),
    ADD COLUMN IF NOT EXISTS user_agent_hash char(64);

ALTER TABLE otp_challenges
    ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();

ALTER TABLE idempotency_keys
    ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS expires_at timestamptz NOT NULL DEFAULT (now() + interval '24 hours');

ALTER TABLE outbox_events
    ADD COLUMN IF NOT EXISTS locked_at timestamptz,
    ADD COLUMN IF NOT EXISTS dead_lettered_at timestamptz;

CREATE INDEX IF NOT EXISTS sessions_user_active_idx
    ON sessions(user_id, expires_at) WHERE revoked_at IS NULL;
CREATE INDEX IF NOT EXISTS otp_challenges_phone_created_idx
    ON otp_challenges(phone, created_at DESC);
CREATE INDEX IF NOT EXISTS idempotency_keys_expiry_idx
    ON idempotency_keys(expires_at);
CREATE INDEX IF NOT EXISTS audit_logs_subject_idx
    ON audit_logs(subject_type, subject_id, created_at DESC);
CREATE INDEX IF NOT EXISTS audit_logs_actor_idx
    ON audit_logs(actor_id, created_at DESC);
CREATE INDEX IF NOT EXISTS outbox_events_pending_idx
    ON outbox_events(available_at, id)
    WHERE processed_at IS NULL AND dead_lettered_at IS NULL;

INSERT INTO roles(name) VALUES
    ('customer'),
    ('support_agent'),
    ('catalog_manager'),
    ('inventory_manager'),
    ('order_operator'),
    ('finance_operator'),
    ('content_manager'),
    ('admin'),
    ('super_admin')
ON CONFLICT (name) DO NOTHING;

INSERT INTO permissions(name) VALUES
    ('catalog.read'),
    ('catalog.write'),
    ('pricing.write'),
    ('inventory.adjust'),
    ('orders.read'),
    ('orders.update_status'),
    ('payments.read'),
    ('payments.refund_request'),
    ('tickets.respond'),
    ('customers.read_limited'),
    ('reports.read'),
    ('staff.manage'),
    ('audit.read')
ON CONFLICT (name) DO NOTHING;

INSERT INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
    r.name = 'super_admin'
    OR r.name = 'admin'
    OR (r.name = 'support_agent' AND p.name IN ('orders.read', 'payments.read', 'tickets.respond', 'customers.read_limited'))
    OR (r.name = 'catalog_manager' AND p.name IN ('catalog.read', 'catalog.write'))
    OR (r.name = 'inventory_manager' AND p.name IN ('catalog.read', 'pricing.write', 'inventory.adjust'))
    OR (r.name = 'order_operator' AND p.name IN ('orders.read', 'orders.update_status', 'customers.read_limited'))
    OR (r.name = 'finance_operator' AND p.name IN ('orders.read', 'payments.read', 'payments.refund_request', 'reports.read'))
    OR (r.name = 'content_manager' AND p.name IN ('catalog.read', 'catalog.write'))
ON CONFLICT DO NOTHING;

INSERT INTO user_roles(user_id, role_id)
SELECT u.id, r.id
FROM users u
CROSS JOIN roles r
WHERE r.name = 'customer'
ON CONFLICT DO NOTHING;
