<?php

namespace App\Enums;

enum UserRole: string
{
    case PLATFORM_ADMIN = 'platform_admin';
    case SUPPORT_AGENT = 'support_agent';
    case ADMIN = 'admin';
    case SUPERVISOR = 'supervisor';
    case EMPLOYEE = 'empleado';
}
