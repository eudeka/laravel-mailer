<?php

declare(strict_types=1);

namespace EmailProviderX\EmailProviderX\Tests;

use EmailProviderX\EmailProviderX\EmailProviderXServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EmailProviderXServiceProvider::class,
        ];
    }
}
