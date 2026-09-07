<?php

declare(strict_types=1);

namespace Sopheak\Core\Contracts;

use Sopheak\Core\Services\OpenApiDocumentBuilder;

interface OpenApiDocumentContributorInterface
{
    public function contribute(OpenApiDocumentBuilder $document): void;
}
