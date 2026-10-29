# 七牛 CDN 管理

入口：页头「CDN 管理」或域名管理页「七牛 CDN 管理」，页面 `project/cdn.php`。访问权限复用域名管理：网站技术、网站客服、网站售后等有域名权限的账号可以使用，普通账号看不到 AK/SK。

添加流程：填写完整加速域名 → 选择业务、加速区域、源站、检测路径、缓存和协议 → 预览 → 输入加速域名并确认 → 提交给七牛。中国大陆和全球区域要求填写真实 ICP 备案号；海外区域不擅自代替用户选择。源站支持 IPv4、另一个源站域名、已有七牛存储空间。回源域名不能等于加速域名，避免 CNAME 接入后循环；检测路径必须真实存在，七牛会检查可访问性。

缓存默认遵循源站，保留查询参数；仅纯静态内容可以选择全局一小时缓存。HTTPS 选择账号已有证书，提交前只读取证书目录中的名称、覆盖域名、有效期，再次校验证书有效且覆盖当前域名，不读取或展示证书私钥。

添加使用服务端配置预览和一次性票据，绑定发起账号，10 分钟有效；输入域名和勾选确认后才发送请求。提交前查询是否已存在，只有明确的 `404/404001` 才继续创建。请求不会自动重试；超时或异常需先查询，不能把 HTTP 200 或“已接受”当作已生效。请求和已接受结果记现有审计日志，未增加数据库表。

创建后查看配置状态和 CNAME。七牛配置成功与 DNS 是否已指向 CDN 分开显示；CNAME 可以复制，也可点「在本站配置 DNSPod / Cloudflare」预填主机记录和值。根据实际账号区域目录匹配最长后缀，不猜测主域名（支持多级子域、com.cn 等）；所选账号没有对应区域时提示先添加所属主域名。跳转和预填不写 DNS，保存仍走现有权限、CSRF 和用户核对，已有 A/AAAA/CNAME 不会被自动覆盖；Cloudflare 使用“仅 DNS”。

凭据保存在「接口设置」的七牛条目，AK 和 SK 均用保险库密钥加密。域名接口走 `api.qiniu.com` 的 Qiniu 鉴权，证书目录走 `fusion.qiniuapi.com` 的 QBox 鉴权，复用已验证的签名封装。业务页面不输出密钥或原始证书内容。

颜色修复：全站普通链接主题覆盖了自定义按钮字体，导致实心绿按钮出现绿字。域名、解析和 CDN 页面已局部指定白色实心按钮文字、深色浅底按钮文字及危险操作颜色，并更新样式版本防止旧缓存。

验证：`php tests/qiniu_cdn.php`（46 项，包括参数、备案、缓存、回源循环、证书覆盖/过期、API 逻辑错误、空响应不误判成功、重复添加、超时不重试、一次性票据、账号区域匹配和凭据隔离）；`php tests/dns_management.php`（53 项）；现有域名/DNS/注册前端测试。真实七牛连接只读验证及加密保存已完成，没有为测试创建真实 CDN 域名或修改客户解析。

官方依据：

- [域名管理](https://developer.qiniu.com/fusion/13363/fusion-api-domain-management)
- [域名配置](https://developer.qiniu.com/fusion/13364/fusion-api-domain-config)
- [鉴权指南](https://developer.qiniu.com/fusion/13360/fusion-api-auth-guide)
- [证书管理](https://developer.qiniu.com/fusion/13368/fusion-api-cert-management)

## 流量（2026-10-28）

七牛 Fusion 用量接口 `POST /v2/tune/flux`、`/v2/tune/bandwidth`（QBox 鉴权，按天，一次最多 31 天、多域名用 `;` 分隔）可用现有 AK/SK 读取。`jobs/qiniu_cdn_traffic_sync.php` 每 30 分钟（root crontab，`flock` + `GET_LOCK`，日志 `/root/hezuoshang_qiniu_traffic_job.log`，改前备份 `/root/crontab.before-qiniu-traffic-20261028`）拉取最近 3 天所有域名的流量（字节，各区域相加）和峰值带宽（bit/s）写入 `qiniu_cdn_traffic`（`migrations/20261028_qiniu_cdn_traffic.sql`，线上需手动执行；首次补历史用 `--days=31`）。CDN 管理页只读该表：列表新增「近 7 天流量 / 今日」两列和「流量概览」（近 7 / 30 天合计、前 5 名），详情页显示近 30 天柱状图和近 14 天明细。今日流量 ≥ 100MB 且 ≥ 前 7 天日均 5 倍时标「异常增长」。七牛统计有延迟，当天数字偏小，计费以七牛账单为准。离线测试：`php -d extension=pdo_sqlite tests/qiniu_cdn_traffic.php`。
