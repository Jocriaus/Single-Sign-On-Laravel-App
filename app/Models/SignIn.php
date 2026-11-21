<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sign-in of a user to one app, started by an authorization request in an SSO session. */
class SignIn extends Model
{
    use HasUuids;

    protected $table = 'sso_sign_ins';

    protected $fillable = ['user_id', 'client_id', 'sid', 'nonce', 'auth_time', 'started_at'];

    protected function casts(): array
    {
        return [
            'auth_time' => 'datetime',
            'started_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
