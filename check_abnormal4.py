# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# 员工清风易订单的 order_no 分布
cur.execute("""
    SELECT order_no, COUNT(*) as cnt FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
    GROUP BY order_no ORDER BY cnt DESC
""")
rows = cur.fetchall()
print(f"员工清风易 order_no 分布（共{len(rows)}个唯一值）:")
for r in rows[:20]:
    print(f"  '{r['order_no']}': {r['cnt']}次")

# 那2个能匹配的
cur.execute("""
    SELECT e.id, e.order_no, e.order_amount, e.raw_data, 
           d.id as dept_id, d.order_amount as dept_amount, d.shop
    FROM orders e
    JOIN orders d ON e.order_no = d.order_no 
    WHERE e.order_scope='personal' AND COALESCE(e.is_deleted,0)=0 
    AND d.order_scope='department' AND COALESCE(d.is_deleted,0)=0
    AND d.shop='清风易软件专营店'
    AND e.raw_data LIKE '%清风易%'
    LIMIT 10
""")
matches = cur.fetchall()
print(f"\n能匹配上的订单（{len(matches)}条）:")
for m in matches:
    rd = json.loads(m['raw_data']) if isinstance(m['raw_data'], str) else {}
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k):
            shop_val = str(v)
            break
    print(f"  emp_id={m['id']}, order_no='{m['order_no']}', emp_amount={m['order_amount']}, dept_amount={m['dept_amount']}, raw店铺='{shop_val}'")

# 看员工订单的 raw_data 列名，确认订单号是从哪列提取的
cur.execute("""
    SELECT raw_data FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
    LIMIT 1
""")
r = cur.fetchone()
rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
print(f"\n员工订单 raw_data 所有列名:")
for k in rd.keys():
    print(f"  {k} = {str(rd[k])[:60]}")

cur.close()
conn.close()
