<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original migration (2024_10_16_120026_move_redis_password_to_envs)
     * moved redis passwords to environment_variables but its dropColumn
     * was wrapped in a try-catch that silently swallowed failures.
     * This ensures the column is dropped for any instances where it survived.
     */
    public function up(): void
    {
        if (Schema::hasColumn('standalone_redis', 'redis_password')) {
            Schema::table('standalone_redis', function (Blueprint $table) {
                $table->dropColumn('redis_password');
            });
        }
    }
};
