<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laracaise\Billing\Database\Factories\PlanFactory;
use Laracaise\Billing\Enums\BillingInterval;

class Plan extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'billing_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'trial_days',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'integer',
            'interval'       => BillingInterval::class,
            'interval_count' => 'integer',
            'trial_days'     => 'integer',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer',
            'metadata'       => 'array',
        ];
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeWithInterval(Builder $query, BillingInterval $interval): void
    {
        $query->where('interval', $interval->value);
    }

    public function isFree(): bool
    {
        return $this->amount === 0;
    }

    public function hasTrial(): bool
    {
        return $this->trial_days > 0;
    }
}
