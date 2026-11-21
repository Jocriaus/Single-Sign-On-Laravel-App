<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserType extends Model
{
    public const ADMIN = 'Admin';
    public const EMPLOYEE = 'Employee';
    public const STUDENT = 'Student';

    public $timestamps = false;

    protected $fillable = ['user_type_name', 'user_type_description'];
}
