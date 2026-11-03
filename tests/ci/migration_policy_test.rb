# frozen_string_literal: true

require 'json'
require 'fileutils'
require 'minitest/autorun'
require 'open3'
require 'pathname'
require 'tmpdir'

class MigrationPolicyTest < Minitest::Test
  SCRIPT = Pathname.new(__dir__).join('../../scripts/ci/check-migration-immutability.sh').expand_path

  def setup
    @directory = Dir.mktmpdir('kostkita-migration-policy-')
    run_git('init', '-q')
    run_git('config', 'user.email', 'ci@example.invalid')
    run_git('config', 'user.name', 'CI Test')
    FileUtils.mkdir_p(File.join(@directory, 'database/migrations'))
    File.write(File.join(@directory, 'database/migrations/2026_01_01_000000_create_example.php'), "<?php\n")
    commit('baseline')
    @base_sha = run_git('rev-parse', 'HEAD').strip
    @event_path = File.join(@directory, 'event.json')
  end

  def teardown
    FileUtils.remove_entry(@directory) if @directory && File.exist?(@directory)
  end

  def test_editing_an_existing_migration_fails_closed
    File.write(File.join(@directory, 'database/migrations/2026_01_01_000000_create_example.php'), "<?php\n// changed\n")
    commit('edit historical migration')
    write_event('push', 'before' => @base_sha)

    status, output = run_policy('push')

    refute status.success?
    assert_includes output, 'Existing database migrations are immutable'
  end

  def test_adding_a_new_migration_is_allowed
    File.write(File.join(@directory, 'database/migrations/2026_01_02_000000_add_example_column.php'), "<?php\n")
    commit('add a new migration')
    write_event('pull_request', 'pull_request' => { 'base' => { 'sha' => @base_sha } })

    status, output = run_policy('pull_request')

    assert status.success?, output
    assert_includes output, 'Existing database migrations are unchanged'
  end

  def test_replacing_an_existing_migration_with_a_symlink_fails_closed
    migration = File.join(@directory, 'database/migrations/2026_01_01_000000_create_example.php')
    File.unlink(migration)
    File.symlink('/tmp/elsewhere.php', migration)
    commit('replace historical migration with symlink')
    write_event('push', 'before' => @base_sha)

    status, output = run_policy('push')

    refute status.success?
    assert_includes output, 'Existing database migrations are immutable'
    assert_includes output, "T\tdatabase/migrations/2026_01_01_000000_create_example.php"
  end

  def test_missing_trusted_base_commit_blocks_the_policy
    write_event('push', 'before' => '0' * 40)

    status, output = run_policy('push')

    refute status.success?
    assert_includes output, 'Trusted migration policy base commit'
  end

  private

  def run_git(*arguments)
    stdout, stderr, status = Open3.capture3('git', *arguments, chdir: @directory)
    raise "git #{arguments.join(' ')} failed: #{stderr}" unless status.success?

    stdout
  end

  def commit(message)
    run_git('add', '.')
    run_git('commit', '-qm', message)
  end

  def write_event(name, payload)
    File.write(@event_path, JSON.generate(payload))
  end

  def run_policy(event_name)
    stdout, stderr, status = Open3.capture3(
      { 'GITHUB_EVENT_NAME' => event_name, 'GITHUB_EVENT_PATH' => @event_path },
      'bash', SCRIPT.to_s,
      chdir: @directory
    )
    [status, stdout + stderr]
  end
end
