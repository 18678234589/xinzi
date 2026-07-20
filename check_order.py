# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

order_no = '5122112473823008529'

# 1. 查这个订单号在 orders 表的所有记录
cur.execute("""
    SELECT id, employee_id, order_no, order_amount, order_date, project, shop, 
           order_scope, is_abnormal, abnormal_reason, raw_data
    FROM orders 
    WHERE order_no = %s
    ORDER BY id
""", (order_no,))
rows = cur.fetchall()
print(f"order_no='{order_no}' 在 orders 表共 {len(rows)} 条记录")
for r in rows:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            shop_val = str(v).strip()
            break
    print(f"\n  id={r['id']}")
    print(f"  employee_id={r['employee_id']}, project='{r['project']}', shop='{r['shop']}'")
    print(f"  order_scope='{r['order_scope']}', amount={r['order_amount']}, date={r['order_date']}")
    print(f"  is_abnormal={r['is_abnormal']}, abnormal_reason='{r['abnormal_reason']}'")
    print(f"  raw_data店铺='{shop_val}'")
    # 列出 raw_data 关键字段
    for k in ['店铺', '店名', '订单编号', '订单号', '金额', '日期', '付费旺旺', '客户旺旺或者微信名称']:
        if k in rd:
            print(f"  raw_data[{k}] = {rd[k]}")

# 2. 查这个订单号在 department 表（order_scope=department）是否存在
cur.execute("""
    SELECT id, shop, order_no, order_amount FROM orders 
    WHERE order_no = %s AND order_scope = 'department'
""", (order_no,))
dept_rows = cur.fetchall()
print(f"\n在 department 表: {len(dept_rows)} 条")
for r in dept_rows:
    print(f"  id={r['id']}, shop='{r['shop']}', amount={r['order_amount']}")

# 3. 如果是员工订单，查它属于哪个店铺
cur.execute("""
    SELECT id, employee_id, order_no, raw_data, project
    FROM orders 
    WHERE order_no = %s AND order_scope = 'personal'
""", (order_no,))
emp_rows = cur.fetchall()
print(f"\n在 personal 表: {len(emp_rows)} 条")
for r in emp_rows:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            shop_val = str(v).strip()
            break
    print(f"  id={r['id']}, employee_id={r['employee_id']}, project='{r['project']}', raw店铺='{shop_val}'")
    # 看完整的 raw_data
    print(f"  raw_data keys: {list(rd.keys()) if isinstance(rd, dict) else 'N/A'}")

cur.close()
conn.close()
