<?php

declare(strict_types=1);

// declare(strict_types=1);
namespace Sopheak\Core\Constants;

/**
 * Class RecordConstants.
 *
 * Defines constants for record actions, permissions, and operations.
 * These constants are used to standardize permission checks and action mapping across the package.
 */
class RecordConstants
{
    /**
     * Read-related permission verbs.
     */
    public const READ = 'read';

    public const VIEW = 'view';

    public const SEE = 'see';

    /**
     * Write-related permission verbs.
     *
     * @var string
     */
    public const WRITE = 'write';


    public const EDIT = 'edit';


    public const DESTROY = 'destroy';


    public const CREATE = 'create';


    public const UPDATE = 'update';


    public const DELETE = 'delete';


    public const RESTORE = 'restore';

    /**
     * Standardized action names used internally by the package.
     *
     * @var string
     */
    public const ACTION_VIEW = 'view';


    public const ACTION_CREATE = 'create';


    public const ACTION_UPDATE = 'update';


    public const ACTION_DELETE = 'delete';


    public const ACTION_RESTORE = 'restore';

    // HTTP methods
    public const HTTP_METHOD_GET = 'GET';

    public const HTTP_METHOD_POST = 'POST';

    public const HTTP_METHOD_PUT = 'PUT';

    public const HTTP_METHOD_DELETE = 'DELETE';

    public const HTTP_METHOD_PATCH = 'PATCH';

}
