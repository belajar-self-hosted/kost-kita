import os
import json
import hashlib
import shlex
import sys
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/deploy/deploy.sh'


class DeploymentSafetyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name)
        self.repo = self.base / 'repo'
        self.repo.mkdir()
        self.run_cmd(['git', 'init', '-q'], self.repo)
        self.run_cmd(['git', 'config', 'user.email', 'test@example.invalid'], self.repo)
        self.run_cmd(['git', 'config', 'user.name', 'Test'], self.repo)
        (self.repo / 'compose.yaml').write_text('services: {}\n')
        self.run_cmd(['git', 'add', 'compose.yaml'], self.repo)
        self.run_cmd(['git', 'commit', '-qm', 'fixture'], self.repo)
        self.sha = self.run_cmd(['git', 'rev-parse', 'HEAD'], self.repo).stdout.strip()
        self.root = self.base / 'root'
        self.root.mkdir()
        self.docker_root = self.base / 'docker-root'
        self.docker_root.mkdir()
        self.script = self.base / 'deploy.sh'
        script_source = SCRIPT.read_text()
        self.assertIn('root=/opt/kostkita\n', script_source)
        self.script.write_text(script_source.replace('root=/opt/kostkita\n', f'root={shlex.quote(str(self.root))}\n', 1))
        self.event_path = self.base / 'event.json'
        self.event_path.write_text(json.dumps({
            'action': 'completed',
            'repository': {'full_name': 'test/repo'},
            'workflow_run': {
                'workflow_id': 405373892,
                'name': 'tests',
                'event': 'push',
                'head_branch': 'main',
                'head_repository': {'full_name': 'test/repo'},
                'conclusion': 'success',
                'head_sha': self.sha,
            },
        }))
        self.envfile = self.root / '.env'
        self.envfile.write_text('POSTGRES_PASSWORD=fixture\n')
        self.envfile.chmod(0o600)
        self.log = self.base / 'docker.log'
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        docker = self.bin / 'docker'
        docker.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$DOCKER_LOG"
case "$*" in
  'inspect --format '*com.docker.compose.project*kostkita_db|'inspect --format '*com.docker.compose.project*kostkita_app|'inspect --format '*com.docker.compose.project*kostkita_web|'inspect --format '*com.docker.compose.project*kostkita_scheduler) echo src ;;
  'inspect --format '*Config.Image*kostkita_app|'inspect --format '*Config.Image*app-container) echo kostkita-app:previous ;;
  'inspect --format '*Config.Image*kostkita_scheduler|'inspect --format '*Config.Image*scheduler-container) echo kostkita-app:previous ;;
  'inspect --format '*Config.Image*kostkita_web|'inspect --format '*Config.Image*web-container) echo kostkita-web:previous ;;
  *Config.Cmd*)
    if [ -n "${SCHEDULER_CMD:-}" ]; then printf '%s\n' "$SCHEDULER_CMD"; else printf '%s\n' '["php","artisan","schedule:work"]'; fi ;;
  'inspect --format '*Mounts*kostkita_app) echo "${TEST_STORAGE_VOLUME:-kostkita_storage}" ;;
  'inspect --format '*Mounts*kostkita_db) echo "${TEST_DATABASE_VOLUME:-kostkita_pgdata}" ;;
  'inspect --format '*State.Running*) echo true ;;
  'inspect --format '*State.Health.Status*) echo healthy ;;
  'inspect --format '*org.opencontainers.image.revision*) echo "$KOSTKITA_RELEASE_SHA" ;;
  'volume inspect kostkita_storage'|'volume inspect kostkita_pgdata') exit 0 ;;
  'image inspect kostkita-app:previous'|'image inspect kostkita-web:previous') exit 0 ;;
  'info --format {{.DockerRootDir}}') echo "$DOCKER_ROOT" ;;
  *'image inspect --format '*org.opencontainers.image.revision*) echo "${BUILD_REVISION:-$KOSTKITA_RELEASE_SHA}" ;;
  'image inspect '*) printf '%s\n' "$*" | sed 's/^image inspect //' | grep -Fxf "$IMAGE_TAGS" >/dev/null 2>&1 ;;
  *' config --format json')
    printf '{"services":{"db":{"volumes":[{"type":"volume","source":"%s","target":"/var/lib/postgresql/data"}],"networks":{"private-data":null}},"app":{"volumes":[{"type":"volume","source":"%s","target":"/var/www/html/storage"}],"networks":{"private-data":null,"app-network":null}},"web":{"ports":[{"host_ip":"%s","published":"%s","target":80,"protocol":"tcp"}],"networks":{"app-network":null}},"scheduler":{"volumes":[{"type":"volume","source":"%s","target":"/var/www/html/storage"}],"networks":{"private-data":null,"app-network":null}}},"networks":{"private-data":{"internal":%s},"app-network":{}}}' "${TEST_COMPOSE_DB_VOLUME:-kostkita_pgdata}" "${TEST_COMPOSE_APP_VOLUME:-kostkita_storage}" "${TEST_COMPOSE_IP:-192.168.0.26}" "${TEST_COMPOSE_PORT:-8080}" "${TEST_COMPOSE_SCHEDULER_VOLUME:-kostkita_storage}" "${TEST_PRIVATE_NETWORK_INTERNAL:-true}" ;;
  *' build --pull app web') printf '%s\n' "kostkita-app:$KOSTKITA_IMAGE_TAG" "kostkita-web:$KOSTKITA_IMAGE_TAG" >> "$IMAGE_TAGS" ;;
  *' ps -q app') echo app-container ;;
  *' ps -q web') echo web-container ;;
  *' ps -q scheduler') echo scheduler-container ;;
  *' ps -q '*) echo container ;;
  *'rollback.'*' up -d --no-build --no-deps app web scheduler')
    for arg in "$@"; do case "$arg" in */rollback.*) cat "$arg" >> "$DOCKER_LOG" ;; esac; done ;;
  *' --user www-data --entrypoint sh app -c '*)
    [ "${STORAGE_PREFLIGHT_FAIL:-0}" != 1 ] || { echo 'storage/framework/cache/data is not writable by www-data' >&2; exit 1; } ;;
  *' run --rm --no-deps --entrypoint php app scripts/deploy/verify-production-config.php')
    [ "${PRODUCTION_CONFIG_FAIL:-0}" != 1 ] || { echo 'Production configuration preflight failed: APP_DEBUG, DB_URL' >&2; exit 1; }
    echo 'Production configuration verified.' ;;
  *'run --rm --no-deps --entrypoint php app artisan schedule:list --json --no-interaction')
    [ "${SCHEDULER_REGISTRY_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' '[{"command":"php artisan bookings:expire-no-show"},{"command":"php artisan billing:generate-monthly-invoices"},{"command":"php artisan deposits:mark-late-settlements"}]' ;;
  *'exec -T scheduler php artisan schedule:list --json --no-interaction')
    [ "${SCHEDULER_ACTIVE_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' '[{"command":"php artisan bookings:expire-no-show"},{"command":"php artisan billing:generate-monthly-invoices"},{"command":"php artisan deposits:mark-late-settlements"}]' ;;
  *' exec -T app php -r '*hash_file*) echo 3284d6c6228b931f0cb36146a79bd85d5e4bf02d9c3b8219a31285b80305625c ;;
  *' exec -T app php -r '*) echo assets/app.js ;;
  *' run --rm --no-deps --entrypoint php app artisan migrate:status --pending=1'*)
    [ "${MIGRATION_FAIL:-0}" != 1 ] || { echo 'Database connection failed'; exit 1; }
    if [ "${CANDIDATE_MIGRATION_PENDING_UNDER_LOCK:-0}" = 1 ] && [ -f "$DEPLOY_LOCK_PATH.held" ]; then echo 'Pending migration: 000023_example'; exit 1; fi
    if [ "${PENDING_MIGRATIONS_UNDER_LOCK:-0}" = 1 ] && [ -f "$DEPLOY_LOCK_PATH.held" ]; then echo 'Pending migration: 000023_example'; exit 1; fi
    [ "${PENDING_MIGRATIONS:-0}" != 1 ] || { echo 'Pending migration: 000023_example'; exit 1; } ;;
  *' exec -T app php artisan migrate:status --pending=1')
    [ "${MIGRATION_FAIL:-0}" != 1 ] || { echo 'Database connection failed'; exit 1; }
    if [ "${PENDING_MIGRATIONS_UNDER_LOCK:-0}" = 1 ] && [ -f "$DEPLOY_LOCK_PATH.held" ]; then echo 'Pending migration: 000023_example'; exit 1; fi
    [ "${PENDING_MIGRATIONS:-0}" != 1 ] || { echo 'Pending migration: 000023_example'; exit 1; } ;;

  *) exit 0 ;;
esac
''')
        docker.chmod(0o755)
        df = self.bin / 'df'
        df.write_text('''#!/bin/sh
case "$*" in
  *-Pi*) printf 'Filesystem Inodes IUsed IFree IUse%% Mounted on\n/dev/root 1000000 1000 %s 1%% /\n' "${FREE_INODES:-900000}" ;;
  *) printf 'Filesystem 1B-blocks Used Available Use%% Mounted on\n/dev/root 10000000000 1000000000 %s 10%% /\n' "${FREE_BYTES:-9000000000}" ;;
esac
''')
        df.chmod(0o755)
        hostname = self.bin / 'hostname'
        hostname.write_text('#!/bin/sh\necho "${TEST_HOSTNAME:-kostkita-app}"\n')
        hostname.chmod(0o755)
        ip = self.bin / 'ip'
        ip.write_text('#!/bin/sh\necho "${TEST_IP_OUTPUT:-2: eth0 inet 192.168.0.26/24 scope global eth0}"\n')
        ip.chmod(0o755)
        virt = self.bin / 'systemd-detect-virt'
        virt.write_text('#!/bin/sh\necho "${TEST_CONTAINER_TYPE:-lxc}"\n')
        virt.chmod(0o755)
        flock = self.bin / 'flock'
        flock.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$FLOCK_LOG"
case "$1" in
  -n) [ "${FLOCK_BUSY:-0}" != 1 ] || exit 1; touch "$DEPLOY_LOCK_PATH.held" ;;
  -u) rm -f "$DEPLOY_LOCK_PATH.held" ;;
  *) exit 2 ;;
esac
''')
        flock.chmod(0o755)
        python = self.bin / 'python3'
        python.write_text('''#!/bin/sh
if [ "$1" = "-c" ]; then exec "$REAL_PYTHON" "$@"; fi
if [ "$1" = "-" ]; then
  code=$(cat)
  case "$code" in
    *urllib.request*) exit "${MAIN_CHECK_FAIL:-0}" ;;
    *) printf '%s\\n' "$code" | "$REAL_PYTHON" "$@"; exit $? ;;
  esac
fi
exit "${MAIN_CHECK_FAIL:-0}"
''')
        python.chmod(0o755)
        curl = self.bin / 'curl'
        curl.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$CURL_LOG"
output=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output=$2; shift 2 ;;
    --write-out) shift 2 ;;
    *) url=$1; shift ;;
  esac
done
case "$url" in
  *"${CURL_FAIL_ON:-NO_SUCH_URL}"*) exit 1 ;;
  *release.txt*|*/health/revision*) printf '%s' "${HTTP_SHA:-$KOSTKITA_RELEASE_SHA}" > "$output" ;;
  */build/*) printf '%s' "${ASSET_BODY:-test-asset}" > "$output" ;;
  *) printf ok > "$output" ;;
esac
printf '%s' "${HTTP_STATUS:-200}"
''')
        curl.chmod(0o755)
        self.env = os.environ.copy()
        self.env.update(PATH=f'{self.bin}:{os.environ["PATH"]}', REAL_PYTHON=sys.executable, KOSTKITA_EXPECTED_HOSTNAME='kostkita-app', KOSTKITA_MIN_FREE_DISK_BYTES='1000000000', KOSTKITA_MIN_FREE_INODES='1000', RUNNER_NAME='kost-kita', DOCKER_ROOT=str(self.docker_root), DEPLOY_LOCK_PATH=str(self.root / 'deploy.lock'), DOCKER_LOG=str(self.log), FLOCK_LOG=str(self.base / 'flock.log'), IMAGE_TAGS=str(self.base / 'images.log'), GITHUB_ACTIONS='true', GITHUB_EVENT_NAME='workflow_run', GITHUB_EVENT_PATH=str(self.event_path), GITHUB_REF='refs/heads/main', GITHUB_RUN_ID='100', GITHUB_RUN_ATTEMPT='1', GITHUB_TOKEN='fixture', GITHUB_API_URL='https://api.github.com', GITHUB_REPOSITORY='test/repo', CURL_LOG=str(self.base / 'curl.log'))

    def tearDown(self):
        self.tmp.cleanup()

    @staticmethod
    def run_cmd(cmd, cwd):
        return subprocess.run(cmd, cwd=cwd, check=True, capture_output=True, text=True)

    def deploy(self):
        return subprocess.run(['bash', str(self.script), self.sha, str(self.envfile)], cwd=self.repo, env=self.env, capture_output=True, text=True)

    def test_wrong_hostname_fails_before_docker_mutation(self):
        self.env['TEST_HOSTNAME'] = 'wrong-host'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('hostname does not match', result.stderr)
        self.assertFalse(self.log.exists())

    def test_wrong_target_ip_fails_before_docker_mutation(self):
        self.env['TEST_IP_OUTPUT'] = '2: eth0 inet 192.168.0.99/24 scope global eth0'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('expected production IP', result.stderr)
        self.assertFalse(self.log.exists())

    def test_wrong_runner_name_fails_before_docker_mutation(self):
        self.env['RUNNER_NAME'] = 'other-runner'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('runner name does not match', result.stderr)
        self.assertFalse(self.log.exists())

    def test_env_file_symlink_is_rejected_before_docker_mutation(self):
        self.envfile.unlink()
        target = self.root / 'actual.env'
        target.write_text('POSTGRES_PASSWORD=fixture\n')
        target.chmod(0o600)
        self.envfile.symlink_to(target)

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Regular absolute production environment file required', result.stderr)
        self.assertFalse(self.log.exists())

    def test_env_file_permissions_are_enforced(self):
        self.envfile.chmod(0o640)

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('mode 0600', result.stderr)
        self.assertFalse(self.log.exists())

    def test_build_stops_when_disk_headroom_is_below_measured_minimum(self):
        self.env['FREE_BYTES'] = '999999999'

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('below its measured minimum free-byte threshold', result.stderr)
        self.assertNotIn(' build --pull app web', self.log.read_text())

    def test_build_stops_when_inode_headroom_is_below_measured_minimum(self):
        self.env['FREE_INODES'] = '999'

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('below its measured minimum free-inode threshold', result.stderr)
        self.assertNotIn(' build --pull app web', self.log.read_text())

    def test_non_lxc_runner_fails_before_docker_mutation(self):
        self.env['TEST_CONTAINER_TYPE'] = 'docker'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not inside an LXC', result.stderr)
        self.assertFalse(self.log.exists())

    def test_deployment_rejects_direct_push_and_manual_dispatch(self):
        for event_name in ('push', 'workflow_dispatch'):
            with self.subTest(event_name=event_name):
                self.env['GITHUB_EVENT_NAME'] = event_name
                result = self.deploy()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('workflow_run on main', result.stderr)
                self.assertFalse(self.log.exists())

    def test_deployment_rejects_non_main_ref(self):
        self.env['GITHUB_REF'] = 'refs/heads/feature'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('workflow_run on main', result.stderr)
        self.assertFalse(self.log.exists())

    def test_deployment_rejects_non_github_execution(self):
        self.env.pop('GITHUB_ACTIONS')
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('workflow_run on main', result.stderr)
        self.assertFalse(self.log.exists())

    def test_deployment_rejects_an_untrusted_workflow_run_id(self):
        payload = json.loads(self.event_path.read_text())
        payload['workflow_run']['workflow_id'] = 999999
        self.event_path.write_text(json.dumps(payload))
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not the successful trusted tests run', result.stderr)
        self.assertFalse(self.log.exists())

    def test_deployment_rejects_workflow_run_for_another_commit(self):
        payload = json.loads(self.event_path.read_text())
        payload['workflow_run']['head_sha'] = '0' * 40
        self.event_path.write_text(json.dumps(payload))
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not the successful trusted tests run', result.stderr)
        self.assertFalse(self.log.exists())

    def test_deployment_rejects_workflow_run_from_another_repository(self):
        payload = json.loads(self.event_path.read_text())
        payload['workflow_run']['head_repository']['full_name'] = 'attacker/fork'
        self.event_path.write_text(json.dumps(payload))
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not the successful trusted tests run', result.stderr)
        self.assertFalse(self.log.exists())

    def test_effective_compose_binding_must_match_target_lxc(self):
        self.env['TEST_COMPOSE_IP'] = '0.0.0.0'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('must be 192.168.0.26:8080/tcp', result.stderr)
        self.assertNotIn(' build --pull app web', self.log.read_text())

    def test_existing_database_volume_must_match_compose(self):
        self.env['TEST_DATABASE_VOLUME'] = 'unexpected-volume'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('database volume does not match', result.stderr)
        self.assertNotIn(' config --format json', self.log.read_text())

    def test_effective_compose_volume_mismatch_fails_closed(self):
        self.env['TEST_COMPOSE_DB_VOLUME'] = 'unexpected-database-volume'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('database or application volume mapping', result.stderr)
        self.assertNotIn(' build --pull app web', self.log.read_text())

    def test_private_database_network_must_remain_internal(self):
        self.env['TEST_PRIVATE_NETWORK_INTERNAL'] = 'false'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('private-data network must remain internal', result.stderr)
        self.assertNotIn(' build --pull app web', self.log.read_text())

    def test_health_url_environment_cannot_redirect_release_verification(self):
        self.env['KOSTKITA_HEALTH_URL'] = 'https://attacker.invalid'
        self.env['KOSTKITA_PUBLIC_URL'] = 'https://attacker.invalid'
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr + (self.log.read_text() if self.log.exists() else ''))
        calls = (self.base / 'curl.log').read_text()
        self.assertNotIn('attacker.invalid', calls)
        self.assertIn('http://192.168.0.26:8080/up', calls)
        self.assertIn('https://kostkita.sequein.my.id/up', calls)

    def test_pending_migrations_abort_before_activation(self):
        self.env['MIGRATION_FAIL'] = '1'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(self.log.exists(), result.stdout + result.stderr)
        calls = self.log.read_text()
        self.assertIn('migrate:status --pending=1', calls)
        self.assertNotIn(' up ', calls)
        self.assertFalse((self.root / 'deploy.lock.held').exists())

    def test_flock_lock_is_released_after_successful_deployment(self):
        result = self.deploy()

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue((self.root / 'deploy.lock').is_file())
        self.assertFalse((self.root / 'deploy.lock.held').exists())
        lock_calls = (self.base / 'flock.log').read_text()
        self.assertIn('-n ', lock_calls)
        self.assertIn('-u ', lock_calls)

    def test_reported_pending_migration_aborts_before_activation(self):
        self.env['PENDING_MIGRATIONS'] = '1'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Pending database migrations detected', result.stderr)
        self.assertNotIn(' up ', self.log.read_text())

    def test_migration_state_is_rechecked_after_activation_lock(self):
        self.env['CANDIDATE_MIGRATION_PENDING_UNDER_LOCK'] = '1'

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        calls = self.log.read_text()
        self.assertIn(' run --rm --no-deps --entrypoint php app artisan migrate:status --pending=1', calls)
        self.assertIn(' exec -T app php artisan migrate:status --pending=1', calls)
        self.assertGreaterEqual(calls.count(' run --rm --no-deps --entrypoint php app artisan migrate:status --pending=1'), 2)
        self.assertNotIn(' up -d --no-build --no-deps app web scheduler', calls)

    def test_deployment_never_runs_database_migrations_or_destructive_cleanup(self):
        source = SCRIPT.read_text()

        for forbidden in (
            'artisan migrate --force',
            'migrate:fresh',
            'migrate:rollback',
            'down -v',
            'docker system prune',
            'docker image prune',
            'docker volume rm',
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)

    def test_storage_permission_mismatch_aborts_before_activation(self):
        self.env['STORAGE_PREFLIGHT_FAIL'] = '1'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('permissions are incompatible', result.stderr)
        self.assertIn('storage/framework/cache/data is not writable', result.stderr)
        self.assertNotIn(' up ', self.log.read_text())

    def test_production_configuration_failure_stops_before_activation_without_secret_values(self):
        self.env['PRODUCTION_CONFIG_FAIL'] = '1'
        self.env['APP_KEY'] = 'do-not-print-this-secret'

        result = self.deploy()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('APP_DEBUG', result.stderr)
        self.assertIn('DB_URL', result.stderr)
        self.assertNotIn('do-not-print-this-secret', result.stdout + result.stderr)
        self.assertNotIn(' up ', self.log.read_text())

    def test_effective_production_configuration_is_checked_before_activation(self):
        result = self.deploy()

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        calls = self.log.read_text()
        self.assertIn('scripts/deploy/verify-production-config.php', calls)
        self.assertLess(calls.index('scripts/deploy/verify-production-config.php'), calls.index(' up -d --no-build --no-deps app web scheduler'))

    def test_built_images_must_carry_the_candidate_commit_label(self):
        self.env['BUILD_REVISION'] = '0' * 40
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Built image revision mismatch', result.stderr)
        self.assertNotIn(' up ', self.log.read_text())

    def test_rerun_uses_a_new_image_tag_for_each_attempt(self):
        self.env['MIGRATION_FAIL'] = '1'
        first = self.deploy()
        self.assertNotEqual(first.returncode, 0)
        self.assertIn(f'kostkita-app:{self.sha}-100-1', self.log.read_text())

        self.env.pop('MIGRATION_FAIL')
        self.env['GITHUB_RUN_ATTEMPT'] = '2'
        second = self.deploy()
        self.assertEqual(second.returncode, 0, second.stdout + second.stderr)
        self.assertIn(f'kostkita-app:{self.sha}-100-2', self.log.read_text())

    def test_failed_health_check_restores_prior_images_without_db_or_volume_operations(self):
        self.env['CURL_FAIL_ON'] = 'https://kostkita.sequein.my.id/build/'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        calls = self.log.read_text()
        self.assertIn(' up -d --no-build --no-deps app web scheduler', calls)
        self.assertIn('rollback.', calls)
        self.assertIn('image: kostkita-app:previous', calls)
        self.assertNotIn('volume rm', calls)
        self.assertNotIn('prune', calls)

    def test_successful_deployment_writes_release_provenance_manifest(self):
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        release = next((self.root / 'releases').iterdir())
        manifest = json.loads((release / 'release-manifest.json').read_text())
        self.assertEqual(manifest['commit_sha'], self.sha)
        self.assertEqual(manifest['github_run_id'], '100')
        self.assertEqual(manifest['github_run_attempt'], '1')
        self.assertEqual(manifest['app_image'], f'kostkita-app:{self.sha}-100-1')
        self.assertEqual(manifest['previous_app_image'], 'kostkita-app:previous')
        self.assertEqual(manifest['previous_scheduler_image'], 'kostkita-app:previous')

    def test_outdated_main_never_activates(self):
        self.env['MAIN_CHECK_FAIL'] = '1'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())

    def test_failed_public_asset_check_fails_deployment(self):
        self.env['CURL_FAIL_ON'] = 'https://kostkita.sequein.my.id/build/'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(' up -d --no-build --no-deps app web scheduler', self.log.read_text())
        self.assertIn('https://kostkita.sequein.my.id/build/', (self.base / 'curl.log').read_text())

    def test_healthy_release_checks_local_and_public_assets(self):
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        calls = (self.base / 'curl.log').read_text()
        self.assertIn('http://192.168.0.26:8080/up', calls)
        self.assertIn('http://192.168.0.26:8080/build/assets/app.js', calls)
        self.assertIn('https://kostkita.sequein.my.id/up', calls)
        self.assertIn('https://kostkita.sequein.my.id/build/assets/app.js', calls)
        self.assertIn('https://kostkita.sequein.my.id/release.txt', calls)
        self.assertIn('https://kostkita.sequein.my.id/health/revision', calls)


    def test_stale_public_sha_fails_deployment(self):
        self.env['HTTP_SHA'] = '0' * 40
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('revision mismatch', result.stderr)

    def test_redirected_health_is_rejected(self):
        self.env['HTTP_STATUS'] = '302'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)

    def test_altered_asset_is_rejected(self):
        self.env['ASSET_BODY'] = 'wrong asset'
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('asset integrity', result.stderr)

class ComposeConfigurationTest(unittest.TestCase):
    def test_explicit_environment_file_wins_over_stale_source_env(self):
        repository = SCRIPT.parents[2]
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            source = base / 'source'
            source.mkdir()
            (source / 'compose.yaml').write_text((repository / 'compose.yaml').read_text())
            (source / '.env').write_text('APP_ENV=stale\n')
            external = base / 'production.env'
            external.write_text('POSTGRES_PASSWORD=fixture\nAPP_ENV=production\n')
            env = os.environ.copy()
            env.update(KOSTKITA_ENV_FILE=str(external), KOSTKITA_RELEASE_SHA='a' * 40, KOSTKITA_IMAGE_TAG='release-1')
            result = subprocess.run(['docker', 'compose', '--env-file', str(external), 'config', '--format', 'json'], cwd=source, env=env, check=True, capture_output=True, text=True)
            services = json.loads(result.stdout)['services']
            self.assertEqual(services['app']['environment']['APP_ENV'], 'production')
            self.assertEqual(services['scheduler']['environment']['APP_ENV'], 'production')
            self.assertEqual(services['app']['image'], 'kostkita-app:release-1')
            self.assertEqual(services['web']['image'], 'kostkita-web:release-1')
            self.assertEqual(services['scheduler']['image'], 'kostkita-app:release-1')
            self.assertEqual(services['app']['build']['args']['KOSTKITA_RELEASE_SHA'], 'a' * 40)


if __name__ == '__main__':
    unittest.main()
