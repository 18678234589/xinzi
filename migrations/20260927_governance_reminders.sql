-- 管理层站内信：轮值董事长脑洞临近截止 / 被自动扣减、监委会 6 个工作日未提交监督、脑洞待评审时督促。
CREATE TABLE IF NOT EXISTS project_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT '',
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '',
  dedupe_key VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  UNIQUE KEY uk_message_dedupe (employee_id, dedupe_key),
  KEY idx_message_unread (employee_id, read_at),
  CONSTRAINT fk_message_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 法定节假日：当天不发督促；脑洞窗口内每个节假日把截止日顺延一天（自动扣减同口径）。由财务或监委会维护。
CREATE TABLE IF NOT EXISTS project_holidays (
  holiday_date DATE NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
