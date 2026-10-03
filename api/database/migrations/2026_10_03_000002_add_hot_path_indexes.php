<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_seat_legs', function (Blueprint $table) {
            // Hold-expiry sweep: state='held' AND held_until < now
            $table->index(['state', 'held_until'], 'tsl_state_held_until_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Booking expiry sweep + agent "recent bookings" queries
            $table->index(['status', 'expires_at'], 'bookings_status_expires_idx');
            $table->index(['tenant_id', 'created_at'], 'bookings_tenant_created_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Finance exports + reconciliation polls ordered by created_at
            $table->index(['tenant_id', 'created_at'], 'payments_tenant_created_idx');
        });

        Schema::table('promotion_redemptions', function (Blueprint $table) {
            // Promo usage checks per booking and per-customer-limit lookups
            $table->index(['tenant_id', 'created_at'], 'promo_red_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trip_seat_legs', fn (Blueprint $t) => $t->dropIndex('tsl_state_held_until_idx'));
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropIndex('bookings_status_expires_idx');
            $t->dropIndex('bookings_tenant_created_idx');
        });
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_tenant_created_idx'));
        Schema::table('promotion_redemptions', fn (Blueprint $t) => $t->dropIndex('promo_red_tenant_created_idx'));
    }
};
