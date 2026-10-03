<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-command demo setup: connectivity check → migrate:fresh → demo seeders.
 * Destructive by design — it rebuilds the database for disposable dev envs.
 */
class SeedDemoCommand extends Command
{
    protected $signature = 'transithub:seed-demo {--yes : Skip the destructive-reset confirmation}';

    protected $description = 'Rebuild the database and seed the full TransitHub demo dataset';

    public function handle(): int
    {
        if (app()->isProduction() && ! config('app.demo_seeding')) {
            $this->error('Refusing to seed demo data in production. Set SEED_DEMO_IN_PRODUCTION=1 to override.');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Database is not reachable: '.$e->getMessage());
            $this->line('Check DB_* settings — see docs/MYSQL_SETUP.md');

            return self::FAILURE;
        }

        $this->info('Database: '.DB::connection()->getDriverName().' @ '.DB::connection()->getDatabaseName());

        if (! $this->option('yes') && ! $this->confirm('migrate:fresh DROPS ALL TABLES. Continue?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->call('transithub:health');

        return self::SUCCESS;
    }
}
