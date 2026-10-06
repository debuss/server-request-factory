<?php declare(strict_types=1);

namespace Tests\Implementation;

use Laminas\Diactoros\{ServerRequestFactory, StreamFactory, UploadedFileFactory, UriFactory};
use Tests\ServerRequestFactoryTestCase;

final class DiactorosTest extends ServerRequestFactoryTestCase
{

    protected function createFactories(): array
    {
        return [new ServerRequestFactory(), new UriFactory(), new UploadedFileFactory(), new StreamFactory()];
    }
}
