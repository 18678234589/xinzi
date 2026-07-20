# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# 员工订单 raw_data 含'清风' 的 order_no
cur.execute("""
    SELECT DISTINCT order_no FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风%'
""")
emp_nos = [r['order_no'] for r in cur.fetchall()]
print(f"员工含'清风'的唯一order_no: {len(emp_nos)}个")

# department 清风易软件专营店 的 order_no
cur.execute("""
    SELECT DISTINCT order_no FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND shop='清风易软件专营店'
""")
dept_nos = set(r['order_no'] for r in cur.fetchall())
print(f"department清风易软件专营店唯一order_no. {len(dept_nos)}个")

emp_set = set(emp_nos)
overlap = emp_set & dept_nos
print(f"\norder_no交集: {len(overlap)}个")
print(f"员工有department没有. {len(emp_set - dept_nos)}个")
print(f"department有员工没有. {len(dept_set - emp_set) if 'dept_set' in dir() else len(dept_nos - emp_set)}个")

# 看交集的 order_no
if overlap:
    print(f"\n交集order_no样例:")
    for no in list(overlap)[:10]:
        print(f"  {no}")

# 看员工独有的 order_no 样例
emp_only = emp_set - dept_nos
if emp_only:
    print(f"\n员工独有order_no样例（前10）:")
    for no in list(emp_only)[:10]:
        print(f"  {no}")

# 看department独有的 order_no 样例
dept_only = dept_nos - emp_set
if dept_only:
    print(f"\ndepartment独有order_no样例（前10）:")
    for no in list(dept_only)[:10]:
        print(f"  {no}")

# 检查：是否员工order_no和department order_no格式不同
# 看长度分布
emp_len = {}
for no in emp_nos:
    l = len(str(no))
    emp_len[l] = emp_len.get(l, 0) + 1
print(f"\n员工order_no长度分布: {emp_len}")

dept_len = {}
for no in dept_nos:
    l = len(str(no))
    dept_len[l] = dept_len.get(l, 0) + 1
print(f"department order_no长度分布. {dept_len}")

cur.close()
conn.close()
