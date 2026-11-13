<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->unique();
            $table->string('role', 20)->default(UserRole::Customer->value)->index();
            $table->timestamp('suspended_at', 6)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->dropIndex(['role']);
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['phone', 'role', 'suspended_at']);
        });
    }
};
