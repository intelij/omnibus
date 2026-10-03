<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Payments\GatewayManager;
use App\Services\FeatureFlags;
use App\Support\Audit;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $tenant = tenant();

        return response()->json([
            'tenant' => $tenant,
            'branding' => $tenant->branding,
            'booking_rules' => $tenant->setting('booking_rules', config('transithub.default_booking_rules')),
            'payment_methods' => $tenant->setting('payment_methods', ['cash']),
            'available_gateways' => app(GatewayManager::class)->available(),
            'features' => FeatureFlags::all($tenant),
            'plan' => $tenant->plan,
        ]);
    }

    public function updateBranding(Request $request)
    {
        abort_unless($request->user()->can('branding.update'), 403);

        $data = $request->validate([
            'logo' => ['nullable', 'string', 'max:500'],
            'favicon' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_text' => ['nullable', 'string', 'max:200'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'font_family' => ['nullable', 'string', 'max:80'],
        ]);

        $tenant = tenant();
        $tenant->update(['branding' => array_merge($tenant->branding ?? [], array_filter($data, fn ($v) => $v !== null))]);
        Audit::record('branding.updated', $tenant, $data);

        return response()->json($tenant->fresh()->branding);
    }

    public function updateRules(Request $request)
    {
        abort_unless($request->user()->can('settings.update'), 403);

        $data = $request->validate([
            'seat_hold_minutes' => ['nullable', 'integer', 'min:2', 'max:60'],
            'pending_payment_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'max_seats_per_booking' => ['nullable', 'integer', 'min:1', 'max:60'],
            'booking_cutoff_minutes' => ['nullable', 'integer', 'min:0'],
            'cash_seat_policy' => ['nullable', 'in:hold,booked'],
            'passenger_fields' => ['nullable', 'array'],
            'cancellation_policy' => ['nullable', 'array'],
            'cancellation_policy.*.min_hours_before' => ['required', 'integer', 'min:0'],
            'cancellation_policy.*.percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['booking_rules'] = array_merge($settings['booking_rules'] ?? [], $data);
        $tenant->update(['settings' => $settings]);
        Audit::record('booking_rules.updated', $tenant, $data);

        return response()->json($settings['booking_rules']);
    }

    public function updatePayments(Request $request)
    {
        abort_unless($request->user()->can('payments.configure'), 403);

        $data = $request->validate([
            'methods' => ['required', 'array', 'min:1'],
            'methods.*' => ['in:cash,card,ecocash,inbucks'],
        ]);

        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['payment_methods'] = $data['methods'];
        $tenant->update(['settings' => $settings]);
        Audit::record('payment_methods.updated', $tenant, $data);

        return response()->json(['payment_methods' => $data['methods']]);
    }
}
