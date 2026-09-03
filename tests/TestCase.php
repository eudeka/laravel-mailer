<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Tests;

use EmailProvider\EmailProvider\EmailProviderServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EmailProviderServiceProvider::class,
        ];
    }
}
