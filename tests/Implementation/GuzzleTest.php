<?php declare(strict_types=1);

namespace Tests\Implementation;

use GuzzleHttp\Psr7\HttpFactory;
use Tests\ServerRequestFactoryTestCase;

final class GuzzleTest extends ServerRequestFactoryTestCase
{

    protected function createFactories(): array
    {
        $factory = new HttpFactory();

        return [$factory, $factory, $factory, $factory];
    }
}
