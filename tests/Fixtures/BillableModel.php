<?php

declare(strict_types=1);

namespace Laracaise\Billing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Concerns\Billable;

/**
 * In-test billable model backed by the 'test_billables' table.
 * Used to verify polymorphic ownership without hardcoding real app models.
 */
class BillableModel extends Model
{
    use Billable;
    use HasUlids;

    protected $table = 'test_billables';

    protected $guarded = [];
}
