<?php

namespace Sopheak\Core\Constants;

class HttpErrorCodeConstant
{
    public const SUCCESS = 0;

    public const GENERAL_ERROR = 10000;

    public const INVALID_TENANT_ID = 10001;

    public const INVALID_ACCESS = 10002;

    public const INVALID_TOKEN = 10003;

    public const INVALID_REQUEST = 10004;

    public const INVALID_RESOURCE = 10005;

    public const INVALID_PERMISSION = 10006;

    public const INVALID_CREDENTIAL = 10007;

    public const PERMISSION_DENIED = 10008;

    public const RESOURCE_NOT_FOUND = 10009;

    public const INTERNAL_SERVER_ERROR = 10010;

    public const UNKNOWN_ERROR = 10011;

    public const TENANT_NOT_FOUND = 10012;

    public const TENANT_DISABLED = 10013;

    public const NO_TENANT_PMS_ACCESS = 10014;

    public const TOKEN_EXPIRED = 10015;

    public const DESCRIPTIONS = [
        self::SUCCESS => 'Success',
        self::GENERAL_ERROR => 'General error',
        self::INVALID_TENANT_ID => 'Invalid tenant identifier',
        self::INVALID_ACCESS => 'Invalid access (unauthorized)',
        self::INVALID_TOKEN => 'Invalid authentication token',
        self::INVALID_REQUEST => 'Invalid request payload or parameters',
        self::INVALID_RESOURCE => 'Invalid resource reference',
        self::INVALID_PERMISSION => 'Invalid permission configuration',
        self::INVALID_CREDENTIAL => 'Invalid user credentials',
        self::PERMISSION_DENIED => 'Permission denied',
        self::RESOURCE_NOT_FOUND => 'Resource not found',
        self::INTERNAL_SERVER_ERROR => 'Internal server error',
        self::UNKNOWN_ERROR => 'Unknown error',
        self::TENANT_NOT_FOUND => 'Tenant not found',
        self::TENANT_DISABLED => 'Tenant is disabled',
        self::NO_TENANT_PMS_ACCESS => 'No PMS access for tenant',
        self::TOKEN_EXPIRED => 'Authentication token expired',
    ];

    public static function describe(int $errorCode): string
    {
        return self::DESCRIPTIONS[$errorCode] ?? 'Unknown error code';
    }
}
