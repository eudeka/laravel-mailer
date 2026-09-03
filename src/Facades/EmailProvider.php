<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \EmailProvider\EmailProvider\EmailProvider
 */
class EmailProvider extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EmailProvider\EmailProvider\EmailProvider::class;
    }
}
