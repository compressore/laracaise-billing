<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->char('currency', 3)->default('ZAR');
            $table->string('interval');
            $table->unsignedTinyInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'bp_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
