#!/usr/bin/env bash
set -Eeuo pipefail

event_path=${GITHUB_EVENT_PATH:-}
event_name=${GITHUB_EVENT_NAME:-}
[[ -f "$event_path" ]] || { echo 'GitHub event payload is required to check migration history' >&2; exit 1; }

base_sha=$(python3 - "$event_path" "$event_name" <<'PY'
import json, re, sys

try:
    with open(sys.argv[1], encoding='utf-8') as event_file:
        event = json.load(event_file)
    if sys.argv[2] == 'push':
        base = event.get('before')
    elif sys.argv[2] == 'pull_request':
        base = (event.get('pull_request') or {}).get('base', {}).get('sha')
    else:
        raise ValueError('unsupported event')
    if not isinstance(base, str) or not re.fullmatch(r'[0-9a-f]{40}', base):
        raise ValueError('missing base commit')
    print(base)
except (OSError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit('Cannot determine the trusted base commit for migration policy')
PY
) || exit 1

git cat-file -e "${base_sha}^{commit}" 2>/dev/null || { echo 'Trusted migration policy base commit is unavailable locally' >&2; exit 1; }
merge_base=$(git merge-base "$base_sha" HEAD) || { echo 'Cannot find a merge base for migration policy' >&2; exit 1; }
changed=$(git diff --name-status --find-renames --diff-filter=MRDT "$merge_base...HEAD" -- database/migrations)
if [[ -n "$changed" ]]; then
    printf 'Existing database migrations are immutable; add a new migration instead:\n%s\n' "$changed" >&2
    exit 1
fi

echo 'Existing database migrations are unchanged.'
