<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public function canEditProjects(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    public function canRunPipelines(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
        };
    }
}
