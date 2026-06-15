<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscription_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')
                ->constrained('billing_subscriptions')
                ->cascadeOnDelete();
            $table->string('feature');
            $table->string('value')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'feature'], 'bso_sub_feature_idx');
            $table->index('expires_at', 'bso_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_overrides');
    }
};
