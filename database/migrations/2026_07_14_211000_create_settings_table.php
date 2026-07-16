<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community-only table: a simple key/value store (a unique `key` + a
 * `value`) backing `App\Support\InstanceSettings`. Holds instance state
 * created at runtime — starting with the singleton workspace's id pointer
 * (`workspace_id`), written by `sendtrap:install` — that has no business
 * living in `config()` because it is created at install time, not known
 * statically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
