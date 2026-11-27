<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_order_rejections', function (Blueprint $table) {
            $table->id('rejection_id');
            $table->integer('order_id');
            $table->integer('technician_id');
            $table->string('reason_code', 40);
            $table->boolean('penalty_applied')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_id', 'technician_id'], 'technician_order_rejections_unique');
            $table->index(['technician_id', 'penalty_applied'], 'technician_rejection_penalty_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_order_rejections');
    }
};
