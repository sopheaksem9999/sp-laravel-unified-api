<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Fixtures\AttributeDiscovery;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Attributes\RecordGlobalFunction;
use Sopheak\Core\Attributes\RecordTable;

#[RecordTable(pmsName: 'invoices', table: 'invoices', hasTenantId: false)]
class InvoiceAttributeResource
{
    /**
     * @return array<string, bool>
     */
    #[RecordFunction(name: 'sync', httpMethod: ['POST'], pmsName: 'invoice.sync')]
    public static function sync(Request $request): array
    {
        return ['ok' => true];
    }

    /**
     * @return array<string, bool>
     */
    #[RecordFunction(name: 'rebuild-index', httpMethod: ['POST'], pmsName: 'invoice.rebuild_index')]
    public static function rebuildIndex(Request $request): array
    {
        return ['ok' => true];
    }

    /**
     * @return array<string, string>
     */
    #[RecordGlobalFunction(name: 'health', httpMethod: ['GET'], isPublic: true)]
    public static function health(Request $request): array
    {
        return ['status' => 'ok'];
    }

    /**
     * @return array<string, bool>
     */
    #[RecordFunction(name: 'archive', displayName: 'Archive Invoice', httpMethod: ['POST'], pmsName: 'invoice.archive')]
    public static function archive(Request $request): array
    {
        return ['ok' => true];
    }
}
