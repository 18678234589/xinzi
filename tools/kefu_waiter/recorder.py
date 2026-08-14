# -*- coding: utf-8 -*-
"""客服绩效采集 - 数据记录与月度CSV生成

本地数据落在本目录下 data/ 文件夹：
  data/YYYY-MM-DD.json          每天一个文件，记录会话与回复明细
  data/cs_perf_YYYY-MM.csv       月度汇总（员工姓名,旺旺账号,日期,进线数,回复总秒数,回复次数）
"""
import csv
import datetime
import glob
import json
import os

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data')


def day_path(day=None):
    day = day or datetime.date.today()
    return os.path.join(DATA_DIR, day.strftime('%Y-%m-%d') + '.json')


class Recorder(object):
    def __init__(self, employee_name='', wangwang=''):
        os.makedirs(DATA_DIR, exist_ok=True)
        self.employee_name = (employee_name or '').strip()
        self.wangwang = (wangwang or '').strip()

    # ---------------- 当日数据 ----------------
    def _load_day(self, day):
        p = day_path(day)
        default = {'date': day.strftime('%Y-%m-%d'), 'sessions': {}, 'log': []}
        if os.path.exists(p):
            try:
                with open(p, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                if isinstance(data, dict):
                    data.setdefault('sessions', {})
                    data.setdefault('log', [])
                    return data
            except Exception:
                pass
        return default

    def _save_day(self, day, data):
        p = day_path(day)
        tmp = p + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=1)
        os.replace(tmp, p)

    # ---------------- 事件记录 ----------------
    def record_incoming(self, session_key, nick='', ts=None):
        """记录一条进线会话（会话首次出现）"""
        day = datetime.date.today()
        ts = ts or datetime.datetime.now()
        data = self._load_day(day)
        if session_key not in data['sessions']:
            data['sessions'][session_key] = {
                'nick': nick or session_key,
                'first_seen': ts.strftime('%H:%M:%S'),
                'first_seen_ts': ts.timestamp(),
                'replies': [],
            }
            data['log'].append({'t': ts.strftime('%H:%M:%S'), 'type': 'incoming', 'session': session_key})
            self._save_day(day, data)
            return True
        return False

    def record_reply(self, session_key, latency_sec, ts=None):
        """记录一次客服回复及该会话首次响应时长(秒)"""
        day = datetime.date.today()
        ts = ts or datetime.datetime.now()
        data = self._load_day(day)
        s = data['sessions'].get(session_key)
        if s is None:
            s = {'nick': session_key, 'first_seen': '', 'first_seen_ts': 0.0, 'replies': []}
            data['sessions'][session_key] = s
        s['replies'].append({'t': ts.strftime('%H:%M:%S'), 'sec': round(float(latency_sec), 1)})
        data['log'].append({'t': ts.strftime('%H:%M:%S'), 'type': 'reply',
                            'session': session_key, 'sec': round(float(latency_sec), 1)})
        self._save_day(day, data)

    def manual_incoming(self):
        """托盘手动补记一条进线"""
        return self.record_incoming('manual-' + datetime.datetime.now().strftime('%H%M%S'), nick='(手动)')

    # ---------------- 月度汇总 ----------------
    def build_month_rows(self, year, month):
        """当月每日聚合行：[{name, wangwang, date, incoming, total_sec, reply_count}, ...]"""
        rows = []
        prefix = '%04d-%02d' % (year, month)
        for f in sorted(glob.glob(os.path.join(DATA_DIR, '20*.json'))):
            day = os.path.basename(f)[:10] if len(os.path.basename(f)) >= 10 else ''
            if not day.startswith(prefix):
                continue
            try:
                with open(f, encoding='utf-8') as fp:
                    d = json.load(fp)
            except Exception:
                continue
            sessions = d.get('sessions', {})
            incoming = len(sessions)
            total_sec = 0.0
            reply_count = 0
            for s in sessions.values():
                reps = s.get('replies', []) or []
                reply_count += len(reps)
                for r in reps:
                    total_sec += float(r.get('sec', 0))
            rows.append({
                'name': self.employee_name,
                'wangwang': self.wangwang,
                'date': day,
                'incoming': incoming,
                'total_sec': round(total_sec, 1),
                'reply_count': reply_count,
            })
        return rows

    def write_month_csv(self, year, month):
        """生成当月（截至今天）汇总CSV，返回文件路径"""
        rows = self.build_month_rows(year, month)
        path = os.path.join(DATA_DIR, 'cs_perf_%04d-%02d.csv' % (year, month))
        with open(path, 'w', encoding='utf-8-sig', newline='') as f:
            w = csv.writer(f)
            w.writerow(['员工姓名', '旺旺账号', '日期', '进线数', '回复总秒数', '回复次数'])
            for r in rows:
                w.writerow([r['name'], r['wangwang'], r['date'], r['incoming'], r['total_sec'], r['reply_count']])
        return path
