<?php

declare(strict_types=1);

namespace Laracaise\Billing\ValueObjects;

readonly class FeatureValue
{
    /**
     * @param string  $feature    Machine key matching billing_plan_features.feature
     * @param ?string $value      null = unlimited; 'true'/'false' = flag; numeric string = hard limit
     * @param bool    $resettable Whether usage resets each billing period (always from the plan feature)
     * @param string  $source     'plan' or 'override'
     */
    public function __construct(
        public string $feature,
        public ?string $value,
        public bool $resettable,
        public string $source,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    public function isFlag(): bool
    {
        return $this->value === 'true' || $this->value === 'false';
    }

    public function flagValue(): bool
    {
        return $this->value === 'true';
    }

    /** Returns the hard limit, or null when the feature is unlimited or a flag. */
    public function limit(): ?int
    {
        if ($this->isUnlimited() || $this->isFlag()) {
            return null;
        }

        return (int) $this->value;
    }
}
