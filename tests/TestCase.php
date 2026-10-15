<?php

namespace Dashed\DashedEcommercePaynl\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Database\Eloquent\Factories\Factory;
use Dashed\DashedEcommercePaynl\DashedEcommercePaynlServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Dashed\\DashedEcommercePaynl\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return array_values(array_unique(array_merge(
            $this->discoveredPackageProviders(),
            [DashedEcommercePaynlServiceProvider::class]
        )));
    }

    /**
     * Testbench skips Laravel's package discovery, so collect the providers
     * every installed package declares in composer.json extra.laravel.providers.
     */
    protected function discoveredPackageProviders(): array
    {
        $manifest = __DIR__.'/../vendor/composer/installed.json';

        if (! is_file($manifest)) {
            return [];
        }

        $installed = json_decode((string) file_get_contents($manifest), true);
        $providers = [];

        foreach ($installed['packages'] ?? $installed as $package) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                if (class_exists($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_dashed-ecommerce-paynl_table.php.stub';
        $migration->up();
        */
    }
}
