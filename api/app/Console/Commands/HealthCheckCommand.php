<?php

namespace App\Console\Commands;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Location;
use App\Models\Promotion;
use App\Models\SeatLayout;
use App\Models\Tenant;
use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/** Environment diagnostic — the "is my install healthy?" command. */
class HealthCheckCommand extends Command
{
    protected $signature = 'transithub:health';

    protected $description = 'Check environment, database, demo data and gateway configuration';

    public function handle(): int
    {
        $ok = true;
        $check = function (string $label, callable $fn) use (&$ok) {
            try {
                [$pass, $detail] = $fn();
                $this->line(($pass ? '  <info>✓</info>' : '  <error>✗</error>')." {$label}".($detail ? " — {$detail}" : ''));
                $ok = $ok && $pass;
            } catch (\Throwable $e) {
                $this->line("  <error>✗</error> {$label} — ".$e->getMessage());
                $ok = false;
            }
        };

        $check('App environment', fn () => [app()->environment() !== 'production' || config('app.demo_seeding'), app()->environment()]);
        $check('App key set', fn () => [(bool) config('app.key'), null]);
        $check('Database connection', fn () => [DB::connection()->getPdo() !== null, DB::connection()->getDriverName().'@'.DB::connection()->getDatabaseName()]);
        $check('Migrations applied', fn () => [Schema::hasTable('bookings') && Schema::hasTable('trip_seat_legs'), DB::table('migrations')->count().' ran']);
        $check('Roles seeded', fn () => [Role::where('name', 'tenant_admin')->exists(), Role::count().' roles']);

        $check('Platform admin exists + password works', function () {
            $u = User::where('email', 'admin@transithub.test')->first();

            return [$u && $u->status === 'active' && Hash::check('password', $u->password), $u?->status ?? 'missing'];
        });
        $check('Tenants', fn () => [Tenant::count() >= 2, Tenant::count().' tenants']);
        $check('Zimbabwe locations', fn () => [Location::active()->count() >= 30, Location::count().' locations']);
        $check('Routes', fn () => [BusRoute::count() >= 10, BusRoute::count().' routes']);
        $check('Buses', fn () => [Bus::count() >= 4, Bus::count().' buses']);
        $check('Seat layouts published', fn () => [SeatLayout::where('status', 'published')->count() >= 3, SeatLayout::count().' layouts']);
        $check('Trip seat inventory', fn () => [TripSeat::count() > 0, TripSeat::count().' trip seats']);
        $check('Bookable trips ahead', fn () => [Trip::where('status', 'scheduled')->where('departure_at', '>', now())->count() > 0, Trip::count().' trips']);
        $check('Promotions', fn () => [Promotion::where('status', 'active')->count() >= 1, Promotion::count().' promotions']);
        $check('Paynow configured', fn () => [
            (bool) (config('transithub.payment_gateways.card.integration_id') && config('transithub.payment_gateways.card.integration_key')),
            config('transithub.payment_gateways.card.integration_id') ? 'integration id set' : 'not configured — card payments disabled (cash still works)',
        ]);

        $this->newLine();
        $ok ? $this->info('All checks passed — environment is ready.') : $this->error('Some checks failed — see above.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
