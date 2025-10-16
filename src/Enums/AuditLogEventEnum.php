<?php

namespace Sopheak\Core\Enums;

enum AuditLogEventEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case GET = 'get';
    case LOGOUT = 'logout';
    case LOGIN = 'login';
    case FAILED_LOGIN = 'failed_login';
}
