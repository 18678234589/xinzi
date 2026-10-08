<?php
/** Token-based declarations; safe for pages because source is never executed. PHP 7.4. */
function split_declarations($source)
{
    $tokens = token_get_all($source);
    $offset = 0;
    $line = 1;
    $items = [];
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $items[] = ['id' => is_array($token) ? $token[0] : null, 'text' => $text,
            'offset' => $offset, 'line' => $line];
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    $declarations = [];
    foreach ($items as $i => $item) {
        if (!in_array($item['id'], [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE], true)) continue;
        $j = $i + 1;
        while (isset($items[$j]) && (in_array($items[$j]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            || $items[$j]['text'] === '&')) $j++;
        if (!isset($items[$j]) || $items[$j]['id'] !== T_STRING) continue;
        $name = $items[$j]['text'];
        while (isset($items[$j]) && !in_array($items[$j]['text'], ['{', ';'], true)) $j++;
        if (!isset($items[$j])) continue;
        $depth = 0;
        $end = $j;
        if ($items[$j]['text'] === '{') {
            for (; isset($items[$end]); $end++) {
                $t = $items[$end];
                if (($t['id'] === null && $t['text'] === '{')
                    || in_array($t['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
                if ($t['id'] === null && $t['text'] === '}') $depth--;
                if ($depth === 0) break;
            }
        }
        if (!isset($items[$end])) throw new RuntimeException('Unclosed declaration: ' . $name);
        $declarations[] = ['name' => $name, 'kind' => token_name($item['id']), 'start' => $item['line'],
            'end' => $items[$end]['line'], 'offset' => $item['offset'],
            'end_offset' => $items[$end]['offset'] + strlen($items[$end]['text']), 'owner' => null];
    }
    foreach ($declarations as &$decl) {
        if ($decl['kind'] !== 'T_FUNCTION') continue;
        foreach ($declarations as $owner) {
            if ($owner['kind'] !== 'T_FUNCTION' && $owner['offset'] < $decl['offset']
                && $owner['end_offset'] > $decl['end_offset']) $decl['owner'] = $owner['name'];
        }
    }
    unset($decl);
    return $declarations;
}

function split_symbols($source)
{
    $symbols = [];
    foreach (split_declarations($source) as $decl) {
        $symbols[] = $decl['kind'] . ':' . ($decl['owner'] ? $decl['owner'] . '::' : '') . $decl['name'];
    }
    sort($symbols);
    return $symbols;
}

function split_token_hash($source)
{
    $significant = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $name = token_name($token[0]);
            $text = str_replace("\r\n", "\n", $token[1]);
            if (in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO], true)) $text = rtrim($text);
            if (strpos($name, 'T_AMPERSAND_') === 0) $name = 0;
            $significant[] = [$name, $text];
        } else $significant[] = [0, $token];
    }
    return hash('sha256', serialize($significant));
}

function split_expand($root, $path, $manifest, &$visited)
{
    $visited[$path] = true;
    $source = file_get_contents($root . '/' . $path);
    foreach ($manifest['preloads'][$path] ?? [] as $preload) {
        if (substr_count($source, $preload) !== 1) throw new RuntimeException('Changed preload: ' . $path);
        $source = str_replace($preload, '', $source);
    }
    foreach ($manifest['fragments'] as $fragment) {
        if ($fragment['parent'] !== $path) continue;
        if (substr_count($source, $fragment['call']) !== 1) {
            throw new RuntimeException('Missing or duplicate fragment call: ' . $fragment['path']);
        }
        $body = split_expand($root, $fragment['path'], $manifest, $visited);
        $prefix = $fragment['prefix'];
        $suffix = $fragment['suffix'];
        if (substr($body, 0, strlen($prefix)) !== $prefix
            || ($suffix !== '' && substr($body, -strlen($suffix)) !== $suffix)) {
            throw new RuntimeException('Changed fragment wrapper: ' . $fragment['path']);
        }
        $body = $suffix === '' ? substr($body, strlen($prefix)) : substr($body, strlen($prefix), -strlen($suffix));
        if (!empty($fragment['dir'])) {
            $parts = [];
            foreach (token_get_all('<?php ' . $fragment['dir']) as $token) {
                if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true)) continue;
                $parts[] = preg_quote(is_array($token) ? $token[1] : $token, '~');
            }
            $body = preg_replace('~' . implode('\s*', $parts) . '~', '__DIR__', $body);
        }
        $source = str_replace($fragment['call'], $body, $source);
    }
    return $source;
}
