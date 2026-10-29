<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Diisi teknisi saat pekerjaan selesai; pelanggan baru dapat membayar setelah ini.
            $table->dateTime('service_finished_at')->nullable()->after('completed_at');
        });

        Schema::table('reviews', function (Blueprint $table) {
            // Label "Apa yang paling membantu?" dipisah koma.
            $table->string('tags', 255)->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('service_finished_at'));
        Schema::table('reviews', fn (Blueprint $t) => $t->dropColumn('tags'));
    }
};
