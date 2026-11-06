-- 免登录令牌记录“登录时的密码是否达标”，恢复会话时据此设置 vault_pw_ok（否则隔天自动登录后进不了“平台与服务器信息”）。
ALTER TABLE auth_remember_tokens ADD COLUMN pw_strong TINYINT(1) NOT NULL DEFAULT 0;
