-- 独立服务器可见名单。NULL 继承现有栏目名单，非 NULL 必须逐条校验。
-- 发布时先部署兼容代码，再添加此列；禁止在权限设置前写入受限凭据。
ALTER TABLE project_vault_items ADD COLUMN access_json TEXT NULL;
