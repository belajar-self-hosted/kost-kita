#!/usr/bin/env bash
# Runs only after successful quality gates and the protected production environment.
set -Eeuo pipefail

sha=${1:-}
env_file=${2:-}
root=/opt/kostkita
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA' >&2; exit 1; }
[[ "$(git rev-parse HEAD)" == "$sha" ]] || { echo 'Checkout does not match release SHA' >&2; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo 'Tracked checkout has local changes' >&2; exit 1; }
expected_hostname=${KOSTKITA_EXPECTED_HOSTNAME:-}
[[ -n "$expected_hostname" ]] || { echo 'Expected production hostname is not configured' >&2; exit 1; }
[[ "$(hostname -s)" == "$expected_hostname" ]] || { echo 'Runner hostname does not match the production target' >&2; exit 1; }
[[ "${RUNNER_NAME:-}" == 'kost-kita' ]] || { echo 'GitHub runner name does not match kost-kita' >&2; exit 1; }
command -v systemd-detect-virt >/dev/null || { echo 'Cannot verify the runner container type' >&2; exit 1; }
[[ "$(systemd-detect-virt --container)" == lxc ]] || { echo 'Runner is not inside an LXC container' >&2; exit 1; }
# Hostname, runner name, and IP are useful guards, but they do not prove the
# Proxmox CT ID. Verify that identity out of band before enabling production.
command -v ip >/dev/null || { echo 'Cannot verify the production target IP' >&2; exit 1; }
ip -o -4 addr show | awk '$4 ~ /^192\.168\.0\.26\// { found=1 } END { exit !found }' || { echo 'Runner does not have the expected production IP 192.168.0.26' >&2; exit 1; }
[[ -f "$env_file" && ! -L "$env_file" && "$env_file" == /* ]] || { echo 'Regular absolute production environment file required' >&2; exit 1; }
[[ "${GITHUB_ACTIONS:-}" == true && "${GITHUB_EVENT_NAME:-}" == workflow_run && "${GITHUB_REF:-}" == refs/heads/main && -n "${GITHUB_EVENT_PATH:-}" && -f "$GITHUB_EVENT_PATH" ]] || { echo 'Deployment requires a GitHub Actions workflow_run on main' >&2; exit 1; }
[[ -n "${GITHUB_TOKEN:-}" && -n "${GITHUB_API_URL:-}" && -n "${GITHUB_REPOSITORY:-}" && "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]] || { echo 'GitHub run identity and read-only token required' >&2; exit 1; }
command -v python3 >/dev/null
command -v curl >/dev/null
command -v df >/dev/null
command -v flock >/dev/null || { echo 'flock is required for process-safe deployment locking' >&2; exit 1; }

python3 - "$env_file" <<'PY'
import os, stat, sys
try:
    metadata = os.lstat(sys.argv[1])
    if not stat.S_ISREG(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_uid != os.getuid():
        raise SystemExit('Production environment file must be a runner-owned regular file with mode 0600')
except OSError:
    raise SystemExit('Production environment file cannot be inspected safely')
PY

python3 - "$sha" "$GITHUB_REPOSITORY" <<'PY'
import json, os, sys
try:
    with open(os.environ['GITHUB_EVENT_PATH'], encoding='utf-8') as event_file:
        payload = json.load(event_file)
    run = payload.get('workflow_run') or {}
    event_repository = payload.get('repository') or {}
    head_repository = run.get('head_repository') or {}
    repository = sys.argv[2]
    if (payload.get('action') != 'completed'
            or event_repository.get('full_name') != repository
            or run.get('workflow_id') != 405373892
            or run.get('name') != 'tests'
            or run.get('event') != 'push'
            or run.get('head_branch') != 'main'
            or head_repository.get('full_name') != repository
            or run.get('conclusion') != 'success'
            or run.get('head_sha') != sys.argv[1]):
        raise SystemExit('The completed workflow_run is not the successful trusted tests run for this commit')
except (AttributeError, KeyError, OSError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit('GitHub workflow_run provenance payload is invalid')
PY

check_main_head() {
    python3 - "$sha" <<'PY'
import json, os, sys, urllib.request
api = os.environ['GITHUB_API_URL'].rstrip('/')
repo = os.environ['GITHUB_REPOSITORY']
if not api.startswith('https://') or len(repo.split('/')) != 2:
    raise SystemExit('Invalid GitHub API identity')
req = urllib.request.Request(f'{api}/repos/{repo}/commits/main', headers={
    'Authorization': 'Bearer ' + os.environ['GITHUB_TOKEN'],
    'Accept': 'application/vnd.github+json',
})
with urllib.request.urlopen(req, timeout=15) as response:
    actual = json.load(response)['sha']
if actual != sys.argv[1]:
    raise SystemExit('Release is no longer the head of main')
PY
}

check_no_pending_migrations() {
    local output
    if output=$("$@" 2>&1); then
        return 0
    fi
    if [[ "$output" == *Pending* ]]; then
        echo 'Pending database migrations detected; automatic production deployment is stopped' >&2
    else
        echo 'Could not verify database migration status' >&2
    fi
    return 1
}

check_scheduler_tasks() {
    local output
    if ! output=$("$@" 2>&1); then
        echo 'Could not read the release scheduler task registry' >&2
        return 1
    fi
    python3 -c '
import json, sys
try:
    tasks = json.load(sys.stdin)
    commands = {task["command"] for task in tasks}
    expected = {
        "php artisan bookings:expire-no-show",
        "php artisan billing:generate-monthly-invoices",
        "php artisan deposits:mark-late-settlements",
    }
    if not expected.issubset(commands):
        raise SystemExit(1)
except (KeyError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit(1)
' <<< "$output" || { echo 'Release scheduler is missing one or more expected application tasks' >&2; return 1; }
}

check_build_headroom() {
    local minimum_bytes minimum_inodes docker_root free_bytes free_inodes
    minimum_bytes=${KOSTKITA_MIN_FREE_DISK_BYTES:-}
    minimum_inodes=${KOSTKITA_MIN_FREE_INODES:-}
    [[ "$minimum_bytes" =~ ^[1-9][0-9]*$ && "$minimum_inodes" =~ ^[1-9][0-9]*$ ]] || {
        echo 'Measured KOSTKITA_MIN_FREE_DISK_BYTES and KOSTKITA_MIN_FREE_INODES are required' >&2
        return 1
    }
    docker_root=$(docker info --format '{{.DockerRootDir}}') || { echo 'Cannot identify the Docker data directory' >&2; return 1; }
    [[ -n "$docker_root" && -d "$docker_root" ]] || { echo 'Docker data directory is unavailable' >&2; return 1; }
    free_bytes=$(df -P -B1 "$docker_root" | awk 'NR == 2 { print $4 }')
    free_inodes=$(df -Pi "$docker_root" | awk 'NR == 2 { print $4 }')
    [[ "$free_bytes" =~ ^[0-9]+$ && "$free_inodes" =~ ^[0-9]+$ ]] || { echo 'Cannot measure free Docker disk space and inodes' >&2; return 1; }
    (( free_bytes >= minimum_bytes )) || { echo 'Docker filesystem is below its measured minimum free-byte threshold' >&2; return 1; }
    (( free_inodes >= minimum_inodes )) || { echo 'Docker filesystem is below its measured minimum free-inode threshold' >&2; return 1; }
}

# Reject stale CI before touching Docker, then recheck after waits/builds to
# avoid promoting an older commit after a burst of pushes to main.
check_main_head
check_build_headroom
response_file=
rollback_file=
deployment_started=false
cleanup() {
    status=$?
    if [[ "$deployment_started" == true && "$status" -ne 0 ]]; then
        echo 'Deployment verification failed; restoring the previously active application and web images' >&2
        set +e
        if [[ -n "$rollback_file" ]]; then
            rollback_compose=("${compose[@]}" -f "$rollback_file")
            "${rollback_compose[@]}" up -d --no-build --no-deps app web scheduler
            rollback_status=$?
            for service in app web scheduler; do
                id=$("${rollback_compose[@]}" ps -q "$service")
                case "$service" in
                    app|scheduler) expected_image=$previous_app_image ;;
                    web) expected_image=$previous_web_image ;;
                esac
                [[ -n "$id" ]] && [[ "$(docker inspect --format '{{.State.Running}}' "$id")" == true ]] && \
                    [[ "$(docker inspect --format '{{.Config.Image}}' "$id")" == "$expected_image" ]] || rollback_status=1
            done
            web_id=$("${rollback_compose[@]}" ps -q web)
            web_health=unknown
            for (( attempt = 0; attempt < 30 && rollback_status == 0; attempt++ )); do
                web_health=$(docker inspect --format '{{.State.Health.Status}}' "$web_id" 2>/dev/null || true)
                [[ "$web_health" == healthy ]] && break
                sleep 2
            done
            [[ "$web_health" == healthy ]] || rollback_status=1
            if [[ "$rollback_status" -eq 0 ]]; then
                echo 'Previous application images are running and web health is healthy; database and volumes were left untouched' >&2
            else
                echo 'AUTOMATIC ROLLBACK FAILED; operator recovery is required' >&2
            fi
        else
            echo 'Rollback image override is unavailable; operator recovery is required' >&2
        fi
        set -e
    fi
    [[ -z "$response_file" ]] || rm -f "$response_file"
    [[ -z "$rollback_file" ]] || rm -f "$rollback_file"
    if ! flock -u 9; then
        echo 'Could not release the host deployment lock' >&2
        [[ "$status" -ne 0 ]] || status=1
    fi
    return "$status"
}

# Preserve the Compose project and explicitly named volumes from the existing stack.
project=$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' kostkita_db)
[[ "$project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo 'Existing database Compose project cannot be verified' >&2; exit 1; }
storage_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{.Name}}{{end}}{{end}}' kostkita_app)
[[ "$storage_volume" == kostkita_storage ]] || { echo 'Existing application storage volume does not match the deployment configuration' >&2; exit 1; }
docker volume inspect "$storage_volume" >/dev/null || { echo 'The configured persistent storage volume does not exist' >&2; exit 1; }
database_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' kostkita_db)
[[ "$database_volume" == kostkita_pgdata ]] || { echo 'Existing database volume does not match the deployment configuration' >&2; exit 1; }
docker volume inspect "$database_volume" >/dev/null || { echo 'The configured database volume does not exist' >&2; exit 1; }

mkdir -p "$root/releases"
release=$(mktemp -d "$root/releases/$sha.XXXXXXXX")
git archive "$sha" | tar -xf - -C "$release"
export KOSTKITA_ENV_FILE="$env_file" KOSTKITA_RELEASE_SHA="$sha"
export KOSTKITA_IMAGE_TAG="$sha-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
compose=(docker compose --project-name "$project" --env-file "$env_file" -f "$release/compose.yaml")

compose_config=$("${compose[@]}" config --format json)
python3 -c '
import json, sys

try:
    config = json.load(sys.stdin)
    services = config["services"]
    ports = services["web"].get("ports", [])
    expected_port = len(ports) == 1 and ports[0].get("host_ip") == "192.168.0.26" and ports[0].get("published") == "8080" and ports[0].get("target") == 80 and ports[0].get("protocol") == "tcp"
    if not expected_port:
        raise SystemExit("Effective Compose web binding must be 192.168.0.26:8080/tcp")

    def has_volume(service, source, target):
        return any(volume.get("type") == "volume" and volume.get("source") == source and volume.get("target") == target for volume in services[service].get("volumes", []))

    if not has_volume("db", "kostkita_pgdata", "/var/lib/postgresql/data") or not has_volume("app", "kostkita_storage", "/var/www/html/storage") or not has_volume("scheduler", "kostkita_storage", "/var/www/html/storage"):
        raise SystemExit("Effective Compose database or application volume mapping does not match the verified deployment")

    expected_networks = {"db": {"private-data"}, "app": {"private-data", "app-network"}, "web": {"app-network"}, "scheduler": {"private-data", "app-network"}}
    for service, expected in expected_networks.items():
        if set(services[service].get("networks", {})) != expected:
            raise SystemExit("Effective Compose service networks do not match the verified deployment")
    if config.get("networks", {}).get("private-data", {}).get("internal") is not True:
        raise SystemExit("Effective Compose private-data network must remain internal")
except (KeyError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit("Rendered Compose configuration is invalid")
' <<< "$compose_config"
unset compose_config

# Each rerun gets a distinct immutable local image tag. A rerun of the same
# GitHub run must not collide with images built by an earlier attempt.
if docker image inspect "kostkita-app:$KOSTKITA_IMAGE_TAG" >/dev/null 2>&1 || docker image inspect "kostkita-web:$KOSTKITA_IMAGE_TAG" >/dev/null 2>&1; then
    echo 'Release image tag already exists' >&2
    exit 1
fi
check_main_head
"${compose[@]}" build --pull app web
for image in "kostkita-app:$KOSTKITA_IMAGE_TAG" "kostkita-web:$KOSTKITA_IMAGE_TAG"; do
    built_revision=$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")
    [[ "$built_revision" == "$sha" ]] || { echo "Built image revision mismatch for $image" >&2; exit 1; }
done
# The persistent storage volume is reused across image changes. Check that the
# non-root runtime account can write Laravel's required directories before any
# service is replaced. This command only inspects directory metadata/permissions.
if ! storage_preflight=$("${compose[@]}" run --rm --no-deps --user www-data --entrypoint sh app -c '
set -eu
for path in storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    if [ ! -d "/var/www/html/$path" ]; then
        printf "%s is missing\n" "$path" >&2
        exit 1
    fi
    if [ ! -w "/var/www/html/$path" ]; then
        printf "%s is not writable by www-data\n" "$path" >&2
        exit 1
    fi
done
'); then
    echo 'Persistent storage permissions are incompatible with the www-data runtime; no services were activated' >&2
    [[ -z "$storage_preflight" ]] || printf '%s\n' "$storage_preflight" >&2
    exit 1
fi
if ! config_preflight=$("${compose[@]}" run --rm --no-deps --entrypoint php app scripts/deploy/verify-production-config.php 2>&1); then
    if [[ "$config_preflight" == 'Production configuration preflight failed: '* && "$config_preflight" != *$'\n'* ]]; then
        printf '%s\n' "$config_preflight" >&2
    else
        echo 'Production configuration preflight failed; details are suppressed to avoid exposing environment values' >&2
    fi
    exit 1
fi
if [[ "$config_preflight" != 'Production configuration verified.' ]]; then
    echo 'Production configuration preflight returned unexpected output; details are suppressed' >&2
    exit 1
fi
unset config_preflight
# Fail closed if pending migrations exist. Production schema changes require a
# separate compatibility review and authorization; this script never migrates.
check_no_pending_migrations "${compose[@]}" run --rm --no-deps --entrypoint php app artisan migrate:status --pending=1 || exit 1
candidate_tasks=$("${compose[@]}" run --rm --no-deps --entrypoint php app artisan schedule:list --json --no-interaction) || { echo 'Could not read the candidate scheduler task registry' >&2; exit 1; }
check_scheduler_tasks printf '%s\n' "$candidate_tasks" || exit 1
# Laravel exits nonzero when --pending=1 finds a migration. The command's
# output says "No pending migrations" on success, so do not parse that word.
check_main_head

# Acquire the short activation lock after build and read-only preflights. This
# lets stale CI runs exit before contending for production while ensuring image
# snapshots and service replacement happen as one serialized operation.
exec 9>>"$root/deploy.lock" || { echo 'Could not open the host deployment lock file' >&2; exit 1; }
lock_deadline=$((SECONDS + 1200))
next_main_check=$((SECONDS + 30))
until flock -n 9; do
    if (( SECONDS >= lock_deadline )); then
        echo 'Timed out waiting for the host deployment lock' >&2
        exit 1
    fi
    if (( SECONDS >= next_main_check )); then
        check_main_head
        next_main_check=$((SECONDS + 30))
    fi
    sleep 5
done
trap cleanup EXIT
check_main_head

[[ "$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' kostkita_db)" == "$project" ]] || { echo 'Database Compose project changed during the build' >&2; exit 1; }
[[ "$(docker inspect --format '{{.State.Health.Status}}' kostkita_db)" == healthy ]] || { echo 'Existing database is not healthy' >&2; exit 1; }
check_build_headroom
check_no_pending_migrations "${compose[@]}" exec -T app php artisan migrate:status --pending=1 || exit 1
# Recheck with candidate code while holding the activation lock. This catches
# newly introduced migrations that the currently active release cannot see.
check_no_pending_migrations "${compose[@]}" run --rm --no-deps --entrypoint php app artisan migrate:status --pending=1 || exit 1
for container in kostkita_app kostkita_web kostkita_scheduler; do
    service_project=$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container")
    [[ "$service_project" == "$project" ]] || { echo "$container is not part of the verified Compose project" >&2; exit 1; }
done
previous_app_image=$(docker inspect --format '{{.Config.Image}}' kostkita_app)
previous_web_image=$(docker inspect --format '{{.Config.Image}}' kostkita_web)
previous_scheduler_image=$(docker inspect --format '{{.Config.Image}}' kostkita_scheduler)
[[ "$previous_app_image" =~ ^kostkita-app:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || { echo 'Existing application image cannot be used for rollback' >&2; exit 1; }
[[ "$previous_web_image" =~ ^kostkita-web:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || { echo 'Existing web image cannot be used for rollback' >&2; exit 1; }
[[ "$previous_scheduler_image" == "$previous_app_image" ]] || { echo 'Application and scheduler do not share the same release image' >&2; exit 1; }
for image in "$previous_app_image" "$previous_web_image"; do
    docker image inspect "$image" >/dev/null || { echo 'Existing release image is missing; rollback cannot be guaranteed' >&2; exit 1; }
done

# Record enough provenance to identify this release and its rollback targets.
rollback_file=$(mktemp "$release/rollback.XXXXXXXX")
cat > "$rollback_file" <<EOF
services:
  app:
    image: $previous_app_image
  web:
    image: $previous_web_image
  scheduler:
    image: $previous_scheduler_image
EOF
cat > "$release/release-manifest.json" <<EOF
{
  "commit_sha": "$sha",
  "github_run_id": "$GITHUB_RUN_ID",
  "github_run_attempt": "$GITHUB_RUN_ATTEMPT",
  "runner_name": "$RUNNER_NAME",
  "target_hostname": "$expected_hostname",
  "compose_project": "$project",
  "app_image": "kostkita-app:$KOSTKITA_IMAGE_TAG",
  "web_image": "kostkita-web:$KOSTKITA_IMAGE_TAG",
  "previous_app_image": "$previous_app_image",
  "previous_web_image": "$previous_web_image",
  "previous_scheduler_image": "$previous_scheduler_image"
}
EOF

# Compose does not provide an atomic multi-service switch. Keep the previous
# local image references and restore them on any failure after activation. This
# never rolls back the database, removes volumes, or prunes Docker images.
deployment_started=true
"${compose[@]}" up -d --no-build --no-deps app web scheduler
for service in app web scheduler; do
    id=$("${compose[@]}" ps -q "$service")
    [[ -n "$id" ]] || { echo "$service is missing" >&2; exit 1; }
    [[ "$(docker inspect --format '{{.State.Running}}' "$id")" == true ]] || { echo "$service is not running" >&2; exit 1; }
    [[ "$(docker inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$id")" == "$sha" ]] || { echo "$service revision mismatch" >&2; exit 1; }
done
[[ "$(docker inspect --format '{{json .Config.Cmd}}' "$("${compose[@]}" ps -q scheduler)")" == '["php","artisan","schedule:work"]' ]] || { echo 'Scheduler is not running the expected schedule:work process' >&2; exit 1; }
scheduler_tasks=$("${compose[@]}" exec -T scheduler php artisan schedule:list --json --no-interaction) || { echo 'Could not read the active scheduler task registry' >&2; exit 1; }
check_scheduler_tasks printf '%s\n' "$scheduler_tasks" || exit 1
check_no_pending_migrations "${compose[@]}" exec -T app php artisan migrate:status --pending=1 || exit 1
asset=$("${compose[@]}" exec -T app php -r '$m=json_decode(file_get_contents("public/build/manifest.json"),true,512,JSON_THROW_ON_ERROR); foreach($m as $entry){if(isset($entry["file"]) && is_string($entry["file"])){echo $entry["file"];exit;}} exit(1);')
[[ "$asset" =~ ^[a-zA-Z0-9_./-]+$ && "$asset" != *..* ]] || { echo 'Invalid Vite manifest asset' >&2; exit 1; }
expected_asset=$("${compose[@]}" exec -T app php -r 'echo hash_file("sha256", "public/build/".$argv[1]);' "$asset")
[[ "$expected_asset" =~ ^[a-f0-9]{64}$ ]] || { echo 'Cannot hash candidate asset' >&2; exit 1; }
response_file=$(mktemp)
fetch() {
    local code
    code=$(curl --fail --silent --show-error --max-time 10 --retry 5 --retry-delay 3 --header 'Cache-Control: no-cache' --output "$response_file" --write-out '%{http_code}' "$1")
    [[ "$code" == 200 ]] || { echo "$1 returned HTTP $code" >&2; return 1; }
}
for base in 'http://192.168.0.26:8080' 'https://kostkita.sequein.my.id'; do
    base=${base%/}
    for path in release.txt health/revision; do
        fetch "$base/$path?deployment_sha=$sha"
        [[ "$(cat "$response_file")" == "$sha" ]] || { echo "$base/$path revision mismatch" >&2; exit 1; }
    done
    fetch "$base/up"
    fetch "$base/build/$asset"
    actual_asset=$(sha256sum "$response_file")
    [[ "${actual_asset%% *}" == "$expected_asset" ]] || { echo "$base asset integrity mismatch" >&2; exit 1; }
done
printf 'Verified deployment %s (images tagged %s)\n' "$sha" "$KOSTKITA_IMAGE_TAG"
