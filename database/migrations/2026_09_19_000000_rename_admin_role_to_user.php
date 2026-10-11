<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the legacy `admin` role to `user`: regular staff keep exactly
     * the workspace access they already had, under the new three-role
     * scheme (developer / super_admin / user). Runs as plain data updates
     * plus a column-default change — no table rebuild, no data loss.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'admin')->update(['role' => 'user']);

        // SQLite cannot alter a column default; its default is irrelevant in
        // practice (every application write sets an explicit valid role).
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'user'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'user'");
        }
    }

    /**
     * Reverse the rename.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'admin']);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'admin'");
        }
    }
};
