<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_sign_ins', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->index();
            $table->string('client_id', 100)->index();
            $table->string('sid', 64)->index();
            $table->text('nonce')->nullable();
            // dateTime, not timestamp: MariaDB without explicit_defaults_for_timestamp gives a NOT NULL
            // timestamp column an implicit default (ON UPDATE CURRENT_TIMESTAMP, or an invalid zero date).
            $table->dateTime('auth_time');
            $table->dateTime('started_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_sign_ins');
    }
};
