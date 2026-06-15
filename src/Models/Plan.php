<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\PlanFactory;
use Laracaise\Billing\Enums\BillingInterval;

/**
 * @property string              $id
 * @property string              $name
 * @property string              $slug
 * @property string|null         $description
 * @property int                 $amount
 * @property string              $currency
 * @property BillingInterval     $interval
 * @property int                 $interval_count
 * @property int                 $trial_days
 * @property bool                $is_active
 * @property int                 $sort_order
 * @property array<string,mixed>|null $metadata
 * @property Carbon|null         $created_at
 * @property Carbon|null         $updated_at
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
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

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @param Builder<Plan> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<Plan> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** @param Builder<Plan> $query */
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
