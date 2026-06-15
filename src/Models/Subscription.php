<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\SubscriptionFactory;
use Laracaise\Billing\Enums\SubscriptionStatus;

/**
 * @property string $id
 * @property string $subscriptionable_type
 * @property string $subscriptionable_id
 * @property string $plan_id
 * @property string $name
 * @property SubscriptionStatus $status
 * @property int $quantity
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $cancels_at
 * @property Carbon|null $cancelled_at
 * @property string|null $provider
 * @property string|null $provider_id
 * @property array<string,mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'billing_subscriptions';

    protected $fillable = [
        'subscriptionable_type',
        'subscriptionable_id',
        'plan_id',
        'name',
        'status',
        'quantity',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancels_at',
        'cancelled_at',
        'provider',
        'provider_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancels_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return MorphTo<Model, $this> */
    public function subscriptionable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(SubscriptionOverride::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // -------------------------------------------------------------------------
    // Status checks
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function isPending(): bool
    {
        return $this->status === SubscriptionStatus::Pending;
    }

    /** Cancelled but still within the paid-for billing period. */
    public function onGracePeriod(): bool
    {
        return $this->isCancelled()
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** @param Builder<Subscription> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Active->value);
    }

    /** @param Builder<Subscription> $query */
    public function scopeTrialing(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Trialing->value);
    }

    /** @param Builder<Subscription> $query */
    public function scopeCancelled(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Cancelled->value);
    }

    /** @param Builder<Subscription> $query */
    public function scopePastDue(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::PastDue->value);
    }

    /** @param Builder<Subscription> $query */
    public function scopeActiveOrTrialing(Builder $query): void
    {
        $query->whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
        ]);
    }

    /** @param Builder<Subscription> $query */
    public function scopeWithName(Builder $query, string $name): void
    {
        $query->where('name', $name);
    }

    /** @param Builder<Subscription> $query */
    public function scopeForOwner(Builder $query, Model $owner): void
    {
        $query
            ->where('subscriptionable_type', $owner->getMorphClass())
            ->where('subscriptionable_id', $owner->getKey());
    }

    /** @param Builder<Subscription> $query */
    public function scopeExpiringBefore(Builder $query, DateTimeInterface $date): void
    {
        $query->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $date);
    }

    /** @param Builder<Subscription> $query */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }
}
