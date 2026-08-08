<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Resolves a model's key behavior from record.id_type at runtime.
 *
 * Eloquent's defaults assume an auto-incrementing integer key. Under
 * record.id_type = 'uuid' all three assumptions are wrong: the column has no
 * database default, so an insert without an id fails; and $keyType = 'int'
 * breaks find() and relation loading for string keys.
 *
 * Eloquent boots trait hooks automatically through bootTraits(), so this
 * composes with a model's own booted() method rather than conflicting with it.
 */
trait HasConfigurableKey
{
    public static function bootHasConfigurableKey(): void
    {
        static::creating(function (Model $model): void {
            if (RecordConfigService::idType() !== 'uuid') {
                return;
            }

            $keyName = $model->getKeyName();

            if ($model->getAttribute($keyName) === null) {
                $model->setAttribute($keyName, (string) Str::uuid());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return RecordConfigService::idType() !== 'uuid';
    }

    public function getKeyType(): string
    {
        return RecordConfigService::idType() === 'uuid' ? 'string' : 'int';
    }
}
