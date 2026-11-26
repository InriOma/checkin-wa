<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum TenantRole: string
{
    case CompanyOwner = 'company_owner';
    case HrAdmin = 'hr_admin';
    case BranchManager = 'branch_manager';
    case Employee = 'employee';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /** @return list<self> */
    public static function managementRoles(): array
    {
        return [
            self::CompanyOwner,
            self::HrAdmin,
            self::BranchManager,
        ];
    }

    /** @return list<self> */
    public static function adminRoles(): array
    {
        return [
            self::CompanyOwner,
            self::HrAdmin,
        ];
    }
}
