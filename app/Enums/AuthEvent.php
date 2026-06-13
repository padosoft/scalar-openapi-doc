<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Discriminator for rows in the auth_logs audit table.
 */
enum AuthEvent: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Failed = 'failed';
}
