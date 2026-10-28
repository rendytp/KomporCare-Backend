<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Percakapan: 'technician' (pelanggan <-> teknisi untuk satu pesanan) atau 'support' (pelanggan <-> CS KomporCare).
        Schema::create('conversations', function (Blueprint $table) {
            $table->id('conversation_id');
            $table->enum('type', ['technician', 'support']);
            $table->integer('customer_id');               // users.user_id (int signed)
            $table->integer('technician_user_id')->nullable(); // users.user_id teknisi, null untuk CS
            $table->integer('order_id')->nullable();      // orders.order_id
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('technician_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('order_id')->references('order_id')->on('orders')->nullOnDelete();
            $table->index(['customer_id', 'last_message_at']);
        });

        // Pesan. sender_role: customer | technician | support | system
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id('message_id');
            $table->unsignedBigInteger('conversation_id');
            $table->integer('sender_id')->nullable();     // null untuk CS/sistem
            $table->enum('sender_role', ['customer', 'technician', 'support', 'system']);
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversations');
    }
};
