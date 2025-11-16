<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('location_address')->nullable()->after('website');
            $table->decimal('location_lat', 10, 7)->nullable()->after('location_address');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['location_address', 'location_lat', 'location_lng']);
        });
    }
};