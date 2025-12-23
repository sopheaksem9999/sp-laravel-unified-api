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
 * @property array|string|null $pms_name        The PMS name identifier(s) for this function (optional, null for public)
 * @property array|string       $method          Allowed HTTP methods (GET, POST, PUT, DELETE, etc.)
 * @property string             $class           Class name for class-based functions (required)
 * @property string             $function_method Method name for class-based functions (required)
 * @property null|string        $description     Function description for documentation purposes
 *
 * @since 1.0.0
 *
 * @author QBO Finance Team
 *
 * Example usage:
 * ```php
 * // Public function (no pms_name)
 * $publicFunction = new RecordFunctionType(
 *     pms_name: null,
 *     method: 'POST',
 *     class: 'App\\Services\\AuthService',
 *     function_method: 'login',
 *     description: 'User login'
 * );
 *
 * // Class-based function for business logic
 * $classFunction = new RecordFunctionType(
 *     pms_name: 'calculate_total',
 *     method: ['POST'],
 *     class: 'App\\Services\\CalculationService',
 *     function_method: 'calculateTotal',
 *     description: 'Calculate total for given items'
 * );
 *
 * // Simple GET endpoint
 * $getFunction = new RecordFunctionType(
 *     pms_name: 'get_status',
 *     method: 'GET',
 *     class: 'App\\Services\\StatusService',
 *     function_method: 'getStatus',
 *     description: 'Get system status information'
 * );
 *
 * // Multiple HTTP methods supported
 * $crudFunction = new RecordFunctionType(
 *     pms_name: 'manage_records',
 *     method: ['GET', 'POST', 'PUT', 'DELETE'],
 *     class: 'App\\Services\\RecordManagementService',
 *     function_method: 'handleRequest',
 *     description: 'Full CRUD operations for records'
 * );
 *
 * // Multiple permissions (user needs at least one)
 * $multiPermissionFunction = new RecordFunctionType(
 *     pms_name: ['create_employeeRoster', 'update_employeeRoster'],
 *     method: ['POST'],
 *     class: 'App\\Http\\Controllers\\EmployeeRosterController',
 *     function_method: 'upsertEmployeeRosters',
 *     description: 'Create or update employee rosters'
 * );
 * ```
 */
class RecordFunctionType
{
    /**
     * Create a new RecordFunctionType instance.
     *
     * @param array|string|null $pms_name   The PMS name identifier(s) for this function (optional, null for public)
     * @param array|string $method          Allowed HTTP methods (e.g., 'GET', ['GET', 'POST'])
     * @param string       $class           Class name for class-based functions (required)
     * @param string       $function_method Method name for class-based functions (required)
     * @param null|string  $description     Function description for documentation purposes
     *
     * @throws InvalidArgumentException When class or function_method is empty
     */
    public function __construct(
        public array|string|null $pms_name = null,
        public array|string|RecordFunctionMethodEnum $method,
        public string $class,
        public string $function_method,
        public ?string $description = null,
        public ?array $query_schema = null,
        public ?array $payload_schema = null,
        public ?array $response_schema = null,
    ) {
        if (null !== $pms_name && (empty($pms_name) || (is_array($pms_name) && [] === $pms_name))) {
            throw new InvalidArgumentException('pms_name cannot be empty if provided');
        }

        if (empty($class)) {
            throw new InvalidArgumentException('class cannot be empty');
        }

        if (empty($function_method)) {
            throw new InvalidArgumentException('function_method cannot be empty');
        }
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * This method is required for Laravel's config:cache command to properly
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
            pms_name: $properties['pms_name'] ?? null,
            method: $properties['method'] ?? throw new InvalidArgumentException('method is required'),
            class: $properties['class'] ?? throw new InvalidArgumentException('class is required'),
            function_method: $properties['function_method'] ?? throw new InvalidArgumentException('function_method is required'),
            description: $properties['description'] ?? null,
            query_schema: $properties['query_schema'] ?? null,
            payload_schema: $properties['payload_schema'] ?? null,
            response_schema: $properties['response_schema'] ?? null,
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
            'pms_name' => $this->pms_name,
            'method' => $this->method,
            'class' => $this->class,
            'function_method' => $this->function_method,
        ];

        if (null !== $this->description) {
            $config['description'] = $this->description;
        }

        if (null !== $this->query_schema) {
            $config['query_schema'] = $this->query_schema;
        }

        if (null !== $this->payload_schema) {
            $config['payload_schema'] = $this->payload_schema;
        }

        if (null !== $this->response_schema) {
            $config['response_schema'] = $this->response_schema;
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
     *     'pms_name' => 'user_report',
     *     'method' => ['GET', 'POST'],
     *     'class' => 'App\\Services\\UserReportService',
     *     'function_method' => 'generateReport',
     *     'description' => 'Generate user reports'
     * ];
     * $function = RecordFunctionType::fromArray($config);
     * ```
     */
    public static function fromArray(array $config): self
    {
        return new self(
            pms_name: $config['pms_name'] ?? null,
            method: $config['method'] ?? throw new InvalidArgumentException('method is required in config array'),
            class: $config['class'] ?? throw new InvalidArgumentException('class is required in config array'),
            function_method: $config['function_method'] ?? throw new InvalidArgumentException('function_method is required in config array'),
            description: $config['description'] ?? null,
            query_schema: $config['query_schema'] ?? null,
            payload_schema: $config['payload_schema'] ?? null,
            response_schema: $config['response_schema'] ?? null,
        );
    }
}
