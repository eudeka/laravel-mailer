<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests;

use Eudeka\LaravelMailer\LaravelMailerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelMailerServiceProvider::class,
        ];
    }
}
