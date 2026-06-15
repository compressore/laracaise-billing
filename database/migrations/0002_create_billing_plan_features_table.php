<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_features', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('billing_plans')->cascadeOnDelete();
            $table->string('feature');
            $table->string('value')->nullable();
            $table->boolean('resettable')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'feature'], 'bpf_plan_feature_unique');
            $table->index('feature', 'bpf_feature_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plan_features');
    }
};
