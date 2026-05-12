<?php

namespace Sopheak\Core\Tests\Fixtures\AttributeDiscovery;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Attributes\RecordGlobalFunction;
use Sopheak\Core\Attributes\RecordTable;

#[RecordTable(table: 'invoices', pmsName: 'invoices', hasTenantId: false)]
class InvoiceAttributeResource
{
    #[RecordFunction(name: 'sync', httpMethod: ['POST'], pmsName: 'invoice.sync')]
    public static function sync(Request $request): array
    {
        return ['ok' => true];
    }

    #[RecordFunction(name: 'rebuild-index', httpMethod: ['POST'], pmsName: 'invoice.rebuild_index')]
    public static function rebuildIndex(Request $request): array
    {
        return ['ok' => true];
    }

    #[RecordGlobalFunction(name: 'health', httpMethod: ['GET'], isPublic: true)]
    public static function health(Request $request): array
    {
        return ['status' => 'ok'];
    }
}
