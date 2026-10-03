<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // amount_mismatch | late_payment | status_conflict
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->string('status')->default('open')->index(); // open | resolved
            $table->text('notes')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->index()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
        Schema::dropIfExists('payment_discrepancies');
    }
};
