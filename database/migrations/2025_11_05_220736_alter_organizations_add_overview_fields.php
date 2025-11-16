<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'mission')) {
                $table->text('mission')->nullable()->after('description');
            }
            if (!Schema::hasColumn('organizations', 'vision')) {
                $table->text('vision')->nullable()->after('mission');
            }
            if (!Schema::hasColumn('organizations', 'logo')) {
                $table->string('logo')->nullable()->after('vision');
            }
            if (!Schema::hasColumn('organizations', 'website')) {
                $table->string('website')->nullable()->after('logo');
            }
            if (!Schema::hasColumn('organizations', 'invite_code')) {
                $table->string('invite_code', 20)->nullable()->unique()->after('website');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['mission', 'vision', 'logo', 'website', 'invite_code']);
        });
    }
};
