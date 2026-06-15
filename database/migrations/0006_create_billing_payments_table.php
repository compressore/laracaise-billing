<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Direct owner morph — allows $model->payments() without joining subscriptions
            $table->morphs('subscriptionable');
            // Nullable: payments may exist without a linked subscription (one-off charges)
            $table->foreignUlid('subscription_id')
                ->nullable()
                ->constrained('billing_subscriptions')
                ->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('ZAR');
            $table->string('status');
            $table->string('type')->default('charge');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Owner lookup
            $table->index(
                ['subscriptionable_type', 'subscriptionable_id'],
                'bpa_owner_idx'
            );
            $table->index('status', 'bpa_status_idx');
            $table->index('paid_at', 'bpa_paid_at_idx');
            // Provider reference lookup (webhook reconciliation)
            $table->index(['provider', 'provider_reference'], 'bpa_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
