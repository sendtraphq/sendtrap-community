<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 06 Phase 4b design §4.1, §9.2: `users.role` — `owner` | `member` |
 * `viewer` (D-08, decided). Default `viewer` at the DB level is the safe
 * floor: a row created without an explicit role (e.g. a raw insert, or a
 * future code path that forgets to set one) gets the *least* privilege
 * rather than the most. `sendtrap:install` (the owner) and the future
 * owner-only Users page always set the role explicitly — the column
 * default is a fallback, not the intended provisioning path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
