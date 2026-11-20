<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case BranchManager = 'branch_manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::BranchManager => 'Branch Manager',
        };
    }
}
