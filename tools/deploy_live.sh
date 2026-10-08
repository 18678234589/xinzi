#!/usr/bin/env bash
# 线上（新服务器 192.168.1.254:/www/wwwroot/hezuoshang）连接 / 拉取 / 滚动部署，Git Bash 下运行。
# 每个子命令只开 1 次 SSH 连接（短时间连接过多会被防暴力破解临时封 IP，表现为 Connection refused）。
#   bash tools/deploy_live.sh check                       校验连的是新服务器（hostname=ai、目录存在、PHP 版本）
#   bash tools/deploy_live.sh fetch includes/A.php ...    把线上文件拉到 $WORK/orig，并记录 md5 到 $WORK/manifest.txt
#   bash tools/deploy_live.sh push <目录> [--dry-run]     把 <目录> 下的 .php（相对路径 = 线上相对路径）滚动部署
#   bash tools/deploy_live.sh smoke                       在服务器上请求登录页 / 业务页并看错误日志
#   bash tools/deploy_live.sh rollback deploy_backup_<时间戳>
# push 在服务器上一次完成：校验主机 → 线上 md5 必须等于 fetch 时的（别人刚传过就中止）→ 备份 →
# 语法检查(php 7.4/8.1/8.3) → .new + mv 原子替换 → md5 核对 → smoke。config/ 不允许部署。
set -u
HOST=192.168.1.254
JUMP=58.58.98.150
JUMP_PORT=20622
ROOT=/www/wwwroot/hezuoshang
KEY="$HOME/.ssh/id_ed25519_tfdev"
WORK="${DEPLOY_WORK:-${TMPDIR:-/tmp}/live_deploy}"
PC="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes -p $JUMP_PORT -W %h:%p root@$JUMP"
SO=(-i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 -o "ProxyCommand=$PC")
die() { echo "ERROR: $*" >&2; exit 1; }

# 每个远程脚本的开头：不是新服务器就立刻退出（退出码 9）
GUARD='[ "$(hostname)" = ai ] && [ -f '$ROOT'/project/import.php ] || { echo "WRONG-HOST: $(hostname)，不是新服务器，已中止"; exit 9; }'

SMOKE='echo "-- smoke（登录页应 200，业务页应 302 -> /login.php，且无 PHP-ERROR）"
/www/server/php/74/bin/php -r '"'"'
$ctx=stream_context_create(["http"=>["header"=>"Host: me.laibangwo.com\r\n","ignore_errors"=>true,"follow_location"=>0,"timeout"=>25]]);
foreach(["login.php","project/index.php","project/import.php","project/order.php?id=1","project/settings.php"] as $p){ $b=@file_get_contents("http://127.0.0.1:80/".$p,false,$ctx); $c=isset($http_response_header[0])?$http_response_header[0]:"no response"; $loc=""; foreach(($http_response_header??[]) as $h) if(stripos($h,"Location:")===0) $loc=" -> ".trim(substr($h,9)); printf("%-26s %s%s%s\n",$p,$c,$loc,preg_match("/Fatal error|Parse error|Uncaught/i",(string)$b)?" PHP-ERROR":""); }'"'"'
echo "-- 最近错误日志里的 Fatal/Parse："; tail -n 80 /www/wwwlogs/me.laibangwo.com.error.log | grep -i "fatal\|parse error\|uncaught" | tail -3; true'

# 远程执行一段脚本（脚本作为命令参数，stdin 留给 tar）
remote() { local script="$1"; shift; ssh "${SO[@]}" "root@$HOST" "bash -c $(printf %q "$script")" "$@" 2> >(grep -v "WARNING\|vulnerable\|upgraded" >&2); }

cmd_check() {
  remote "$GUARD
echo \"新服务器 OK：\$(hostname) $ROOT\"; ls /www/server/php/ | tr '\n' ' '; echo; grep -m1 'include enable-php' /www/server/panel/vhost/nginx/me.laibangwo.com.conf; date" </dev/null
}

cmd_fetch() {
  [ $# -gt 0 ] || die "用法：fetch <相对路径>..."
  mkdir -p "$WORK/orig"
  remote "$GUARD
cd $ROOT && tar -cf - $* " </dev/null | tar -xf - -C "$WORK/orig" || die "拉取失败（若是 WRONG-HOST 或 Connection refused，见文件头说明）"
  touch "$WORK/manifest.txt"
  for f in "$@"; do
    [ -f "$WORK/orig/$f" ] || die "没拉到 $f"
    grep -v "^$f " "$WORK/manifest.txt" > "$WORK/manifest.tmp"; echo "$f $(md5sum < "$WORK/orig/$f" | cut -c1-32)" >> "$WORK/manifest.tmp"; mv "$WORK/manifest.tmp" "$WORK/manifest.txt"
    echo "fetched $f"
  done
  echo "工作目录：$WORK/orig（在这里打补丁；改好的文件放进另一个目录再 push）"
}

cmd_push() {
  local dir="${1:-}" dry=0; [ "${2:-}" = "--dry-run" ] && dry=1
  [ -d "$dir" ] || die "用法：push <目录> [--dry-run]"
  local files; files=$(cd "$dir" && find . -type f -name '*.php' | sed 's|^\./||' | sort)
  [ -n "$files" ] || die "目录里没有 .php 文件"
  echo "$files" | grep -q '^config/' && die "不允许部署 config/（两台服务器各自保留）"
  local expect="" f want
  for f in $files; do want=$(grep "^$f " "$WORK/manifest.txt" 2>/dev/null | cut -d' ' -f2); expect="$expect$f ${want:-NEW}"$'\n'; done
  local ts; ts=$(date +%Y%m%d_%H%M%S)
  local script="$GUARD
set -u; stage=/tmp/deploy_$ts; backup=/root/deploy_backup_$ts; dry=$dry
mkdir -p \$stage \$backup; tar -xf - -C \$stage || { echo 'upload failed'; exit 1; }
# 1) 线上文件必须等于 fetch 时的版本（NEW = 线上本来没有这个文件）
bad=0
while read -r f want; do [ -n \"\$f\" ] || continue
  if [ -f $ROOT/\$f ]; then live=\$(md5sum < $ROOT/\$f | cut -c1-32); [ \"\$want\" = NEW ] && { echo \"线上已存在但没 fetch 过（会覆盖别人的改动）: \$f\"; bad=1; } || { [ \"\$live\" = \"\$want\" ] || { echo \"线上被别人改过（fetch 后变了）: \$f\"; bad=1; }; }
  else [ \"\$want\" = NEW ] || { echo \"线上没有该文件但 manifest 里有: \$f\"; bad=1; }; fi
done <<'EXP'
$expect
EXP
[ \$bad = 0 ] || { rm -rf \$stage; echo '已中止，线上未改动：请重新 fetch 并合并'; exit 2; }
# 2) 语法检查（7.4 / 8.1 / 8.3）
for f in \$(cd \$stage && find . -type f -name '*.php' | sed 's|^\./||'); do for v in 74 81 83; do [ -x /www/server/php/\$v/bin/php ] && { /www/server/php/\$v/bin/php -l \$stage/\$f >/dev/null 2>&1 || { echo \"LINTFAIL php\$v \$f\"; bad=1; }; }; done; done
[ \$bad = 0 ] || { rm -rf \$stage; echo '语法检查未通过，已中止，线上未改动'; exit 3; }
echo \"语法检查通过；备份目录 \$backup\"
[ \$dry = 1 ] && { rm -rf \$stage; rmdir \$backup 2>/dev/null; echo 'dry-run 结束，线上未改动'; exit 0; }
# 3) 备份 + 原子替换 + 核对
for f in \$(cd \$stage && find . -type f -name '*.php' | sed 's|^\./||'); do
  mkdir -p \$backup/\$(dirname \$f); [ -f $ROOT/\$f ] && cp -p $ROOT/\$f \$backup/\$f
  chmod 644 \$stage/\$f; if [ -f $ROOT/\$f ]; then chown --reference=$ROOT/\$f \$stage/\$f; else chown --reference=$ROOT/project/import.php \$stage/\$f; fi
  cp -p \$stage/\$f $ROOT/\$f.new && mv $ROOT/\$f.new $ROOT/\$f
  [ \"\$(md5sum < $ROOT/\$f | cut -c1-32)\" = \"\$(md5sum < \$stage/\$f | cut -c1-32)\" ] && echo \"deployed \$f\" || echo \"MISMATCH \$f（请 rollback）\"
done
rm -rf \$stage
$SMOKE
echo \"回滚：bash tools/deploy_live.sh rollback deploy_backup_$ts\""
  tar -cf - -C "$dir" . | remote "$script"
}

cmd_smoke() { remote "$GUARD
$SMOKE" </dev/null; }

cmd_rollback() {
  local b="${1:-}"; [ -n "$b" ] || die "用法：rollback deploy_backup_<时间戳>"
  remote "$GUARD
cd /root/$b 2>/dev/null || { echo '没有这个备份目录'; exit 1; }
for f in \$(find . -type f | sed 's|^\./||'); do cp -p /root/$b/\$f $ROOT/\$f.new && mv $ROOT/\$f.new $ROOT/\$f && echo \"restored \$f\"; done
$SMOKE" </dev/null
}

case "${1:-}" in
  check) cmd_check;; fetch) shift; cmd_fetch "$@";; push) shift; cmd_push "$@";; smoke) cmd_smoke;; rollback) shift; cmd_rollback "$@";;
  *) sed -n '2,12p' "$0";;
esac
