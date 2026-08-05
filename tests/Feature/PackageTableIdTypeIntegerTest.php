<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

class PackageTableIdTypeIntegerTest extends PackageTableGovernedIdTypeTest
{
    protected function idType(): string
    {
        return 'integer';
    }
}
