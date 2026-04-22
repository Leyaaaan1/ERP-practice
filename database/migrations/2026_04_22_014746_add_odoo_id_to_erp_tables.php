<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('odoo_id')->nullable()->unique()->after('id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('odoo_id')->nullable()->unique()->after('id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedInteger('odoo_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) { $table->dropColumn('odoo_id'); });
        Schema::table('products',  function (Blueprint $table) { $table->dropColumn('odoo_id'); });
        Schema::table('sales_orders', function (Blueprint $table) { $table->dropColumn('odoo_id'); });
    }
};