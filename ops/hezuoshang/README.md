# me.laibangwo.com 迁移运行说明

应用已迁至 `vps4`：SSH `root@58.58.97.174:5024`，目录 `/myprograms/hezuoshang`。运行配置在 `/myprograms/hezuoshang-runtime`，主数据库为 1Panel 管理的 `1Panel-mysql-migrated` 容器内 `xinzi`，沿用原库名和应用账号。

公网 HTTPS 在主控的 `jump-host` 中终结：`/etc/nginx/sites-enabled/me.laibangwo.com` 转发至 `10.99.0.4:80`。证书到期日为 2027-01-06，已接入现有 `certbot.timer`，实际自动续签演练通过；HTTP-01 验证目录 `/var/www/acme`，续签后重载 Nginx。用户自行把域名 A 记录改为 `58.58.97.174`。有 AAAA 记录时也须指向这套入口的可用 IPv6，不能保留旧站 IPv6。

旧 `ai:/www/wwwroot/hezuoshang` 保留备份；原 Nginx 入口已转发到新公网 HTTPS，DNS 尚未改变时也使用新后台。原机 5 条项目任务已停用，新机任务已启用；两机系统时区均为 UTC，PHP 时区保持中国时间。此项目的部署目标不再是 `192.168.1.254`。

## 运行服务

- `hezuoshang-nginx`：只绑定 `10.99.0.4:80`，应用文件只读；配置、私有上传、工具和隐藏文件拒绝公网读取。
- `hezuoshang-php`：PHP 7.4，连接 `1panel-network`；导入和图片所需扩展已安装。
- `hezuoshang-php:8.3`：独立 CLI 镜像供订单同步及自动审核任务使用。
- `laibangwo-workbench.service`：保留 `/studio/` 工作台，站外 SSO 配置和密钥在 `/etc/laibangwo-workbench`，队列和用户数据在 `/var/lib/laibangwo-workbench`。
- `hezuoshang-etmll-bridge.service`：上游 `jujian` 仍在旧机更新，使用专用受限 SSH 密钥读取，目标 `172.17.0.1:13307`。项目主库使用新机 `xinzi`。新机同时保留 `jujian` 快照；上游确认迁移后再修改此连接并停用隧道。

工作台菜单仍指向现有 `media.laibangwo.com`，该域名未变更；`/studio/` 在新机独立可用，SSO 共用原接入密钥。

## 验证与备份

最后停写快照的 104 张主库表逐表计数一致：项目订单 4,696，老订单 114,826，导入记录 107，审计记录 40,068。恢复服务后的正常任务会继续增加业务数据。

1,044 个原文件哈希一致，只有数据库地址和 `open_basedir` 配置按新目录调整；所有应用 PHP 语法检查通过。私有上传中以 `.php` 包装存放的 Excel/图片属于数据，未作为可执行源码检查，外部访问返回 403。新 IP 的真实 TLS/SNI、登录页 200、业务页 302、验证码、工作台 200，以及 SSO 授权/交换/重放拒绝均已验证。

两机迁移备份位于 `/root/hezuoshang-migration-20261008`；最终快照、逐表计数、文件清单和目标导入前备份在 `cutover/`。源站原 vhost/任务在 `cutover/vhost.before.conf` 和 `cutover/cron.before.txt`。已接受新业务写入后，不能只恢复旧 vhost 回滚：必须先停写并反向同步新数据。

## 后续部署

本机使用已有 `~/.ssh/id_ed25519_tfdev`，新机已授权公钥并固定 SSH 主机密钥；Python 依赖 `paramiko`。业务配置与密钥不放入 Git。

```powershell
python tools/deploy_hezuoshang.py check
python tools/deploy_hezuoshang.py fetch project/import.php --out C:/Temp/hezuoshang-baseline
# 把核对过的改动放入单独目录，不能直接推送整个仓库。
python tools/deploy_hezuoshang.py push C:/Temp/hezuoshang-changes --manifest C:/Temp/hezuoshang-baseline/manifest.json --dry-run
python tools/deploy_hezuoshang.py push C:/Temp/hezuoshang-changes --manifest C:/Temp/hezuoshang-baseline/manifest.json
python tools/deploy_hezuoshang.py smoke
python tools/deploy_hezuoshang.py rollback <发布备份名称>
```

部署会校验主机、完整基线哈希、PHP 7.4 语法和上传哈希，再备份并替换；并行修改会中止。原 `tools/deploy_live.sh` 转调新工具，`push` 必须显式传入拉取清单。工具仅接受应用 PHP/JS/CSS，不接受配置、数据库、私有上传或测试目录。
