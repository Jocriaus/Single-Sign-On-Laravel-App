<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'client_id', 'endpoint', 'http_method', 'ip_address', 'user_agent',
        'status_code', 'status', 'error_code', 'message', 'request_body', 'response_body',
    ];
}
