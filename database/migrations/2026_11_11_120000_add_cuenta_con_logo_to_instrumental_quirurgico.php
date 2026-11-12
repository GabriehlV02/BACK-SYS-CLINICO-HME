<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('instrumental_quirurgico', function (Blueprint $table): void {
            $table->boolean('cuenta_con_logo')->default(false);
        });
    }
    public function down(): void
    {
        Schema::table('instrumental_quirurgico', function (Blueprint $table): void {
            $table->dropColumn('cuenta_con_logo');
        });
    }
};
