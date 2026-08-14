# -*- coding: utf-8 -*-
"""客服绩效采集小工具 - 启动入口

客服在自己的电脑上运行本工具（或打包后的 exe）：
  - 托盘常驻，自动在后台读取千牛/旺旺会话并记录进线、回复时长
  - 每天/退出时把当月汇总上传到财务系统的 kefu/upload.php
建议第一次使用先在 config.ini 里填好员工姓名与旺旺账号。
"""
import configparser
import datetime
import os
import sys
import time

import monitor as mon
import recorder as rec
import uploader as up

APP_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(APP_DIR, 'config.ini')


def load_config():
    cfg = configparser.ConfigParser()
    try:
        cfg.read(CONFIG_FILE, encoding='utf-8')
    except Exception:
        cfg.read(CONFIG_FILE)
    return cfg


def is_autostart():
    import winreg
    key_path = r'Software\Microsoft\Windows\CurrentVersion\Run'
    try:
        key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path, 0, winreg.KEY_READ)
        try:
            winreg.QueryValueEx(key, '客服绩效采集工具')
            return True
        except FileNotFoundError:
            return False
        finally:
            winreg.CloseKey(key)
    except Exception:
        return False


def setup_autostart(enable):
    """注册为开机自启（仅打包成 exe 后生效；源码运行不设置）"""
    if getattr(sys, 'frozen', False) is False:
        return '源码运行模式，未设置开机自启'
    import winreg
    key_path = r'Software\Microsoft\Windows\CurrentVersion\Run'
    exe = '"%s"' % sys.executable
    try:
        key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path, 0, winreg.KEY_SET_VALUE)
        if enable:
            winreg.SetValueEx(key, '客服绩效采集工具', 0, winreg.REG_SZ, exe)
        else:
            try:
                winreg.DeleteValue(key, '客服绩效采集工具')
            except FileNotFoundError:
                pass
        winreg.CloseKey(key)
        return '开机自启：已开启' if enable else '开机自启：已关闭'
    except Exception as e:
        return '开机自启设置失败：%s' % e


def do_upload(cfg, r):
    """生成当月汇总CSV并上传，返回提示文本"""
    today = datetime.date.today()
    path = r.write_month_csv(today.year, today.month)
    if not os.path.exists(path):
        return '当月暂无数据可上传'
    resp = up.upload_file(cfg, path)
    try:
        import json
        obj = json.loads(resp)
        if obj.get('ok'):
            return '上传成功：匹配%d，未匹配%d' % (obj.get('matched', 0), obj.get('pending', 0))
        return '上传被服务器拒绝：%s' % obj.get('error', resp)
    except Exception:
        return '上传响应：%s' % resp


def run_tray(cfg, r, mm):
    from pystray import Icon, Menu, MenuItem
    from PIL import Image, ImageDraw

    def make_icon():
        img = Image.new('RGB', (64, 64), '#28a745')
        d = ImageDraw.Draw(img)
        d.rectangle([8, 8, 56, 56], outline='white', width=3)
        d.text((24, 20), '绩', fill='white')
        return img

    def notify(msg):
        try:
            icon.notify(msg, title='客服绩效采集工具')
        except Exception:
            pass

    def on_upload(icon_item, item):
        try:
            notify(do_upload(cfg, r))
        except Exception as e:
            notify('上传失败: %s' % e)

    def on_manual_incoming(icon_item, item):
        ok = r.manual_incoming()
        notify('已手动补记一条进线' if ok else '今日该会话已存在')

    def on_toggle_autostart(icon_item, item):
        notify(setup_autostart(not is_autostart()))

    def on_quit(icon_item, item):
        try:
            do_upload(cfg, r)  # 退出前先上传一次
        except Exception:
            pass
        icon.stop()

    icon = Icon('客服绩效采集工具', make_icon(), menu=Menu(
        MenuItem('立即上传数据', on_upload),
        MenuItem('手动补记进线 +1', on_manual_incoming),
        MenuItem('开机自启', on_toggle_autostart, checked=lambda item: is_autostart()),
        MenuItem('退出', on_quit),
    ))
    icon.run()


def main():
    cfg = load_config()
    r = rec.Recorder(cfg.get('employee', 'name', fallback=''),
                     cfg.get('employee', 'wangwang', fallback=''))
    mm = mon.WangWangMonitor(r, cfg)
    mm.start()

    if not cfg.has_section('server') or not cfg.get('server', 'url', fallback=''):
        # 服务器地址未配置，不影响本地记录，但上传会失败
        pass

    try:
        run_tray(cfg, r, mm)
    except Exception as e:
        # 无托盘环境（如未装 pystray）退化为基础运行模式
        print('客服绩效采集工具运行中 (Ctrl+C 退出)')
        print('数据目录: %s' % rec.DATA_DIR)
        print('提示: 请确认已安装依赖并可托盘运行; 异常: %r' % (e,))
        try:
            while True:
                time.sleep(3600)
        except KeyboardInterrupt:
            pass
        try:
            do_upload(cfg, r)
        except Exception:
            pass
    finally:
        mm.stop()


if __name__ == '__main__':
    main()
