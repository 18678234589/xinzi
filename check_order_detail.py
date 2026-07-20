# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

order_no = '5122112473823008529'

# 1. 员工订单
cur.execute("""
    SELECT id, employee_id, order_no, order_amount, order_date, project, raw_data
    FROM orders WHERE order_no = %s AND order_scope = 'personal'
""", (order_no,))
emp_rows = cur.fetchall()
print(f"员工订单 {len(emp_rows)} 条:")
for r in emp_rows:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    print(f"  id={r['id']}, emp_id={r['employee_id']}, project='{r['project']}'")
    print(f"  raw_data 完整内容:")
    for k, v in rd.items():
        print(f"    {k} = {v}")
    print()

# 2. department 表里这个 order_no
cur.execute("""
    SELECT id, shop, order_no, order_amount, order_date, raw_data
    FROM orders WHERE order_no = %s AND order_scope = 'department'
""", (order_no,))
dept_rows = cur.fetchall()
print(f"department 表 order_no='{order_no}': {len(dept_rows)} 条")
for r in dept_rows:
    print(f"  id={r['id']}, shop='{r['shop']}', amount={r['order_amount']}, date={r['order_date']}")

# 3. 模糊查 - 是否有相近的 order_no
cur.execute("""
    SELECT id, shop, order_no, order_amount, order_scope
    FROM orders 
    WHERE order_scope = 'department' 
    AND shop = '清风易软件专营店'
    AND (order_no LIKE %s OR order_no LIKE %s)
    LIMIT 10
""", ('%' + order_no[:10] + '%', '%' + order_no[-10:] + '%'))
fuzzy = cur.fetchall()
print(f"\n清风易软件专营店 department 表里 order_no 模糊匹配前10位/后10位: {len(fuzzy)} 条")
for r in fuzzy:
    print(f"  id={r['id']}, order_no='{r['order_no']}', shop='{r['shop']}', amount={r['order_amount']}")

# 4. 看员工订单的 order_no 是从 raw_data 哪列提取的
if emp_rows:
    rd = json.loads(emp_rows[0]['raw_data']) if isinstance(emp_rows[0]['raw_data'], str) else {}
    print(f"\n员工 raw_data 列名: {list(rd.keys())}")
    # 看订单号在哪个列
    for k, v in rd.items():
        if str(v).strip() == order_no:
            print(f"  → order_no 来自列 '{k}' = '{v}'")

# 5. 看 department 清风易的 order_no 样例，对比格式
cur.execute("""
    SELECT DISTINCT order_no FROM orders 
    WHERE order_scope='department' AND shop='清风易软件专营店'
    LIMIT 5
""")
print(f"\ndepartment 清风易 order_no 样例:")
for r in cur.fetchall():
    print(f"  '{r['order_no']}' (长度={len(r['order_no'])})")

print(f"\n员工 order_no: '{order_no}' (长度={len(order_no)})")

cur.close()
conn.close()
