<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\PaymentFactory;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;

/**
 * @property string                   $id
 * @property string                   $subscriptionable_type
 * @property string                   $subscriptionable_id
 * @property string|null              $subscription_id
 * @property int                      $amount
 * @property string                   $currency
 * @property PaymentStatus            $status
 * @property PaymentType              $type
 * @property string|null              $provider
 * @property string|null              $provider_reference
 * @property array<string,mixed>|null $provider_response
 * @property array<string,mixed>|null $metadata
 * @property Carbon|null              $paid_at
 * @property Carbon|null              $created_at
 * @property Carbon|null              $updated_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'billing_payments';

    protected $fillable = [
        'subscriptionable_type',
        'subscriptionable_id',
        'subscription_id',
        'amount',
        'currency',
        'status',
        'type',
        'provider',
        'provider_reference',
        'provider_response',
        'metadata',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'integer',
            'status'            => PaymentStatus::class,
            'type'              => PaymentType::class,
            'provider_response' => 'array',
            'metadata'          => 'array',
            'paid_at'           => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return MorphTo<Model, $this> */
    public function subscriptionable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // -------------------------------------------------------------------------
    // Status / type checks
    // -------------------------------------------------------------------------

    public function isSucceeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isRefund(): bool
    {
        return $this->type === PaymentType::Refund;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** @param Builder<Payment> $query */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', PaymentStatus::Succeeded->value);
    }

    /** @param Builder<Payment> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending->value);
    }

    /** @param Builder<Payment> $query */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', PaymentStatus::Failed->value);
    }

    /** @param Builder<Payment> $query */
    public function scopeCharges(Builder $query): void
    {
        $query->where('type', PaymentType::Charge->value);
    }

    /** @param Builder<Payment> $query */
    public function scopeRefunds(Builder $query): void
    {
        $query->where('type', PaymentType::Refund->value);
    }

    /** @param Builder<Payment> $query */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /** @param Builder<Payment> $query */
    public function scopeForOwner(Builder $query, Model $owner): void
    {
        $query
            ->where('subscriptionable_type', $owner->getMorphClass())
            ->where('subscriptionable_id', $owner->getKey());
    }
}
