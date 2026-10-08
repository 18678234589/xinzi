<?php
/** Wrap PHP token boundaries only. HTML, JS, CSS and string contents remain byte-for-byte unchanged. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$manifestPath = __DIR__ . '/split_manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true);
$files = array_fill_keys(array_keys($manifest['sources']), true);
foreach ($manifest['fragments'] as $fragment) $files[$fragment['path']] = true;
$changed = 0;
foreach (array_keys($files) as $path) {
    if (substr($path, -4) !== '.php') continue;
    $source = file_get_contents($root . '/' . $path);
    $protected = [];
    foreach ($manifest['fragments'] as &$fragment) {
        if ($fragment['parent'] !== $path) continue;
        $call = $fragment['call'];
        if (strlen($call) > 180 && strpos($call, '*/ ') !== false) {
            $wrapped = str_replace('*/ ', "*/\n", $call);
            $source = str_replace($call, $wrapped, $source);
            $fragment['call'] = $wrapped;
        }
    }
    unset($fragment);
    foreach ($manifest['fragments'] as $fragment) {
        if ($fragment['parent'] !== $path) continue;
        $position = strpos($source, $fragment['call']);
        if ($position !== false) $protected[] = [$position, $position + strlen($fragment['call'])];
    }
    $output = '';
    $offset = 0;
    $column = 0;
    $php = false;
    $quoted = false;
    $heredoc = false;
    $newline = strpos($source, "\r\n") !== false ? "\r\n" : "\n";
    foreach (token_get_all($source) as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $insideCall = false;
        foreach ($protected as $range) {
            if ($offset >= $range[0] && $offset < $range[1]) { $insideCall = true; break; }
        }
        if ($php && !$quoted && !$heredoc && !$insideCall && $column >= 180
            && !in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
            $output .= $newline . '    ';
            $column = 4;
        }
        $output .= $text;
        $offset += strlen($text);
        $last = strrpos($text, "\n");
        $column = $last === false ? $column + strlen($text) : strlen(substr($text, $last + 1));
        if (in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) $php = true;
        if ($id === T_CLOSE_TAG) $php = false;
        if ($id === T_START_HEREDOC) $heredoc = true;
        if ($id === T_END_HEREDOC) $heredoc = false;
        if ($php && !$heredoc && $id === null && in_array($text, ['"', '`'], true)) $quoted = !$quoted;
    }
    if ($output !== file_get_contents($root . '/' . $path)) {
        file_put_contents($root . '/' . $path, $output);
        $changed++;
    }
}
file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
echo 'Wrapped ' . $changed . " PHP files; run verify_split.php and rendering comparison\n";
