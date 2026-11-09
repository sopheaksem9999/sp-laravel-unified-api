<?php

declare(strict_types=1);

namespace Sopheak\Core\Attributes;

use Attribute;
use Sopheak\Core\Enums\RecordValidatorHookEnum;

/**
 * Declare a validation rule directly on a method in a Validator or Handler class.
 *
 * When a class is passed to the `createValidator`, `updateValidator`, or `deleteValidator`
 * properties of a RecordTableType, the package will automatically scan the class for
 * these attributes and map them to the appropriate validation hooks.
 *
 * Example:
 * ```php
 * class UserValidators
 * {
 *     #[RecordValidator('create')]
 *     public static function createRules(): array
 *     {
 *         return [
 *             'email' => 'required|email|unique:users',
 *             'password' => 'required|min:8',
 *         ];
 *     }
 *
 *     #[RecordValidator('update')]
 *     public static function updateRules(): array
 *     {
 *         return [
 *             'email' => 'sometimes|email',
 *         ];
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RecordValidator
{
    public readonly string $hook;

    public function __construct(
        RecordValidatorHookEnum|string $hook,
        public readonly ?string $description = null,
    ) {
        $this->hook = $hook instanceof RecordValidatorHookEnum ? $hook->value : $hook;
    }
}
