# 财务薪资结算系统

## 环境要求
- PHP 7.4+（需启用 PDO MySQL、ZipArchive 扩展）
- MySQL 8.0
- Apache / Nginx

## 安装步骤

### 1. 导入数据库
使用 phpMyAdmin 导入 `database.sql` 文件，将自动创建 `caiwu` 数据库及全部表结构。

### 2. 配置数据库连接
编辑 `config/database.php`，修改数据库连接参数：
```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'caiwu');
define('DB_USER', 'root');
define('DB_PASS', 'root');  // 改为你的实际密码
```

### 3. 访问系统
浏览器访问系统根目录，使用默认管理员账号登录：
- 用户名：`admin`
- 密码：`admin123`

## 功能模块

| 模块 | 说明 |
|------|------|
| 员工管理 | 员工增删改查，密码MD5加密存储 |
| 订单上传 | 选择部门→员工→上传Excel(xlsx/csv)或手动添加 |
| 薪资结算 | 选员工+月份→自动汇总→一键生成（支持覆盖） |
| 薪资查询 | 按部门/员工/月份筛选，支持导出Excel |

## 薪资计算公式
```
实发工资 = 基本工资 + (当月订单总额 × 提成比例)
```

## 数据库表结构

- **admins** - 管理员（系统登录）
- **employees** - 员工（id, name, department, base_salary, commission_rate, password）
- **orders** - 订单（id, employee_id, order_amount, order_date）
- **salaries** - 薪资（id, employee_id, month, order_total, commission, net_pay, created_at）

## Excel上传格式
文件第1列为订单金额，第2列为订单日期，支持表头自动识别：

| 订单金额 | 订单日期 |
|----------|----------|
| 12000.00 | 2026-06-05 |
| 8500.00  | 2026-06-12 |

## 安全特性
- PDO预处理语句防SQL注入
- 密码MD5加密存储
- 文件类型校验（仅允许 .xlsx / .csv）
- Session会话管理登录验证

## 目录结构
```
caiwu.com/
├── config/database.php      数据库配置
├── includes/                公共组件(认证/函数/页头页脚)
├── classes/SimpleXLSX.php   XLSX解析器
├── employees/               员工管理
├── orders/                  订单管理
├── salaries/                薪资结算与查询
├── assets/                  静态资源
├── database.sql             数据库SQL
├── login.php / logout.php   登录/登出
└── index.php                系统首页(仪表盘)
```
