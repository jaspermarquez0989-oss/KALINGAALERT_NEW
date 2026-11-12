<?php

namespace App\Enums;

enum UserRole: string
{
    case SystemAdministrator = 'system_admin';
    case MdrrmoPersonnel = 'mdrrmo';
    case BfpOfficer = 'bfp_officer';
    case AlertOperator = 'alert_operator';
    case CommunityResident = 'resident';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SystemAdministrator->value => 'System administrator',
            self::MdrrmoPersonnel->value => 'MDRRMO personnel',
            self::BfpOfficer->value => 'BFP / fire information officer',
            self::AlertOperator->value => 'Authorized alert operator',
            self::CommunityResident->value => 'Community resident',
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffValues(): array
    {
        return [
            self::SystemAdministrator->value,
            self::MdrrmoPersonnel->value,
            self::BfpOfficer->value,
            self::AlertOperator->value,
        ];
    }
}
