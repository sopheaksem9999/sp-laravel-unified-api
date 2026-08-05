<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;
use Sopheak\Core\Enums\RecordFunctionMethodEnum;

/**
 * Class RecordFunctionType.
 *
 * Represents a custom function configuration for table-specific or global functions.
 * This class defines the structure and behavior of custom API endpoints that can be
 * dynamically registered and executed within the ERP system.
 *
 * @property array|string|null $pmsName        The PMS name identifier(s) for this function (optional, null for public)
 * @property array|string       $httpMethod      Allowed HTTP methods (GET, POST, PUT, DELETE, etc.)
 * @property string             $class           Class name for class-based functions (required)
 * @property string             $functionName Method name for class-based functions (required)
 * @property null|string        $name            Display name for OpenAPI summary generation (optional; falls back to $description, then a humanized function key, when empty)
 * @property null|string        $description     Function description for documentation purposes
 *
 * @since 1.0.0
 *
 * @author QBO Finance Team
 *
 * Example usage:
 * ```php
 * // Public function (no pmsName)
 * $publicFunction = new RecordFunctionType(
 *     pmsName: null,
 *     httpMethod: RecordFunctionMethodEnum::POST->value,
 *     class: 'App\\Services\\AuthService',
 *     functionName: 'login',
 *     disableCache: false,
 *     description: 'User login'
 * );
 *
 * // Class-based function for business logic
 * $classFunction = new RecordFunctionType(
 *     pmsName: 'calculate_total',
 *     httpMethod: [RecordFunctionMethodEnum::POST->value],
 *     class: 'App\\Services\\CalculationService',
 *     functionName: 'calculateTotal',
 *     disableCache: false,
 *     description: 'Calculate total for given items'
 * );
 *
 * // Simple GET endpoint
 * $getFunction = new RecordFunctionType(
 *     pmsName: 'get_status',
 *     httpMethod: RecordFunctionMethodEnum::GET->value,
 *     class: 'App\\Services\\StatusService',
 *     functionName: 'getStatus',
 *     disableCache: false,
 *     description: 'Get system status information'
 * );
 *
 * // Multiple HTTP methods supported
 * $crudFunction = new RecordFunctionType(
 *     pmsName: 'manage_records',
 *     httpMethod: [
 *         RecordFunctionMethodEnum::GET->value,
 *         RecordFunctionMethodEnum::POST->value,
 *         RecordFunctionMethodEnum::PUT->value,
 *         RecordFunctionMethodEnum::DELETE->value,
 *     ],
 *     class: 'App\\Services\\RecordManagementService',
 *     functionName: 'handleRequest',
 *     disableCache: false,
 *     description: 'Full CRUD operations for records'
 * );
 *
 * // Multiple permissions (user needs at least one)
 * $multiPermissionFunction = new RecordFunctionType(
 *     pmsName: ['create_employeeRoster', 'update_employeeRoster'],
 *     httpMethod: [RecordFunctionMethodEnum::POST->value],
 *     class: 'App\\Http\\Controllers\\EmployeeRosterController',
 *     functionName: 'upsertEmployeeRosters',
 *     disableCache: false,
 *     description: 'Create or update employee rosters'
 * );
 * ```
 */
class RecordFunctionType
{
    /**
     * Create a new RecordFunctionType instance.
     *
     * @param array|string|RecordFunctionMethodEnum $httpMethod      Allowed HTTP methods (e.g., 'GET', ['GET', 'POST'])
     * @param string       $class           Class name for class-based functions (required)
     * @param string       $functionName Method name for class-based functions (required)
     * @param null|string  $name            Display name for OpenAPI summary generation (optional; falls back to $description, then a humanized function key, when empty)
     * @param null|string  $description     Function description for documentation purposes
     * @param array|string|null $pmsName   The PMS name identifier(s) for this function (optional, null for public)
     * @param bool $isPublic Whether the function is public (default: false)
     * @param int|null $cacheTTL Cache TTL in seconds (default: null)
     * @param array|string|null $clearCacheTables Tables to clear cache (default: null)
     * @param array|string|null $middleware Middleware to apply (default: null)
     * @param array|null $querySchema OpenAPI schema array for query parameters (e.g. ['type' => 'object', 'properties' => [...]])
     * @param array|null $payloadSchema OpenAPI schema array for request payload (e.g. ['type' => 'object', 'properties' => [...]])
     * @param array|null $responseSchema OpenAPI schema array for response body (e.g. ['type' => 'object', 'properties' => [...]])
     *
     * @throws InvalidArgumentException When class or functionName is empty
     */
    public function __construct(
        public array|string|RecordFunctionMethodEnum $httpMethod,
        public string $class,
        public string $functionName,
        public bool $isPublic = false,
        public array|string|null $pmsName = null,
        public bool $disableCache = false,
        public ?int $cacheTTL = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?array $querySchema = null,
        public ?array $payloadSchema = null,
        public ?array $responseSchema = null,
        public array|string|null $clearCacheTables = null,
        public array|string|null $middleware = null,
    ) {
        if (null !== $pmsName && (empty($pmsName) || (is_array($pmsName) && [] === $pmsName))) {
            throw new InvalidArgumentException('pmsName cannot be empty if provided');
        }

        if (empty($class)) {
            throw new InvalidArgumentException('class cannot be empty');
        }

        if (empty($functionName)) {
            throw new InvalidArgumentException('functionName cannot be empty');
        }

        if (null !== $cacheTTL && $cacheTTL <= 0) {
            throw new InvalidArgumentException('cacheTTL must be greater than 0');
        }
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * This httpMethod is required for Laravel's config:cache command to properly
     * serialize and deserialize the object when caching configurations.
     *
     * @param array<string, mixed> $properties The properties array from var_export
     *
     * @return static A new instance of RecordFunctionType
     *
     * @throws InvalidArgumentException When required properties are missing
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            httpMethod: $properties['httpMethod'] ?? throw new InvalidArgumentException('httpMethod is required'),
            class: $properties['class'] ?? throw new InvalidArgumentException('class is required'),
            functionName: $properties['functionName'] ?? throw new InvalidArgumentException('functionName is required'),
            isPublic: $properties['isPublic'] ?? false,
            pmsName: $properties['pmsName'] ?? null,
            disableCache: $properties['disableCache'] ?? false,
            cacheTTL: $properties['cacheTTL'] ?? null,
            name: $properties['name'] ?? null,
            description: $properties['description'] ?? null,
            querySchema: $properties['querySchema'] ?? null,
            payloadSchema: $properties['payloadSchema'] ?? null,
            responseSchema: $properties['responseSchema'] ?? null,
            clearCacheTables: $properties['clearCacheTables'] ?? null,
            middleware: $properties['middleware'] ?? null,
        );
    }

    /**
     * Convert the function configuration to array format.
     * This is useful for serialization and configuration export.
     *
     * @return array The function configuration as an associative array
     */
    public function toArray(): array
    {
        $config = [
            'pmsName' => $this->pmsName,
            'isPublic' => $this->isPublic,
            'httpMethod' => $this->httpMethod,
            'class' => $this->class,
            'functionName' => $this->functionName,
            'disableCache' => $this->disableCache,
            'cacheTTL' => $this->cacheTTL,
            'clearCacheTables' => $this->clearCacheTables,
            'middleware' => $this->middleware,
        ];

        if (null !== $this->name) {
            $config['name'] = $this->name;
        }

        if (null !== $this->description) {
            $config['description'] = $this->description;
        }

        if (null !== $this->querySchema) {
            $config['querySchema'] = $this->querySchema;
        }

        if (null !== $this->payloadSchema) {
            $config['payloadSchema'] = $this->payloadSchema;
        }

        if (null !== $this->responseSchema) {
            $config['responseSchema'] = $this->responseSchema;
        }

        return $config;
    }

    /**
     * Create a RecordFunctionType instance from array configuration.
     *
     * This is useful for loading configuration from files, databases, or
     * when deserializing configuration data from external sources.
     *
     * @param array<string, mixed> $config The configuration array containing function parameters
     *
     * @return static A new instance of RecordFunctionType
     *
     * @throws InvalidArgumentException When required configuration keys are missing
     *
     * @example
     * ```php
     * $config = [
     *     'pmsName' => 'user_report',
     *     'httpMethod' => [RecordFunctionMethodEnum::GET->value, RecordFunctionMethodEnum::POST->value],
     *     'class' => 'App\\Services\\UserReportService',
     *     'functionName' => 'generateReport',
     *     'description' => 'Generate user reports'
     * ];
     * $function = RecordFunctionType::fromArray($config);
     * ```
     */
    public static function fromArray(array $config): self
    {
        return new self(
            httpMethod: $config['httpMethod'] ?? throw new InvalidArgumentException('httpMethod is required in config array'),
            class: $config['class'] ?? throw new InvalidArgumentException('class is required in config array'),
            functionName: $config['functionName'] ?? throw new InvalidArgumentException('functionName is required in config array'),
            isPublic: $config['isPublic'] ?? false,
            pmsName: $config['pmsName'] ?? null,
            disableCache: $config['disableCache'] ?? false,
            cacheTTL: $config['cacheTTL'] ?? null,
            name: $config['name'] ?? null,
            description: $config['description'] ?? null,
            querySchema: $config['querySchema'] ?? null,
            payloadSchema: $config['payloadSchema'] ?? null,
            responseSchema: $config['responseSchema'] ?? null,
            clearCacheTables: $config['clearCacheTables'] ?? null,
            middleware: $config['middleware'] ?? null,
        );
    }
}
