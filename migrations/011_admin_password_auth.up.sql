ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username varchar(100),
    ADD COLUMN IF NOT EXISTS password_hash varchar(255),
    ADD COLUMN IF NOT EXISTS last_login_at timestamptz;

CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique_idx
    ON users (lower(username))
    WHERE username IS NOT NULL;
