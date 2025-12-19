<?php

namespace Sopheak\Core\Enums;

enum RecordApiJsonResponseEnum: string
{
    case SUCCESS = '200';     // Successfully retrieved or updated resource
    case CREATED = '201';     // Successfully created resource
    case DELETED = '204';     // Successfully deleted resource (no content returned)
    case ERROR = '400';       // Bad request
    case UNAUTHORIZED = '401'; // Unauthorized access
    case FORBIDDEN = '403';    // Forbidden access
    case NOT_FOUND = '404';    // Resource not found
    case SERVER_ERROR = '500'; // Internal server error
}
