<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->ulid('aggregate_id')->after('id');
            $table->string('aggregate_type')->after('aggregate_id');

            $table->index(['aggregate_id', 'aggregate_type']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex(['aggregate_id', 'aggregate_type']);
            $table->dropColumn(['aggregate_id', 'aggregate_type']);
        });
    }
};
