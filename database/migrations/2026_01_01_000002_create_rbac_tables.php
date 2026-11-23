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
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255);
            $table->boolean('is_system')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->string('slug', 80)->unique();
            $table->string('module', 60);
            $table->string('description', 255);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index('module', 'IX_permissions_module');
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('permission_id')->references('id')->on('permissions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
