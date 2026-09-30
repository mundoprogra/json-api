<?php

namespace MundoProgra\JsonApi\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MundoProgra\JsonApi\JsonApi
 */
class JsonApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MundoProgra\JsonApi\JsonApi::class;
    }
}
