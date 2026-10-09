# 仓库协作规则

## 先定位再读取

- 先搜索 `docs/code-map.md` 中的函数、类方法或入口，再按起止行号读取需要的代码。
- 更新源码结构后运行 `php tools/gen_code_map.php`。门面文件只加载依赖和分片，调用方继续使用原入口。
- 项目结算及导入：`project/`、`includes/Project*.php`；老订单及报酬：`orders/`、`salaries/`、`includes/SalaryCalculator.php`。
- 通用函数：`includes/functions.php`；回归：`tests/`；运维和结构验证：`tools/`。

## 红线

- 生产 PHP 7.4，不引入命名空间、Composer 或自动加载，不使用 PHP 8 专属语法。
- 测试写入仅允许本地隔离数据库（库名以 `_test`、`_ci` 或 `_sandbox` 结尾），不能通过默认连接运行写入测试。
- 当前生产：`vps4` / `58.58.97.174:5024`，目录 `/myprograms/hezuoshang`；PHP 在 `hezuoshang-php` 容器运行。旧 `ai` 入口只转发，不能再向旧目录部署。
- 部署使用 `python tools/deploy_hezuoshang.py`；先 `fetch` 保存哈希基线，再 `push --manifest <清单> --dry-run`，验证后正式推送。见 `ops/hezuoshang/README.md`。
- `系统连接方式.local` 是本地连接说明；部署不上传 `config/`、本地配置、测试或临时文件。
- 结构拆分只搬运原代码，功能修改另行处理。原入口和所有依赖分片必须一起部署。
- 页面分片在原作用域加载；不能把入口级 `return` 移进分片。`continue` / `break` 及控制结构必须留在所属循环中。
- 搬运须校正 `__DIR__` 的基准，保留加载顺序、函数修饰符、引用参数及静态变量。
- 同时只拆一个大文件。先在下表登记；其他会话应避开正在拆分的入口及其分片。
- 验证：`php tools/verify_split.php`、相关测试、同数据渲染对比及 PHP 7.4 语法检查。
- `tools/split_manifest.json` 是本次搬运基线；后续功能修改应独立提交，不要为让检查通过而重置基线。

## 对外用语（内部规则，勿写进任何用户可见页面）

- 用户可见文字不得出现劳动管理术语：考勤→服务时长确认（考勤表 / 考勤记录→服务时长确认单）、出勤→到岗确认、缺勤→未履约、打卡→签到确认、值班→排班时段、工时→服务时长、请假→服务暂停、调休→时段调整、加班→延时服务、加班费→超时补贴。
- 页面输出由 `includes/header.php` 顶部的 `pt_start()` 统一替换（`includes/ProjectTerms.php`）；新增导出、模板、下载文件、JSON 和脚本提示语（不经过页面替换）要直接写对外用语。上传表头识别新旧叫法都认。
- 老词只允许出现在 PHP 注释、数据库字段名和表头识别里；完整对照见 `docs/terminology.md`（不部署）。

## 拆分登记

| 会话 | 文件 | 状态 |
|---|---|---|
| 大文件拆分 | P0–P6 全部 16 个入口 | 已完成，锁已释放；见 `docs/split-progress.md` |
| 在线表格编辑 | includes/ProjectSheetEdit.php、project/files.php、project/import/ 与 assets/*/sheet_editor.* | 已上线并验证，锁已释放；见 docs/table-editing.md。共享文件请保留海外微信会话的独立改动 |
