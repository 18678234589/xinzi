"""Conservative static check for literal PHP require paths relative to __DIR__."""
import posixpath
import re

_TOKENS = re.compile(r'''//[^\r\n]*|/\*.*?\*/|\#[^\r\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[A-Za-z_]\w*|\S''', re.S)


def literal_php_requires(relative_path, source):
    tokens = [token for token in _TOKENS.findall(source)
              if not token.startswith(('//', '/*', '#'))]
    result = set()
    for index, token in enumerate(tokens):
        if token.lower() not in ('require', 'require_once'):
            continue
        tail = tokens[index + 1:]
        if tail and tail[0] == '(':
            tail = tail[1:]
        if len(tail) < 3 or tail[0] != '__DIR__' or tail[1] != '.':
            continue
        quoted = tail[2]
        if len(quoted) < 2 or quoted[0] not in ("'", '"') or quoted[-1] != quoted[0]:
            continue
        literal = quoted[1:-1]
        if quoted[0] == '"' and '$' in literal:
            continue  # Dynamic paths need runtime validation, not a guessed dependency.
        if '\\' in literal:
            continue
        if not literal.startswith('/'):
            continue
        dependency = posixpath.normpath(posixpath.join(posixpath.dirname(relative_path), literal.lstrip('/')))
        if dependency.startswith('../') or dependency.startswith('/'):
            raise ValueError('Required dependency is outside application: ' + relative_path)
        result.add(dependency)
    return sorted(result)
