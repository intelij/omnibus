<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('id_document')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'last_name']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_reference', 12)->unique();
            $table->string('status')->default('held')->index();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('ticket_type')->default('dated');
            $table->string('contact_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone');
            $table->foreignId('boarding_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->foreignId('dropoff_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->unsignedSmallInteger('boarding_sequence');
            $table->unsignedSmallInteger('dropoff_sequence');
            $table->string('idempotency_key')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'trip_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('id_document')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('passenger_type')->default('adult');
            $table->string('emergency_contact')->nullable();
            $table->string('special_requirements')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id']);
        });

        Schema::create('booking_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->nullable()->constrained()->nullOnDelete();
            $table->string('seat_label');
            $table->decimal('fare', 10, 2);
            $table->unsignedSmallInteger('boarding_sequence');
            $table->unsignedSmallInteger('dropoff_sequence');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['booking_id', 'trip_seat_id']);
            $table->index(['tenant_id', 'trip_seat_id', 'status']);
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'booking_id']);
            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('booking_seats');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('passengers');
    }
};
