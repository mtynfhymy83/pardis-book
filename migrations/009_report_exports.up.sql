CREATE TABLE report_exports (
    id bigserial PRIMARY KEY,
    public_id varchar(40) UNIQUE NOT NULL,
    requested_by bigint NOT NULL REFERENCES users,
    report_type varchar(60) NOT NULL,
    filters jsonb NOT NULL DEFAULT '{}'::jsonb,
    status varchar(20) NOT NULL DEFAULT 'queued',
    storage_key text,
    error_code varchar(80),
    created_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz,
    CHECK (status IN ('queued','processing','completed','failed'))
);
CREATE INDEX report_exports_requester_created_idx ON report_exports(requested_by, id DESC);
CREATE INDEX report_exports_pending_idx ON report_exports(id) WHERE status='queued';
