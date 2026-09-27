<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

class PackageTableIdTypeUuidTest extends PackageTableGovernedIdTypeTestCase
{
    protected function idType(): string
    {
        return 'uuid';
    }
}
