<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Servers;

final class SchemaServer extends CatalogServer
{
    protected bool $schemaOnly = true;
}
