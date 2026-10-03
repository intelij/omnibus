<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_layout_seats', function (Blueprint $table) {
            $table->string('side')->nullable()->after('position'); // left | right | center
        });
        Schema::table('trip_seats', function (Blueprint $table) {
            $table->string('side')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('seat_layout_seats', fn (Blueprint $t) => $t->dropColumn('side'));
        Schema::table('trip_seats', fn (Blueprint $t) => $t->dropColumn('side'));
    }
};
