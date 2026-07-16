<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community-only table (Plan 06 Phase 4b design §5.3): the parallel of
 * Cloud's `team_send_usages`, re-keyed by workspace_id instead of team_id.
 * Backs `App\Support\CommunityUsageMeter`'s monthly sending quota — a
 * durable counter incremented on every accepted send, independent of
 * stored Message rows (so deleting messages, e.g. via retention pruning or
 * the bulk-delete endpoint, can never let a workspace reclaim quota
 * mid-period). No backfill: Community is a fresh install at this table's
 * creation, unlike Cloud's migration which preserved usage from existing
 * production teams.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_send_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedBigInteger('send_count')->default(0);
            $table->unique(['workspace_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_send_usages');
    }
};
