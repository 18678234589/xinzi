# 服务器管理（雅云上游）

入口：页头「服务器管理」→ `project/servers.php`。参考 `C:\mydev\ZJMF-CBAP`（智简魔方）对雅云（`type=finance`）的接口用法，凭据从那里的供应商表复制到本站「接口设置 › 雅云」（密码加密保存，页面不显示）。

## 页面
- **服务器目录**：378 个套餐，按国家 / 地区（🇨🇳🇭🇰🇺🇸🇯🇵…）× 品类（云服务器、轻量云、物理机、裸金属、住宅 / 双 ISP、高防 CDN、云电脑、活动专区）× 机房分区浏览，搜索、只看有货、价格 / 库存。国家与品类由栏目、子分类、商品名关键词推断（上游没有这两个字段），推断不到归「其他」。
- **我的服务器**：雅云账号下全部主机（含已删除），状态、IP、到期（<15 天橙、<7 天红）、续费价；详情抽屉：开机 / 关机 / 重启 / 状态 / VNC / 监控图；可开通的人另有：登录账号密码（点击才显示）、续费、重装、重置密码、救援、强制断电 / 重启、申请停用。
- **开通记录**、**开通权限**（仅超级管理员维护名单）。

## 权限（`includes/YayunAccess.php`）
- 查看 + 日常管理：有域名管理权限的人（网站 / 小程序的客服、技术、售后等）+ 可开通名单。
- 开通 / 续费 / 重装 / 看密码 / 停用：超级管理员 + 可开通名单（系统设置 `yayun_provisioners`）。2026-11-01 名单：于洋、孙杰、栾鑫。治理角色（栾鑫）的页面白名单 `includes/settlement/actor_auth.php` 已加入 `servers.php`。

## 接口（`includes/YayunApi.php`，登录 `zjmf_api_login`，jwt 缓存、401 / 405 自动重登）
`host/list`、`host/header`、`provision/default`（on / off / reboot / hard_* / crack_pass / reinstall / vnc / status / rescue_system）、`provision/custom/<id>`、`provision/chart/<id>`、`host/renewpage`、`host/renew` + `apply_credit`、`host/cancel`、`cart/get_product_config`（配置项与周期价）、`cart/get_total`（报价）、`cart/add_to_shop` + `cart/settle` + `apply_credit`（购买）。目录快照 `includes/YayunCatalogData.php` 来自 ZJMF 的 `config/yayun_catalog.json`（2026-09-30 核对，378 个商品）；价格 / 库存由 `jobs/yayun_sync.php` 每 6 小时同步（约 4–5 分钟）到 `yayun_prices`（`migrations/20261101_yayun_servers.sql`，线上已执行），页面只读缓存。crontab 备份 `/root/crontab.before-yayun-20261101`，日志 `/root/hezuoshang_yayun_job.log`。

## 开通流程与保护（`includes/YayunOrder.php`）
选周期 / 配置 → 报价（写 `yayun_orders=quoted`，10 分钟有效）→ 勾选确认 + 输入商品编号 → 弹窗再确认 → 提交。提交前再核价；用 `UPDATE … WHERE status='quoted'` 原子占位，同一报价只会购买一次；账单金额与报价不一致或一个订单里不是恰好一台主机时**不支付**；单笔上限 ¥3000；任何异常状态保留为 `unknown` 供人工核对，绝不自动重试。
**尚未真实购买过**：`cart/settle` 的返回字段（`data.hostid / invoiceid / total`）按 ZJMF 代码推断；首次请由管理员用便宜套餐实测，并核对「开通记录」。探测时我对商品 1013 做过一次 `cart/add_to_shop`（没有结算），上游购物车里可能留有这一件，首次真实购买若混入它，账单金额与报价不符会被拦下不支付，需到雅云后台清掉。
离线测试：`php -d extension=pdo_sqlite tests/yayun_servers.php`（47 项）。
