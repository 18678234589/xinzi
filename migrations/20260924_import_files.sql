-- 合作人员 / 财务上传的原始订单表格：原文件保存在站点目录外，财务可在“原始表格”页查看、筛选、下载。
CREATE TABLE IF NOT EXISTS project_import_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  file_size INT NOT NULL DEFAULT 0,
  uploaded_by_type VARCHAR(20) NOT NULL,
  uploaded_by_id INT NOT NULL,
  employee_id INT NULL,
  status ENUM('preview','imported') NOT NULL DEFAULT 'preview',
  sheets_used VARCHAR(500) NOT NULL DEFAULT '',
  rows_total INT NOT NULL DEFAULT 0,
  imported_count INT NOT NULL DEFAULT 0,
  skipped_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at DATETIME NULL,
  KEY idx_import_files_business (business_name, created_at),
  KEY idx_import_files_employee (employee_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
