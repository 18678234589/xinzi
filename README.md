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
| 考勤管理 | 按月上传考勤表(xlsx)，支持多工作表自动识别、手动添加、批量删除 |
| 薪资结算 | 选员工+月份→自动汇总订单+考勤→预览→一键生成（支持覆盖） |
| 薪资查询 | 按部门/员工/月份筛选，支持导出Excel |

## 薪资计算公式

### 基础公式
```
实发工资 = 底薪（按出勤折算） + 模块合计 + 全勤奖净额 + 自定义额外金额
```

### 底薪按出勤折算（分母固定30）
| 请假天数 | 公式 | 示例（底薪¥3300，请假1天） |
|----------|------|---------------------------|
| 满勤(0天) | 底薪全额 | ¥3300 |
| ≤4天 | 底薪 − 底薪/30 × 请假天数 | 3300 − 3300/30×1 = **¥3190** |
| >4天 | 底薪/30 × 实际出勤天数 | 3300/30 × 实际出勤天数 |

- 实际出勤天数 = (应出勤小时 − 请假小时) / 8
- 无考勤记录：底薪按满勤全额发放
- 底薪来源：员工表 `base_salary` 字段 / 算法配置中的 `base_salary` 模块（自定义底薪）/ `base_salary_tiered` 模块（阶梯底薪，按订单总额匹配阶梯）

### 全勤奖（先加后扣）
| 请假小时 | 扣除规则 | 示例（满勤奖¥200） |
|----------|----------|---------------------|
| 0h | 全额发放 | +¥200 |
| <4h | 不扣 | +¥200 |
| ≥4h | 扣一半 | +¥200 − ¥100 = +¥100 |
| ≥8h | 全扣 | +¥200 − ¥200 = ¥0 |

- 无考勤记录：不发全勤奖
- 默认满勤金额 200 元，可在结算界面修改

### 自定义额外金额
手动调整金额，正数加、负数减，结算时累加到实发工资。

## 考勤管理

### 数据存储
考勤以**小时**为单位存储（8小时 = 1天）：
- `work_hours` = 应出勤小时数 = 满勤天数 × 8
- `absent_hours` = 请假小时数 = (满勤天数 − 实际出勤天数) × 8

### 上传格式
支持三种解析模式（自动识别）：

**① 天数列格式**（含"满勤天数/实际出勤天数"列）
| 员工姓名 | 满勤天数 | 实际出勤天数 |
|----------|----------|--------------|
| 张三 | 22 | 21 |

**② 自动每日打卡列格式**（含30天打卡明细）
| 姓名 | 1日 | 2日 | ... | 30日 |
自动统计节假日、出勤天数。

**③ 小时数格式**（直接读应出勤/请假小时）

### 多工作表支持
xlsx 文件含多个工作表时，系统自动扫描所有工作表，按表头列匹配选择含"满勤天数/实际出勤天数/请假"等文档记录格式列的汇总表，跳过原始打卡明细表。

## 数据库表结构

- **admins** - 管理员（系统登录）
- **employees** - 员工（id, name, department, base_salary, commission_rate, password）
- **orders** - 订单（id, employee_id, order_amount, order_date）
- **attendances** - 考勤（employee_id, year, month, work_hours, absent_hours, remark）
  - 运行时自动创建，唯一键 (employee_id, year, month)
- **salaries** - 薪资（employee_id, month, order_total, commission, net_pay, extra_amount, full_attendance_bonus, base_salary_amount）
  - `extra_amount` 自定义额外金额（运行时自动加列）
  - `full_attendance_bonus` 全勤奖净额（运行时自动加列）
  - `base_salary_amount` 折算后底薪（运行时自动加列）

## 自定义薪资算法

每位员工可配置独立的薪资算法（JSON 多模块组合），存放于 `algorithms/` 目录：
- `config_{员工id}.json` — JSON 配置
- `employee_{员工id}.php` — PHP 单文件算法（旧版兼容）

### 支持的模块类型
| 类型 | 说明 |
|------|------|
| `base_salary` | 自定义底薪（覆盖员工表底薪） |
| `base_salary_tiered` | 阶梯底薪（按订单总额匹配阶梯） |
| `standard` | 标准比例提成 |
| `tiered` | 阶梯比例提成 |
| `per_order` | 每笔奖励 |
| `attendance_full` | 全勤奖模块 |
| `attendance_daily` | 考勤日薪 |
| `attendance_deduct` | 缺勤扣款 |

## Excel上传格式

### 订单上传
文件第1列为订单金额，第2列为订单日期，支持表头自动识别：

| 订单金额 | 订单日期 |
|----------|----------|
| 12000.00 | 2026-06-05 |
| 8500.00 | 2026-06-12 |

### 考勤上传
支持 .xlsx 格式，含员工姓名、满勤天数、实际出勤天数（或每日打卡列）。

## 安全特性
- PDO预处理语句防SQL注入
- 密码MD5加密存储
- 文件类型校验（仅允许 .xlsx / .csv）
- Session会话管理登录验证

## 目录结构
```
caiwu.com/
├── config/database.php      数据库配置
├── includes/                公共组件(认证/函数/页头页脚/SalaryCalculator)
│   ├── functions.php        公共函数（get_attendance 等）
│   └── SalaryCalculator.php 薪资算法引擎
├── classes/SimpleXLSX.php   XLSX解析器（支持多工作表）
├── algorithms/              自定义薪资算法配置（JSON/PHP）
├── employees/               员工管理
├── orders/                  订单管理
├── attendance/              考勤管理（上传/手动添加/批量删除）
├── salaries/                薪资结算(settle.php)与查询(query.php)
├── assets/                  静态资源
├── database.sql             数据库SQL
├── login.php / logout.php   登录/登出
└── index.php                系统首页(仪表盘)
```
