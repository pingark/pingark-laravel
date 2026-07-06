<?php

namespace PingArk\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use PingArk\Laravel\Facades\PingArk;
use PingArk\Laravel\PingArkServiceProvider;

/**
 * Base test case: boots a minimal Laravel app (via Orchestra Testbench) with the
 * plugin's service provider and PingArk facade registered, and a known ping
 * configuration so the HTTP-faked assertions have stable URLs to match.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Register the plugin's service provider with the test application.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PingArkServiceProvider::class];
    }

    /**
     * Register the PingArk facade alias with the test application.
     *
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['PingArk' => PingArk::class];
    }

    /**
     * Define the plugin config used across the suite.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pingark', [
            'enabled' => true,
            'base_url' => 'https://pingark.test',
            'api_url' => 'https://pingark.test',
            'ping_key' => 'projkey123',
            'api_key' => 'pa_test_key',
            'default_grace' => 600,
            'timeout' => 5,
            'user_agent' => 'PingArk-Laravel',
            'default_check' => null,
        ]);
    }
}
