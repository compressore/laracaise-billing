<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\UsageRecordFactory;

/**
 * @property string      $id
 * @property string      $subscription_id
 * @property string      $feature
 * @property int         $quantity
 * @property Carbon|null $recorded_at
 * @property Carbon|null $created_at
 */
class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'billing_usage_records';

    // Append-only — the table has created_at but not updated_at
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'subscription_id',
        'feature',
        'quantity',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'recorded_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    protected static function newFactory(): UsageRecordFactory
    {
        return UsageRecordFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->created_at  ??= now();
            $record->recorded_at ??= now();
        });
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
    // Scopes
    // -------------------------------------------------------------------------

    /** @param Builder<UsageRecord> $query */
    public function scopeForFeature(Builder $query, string $feature): void
    {
        $query->where('feature', $feature);
    }

    /** @param Builder<UsageRecord> $query */
    public function scopeInPeriod(Builder $query, DateTimeInterface $start, DateTimeInterface $end): void
    {
        $query->whereBetween('recorded_at', [$start, $end]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isIncrement(): bool
    {
        return $this->quantity > 0;
    }

    public function isDecrement(): bool
    {
        return $this->quantity < 0;
    }
}
