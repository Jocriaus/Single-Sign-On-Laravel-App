<?php

namespace App\Sso;

use Illuminate\Support\Facades\DB;
use Throwable;

class HealthProbe
{
    public function databaseIsUp(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
