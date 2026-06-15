<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\SubscriptionOverrideFactory;

/**
 * @property string      $id
 * @property string      $subscription_id
 * @property string      $feature
 * @property string|null $value
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubscriptionOverride extends Model
{
    /** @use HasFactory<SubscriptionOverrideFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'billing_subscription_overrides';

    protected $fillable = [
        'subscription_id',
        'feature',
        'value',
        'reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SubscriptionOverrideFactory
    {
        return SubscriptionOverrideFactory::new();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // -------------------------------------------------------------------------
    // Value helpers
    // -------------------------------------------------------------------------

    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function limitValue(): ?int
    {
        return $this->value !== null ? (int) $this->value : null;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** @param Builder<SubscriptionOverride> $query */
    public function scopeForFeature(Builder $query, string $feature): void
    {
        $query->where('feature', $feature);
    }

    /** @param Builder<SubscriptionOverride> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /** @param Builder<SubscriptionOverride> $query */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
