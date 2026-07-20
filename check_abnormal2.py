# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# department 订单里 shop 字段的值分布
cur.execute("""
    SELECT shop, COUNT(*) as cnt FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    GROUP BY shop ORDER BY cnt DESC
""")
rows = cur.fetchall()
print("department 订单 shop 字段分布:")
for r in rows:
    print(f"  '{r['shop']}': {r['cnt']}")

# 查 department 订单里有没有清风易相关
cur.execute("""
    SELECT shop, order_no, order_amount FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND (shop LIKE '%清风易%' OR shop LIKE '%清风%')
    LIMIT 10
""")
rows = cur.fetchall()
print(f"\ndepartment 订单含'清风': {len(rows)}条")
for r in rows:
    print(f"  shop='{r['shop']}', order_no='{r['order_no']}', amount={r['order_amount']}")

# 员工订单里 order_no 和 department 的能不能对上
# 取员工订单前5条清风易的 order_no，去 department 表查
cur.execute("""
    SELECT order_no FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
    LIMIT 5
""")
emp_nos = [r['order_no'] for r in cur.fetchall()]
print(f"\n员工订单前5个order_no: {emp_nos}")

if emp_nos:
    ph = ','.join(['%s']*len(emp_nos))
    cur.execute(f"""
        SELECT shop, order_no, order_amount FROM orders 
        WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
        AND order_no IN ({ph})
    """, emp_nos)
    dept_matches = cur.fetchall()
    print(f"这些order_no在department表里匹配到: {len(dept_matches)}条")
    for r in dept_matches:
        print(f"  shop='{r['shop']}', order_no='{r['order_no']}', amount={r['order_amount']}")

# 看员工订单这些 order_no 对应的 raw_data 里的店铺名
cur.execute("""
    SELECT id, order_no, raw_data FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
    LIMIT 5
""")
for r in cur.fetchall():
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            shop_val = str(v).strip()
            break
    print(f"  emp order_no='{r['order_no']}', raw店铺='{shop_val}'")

cur.close()
conn.close()
