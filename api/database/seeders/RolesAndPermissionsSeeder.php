<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'bookings.view', 'bookings.create', 'bookings.update', 'bookings.cancel', 'bookings.refund', 'bookings.export',
        'payments.view', 'payments.reconcile', 'payments.refund', 'payments.export', 'payments.confirm_cash', 'payments.configure',
        'buses.view', 'buses.create', 'buses.update', 'buses.delete',
        'seat_layouts.view', 'seat_layouts.create', 'seat_layouts.update', 'seat_layouts.delete',
        'routes.view', 'routes.create', 'routes.update', 'routes.delete',
        'schedules.view', 'schedules.create', 'schedules.update', 'schedules.cancel',
        'trips.view', 'trips.update', 'trips.cancel', 'trips.manifest',
        'users.view', 'users.create', 'users.update', 'users.disable',
        'reports.view', 'reports.export',
        'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
        'support.view', 'support.create', 'support.update', 'support.assign', 'support.close',
        'settings.view', 'settings.update', 'branding.view', 'branding.update',
        'audit.view', 'tickets.verify', 'tickets.resend',
        'tenants.manage', 'platform.reports',
    ];

    public const ROLE_PERMISSIONS = [
        'tenant_owner' => '*',
        'tenant_admin' => [
            'bookings.view', 'bookings.create', 'bookings.update', 'bookings.cancel', 'bookings.export',
            'payments.view',
            'buses.view', 'buses.create', 'buses.update',
            'seat_layouts.view', 'seat_layouts.create', 'seat_layouts.update',
            'routes.view', 'routes.create', 'routes.update',
            'schedules.view', 'schedules.create', 'schedules.update', 'schedules.cancel',
            'trips.view', 'trips.update', 'trips.cancel', 'trips.manifest',
            'users.view', 'users.create', 'users.update', 'users.disable',
            'reports.view', 'reports.export',
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
            'support.view', 'support.create', 'support.update', 'support.assign', 'support.close',
            'settings.view', 'settings.update', 'branding.view', 'branding.update',
            'audit.view', 'tickets.verify', 'tickets.resend',
        ],
        'booking_agent' => [
            'bookings.view', 'bookings.create', 'bookings.update',
            'buses.view', 'routes.view', 'schedules.view', 'trips.view', 'trips.manifest',
            'support.view', 'support.create', 'tickets.verify', 'tickets.resend',
        ],
        'finance_officer' => [
            'bookings.view', 'payments.view', 'payments.reconcile', 'payments.refund',
            'payments.export', 'payments.confirm_cash',
            'trips.view', 'reports.view', 'reports.export',
        ],
        'support_agent' => [
            'bookings.view', 'trips.view', 'support.view', 'support.create',
            'support.update', 'support.assign', 'tickets.resend',
        ],
        'platform_super_admin' => ['tenants.manage', 'platform.reports', 'audit.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            $roleModel = Role::findOrCreate($role, 'sanctum');

            if ($permissions === '*') {
                $roleModel->syncPermissions(Permission::where('guard_name', 'sanctum')->get());
            } else {
                $roleModel->syncPermissions(collect($permissions)->map(
                    fn ($p) => Permission::findOrCreate($p, 'sanctum')
                ));
            }
        }
    }
}
