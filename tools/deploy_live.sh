#!/usr/bin/env bash
# Compatibility entry point for the migrated live host.
exec python "$(dirname "$0")/deploy_hezuoshang.py" "$@"
