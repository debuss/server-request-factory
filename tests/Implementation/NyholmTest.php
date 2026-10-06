<?php declare(strict_types=1);

namespace Tests\Implementation;

use Nyholm\Psr7\Factory\Psr17Factory;
use Tests\ServerRequestFactoryTestCase;

final class NyholmTest extends ServerRequestFactoryTestCase
{

    protected function createFactories(): array
    {
        $factory = new Psr17Factory();

        return [$factory, $factory, $factory, $factory];
    }
}
