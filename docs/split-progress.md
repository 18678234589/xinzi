# 大文件拆分完成记录

P0–P6 已完成。仅搬运代码及校正加载路径，调用方继续使用原入口。未部署线上。

## 验证结果

- 16 个入口及全部分片的函数/方法清单、PHP token 和 HTML 对比通过。运行时 294 个全局函数、SalaryCalculator 的 40 个方法完全一致，包括可见性、static、引用和参数数量。
- PHP 8.5、PHP 7.4 语法及结构检查通过。不同 PHP 版本采用各自的 token 基线。
- 十个页面在相同隔离数据、账号和请求参数下输出一致：统一 CRLF/LF，并对齐未修改资源的 filemtime。
- 月度结果对比覆盖 16 组规则场景、三个账期、正常和预期两种上下文，共 150 行逐字段一致。规则搬运前后均通过。
- 61 项既有可执行测试：拆分前 36 通过、25 失败；拆分后相同，没有新增失败。原称 62 个 PHP 测试文件包含一个数据库安全辅助文件。新增月度对比测试单独运行。
- 测试数据库为本机独立实例 `127.0.0.1:13399/xinzi_split_test`。只读导出完整表结构、基础资料和抽样订单；每轮恢复同一快照，使用相同的本地 Excel 样表。没有向生产库写入测试数据。

## 结构约定

- 导入：`project/import/`；订单详情：`project/order/`；订单列表：`project/index/` 及同作用域 `includes/ProjectOrderList.php`。
- 通用函数：`includes/lib/`；计算模块：`includes/salary/`；结算：`includes/settlement/`；月度：`includes/monthly/`；治理：`includes/governance/`。
- 月度规则以同作用域 `includes/monthly/types/` 分片执行，保留唯一入口 `ps_monthly_results()`。循环跳转留在主函数，不新增全局策略函数，满足函数清单完全一致的要求。
- 页面分片在原作用域加载。静态 JS/CSS 搬到 assets，动态脚本留在视图分片；通过 include 保持原始浏览器输出和执行顺序。
- 入口级 CLI return 保留在入口。页面原有全局函数提前 require_once，保持函数在动作处理前可用。
- 原入口和所有 PHP/JS/CSS 分片必须同时发布。现有 deploy_live.sh 仅接收 PHP；发布这些资源时需使用覆盖 JS/CSS 的通道。本次未发布。

## 重跑命令

```powershell
php tools/gen_code_map.php
php tools/verify_split.php
C:/BtSoft/php/74/php.exe -d disable_functions= tools/verify_split.php
# DB_* 必须指向本地隔离库，不要直接用默认数据库连接运行测试。
$env:SPLIT_BASELINE_ROOT="<拆分前的独立检出目录>"
C:/BtSoft/php/74/php.exe -d disable_functions= tests/project_monthly_split_compare.php
```

`tools/split_manifest.json` 保存搬运基线和分片拓扑。可用 `verify_split.php --entry <原入口>` 做增量检查。后续功能提交不应为通过本次搬运校验而重置基线。

## 拆分前已存在的失败

这些失败与拆分后相同，未夹带业务修复。主要涉及现有规则引用不存在的 `SalaryCalculator::runModuleFor()`、样表/资源/账号前置条件和已有手机号校验行为。

| Entry | Before lines | After lines | After KB |
|---|---:|---:|---:|
| `project/import.php` | 1238 | 5 | 0.4 |
| `includes/ProjectIntake.php` | 839 | 11 | 0.8 |
| `project/order.php` | 879 | 9 | 0.5 |
| `project/index.php` | 860 | 90 | 7.6 |
| `includes/functions.php` | 2858 | 11 | 0.9 |
| `includes/SalaryCalculator.php` | 2233 | 351 | 15.4 |
| `includes/ProjectSettlement.php` | 1256 | 15 | 1.1 |
| `includes/ProjectMonthly.php` | 912 | 32 | 3.4 |
| `orders/index.php` | 2519 | 5 | 0.3 |
| `salaries/settle.php` | 1108 | 6 | 0.4 |
| `shops/upload.php` | 955 | 4 | 0.3 |
| `employees/algorithm.php` | 883 | 3 | 0.2 |
| `attendance/month.php` | 621 | 2 | 0.2 |
| `performance/index.php` | 533 | 2 | 0.2 |
| `performance/month.php` | 526 | 2 | 0.2 |
| `includes/ProjectGovernance.php` | 528 | 9 | 0.7 |

| Existing failing test | Before/after exit code |
|---|---:|
| `attendance_approval_smoke.php` | 1 |
| `bid_flow_202608.php` | 1 |
| `custom_site_cs_smoke.php` | 1 |
| `designer_split_import_smoke.php` | 1 |
| `governance_contributions_smoke.php` | 1 |
| `graphic_design_flow_smoke.php` | 1 |
| `import_followup_smoke.php` | 1 |
| `import_lenient_people_smoke.php` | 1 |
| `import_replay_fixes_smoke.php` | 1 |
| `project_algorithm_smoke.php` | 1 |
| `project_department_import_flow_smoke.php` | 255 |
| `project_finance_smoke.php` | 1 |
| `project_governance_cycle_smoke.php` | 255 |
| `project_intake_smoke.php` | 1 |
| `project_order_items_store_test.php` | 1 |
| `project_payroll_reconcile_202608.php` | 1 |
| `project_refund_import_smoke.php` | 255 |
| `project_renewals_unit.php` | 255 |
| `project_site_projects_storage.php` | 255 |
| `test_trademark_integration.php` | 255 |
| `trademark_cost_flow_smoke.php` | 1 |
| `trademark_department_smoke.php` | 1 |
| `trademark_upload_flow_202608.php` | 1 |
| `website_split_import_smoke.php` | 1 |
| `wechat_writing_smoke.php` | 1 |
