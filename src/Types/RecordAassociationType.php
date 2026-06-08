<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

class RecordAassociationType
{
    public function __construct(
        public string $related,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::HAS_MANY_THROUGH,
        public string $fromObjectType = '',
        public string $fromObjectId = 'owner_id',
        public string $toObjectType = '',
        public string $toObjectId = 'target_id',
        public bool $allowCreate = true,
        public bool $allowUpdate = true,
        public bool $allowDelete = true,
    ) {
        if ('' === $related) {
            throw new InvalidArgumentException('related model/table name cannot be empty');
        }

        if ('' === $fromObjectType) {
            throw new InvalidArgumentException('fromObjectType (owner table) cannot be empty');
        }

        if ('' === $toObjectType) {
            throw new InvalidArgumentException('toObjectType (target table) cannot be empty');
        }

        if (RecordRelationshipsEnum::HAS_MANY_THROUGH !== $type) {
            throw new InvalidArgumentException('RecordAassociationType only supports HAS_MANY_THROUGH');
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            related: $properties['related'] ?? $properties['table'] ?? throw new InvalidArgumentException('related is required'),
            type: $properties['type'] ?? RecordRelationshipsEnum::HAS_MANY_THROUGH,
            fromObjectType: $properties['fromObjectType'] ?? '',
            fromObjectId: $properties['fromObjectId'] ?? 'owner_id',
            toObjectType: $properties['toObjectType'] ?? '',
            toObjectId: $properties['toObjectId'] ?? 'target_id',
            allowCreate: $properties['allowCreate'] ?? true,
            allowUpdate: $properties['allowUpdate'] ?? true,
            allowDelete: $properties['allowDelete'] ?? true,
        );
    }

    public function toArray(): array
    {
        $config = [
            'related' => $this->related,
            'type' => $this->type,
            'allowCreate' => $this->allowCreate,
            'allowUpdate' => $this->allowUpdate,
            'allowDelete' => $this->allowDelete,
        ];

        if ($this->fromObjectType !== '') {
            $config['fromObjectType'] = $this->fromObjectType;
        }

        if ($this->fromObjectId !== '') {
            $config['fromObjectId'] = $this->fromObjectId;
        }

        if ($this->toObjectType !== '') {
            $config['toObjectType'] = $this->toObjectType;
        }

        if ($this->toObjectId !== '') {
            $config['toObjectId'] = $this->toObjectId;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            related: $config['related'] ?? $config['table'] ?? throw new InvalidArgumentException('related is required in config array'),
            type: $config['type'] ?? RecordRelationshipsEnum::HAS_MANY_THROUGH,
            fromObjectType: $config['fromObjectType'] ?? '',
            fromObjectId: $config['fromObjectId'] ?? 'owner_id',
            toObjectType: $config['toObjectType'] ?? '',
            toObjectId: $config['toObjectId'] ?? 'target_id',
            allowCreate: $config['allowCreate'] ?? true,
            allowUpdate: $config['allowUpdate'] ?? true,
            allowDelete: $config['allowDelete'] ?? true,
        );
    }
}
