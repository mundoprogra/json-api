<?php

namespace MundoProgra\JsonApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use MundoProgra\JsonApi\Commands\JsonApiCommand;

class JsonApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('json-api')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_json_api_table')
            ->hasCommand(JsonApiCommand::class);
    }
}
