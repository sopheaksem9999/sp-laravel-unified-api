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

}
