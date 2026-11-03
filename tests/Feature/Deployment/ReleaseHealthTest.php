<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

class ReleaseHealthTest extends TestCase
{
    public function test_release_endpoint_returns_the_application_image_revision(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kostkita-release-');
        $revision = str_repeat('a', 40);
        file_put_contents($file, $revision."\n");
        config(['app.release_file' => $file]);

        try {
            $this->get('/health/revision')
                ->assertOk()
                ->assertSeeText($revision)
                ->assertHeader('Cache-Control', 'no-store, private');
        } finally {
            unlink($file);
        }
    }

    public function test_release_endpoint_rejects_missing_or_invalid_revision(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kostkita-release-');
        file_put_contents($file, 'not-a-revision');
        config(['app.release_file' => $file]);

        try {
            $this->get('/health/revision')->assertServiceUnavailable();
            unlink($file);
            $this->get('/health/revision')->assertServiceUnavailable();
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
