#!/usr/bin/env bash
# 在本地隔离测试库逐个跑 tests/*_smoke.php，每个测试前还原共享状态（见 tests/reset_scratch_state.php）。
# 用法：tools/run_scratch_suite.sh [结果文件]    先确保 13399 的 xinzi_test 库已就绪（scratch.ps1 start / 克隆线上只读数据）。
out="${1:-/tmp/suite_result.txt}"; : > "$out"
export DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test
cd "$(dirname "$0")/.." || exit 1
php tests/reset_scratch_state.php save || exit 1
for t in tests/*_smoke.php; do
  php tests/reset_scratch_state.php restore
  n=$(basename "$t" .php)
  log=$(timeout 120 php -d extension=zip "$t" 2>&1); rc=$?
  ok=$(printf '%s\n' "$log" | grep -c '^\s*\[OK\]')
  err=$(printf '%s\n' "$log" | grep -v '^\s*\[OK\]' | grep -E 'Fatal error|Uncaught|未通过|SQLSTATE|Error' | head -1 | tr -d '\r' | cut -c1-230)
  echo "$n rc=$rc ok=$ok $err" >> "$out"
done
echo "done" >> "$out"
echo "pass: $(grep -c 'rc=0 ' "$out")  total: $(grep -vc '^done' "$out")"
