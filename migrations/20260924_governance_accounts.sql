-- 管理层专属登录角色，不附带项目订单录入权限。
ALTER TABLE project_users MODIFY COLUMN role ENUM('technical','customer_service','governance') NOT NULL;
