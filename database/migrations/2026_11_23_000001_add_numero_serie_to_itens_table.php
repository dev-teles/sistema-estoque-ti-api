<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens', function (Blueprint $table) {
            $table->string('numero_serie', 4)->nullable()->after('nome');
            $table->index(['numero_serie']);
        });
    }

    public function down(): void
    {
        Schema::table('itens', function (Blueprint $table) {
            $table->dropIndex(['numero_serie']);
            $table->dropColumn('numero_serie');
        });
    }
};
