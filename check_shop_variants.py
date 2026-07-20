# -*- coding: utf-8 -*-
import pymysql, json

conn = pymysql.connect(
    host='58.58.98.150', port=3306, user='xinzi', password='xinzi@123',
    database='xinzi', charset='utf8mb4',
)
cur = conn.cursor(pymysql.cursors.DictCursor)

# 搜员工订单 raw_data 里店铺名包含"清风"的（不限于"清风易"）
cur.execute("""
    SELECT id, employee_id, order_no, order_amount, raw_data 
    FROM orders 
    WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
    AND raw_data LIKE '%清风%'
    ORDER BY id
""")
rows = cur.fetchall()
print(f"员工订单 raw_data 含'清风': {len(rows)}条")

# 提取每条的店铺名
shop_names = {}
for r in rows:
    rd = json.loads(r['raw_data']) if isinstance(r['raw_data'], str) else {}
    if not isinstance(rd, dict): continue
    shop_val = ''
    for k, v in rd.items():
        if '店铺' in str(k) or '店名' in str(k):
            shop_val = str(v).strip()
            break
    shop_names[shop_val] = shop_names.get(shop_val, 0) + 1

print(f"\n店铺名写法分布:")
for name, cnt in sorted(shop_names.items(), key=lambda x: -x[1]):
    print(f"  '{name}': {cnt}")

# shops 表里清风相关的标准名
cur.execute("SELECT id, name FROM shops WHERE name LIKE '%清风%'")
shops = cur.fetchall()
print(f"\nshops表清风相关:")
for s in shops:
    print(f"  id={s['id']}, name='{s['name']}'")

# 看这些不同写法的 order_no 能否在 department 表找到
# 先获取所有风相关的 department shop 名
cur.execute("""
    SELECT DISTINCT shop FROM orders 
    WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
    AND (shop LIKE '%清风%' OR shop LIKE '%清风易%')
""")
dept_shops = [r['shop'] for r in cur.fetchall()]
print(f"\ndepartment 表清风相关店铺名: {dept_shops}")

# 对每种写法，统计 order_no 在 department 表的匹配率
for shop_val in sorted(shop_names.keys()):
    cur.execute("""
        SELECT order_no FROM orders 
        WHERE order_scope='personal' AND COALESCE(is_deleted,0)=0 
        AND raw_data LIKE %s
    """, (f'%{shop_val}%',))
    emp_nos = list(set(r['order_no'] for r in cur.fetchall()))
    if not emp_nos:
        continue
    
    # 在所有清风相关 department 订单里查
    ph = ','.join(['%s'] * min(len(emp_nos), 500))
    cur.execute(f"""
        SELECT COUNT(DISTINCT order_no) as cnt FROM orders 
        WHERE order_scope='department' AND COALESCE(is_deleted,0)=0 
        AND shop IN (%s)
        AND order_no IN ({ph})
    """ % (','.join(["'" + s + "'" for s in dept_shops]),), emp_nos[:500])
    matched = cur.fetchone()['cnt']
    print(f"\n店铺写法'{shop_val}': {len(emp_nos)}个唯一order_no, 匹配department {matched}个")

cur.close()
conn.close()
