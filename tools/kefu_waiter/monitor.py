# -*- coding: utf-8 -*-
"""千牛/旺旺客户端会话监控（v1，面向千牛PC标准版）

原理：
  1. 用 pywinauto(UI Automation) 枚举标题含"千牛/旺旺/Ayu"等关键字的窗口；
  2. 以会话昵称/标题作为会话标识，发现新会话即记一条"进线"；
  3. 探测客服发送回复：监控聊天窗口里的输入框 Edit 控件，
     出现"已有文本→清空"的变化即视为发送，并结合该会话最近一次进线时间算出首次响应秒数。

注意：读取客户端窗口属灰色操作，可能被淘宝风控（详见 README）。
此模块所有 UI 调用均尽量吞掉异常，保证程序不会崩溃。
"""
import datetime
import os
import re
import threading
import time

import recorder as rec

try:
    from pywinauto import Desktop
    HAVE_PYWINAUTO = True
except Exception:
    HAVE_PYWINAUTO = False

# 窗口标题关键字（含任一即视为旺旺/千牛会话窗口）
WINDOW_KEYWORDS = ['千牛', '旺旺', 'alisoft', 'qianniu', 'qnclient', 'wangwang', 'ayu']


def is_wangwang_window(title):
    t = (title or '')
    tl = t.lower()
    return any(k in tl for k in WINDOW_KEYWORDS)


def cleanup_nick(text):
    """从窗口标题/控件文本中提取会话标识（买家昵称等）"""
    t = (text or '').strip()
    if not t:
        return ''
    t = re.sub(r'[\[\]【】()（）<>《》{}]+', '', t)
    t = re.sub(r'(千牛|旺旺|工作台|消息中心|客服工作台|店铺)', '', t)
    return t.strip()[:40]


def find_chat_windows():
    """返回所有可见的旺旺/千牛顶层窗口"""
    out = []
    if not HAVE_PYWINAUTO:
        return out
    try:
        for w in Desktop(backend='uia').windows():
            try:
                if not w.is_visible():
                    continue
                title = w.window_text() or ''
                if not is_wangwang_window(title):
                    continue
                out.append(w)
            except Exception:
                continue
    except Exception:
        pass
    return out


class WangWangMonitor(threading.Thread):
    def __init__(self, recorder, cfg):
        super(WangWangMonitor, self).__init__(daemon=True)
        self.r = recorder
        self.cfg = cfg
        self.stop_flag = False
        self.window_sessions = {}   # hash(窗口,控件) -> session_key
        self.edit_prev = {}         # 输入框标识 -> 上次文本
        self.session_last_incoming = {}  # session_key -> 最近一次进线时间戳
        self._log_path = os.path.join(rec.DATA_DIR, 'monitor.log')

    def stop(self):
        self.stop_flag = True

    def _log(self, msg):
        try:
            with open(self._log_path, 'a', encoding='utf-8') as f:
                f.write('%s %s\n' % (datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'), msg))
        except Exception:
            pass

    # ---------------- 会话扫描 ----------------
    def _iter_chat_sessions(self):
        """提取 (会话key, 昵称) 集合"""
        sessions = []
        windows = find_chat_windows()
        if not windows:
            return sessions
        for w in windows:
            try:
                title = w.window_text() or ''
                # 顶层标题本身可能携带当前会话名
                n = cleanup_nick(title)
                if len(n) >= 2:
                    sessions.append((n, title.replace('\n', ' ')[:40]))
                # 子控件文本里通常出现会话列表（买家昵称）
                try:
                    texts = w.children_texts()
                except Exception:
                    texts = []
                for t in (texts or [])[:60]:
                    n = cleanup_nick(t)
                    if len(n) >= 2 and n not in [k for k, _ in sessions]:
                        sessions.append((n, t[:40]))
            except Exception:
                continue
        return sessions

    # ---------------- 主循环 ----------------
    def run(self):
        try:
            interval = float(self.cfg.get('monitor', 'interval_sec', fallback='3'))
        except Exception:
            interval = 3.0
        self._log('监控启动（远程/采集 v1）')
        while not self.stop_flag:
            try:
                self._scan_sessions()
                self._scan_replies()
            except Exception as e:
                try:
                    self._log('监控异常: %r' % (e,))
                except Exception:
                    pass
            time.sleep(interval)

    def _scan_sessions(self):
        for key, nick in self._iter_chat_sessions():
            if key in self.window_sessions:
                continue
            self.window_sessions[key] = True
            self.r.record_incoming(key, nick=nick)
            self.session_last_incoming[key] = time.time()

    # ---------------- 回复探测 ----------------
    def _scan_replies(self):
        if not HAVE_PYWINAUTO:
            return
        now = time.time()
        for w in find_chat_windows():
            try:
                edits = w.descendants(control_type='Edit', depth=8)
            except Exception:
                continue
            if not edits:
                continue
            wtitle = cleanup_nick(w.window_text() or '')
            for ed in edits[:8]:
                eid = None
                try:
                    eid = ed.element_info.runtime_id
                except Exception:
                    eid = wtitle + '#' + w.window_handle()
                ckey = str(eid)
                try:
                    txt = ed.get_value() or ''
                except Exception:
                    try:
                        txt = ed.window_text() or ''
                    except Exception:
                        txt = ''
                prev = self.edit_prev.get(ckey, '__FIRST__')
                if prev == '__FIRST__':
                    self.edit_prev[ckey] = txt
                    continue
                if prev.strip() and not txt.strip():
                    # 输入框曾有过内容、现被清空 → 视为发送了一条回复
                    session_key = self.window_sessions.get(ckey)
                    if session_key is None:
                        # 尽力把该输入框归属到窗口标题对应的会话
                        session_key = wtitle
                        if session_key and session_key not in self.window_sessions:
                            self.window_sessions[ckey] = session_key
                    last_in = self.session_last_incoming.get(session_key)
                    if last_in is not None:
                        latency = max(0.0, now - last_in)
                        self.r.record_reply(session_key, latency)
                    self._log('探测到发送 @ %s (延迟=%.1fs)' % (str(session_key), now - (last_in or now)))
                    self.session_last_incoming[session_key] = now
                self.edit_prev[ckey] = txt
