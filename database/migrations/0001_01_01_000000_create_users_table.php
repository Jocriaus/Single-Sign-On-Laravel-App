<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_types', function (Blueprint $table) {
            $table->id();
            $table->string('user_type_name');
            $table->string('user_type_description')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('user_type_id')->constrained('user_types');
            $table->boolean('status_flag')->default(true);
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('middle_initial', 20)->nullable();
            $table->string('last_name')->nullable();
            $table->string('suffix', 20)->nullable();
            $table->string('student_no', 50)->nullable()->unique();
            $table->string('employee_no', 50)->nullable()->unique();
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->boolean('account_locked')->default(false);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_types');
    }
};
