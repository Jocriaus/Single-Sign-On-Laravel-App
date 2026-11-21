<?php

namespace App\Sso;

use App\Models\ActivityLog;
use App\Models\User;

class Activity
{
    public const CREATE = 'CREATE';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const READ = 'READ';
    public const AUTH = 'AUTH';

    public static function log(?User $user, string $activity, string $type, ?string $module = null): void
    {
        ActivityLog::create([
            'user_id' => $user?->getKey(),
            'activity' => $activity,
            'activity_type' => $type,
            'module' => $module,
        ]);
    }
}
