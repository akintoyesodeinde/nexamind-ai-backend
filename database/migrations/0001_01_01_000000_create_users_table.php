<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->string('name', 120);
            $table->string('email', 255)->unique();
            $table->dateTime('email_verified_at', 3)->nullable();
            $table->string('password');
            $table->string('job_title', 120)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en-US');
            $table->text('preferences_json')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('last_login_at', 3)->nullable();
            $table->rememberToken();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index('status', 'IX_users_status');
        });

        DB::statement("ALTER TABLE dbo.users ADD CONSTRAINT CK_users_status CHECK (status IN ('active', 'suspended'))");

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 255)->primary();
            $table->string('token');
            $table->dateTime('created_at', 3)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
