<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('town'); // city | town
            $table->string('province')->nullable();
            $table->string('country_code', 2)->default('ZW');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['slug', 'country_code']);
            $table->index(['country_code', 'name']);
        });

        // Stops may link to a canonical location (optional — free-text names
        // stay supported for granular stops like depots/landmarks).
        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('name')
                ->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::dropIfExists('locations');
    }
};
