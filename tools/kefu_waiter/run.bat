@echo off
rem 开发调试运行（源码方式，窗口显示日志），正式使用请用打包后的 exe
cd /d %~dp0
python main.py
pause
