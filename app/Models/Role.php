<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const STANDARD = ['ICTO_ADMIN', 'ADMIN', 'STUDENT', 'EMPLOYEE'];
    public const AUTOMATIC = ['STUDENT', 'EMPLOYEE'];

    protected $fillable = ['role_name', 'role_description'];
}
