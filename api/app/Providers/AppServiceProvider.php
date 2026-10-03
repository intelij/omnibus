<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Schedule;
use App\Models\SeatLayout;
use App\Models\SupportTicket;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Policies\BusPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PromotionPolicy;
use App\Policies\RoutePolicy;
use App\Policies\SchedulePolicy;
use App\Policies\SeatLayoutPolicy;
use App\Policies\SupportTicketPolicy;
use App\Policies\TicketPolicy;
use App\Policies\TripPolicy;
use App\Policies\UserPolicy;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Bus::class, BusPolicy::class);
        Gate::policy(SeatLayout::class, SeatLayoutPolicy::class);
        Gate::policy(BusRoute::class, RoutePolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(Trip::class, TripPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by(($r->input('email') ?? '').'|'.$r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('bookings', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
    }
}
