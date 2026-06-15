<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->morphs('subscriptionable');
            $table->foreignUlid('plan_id')->constrained('billing_plans');
            $table->string('name')->default('default');
            $table->string('status');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancels_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Owner lookup — the most frequent query
            $table->index(
                ['subscriptionable_type', 'subscriptionable_id'],
                'bs_owner_idx'
            );
            // Owner + name + status — active subscription lookup
            $table->index(
                ['subscriptionable_type', 'subscriptionable_id', 'name', 'status'],
                'bs_owner_name_status_idx'
            );
            $table->index('status', 'bs_status_idx');
            // Period-based queries (renewals, expirations)
            $table->index('current_period_end', 'bs_period_end_idx');
            $table->index('cancels_at', 'bs_cancels_at_idx');
            $table->index('trial_ends_at', 'bs_trial_ends_idx');
            // Provider reference lookup
            $table->index(['provider', 'provider_id'], 'bs_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
