<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('recipient_name', 100)->nullable()->after('label');
            $table->string('recipient_phone', 20)->nullable()->after('recipient_name');
            $table->string('note', 255)->nullable()->after('postal_code'); // patokan / catatan untuk teknisi
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_phone', 'note']);
        });
    }
};
