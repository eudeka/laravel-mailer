<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Facades;

use Eudeka\LaravelMailer\LaravelMailer as MailerService;
use Illuminate\Support\Facades\Facade;

/**
 * @see MailerService
 */
class LaravelMailer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailerService::class;
    }
}
