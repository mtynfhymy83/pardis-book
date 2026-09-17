ALTER TABLE support_tickets
    ADD COLUMN IF NOT EXISTS assigned_to bigint REFERENCES users,
    ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
    ADD CONSTRAINT support_tickets_status_allowed CHECK (status IN ('new','in_review','waiting_for_customer','answered','closed')) NOT VALID;

ALTER TABLE support_messages
    ADD COLUMN IF NOT EXISTS is_staff boolean NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS order_notes (
    id bigserial PRIMARY KEY,
    public_id varchar(40) UNIQUE NOT NULL,
    order_id bigint NOT NULL REFERENCES orders ON DELETE CASCADE,
    actor_id bigint NOT NULL REFERENCES users,
    body text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS support_tickets_user_status_idx ON support_tickets(user_id, status, id DESC);
CREATE INDEX IF NOT EXISTS support_tickets_assignee_status_idx ON support_tickets(assigned_to, status, id DESC);
CREATE INDEX IF NOT EXISTS support_messages_ticket_idx ON support_messages(ticket_id, id);
CREATE INDEX IF NOT EXISTS order_notes_order_idx ON order_notes(order_id, id DESC);
