<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('decks')->default(1);
            $table->unsignedSmallInteger('rows')->default(0);
            $table->unsignedTinyInteger('cols')->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('seat_layout_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_layout_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('deck')->default(1);
            $table->unsignedSmallInteger('row');
            $table->unsignedSmallInteger('col');
            $table->string('label');
            $table->unsignedSmallInteger('seat_number')->nullable();
            $table->string('type')->default('standard');
            $table->string('position')->default('none');
            $table->decimal('price_modifier', 8, 2)->default(0);
            $table->boolean('is_bookable')->default(true);
            $table->json('attributes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['seat_layout_id', 'deck', 'row', 'col']);
            $table->unique(['seat_layout_id', 'label']);
            $table->index(['tenant_id', 'seat_layout_id']);
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_layout_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_number');
            $table->string('fleet_number')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('bus_type')->default('coach');
            $table->unsignedTinyInteger('decks')->default(1);
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->json('amenities')->nullable();
            $table->json('accessibility')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'registration_number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buses');
        Schema::dropIfExists('seat_layout_seats');
        Schema::dropIfExists('seat_layouts');
    }
};
