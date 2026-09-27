<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

class PackageTableIdTypeIntegerTest extends PackageTableGovernedIdTypeTestCase
{
    protected function idType(): string
    {
        return 'integer';
    }
}
