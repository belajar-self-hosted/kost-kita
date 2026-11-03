# frozen_string_literal: true

require 'minitest/autorun'
require 'yaml'

class StoragePolicyTest < Minitest::Test
  def test_application_and_scheduler_run_as_the_unprivileged_application_user
    compose = YAML.safe_load_file(File.expand_path('../../compose.yaml', __dir__))

    assert_equal 'www-data', compose.fetch('services').fetch('app').fetch('user')
    assert_equal 'www-data', compose.fetch('services').fetch('scheduler').fetch('user')
  end

  def test_runtime_entrypoint_checks_write_access_without_changing_volume_permissions
    entrypoint = File.read(File.expand_path('../../docker/entrypoint.sh', __dir__))

    assert_includes entrypoint, '[ ! -w "$directory" ]'
    refute_match(/\b(?:chown|chmod)\b/, entrypoint)
    refute_match(/\bmkdir\b/, entrypoint)
  end

  def test_docker_image_sets_initial_directory_ownership_during_build
    dockerfile = File.read(File.expand_path('../../Dockerfile', __dir__))

    assert_match(/RUN chown -R www-data:www-data \/var\/www\/html\/storage \/var\/www\/html\/bootstrap\/cache/, dockerfile)
  end

  def test_release_revision_response_is_not_cacheable
    nginx = File.read(File.expand_path('../../docker/nginx/default.conf', __dir__))

    assert_match(/location = \/release\.txt\s*\{[^}]*Cache-Control "no-store, private" always;/m, nginx)
  end
end
