# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# 统计员工清风易订单有多少 order_no 能在 department 表找到
cur.execute("""
    SELECT order_no FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风易%'
""")
emp_nos = [r['order_no'] for r in cur.fetchall()]
print(f"员工清风易订单数: {len(emp_nos)}")

# 去 department 表查
unique_nos = list(set(emp_nos))
print(f"唯一 order_no 数: {len(unique_nos)}")

ph = ','.join(['%s'] * min(len(unique_nos), 1000))
cur.execute(f"""
    SELECT COUNT(DISTINCT order_no) as cnt FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND shop='清风易软件专营店'
    AND order_no IN ({ph})
""", unique_nos[:1000])
matched = cur.fetchone()['cnt']
print(f"能在department清风易找到的: {matched} 个")

# 看看 department 清风易的 order_no 样例
cur.execute("""
    SELECT order_no FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND shop='清风易软件专营店'
    LIMIT 10
""")
dept_nos = [r['order_no'] for r in cur.fetchall()]
print(f"\ndepartment 清风易 order_no 样例: {dept_nos}")

print(f"\n员工 order_no 样例: {unique_nos[:10]}")

# 看是否有交集
emp_set = set(unique_nos)
dept_set = set()
cur.execute("""
    SELECT DISTINCT order_no FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND shop='清风易软件专营店'
""")
for r in cur.fetchall():
    dept_set.add(r['order_no'])

overlap = emp_set & dept_set
print(f"\n交集: {len(overlap)} 个")
print(f"员工有但department没有: {len(emp_set - dept_set)} 个")
print(f"department有但员工没有: {len(dept_set - emp_set)} 个")

cur.close()
conn.close()
