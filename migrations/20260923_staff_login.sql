-- 合作人员登录：首次登录绑定手机号（之后可用手机号登录），记录是否已修改默认密码。
ALTER TABLE project_users ADD COLUMN phone VARCHAR(20) NULL AFTER username;
ALTER TABLE project_users ADD UNIQUE KEY uk_project_user_phone (phone);
ALTER TABLE project_users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
