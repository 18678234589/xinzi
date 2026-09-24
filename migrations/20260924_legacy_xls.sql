-- 原件仍留档；旧版 XLS 在浏览器转换成 XLSX 后只将转换件用于解析。
ALTER TABLE project_import_files ADD COLUMN parse_name VARCHAR(100) NULL AFTER stored_name;
