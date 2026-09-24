-- AI 托底记录（系统日志）：系统规则处理不了时由 AI 给出方案，方案存档后同类问题直接复用、不再调用 AI。
CREATE TABLE IF NOT EXISTS project_ai_solutions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(40) NOT NULL,
  business_name VARCHAR(100) NOT NULL DEFAULT '',
  signature CHAR(40) NOT NULL,
  problem TEXT NOT NULL,
  solution_json TEXT NOT NULL,
  explanation VARCHAR(1000) NOT NULL DEFAULT '',
  source ENUM('ai','manual','error') NOT NULL DEFAULT 'ai',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  uses INT NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  created_by_type VARCHAR(20) NOT NULL DEFAULT '',
  created_by_id INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ai_solution (category, business_name, signature),
  KEY idx_ai_solution_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
