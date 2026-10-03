<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->time('departure_time');
            $table->json('days_of_week');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('fare_override', 10, 2)->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            $table->foreignId('bus_id')->constrained()->restrictOnDelete();
            $table->string('driver_name')->nullable();
            $table->timestamp('departure_at')->index();
            $table->timestamp('arrival_estimate_at')->nullable();
            $table->decimal('base_fare', 10, 2);
            $table->string('status')->default('scheduled')->index();
            $table->timestamp('delayed_until')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'departure_at']);
            $table->index(['tenant_id', 'status', 'departure_at']);
        });

        Schema::create('trip_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained('seat_layout_seats')->cascadeOnDelete();
            $table->unsignedTinyInteger('deck')->default(1);
            $table->unsignedSmallInteger('row');
            $table->unsignedSmallInteger('col');
            $table->string('label');
            $table->string('type')->default('standard');
            $table->string('position')->default('none');
            $table->decimal('price_modifier', 8, 2)->default(0);
            $table->string('state')->default('available')->index();
            $table->timestamps();
            $table->unique(['trip_id', 'seat_id']);
            $table->index(['tenant_id', 'trip_id', 'state']);
        });

        Schema::create('trip_seat_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_seat_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('leg_index');
            $table->string('state')->default('available')->index();
            $table->timestamp('held_until')->nullable()->index();
            $table->string('hold_token')->nullable();
            $table->foreignId('booking_id')->nullable();
            $table->timestamps();
            $table->unique(['trip_seat_id', 'leg_index']);
            $table->index(['tenant_id', 'trip_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_seat_legs');
        Schema::dropIfExists('trip_seats');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('schedules');
    }
};
