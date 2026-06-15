<?php

declare(strict_types=1);

namespace Laracaise\Billing\Console\Commands;

use Illuminate\Console\Command;

final class BillingInstallCommand extends Command
{
    protected $signature = 'billing:install {--force : Overwrite existing published files}';

    protected $description = 'Publish Laracaise Billing configuration and migrations.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', [
            '--tag' => 'laracaise-billing-config',
            '--force' => $force,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'laracaise-billing-migrations',
            '--force' => $force,
        ]);

        $this->info('Laracaise Billing scaffolding published.');

        return self::SUCCESS;
    }
}
