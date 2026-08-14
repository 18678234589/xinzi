@echo off
rem ============================================================
rem  打包 客服绩效采集工具 为单文件 exe（需已装 Python 3.8+）
rem  首次准备：
rem    pip install -r requirements.txt
rem    pip install pyinstaller
rem 点击运行本脚本即可，生成 dist\客服绩效采集工具.exe
rem ============================================================
cd /d %~dp0

where python >nul 2>nul
if errorlevel 1 (
    echo [错误] 未找到 python，请先安装 Python 3.8+ 并勾选 Adds to PATH
    pause
    exit /b 1
)

python -m PyInstaller --onefile --noconsole --name 客服绩效采集工具 --clean main.py

if errorlevel 1 (
    echo [错误] 打包失败，请确认已 pip install -r requirements.txt 和 pyinstaller
    pause
    exit /b 1
)

echo.
echo [完成] 已生成 dist\客服绩效采集工具.exe
echo 请把 dist\客服绩效采集工具.exe 和 config.ini 一起发给客服使用
pause
