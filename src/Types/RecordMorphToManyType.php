<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use Sopheak\Core\Enums\RecordRelationshipsEnum;

class RecordMorphToManyType
{
    public $recordRelationshipsEnum;

    public function __construct(
        public string $related,
        public string $relation,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::MORPH_TO_MANY,
        public ?string $table = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
        public array $withPivot = [],
        public array $wherePivot = [],
        public bool $withTimestamps = false,
        public array $select = [],
        public bool $teamsEnabled = false,
        public ?string $teamsKey = null,
    ) {
        if (!in_array('model_type', $this->withPivot)) {
            $this->withPivot[] = 'model_type';
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            related: $properties['related'] ?? '',
            relation: $properties['relation'] ?? '',
            type: $properties['type'] ?? RecordRelationshipsEnum::MORPH_TO_MANY,
            table: $properties['table'] ?? null,
            foreignPivotKey: $properties['foreignPivotKey'] ?? null,
            relatedPivotKey: $properties['relatedPivotKey'] ?? null,
            parentKey: $properties['parentKey'] ?? null,
            relatedKey: $properties['relatedKey'] ?? null,
            withPivot: $properties['withPivot'] ?? [],
            wherePivot: $properties['wherePivot'] ?? [],
            withTimestamps: $properties['withTimestamps'] ?? false,
            select: $properties['select'] ?? [],
            teamsEnabled: $properties['teamsEnabled'] ?? false,
            teamsKey: $properties['teamsKey'] ?? null,
        );
    }

    public function toArray(): array
    {
        $config = [
            'related' => $this->related,
            'relation' => $this->relation,
            'type' => $this->recordRelationshipsEnum,
        ];

        if (null !== $this->table) {
            $config['table'] = $this->table;
        }

        if (null !== $this->foreignPivotKey) {
            $config['foreignPivotKey'] = $this->foreignPivotKey;
        }

        if (null !== $this->relatedPivotKey) {
            $config['relatedPivotKey'] = $this->relatedPivotKey;
        }

        if (null !== $this->parentKey) {
            $config['parentKey'] = $this->parentKey;
        }

        if (null !== $this->relatedKey) {
            $config['relatedKey'] = $this->relatedKey;
        }

        if (!empty($this->withPivot)) {
            $config['withPivot'] = $this->withPivot;
        }

        if (!empty($this->wherePivot)) {
            $config['wherePivot'] = $this->wherePivot;
        }

        if ($this->withTimestamps) {
            $config['withTimestamps'] = $this->withTimestamps;
        }

        if (!empty($this->select)) {
            $config['select'] = $this->select;
        }

        if ($this->teamsEnabled) {
            $config['teamsEnabled'] = $this->teamsEnabled;
        }

        if (null !== $this->teamsKey) {
            $config['teamsKey'] = $this->teamsKey;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            related: $config['related'] ?? '',
            relation: $config['relation'] ?? '',
            type: $config['type'] ?? RecordRelationshipsEnum::MORPH_TO_MANY,
            table: $config['table'] ?? null,
            foreignPivotKey: $config['foreignPivotKey'] ?? null,
            relatedPivotKey: $config['relatedPivotKey'] ?? null,
            parentKey: $config['parentKey'] ?? null,
            relatedKey: $config['relatedKey'] ?? null,
            withPivot: $config['withPivot'] ?? [],
            wherePivot: $config['wherePivot'] ?? [],
            withTimestamps: $config['withTimestamps'] ?? false,
            select: $config['select'] ?? [],
            teamsEnabled: $config['teamsEnabled'] ?? false,
            teamsKey: $config['teamsKey'] ?? null,
        );
    }
}
