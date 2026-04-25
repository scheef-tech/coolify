<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('environments') && ! Schema::hasColumn('environments', 'is_dev_pullable')) {
            Schema::table('environments', function (Blueprint $table) {
                $table->boolean('is_dev_pullable')->default(false)->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('environments') && Schema::hasColumn('environments', 'is_dev_pullable')) {
            Schema::table('environments', function (Blueprint $table) {
                $table->dropColumn('is_dev_pullable');
            });
        }
    }
};
