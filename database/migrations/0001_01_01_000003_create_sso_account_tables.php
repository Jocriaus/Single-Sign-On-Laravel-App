<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 100)->unique();
            $table->text('role_description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity');
            $table->string('activity_type', 60);
            $table->string('module')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('client_id')->nullable();
            $table->string('endpoint');
            $table->string('http_method', 10);
            $table->string('ip_address', 50)->nullable();
            $table->string('user_agent')->nullable();
            $table->integer('status_code');
            $table->string('status', 20);
            $table->string('error_code', 50)->nullable();
            $table->text('message')->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
