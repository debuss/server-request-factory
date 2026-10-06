<?php declare(strict_types=1);

namespace Tests\Implementation;

use Slim\Psr7\Factory\{ServerRequestFactory, StreamFactory, UploadedFileFactory, UriFactory};
use Tests\ServerRequestFactoryTestCase;

final class SlimTest extends ServerRequestFactoryTestCase
{

    protected function createFactories(): array
    {
        return [new ServerRequestFactory(), new UriFactory(), new UploadedFileFactory(), new StreamFactory()];
    }
}
