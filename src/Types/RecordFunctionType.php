<?php

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
 *     httpMethod: 'POST',
 *     class: 'App\\Services\\AuthService',
 *     functionName: 'login',
 *     description: 'User login'
 * );
 *
 * // Class-based function for business logic
 * $classFunction = new RecordFunctionType(
 *     pmsName: 'calculate_total',
 *     httpMethod: ['POST'],
 *     class: 'App\\Services\\CalculationService',
 *     functionName: 'calculateTotal',
 *     description: 'Calculate total for given items'
 * );
 *
 * // Simple GET endpoint
 * $getFunction = new RecordFunctionType(
 *     pmsName: 'get_status',
 *     httpMethod: 'GET',
 *     class: 'App\\Services\\StatusService',
 *     functionName: 'getStatus',
 *     description: 'Get system status information'
 * );
 *
 * // Multiple HTTP methods supported
 * $crudFunction = new RecordFunctionType(
 *     pmsName: 'manage_records',
 *     httpMethod: ['GET', 'POST', 'PUT', 'DELETE'],
 *     class: 'App\\Services\\RecordManagementService',
 *     functionName: 'handleRequest',
 *     description: 'Full CRUD operations for records'
 * );
 *
 * // Multiple permissions (user needs at least one)
 * $multiPermissionFunction = new RecordFunctionType(
 *     pmsName: ['create_employeeRoster', 'update_employeeRoster'],
 *     httpMethod: ['POST'],
 *     class: 'App\\Http\\Controllers\\EmployeeRosterController',
 *     functionName: 'upsertEmployeeRosters',
 *     description: 'Create or update employee rosters'
 * );
 * ```
 */
class RecordFunctionType
{
    /**
     * Create a new RecordFunctionType instance.
     *
     * @param array|string|null $pmsName   The PMS name identifier(s) for this function (optional, null for public)
     * @param array|string|RecordFunctionMethodEnum $httpMethod      Allowed HTTP methods (e.g., 'GET', ['GET', 'POST'])
     * @param string       $class           Class name for class-based functions (required)
     * @param string       $functionName Method name for class-based functions (required)
     * @param null|string  $description     Function description for documentation purposes
     *
     * @throws InvalidArgumentException When class or functionName is empty
     */
    public function __construct(
        public array|string|RecordFunctionMethodEnum $httpMethod,
        public string $class,
        public string $functionName,
        public bool $isPublic = false,
        public array|string|null $pmsName = null,
        public ?string $description = null,
        public ?array $querySchema = null,
        public ?array $payloadSchema = null,
        public ?array $responseSchema = null,
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
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * This httpMethod is required for Laravel's config:cache command to properly
     * serialize and deserialize the object when caching configurations.
     *
     * @param array $properties The properties array from var_export
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
            description: $properties['description'] ?? null,
            querySchema: $properties['querySchema'] ?? null,
            payloadSchema: $properties['payloadSchema'] ?? null,
            responseSchema: $properties['responseSchema'] ?? null,
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
        ];

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
     * @param array $config The configuration array containing function parameters
     *
     * @return static A new instance of RecordFunctionType
     *
     * @throws InvalidArgumentException When required configuration keys are missing
     *
     * @example
     * ```php
     * $config = [
     *     'pmsName' => 'user_report',
     *     'httpMethod' => ['GET', 'POST'],
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
            description: $config['description'] ?? null,
            querySchema: $config['querySchema'] ?? null,
            payloadSchema: $config['payloadSchema'] ?? null,
            responseSchema: $config['responseSchema'] ?? null,
        );
    }
}
