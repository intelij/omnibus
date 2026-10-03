<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('origin');
            $table->string('destination');
            $table->unsignedInteger('distance_km')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('base_fare', 10, 2)->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['origin', 'destination']);
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->boolean('can_board')->default(true);
            $table->boolean('can_alight')->default(true);
            $table->unsignedInteger('offset_minutes')->default(0);
            $table->timestamps();
            $table->unique(['route_id', 'sequence']);
            $table->index(['tenant_id', 'route_id']);
        });

        Schema::create('route_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('from_sequence')->nullable();
            $table->unsignedSmallInteger('to_sequence')->nullable();
            $table->string('passenger_type')->default('adult');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_fares');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
    }
};
