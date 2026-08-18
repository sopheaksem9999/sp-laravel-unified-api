<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

/**
 * Configuration type for global meta-table based has-many-through relationships.
 *
 * Uses a shared "meta" table as a generic pivot between an owning table and a
 * target table, using columns:
 * - owner      (string discriminator for the owning table, e.g. "package")
 * - owner_id   (ID of the owning record)
 * - target_id  (ID of the related record in the target table)
 *
 * The constructor mirrors RecordHasManyThroughType so that consumers see a
 * consistent API:
 * - $table: target table/model (e.g. 'modules')
 * - $through: meta/pivot table (default: 'meta')
 * - $firstKey: pivot column pointing to parent (default: 'owner_id')
 * - $secondKey: related primary key (default: 'id')
 * - $localKey: parent primary key (defaults to 'id')
 * - $secondLocalKey: pivot column pointing to related (default: 'target_id')
 *
 * @param string                  $table          The target table/model name (required)
 * @param string                  $through        The meta/pivot table name (default: 'meta')
 * @param string                  $firstKey       Pivot column pointing to parent (default: 'owner_id')
 * @param string                  $secondKey      Related primary key (default: 'id')
 * @param string                  $localKey       Parent primary key (default: 'id')
 * @param string                  $secondLocalKey Pivot column pointing to related (default: 'target_id')
 * @param array                   $orderBy        Optional sorting configuration (default: ['created_at' => 'desc'])
 * @param RecordRelationshipsEnum $type           The relationship type (defaults to HAS_MANY_THROUGH)
 * @param null|string             $ownerColumn    Discriminator column in the meta table (default: 'owner')
 * @param null|string             $owner          Discriminator value for the owning table (e.g. 'package')
 * @param bool                    $allowCreate    Whether nested creates are allowed through this relationship
 * @param bool                    $allowUpdate    Whether nested updates are allowed through this relationship
 * @param bool                    $allowDelete    Whether nested deletes are allowed through this relationship
 */
class RecordMetaHasManyThroughType
{
    public function __construct(
        public string $table,
        public string $through = 'meta',
        public string $firstKey = 'owner_id',
        public string $secondKey = 'id',
        public string $localKey = 'id',
        public string $secondLocalKey = 'target_id',
        public array $orderBy = ['created_at' => 'desc'],
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::HAS_MANY_THROUGH,
        public ?string $ownerColumn = 'owner',
        public ?string $owner = null,
        public bool $allowCreate = true,
        public bool $allowUpdate = true,
        public bool $allowDelete = true,
    ) {
        if ($table === '') {
            throw new InvalidArgumentException('table name cannot be empty');
        }

        if (null !== $owner && $owner === '') {
            throw new InvalidArgumentException('owner discriminator cannot be empty when provided');
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            table: $properties['table'],
            through: $properties['through'] ?? 'meta',
            firstKey: $properties['firstKey'] ?? 'owner_id',
            secondKey: $properties['secondKey'] ?? 'id',
            localKey: $properties['localKey'] ?? 'id',
            secondLocalKey: $properties['secondLocalKey'] ?? 'target_id',
            orderBy: $properties['orderBy'] ?? ['created_at' => 'desc'],
            type: $properties['type'] ?? RecordRelationshipsEnum::HAS_MANY_THROUGH,
            ownerColumn: $properties['ownerColumn'] ?? 'owner',
            owner: $properties['owner'] ?? null,
            allowCreate: $properties['allowCreate'] ?? true,
            allowUpdate: $properties['allowUpdate'] ?? true,
            allowDelete: $properties['allowDelete'] ?? true,
        );
    }

    public function toArray(): array
    {
        $config = [
            'table' => $this->table,
            'through' => $this->through,
            'firstKey' => $this->firstKey,
            'secondKey' => $this->secondKey,
            'localKey' => $this->localKey,
            'secondLocalKey' => $this->secondLocalKey,
            'type' => $this->type,
            'orderBy' => $this->orderBy,
            'allowCreate' => $this->allowCreate,
            'allowUpdate' => $this->allowUpdate,
            'allowDelete' => $this->allowDelete,
        ];

        if (null !== $this->owner) {
            $config['owner'] = $this->owner;
        }

        if (null !== $this->ownerColumn) {
            $config['ownerColumn'] = $this->ownerColumn;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            table: $config['table'] ?? throw new InvalidArgumentException('table is required in config array'),
            through: $config['through'] ?? 'meta',
            firstKey: $config['firstKey'] ?? 'owner_id',
            secondKey: $config['secondKey'] ?? 'id',
            localKey: $config['localKey'] ?? 'id',
            secondLocalKey: $config['secondLocalKey'] ?? 'target_id',
            orderBy: $config['orderBy'] ?? ['created_at' => 'desc'],
            type: $config['type'] ?? RecordRelationshipsEnum::HAS_MANY_THROUGH,
            ownerColumn: $config['ownerColumn'] ?? 'owner',
            owner: $config['owner'] ?? null,
            allowCreate: $config['allowCreate'] ?? true,
            allowUpdate: $config['allowUpdate'] ?? true,
            allowDelete: $config['allowDelete'] ?? true,
        );
    }
}
