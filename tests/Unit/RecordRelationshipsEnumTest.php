<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

class RecordRelationshipsEnumTest extends TestCase
{
    public function test_is_morph_relationship_supports_all_morph_types(): void
    {
        $morphCases = [
            RecordRelationshipsEnum::MORPH_TO,
            RecordRelationshipsEnum::MORPH_ONE,
            RecordRelationshipsEnum::MORPH_MANY,
            RecordRelationshipsEnum::MORPH_TO_MANY,
            RecordRelationshipsEnum::MORPH_BY_MANY,
            RecordRelationshipsEnum::SPATIE_PERMISSION,
        ];

        foreach ($morphCases as $case) {
            $this->assertTrue(
                $case->isMorphRelationship(),
                sprintf('Expected %s to be treated as morph relationship', $case->value),
            );
        }

        $nonMorphCases = array_filter(
            RecordRelationshipsEnum::cases(),
            static fn(RecordRelationshipsEnum $enum): bool => !in_array($enum, $morphCases, true),
        );

        foreach ($nonMorphCases as $case) {
            $this->assertFalse(
                $case->isMorphRelationship(),
                sprintf('Did not expect %s to be treated as morph relationship', $case->value),
            );
        }
    }

    public function test_supports_pivot_only_for_pivot_relationships(): void
    {
        $pivotCases = [
            RecordRelationshipsEnum::BELONGS_TO_MANY,
            RecordRelationshipsEnum::MORPH_TO_MANY,
            RecordRelationshipsEnum::MORPH_BY_MANY,
            RecordRelationshipsEnum::SPATIE_PERMISSION,
        ];

        foreach ($pivotCases as $case) {
            $this->assertTrue(
                $case->supportsPivot(),
                sprintf('Expected %s to support pivot tables', $case->value),
            );
        }

        $nonPivotCases = array_filter(
            RecordRelationshipsEnum::cases(),
            static fn(RecordRelationshipsEnum $enum): bool => !in_array($enum, $pivotCases, true),
        );

        foreach ($nonPivotCases as $case) {
            $this->assertFalse(
                $case->supportsPivot(),
                sprintf('Did not expect %s to support pivot tables', $case->value),
            );
        }
    }
}
