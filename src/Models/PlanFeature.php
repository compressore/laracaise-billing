<?php

declare(strict_types=1);

namespace Laracaise\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Database\Factories\PlanFeatureFactory;

/**
 * @property string      $id
 * @property string      $plan_id
 * @property string      $feature
 * @property string|null $value
 * @property bool        $resettable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanFeature extends Model
{
    /** @use HasFactory<PlanFeatureFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'billing_plan_features';

    protected $fillable = [
        'plan_id',
        'feature',
        'value',
        'resettable',
    ];

    protected function casts(): array
    {
        return [
            'resettable' => 'boolean',
        ];
    }

    protected static function newFactory(): PlanFeatureFactory
    {
        return PlanFeatureFactory::new();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @param Builder<PlanFeature> $query */
    public function scopeForFeature(Builder $query, string $feature): void
    {
        $query->where('feature', $feature);
    }

    /** @param Builder<PlanFeature> $query */
    public function scopeResettable(Builder $query): void
    {
        $query->where('resettable', true);
    }

    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    public function isFlag(): bool
    {
        return in_array($this->value, ['true', 'false'], strict: true);
    }

    public function flagValue(): bool
    {
        return $this->value === 'true';
    }

    public function limitValue(): ?int
    {
        if ($this->isUnlimited() || $this->isFlag()) {
            return null;
        }

        return (int) $this->value;
    }
}
