# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# 查清风易的店铺订单
cur.execute("""
    SELECT id, shop, order_no, order_amount, order_date 
    FROM orders 
    WHERE order_scope = 'department' AND shop = '清风易' AND COALESCE(is_deleted,0)=0
    ORDER BY id LIMIT 10
""")
rows = cur.fetchall()
print(f"清风易 department 订单: {len(rows)}条（前10条）")
for r in rows:
    print(f"  id={r['id']}, shop='{r['shop']}', order_no='{r['order_no']}', amount={r['order_amount']}, date={r['order_date']}")

# 总数
cur.execute("""
    SELECT COUNT(*) as cnt FROM orders 
    WHERE order_scope='department' AND shop='清风易' AND COALESCE(is_deleted,0)=0
""")
print(f"\n清风易 department 订单总数: {cur.fetchone()['cnt']}")

# 查 shops 表里清风易的记录
cur.execute("SELECT id, name FROM shops WHERE name LIKE '%清风易%'")
shops = cur.fetchall()
print(f"\nshops表清风易: {shops}")

# 查员工订单里 raw_data 提取店铺名含"清风易"的
cur.execute("""
    SELECT id, employee_id, order_no, order_amount, raw_data 
    FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
    ORDER BY id LIMIT 10
""")
emp_rows = cur.fetchall()
print(f"\n员工订单 raw_data 含'清风易': {len(emp_rows)}条（前10条）")
for r in emp_rows:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    # 找店铺相关字段
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            shop_val = f"{k}={v}"
            break
    print(f"  id={r['id']}, emp_id={r['employee_id']}, order_no='{r['order_no']}', amount={r['order_amount']}, {shop_val}")

# 统计 raw_data 里店铺名都是什么
cur.execute("""
    SELECT raw_data FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
""")
all_emp = cur.fetchall()
shop_names = {}
for r in all_emp:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    if not isinstance(rd, dict): continue
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            v = str(v).strip()
            shop_names[v] = shop_names.get(v, 0) + 1
            break
print(f"\n员工订单中清风易相关店铺名分布:")
for name, cnt in sorted(shop_names.items(), key=lambda x:-x[1]):
    print(f"  '{name}': {cnt}")

cur.close()
conn.close()
