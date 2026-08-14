@echo off
rem ============================================================
rem  安装【客服绩效采集文件】定时自动导入任务
rem  每 30 分钟扫描一次 storage\cs_perf_uploads 目录并自动导入
rem  请在服务器上以管理员身份运行本脚本
rem ============================================================
setlocal

rem 修改这里的 PHP 路径为服务器上 php.exe 的实际位置
set PHP=C:\php\php.exe
if not exist "%PHP%" set PHP=php

set SCRIPT=%~dp0cs_perf_sync.php

echo 正在创建计划任务 cs_perf_sync ...
schtasks /Create /F /TN "cs_perf_sync" /SC MINUTE /MO 30 /TR "\"%PHP%\" \"%SCRIPT%\""

if %errorlevel%==0 (
    echo 计划任务创建成功：每30分钟执行一次 %SCRIPT%
) else (
    echo 计划任务创建失败，请检查权限与 PHP 路径后重试
)

endlocal
