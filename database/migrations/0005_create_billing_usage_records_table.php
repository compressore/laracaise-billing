<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')
                ->constrained('billing_subscriptions')
                ->cascadeOnDelete();
            $table->string('feature');
            $table->integer('quantity');
            $table->timestamp('recorded_at')->useCurrent();
            // created_at only — records are immutable, no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'feature'], 'bur_sub_feature_idx');
            // Period-range queries: sum usage for a feature within a billing period
            $table->index(
                ['subscription_id', 'feature', 'recorded_at'],
                'bur_period_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_records');
    }
};
