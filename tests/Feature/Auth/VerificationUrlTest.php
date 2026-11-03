<?php

namespace Tests\Feature\Auth;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerificationUrlTest extends TestCase
{
    public function test_production_signed_urls_use_the_configured_public_origin(): void
    {
        config(['app.url' => 'https://kostkita.sequein.my.id']);

        URL::forceRootUrl(null);
        URL::forceScheme(null);

        (new AppServiceProvider($this->app))->boot();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(10),
            ['id' => 1, 'hash' => sha1('owner@example.test')],
        );

        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('kostkita.sequein.my.id', parse_url($url, PHP_URL_HOST));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }
}
