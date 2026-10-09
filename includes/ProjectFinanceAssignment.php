<?php
/** Business assignment is a financial worklist filter, not a change to view permissions. */
function ps_finance_username(array $actor): string
{
    if (($actor['role'] ?? '') !== 'finance') return '';
    if (!empty($actor['username'])) return strtolower((string)$actor['username']);
    if (($actor['type'] ?? '') !== 'admin' || empty($actor['id'])) return '';
    static $names = [];
    $id = (int)$actor['id'];
    if (!array_key_exists($id, $names)) {
        $q = db()->prepare('SELECT username FROM admins WHERE id=?');
        $q->execute([$id]); $names[$id] = strtolower((string)$q->fetchColumn());
    }
    return $names[$id];
}

function ps_finance_filter(array $actor, $requested = null): string
{
    if (($actor['role'] ?? '') !== 'finance') return 'all';
    $mine = ps_finance_username($actor);
    $valid = array_map(function ($r) { return strtolower($r['username']); }, ps_admin_reviewers());
    if ($requested === 'all') return 'all';
    if ($requested === 'mine' && $mine !== '') return $mine;
    if (is_string($requested) && in_array(strtolower($requested), $valid, true)) return strtolower($requested);
    // Direct personal and file links keep their original explicit scope.
    if (!empty($_GET['employee_id']) || !empty($_GET['import_file']) || !empty($_GET['participating'])) return 'all';
    return $mine !== '' && $mine !== 'admin' && in_array($mine, ps_business_reviewers(), true) ? $mine : 'all';
}

function ps_finance_business_condition(string $username, string $alias = 'o'): string
{
    if ($username === '' || $username === 'all') return '1=1';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid table alias');
    // Include historical business aliases as well as the current catalog.
    $names = array_unique(array_merge(array_keys(ps_business_catalog()), db()->query('SELECT DISTINCT project_type FROM project_orders')->fetchAll(PDO::FETCH_COLUMN)));
    $owned = [];
    foreach ($names as $name) {
        $owner = strtolower((string)ps_business_reviewer($name));
        if ($owner === $username || $owner === 'all') $owned[] = db()->quote($name);
    }
    return $owned ? $alias . '.project_type IN (' . implode(',', $owned) . ')' : '1=0';
}

function ps_finance_name(string $username): string
{
    if ($username === 'all' || $username === '') return '全体财务';
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (ps_admin_reviewers() as $r) $names[strtolower($r['username'])] = $r['real_name'];
    }
    return $names[strtolower($username)] ?? $username;
}
