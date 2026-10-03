<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Schedule;
use App\Models\SeatLayout;
use App\Models\Tenant;

/**
 * Operator onboarding checklist — gates "go live" until the tenant has the
 * minimum configuration (§57/58).
 */
class OnboardingService
{
    public function steps(Tenant $tenant): array
    {
        $hasBranding = ! empty($tenant->brandingValue('logo')) || ! empty($tenant->brandingValue('primary_color'));
        $hasBus = Bus::where('status', '!=', 'retired')->exists();
        $hasLayout = SeatLayout::where('status', 'published')->exists();
        $hasRoute = BusRoute::where('status', 'active')->whereHas('stops')->exists();
        $hasFare = BusRoute::where('status', 'active')->where('base_fare', '>', 0)->exists();
        $hasSchedule = Schedule::where('status', 'active')->exists();
        $hasStaff = $tenant->users()->count() > 0;
        $hasPayments = ! empty($tenant->setting('payment_methods'));
        $hasCancellation = ! empty($tenant->bookingRule('cancellation_policy'));

        return [
            'company_details' => ['done' => ! empty($tenant->legal_name) && ! empty($tenant->email), 'label' => 'Company details'],
            'branding' => ['done' => $hasBranding, 'label' => 'Branding'],
            'payment_methods' => ['done' => $hasPayments, 'label' => 'Payment methods'],
            'bus' => ['done' => $hasBus, 'label' => 'Add a bus'],
            'seat_layout' => ['done' => $hasLayout, 'label' => 'Publish a seat layout'],
            'route' => ['done' => $hasRoute, 'label' => 'Route with stops'],
            'fare' => ['done' => $hasFare, 'label' => 'Fares'],
            'schedule' => ['done' => $hasSchedule, 'label' => 'Schedule'],
            'cancellation_policy' => ['done' => (bool) $hasCancellation, 'label' => 'Cancellation policy'],
            'invite_staff' => ['done' => $hasStaff, 'label' => 'Invite staff'],
        ];
    }

    public function progress(Tenant $tenant): array
    {
        $steps = $this->steps($tenant);
        $done = collect($steps)->where('done')->count();

        return [
            'steps' => $steps,
            'complete' => $done === count($steps),
            'completed' => $done,
            'total' => count($steps),
            'remediation' => collect($steps)->where('done', false)->keys()->map(fn ($k) => $steps[$k]['label'])->values(),
        ];
    }

    public function canGoLive(Tenant $tenant): bool
    {
        $steps = $this->steps($tenant);

        return collect($steps)->except('invite_staff')->every(fn ($s) => $s['done']);
    }
}
