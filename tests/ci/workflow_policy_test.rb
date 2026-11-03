# frozen_string_literal: true

require 'minitest/autorun'
require 'yaml'

class WorkflowPolicyTest < Minitest::Test
  def setup
    @workflow = YAML.safe_load_file(File.expand_path('../../.github/workflows/deploy.yml', __dir__))
    @triggers = @workflow.fetch(true) # Psych parses YAML 1.1's `on` as true.
    @deploy = @workflow.fetch('jobs').fetch('deploy')
  end

  def test_only_successful_main_push_from_this_repository_with_ci_is_eligible
    assert_equal ['workflow_run'], @triggers.keys
    assert_equal ['tests'], @triggers.fetch('workflow_run').fetch('workflows')
    assert_equal ['completed'], @triggers.fetch('workflow_run').fetch('types')
    assert_equal ['main'], @triggers.fetch('workflow_run').fetch('branches')
    refute @triggers.key?('workflow_dispatch'), 'manual dispatch must not bypass CI'
    condition = @deploy.fetch('if')
    assert_includes condition, "github.event.workflow_run.conclusion == 'success'"
    assert_includes condition, "github.event.workflow_run.event == 'push'"
    assert_includes condition, "github.event.workflow_run.head_branch == 'main'"
    assert_includes condition, 'github.event.workflow_run.head_repository.full_name == github.repository'
    assert_includes condition, 'github.event.workflow_run.workflow_id == 405373892'
    assert_includes condition, "github.event.workflow_run.name == 'tests'"
    assert_includes condition, "vars.KOSTKITA_DEPLOYMENT_ENABLED == 'true'"
    assert_equal ['deploy'], @workflow.fetch('jobs').keys
    expected = [
      "github.event.workflow_run.conclusion == 'success'",
      "github.event.workflow_run.event == 'push'",
      "github.event.workflow_run.head_branch == 'main'",
      'github.event.workflow_run.head_repository.full_name == github.repository',
      'github.event.workflow_run.workflow_id == 405373892',
      "github.event.workflow_run.name == 'tests'",
      "vars.KOSTKITA_DEPLOYMENT_ENABLED == 'true'"
    ]
    assert_equal expected.sort, condition.split('&&').map(&:strip).sort
  end

  def test_deployment_requires_target_runner_and_protected_environment
    assert_equal ['self-hosted', 'linux', 'x64', 'kostkita'], @deploy.fetch('runs-on')
    assert_equal 'production', @deploy.fetch('environment')
    assert_equal({ 'contents' => 'read' }, @workflow.fetch('permissions'))
    assert_equal '${{ vars.KOSTKITA_EXPECTED_HOSTNAME }}', @deploy.fetch('env').fetch('KOSTKITA_EXPECTED_HOSTNAME')
    assert_equal '${{ vars.KOSTKITA_MIN_FREE_DISK_BYTES }}', @deploy.fetch('env').fetch('KOSTKITA_MIN_FREE_DISK_BYTES')
    assert_equal '${{ vars.KOSTKITA_MIN_FREE_INODES }}', @deploy.fetch('env').fetch('KOSTKITA_MIN_FREE_INODES')
    refute @workflow.fetch('jobs').key?('deployment_blocked')
    refute_includes File.read(File.expand_path('../../.github/workflows/deploy.yml', __dir__)), 'ghcr.io'
  end

  def test_ci_runs_deployment_regression_suite
    ci = YAML.safe_load_file(File.expand_path('../../.github/workflows/tests.yml', __dir__))
    steps = ci.fetch('jobs').fetch('ci').fetch('steps')
    assert steps.any? { |step| step.fetch('run', '').include?("python3 -m unittest discover -s tests/deployment -p 'test_*.py'") }
  end

  def test_ci_runs_pinned_workflow_syntax_validation_with_narrow_queue_exception
    ci = YAML.safe_load_file(File.expand_path('../../.github/workflows/tests.yml', __dir__))
    steps = ci.fetch('jobs').fetch('ci').fetch('steps')
    install = steps.find { |step| step.fetch('run', '').include?('actionlint@v1.7.12') }
    validate = steps.find { |step| step.fetch('run', '').include?('actionlint .github/workflows/*.yml') }
    refute_nil install
    refute_nil validate

    config = YAML.safe_load_file(File.expand_path('../../.github/actionlint.yaml', __dir__))
    assert_equal ['kostkita'], config.fetch('self-hosted-runner').fetch('labels')
    ignores = config.fetch('paths').fetch('.github/workflows/deploy.yml').fetch('ignore')
    assert_equal ['unexpected key "queue" for "concurrency" section'], ignores
  end

  def test_ci_rejects_edits_to_historical_migrations
    ci = YAML.safe_load_file(File.expand_path('../../.github/workflows/tests.yml', __dir__))
    steps = ci.fetch('jobs').fetch('ci').fetch('steps')
    checkout = steps.find { |step| step.fetch('uses', '').start_with?('actions/checkout@') }
    migration_check = steps.find { |step| step.fetch('run', '').include?('tests/ci/migration_policy_test.rb') }

    assert_equal 0, checkout.fetch('with').fetch('fetch-depth')
    assert_equal false, checkout.fetch('with').fetch('persist-credentials')
    refute_nil migration_check
  end

  def test_postgres_acceptance_uses_only_a_dedicated_github_service
    ci = YAML.safe_load_file(File.expand_path('../../.github/workflows/tests.yml', __dir__))
    job = ci.fetch('jobs').fetch('ci')
    assert_equal 'ubuntu-24.04', job.fetch('runs-on')
    assert job.key?('services'), 'CI must provide its own disposable PostgreSQL service'
    postgres = job.fetch('services').fetch('postgres')
    assert_equal 'postgres:16', postgres.fetch('image')
    assert_equal ['5432/tcp'], postgres.fetch('ports')
    step = job.fetch('steps').find { |item| item.fetch('run', '').include?('phpunit.postgres.xml') }
    refute_nil step
    env = step.fetch('env')
    assert_equal '127.0.0.1', env.fetch('DB_HOST')
    assert_equal "${{ job.services.postgres.ports['5432'] }}", env.fetch('DB_PORT')
    assert_equal 'kostkita_ci', env.fetch('DB_DATABASE')
    assert_equal env.fetch('DB_DATABASE'), env.fetch('KOSTKITA_PG_TEST_DATABASE')
    assert_equal 'pgsql', env.fetch('DB_CONNECTION')
    assert_equal '', env.fetch('DB_URL')
    assert_includes step.fetch('run'), 'php artisan migrate --force --no-interaction'
  end

  def test_ci_uses_locked_dependencies_and_disposable_local_setup
    ci = YAML.safe_load_file(File.expand_path('../../.github/workflows/tests.yml', __dir__))
    steps = ci.fetch('jobs').fetch('ci').fetch('steps')
    runs = steps.map { |step| step.fetch('run', '') }

    assert runs.any? { |run| run.include?('composer install --no-interaction --prefer-dist --no-progress') }
    assert runs.any? { |run| run.include?('touch database/database.sqlite') }
    assert runs.any? { |run| run.include?('npm ci') }
    assert runs.any? { |run| run.include?('npm run build') }
    refute runs.any? { |run| run.include?('composer setup') }, 'the setup script uses npm install instead of enforcing the lockfile'
  end

  def test_all_deployments_share_one_group_and_run_the_tested_checkout
    concurrency = @workflow.fetch('concurrency')
    assert_equal 'kostkita-production-deploy', concurrency.fetch('group')
    assert_equal false, concurrency.fetch('cancel-in-progress')
    assert_equal 'max', concurrency.fetch('queue')
    checkouts = @deploy.fetch('steps').select { |step| step.fetch('uses', '').start_with?('actions/checkout@') }
    assert checkouts.any? do |step|
      options = step.fetch('with', {})
      options['ref'] == '${{ github.event.workflow_run.head_sha }}' && options['path'] == 'candidate'
    end
    assert checkouts.any? { |step| step.fetch('with', {})['ref'] == 'main' }
    assert checkouts.all? { |step| step.fetch('with', {})['persist-credentials'] == false }
    script = @deploy.fetch('steps').find { |step| step.fetch('run', '').include?('scripts/deploy/deploy.sh') }
    refute_nil script
    assert_includes script.fetch('run'), '"$CANDIDATE_SHA"'
    assert_equal 'candidate', script.fetch('working-directory')
  end
end
