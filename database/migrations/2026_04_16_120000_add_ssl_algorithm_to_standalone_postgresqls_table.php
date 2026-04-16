<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('standalone_postgresqls') && ! Schema::hasColumn('standalone_postgresqls', 'ssl_algorithm')) {
            Schema::table('standalone_postgresqls', function (Blueprint $table) {
                $table->enum('ssl_algorithm', ['prime256v1', 'rsa-2048', 'secp521r1'])->default('secp521r1')->after('ssl_mode');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('standalone_postgresqls') && Schema::hasColumn('standalone_postgresqls', 'ssl_algorithm')) {
            Schema::table('standalone_postgresqls', function (Blueprint $table) {
                $table->dropColumn('ssl_algorithm');
            });
        }
    }
};
