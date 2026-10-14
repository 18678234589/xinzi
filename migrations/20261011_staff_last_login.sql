-- Applied once by guarded account maintenance; no password fallback is introduced.
ALTER TABLE project_users ADD COLUMN last_login_at DATETIME NULL;
