<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_schedules', function (Blueprint $table) {
            // Simple attendance window for the whole event
            $table->time('check_in_window_start')->nullable()->after('start_time');
            $table->time('check_in_window_end')->nullable()->after('check_in_window_start');
            $table->time('check_out_window_start')->nullable()->after('check_in_window_end');
            $table->time('check_out_window_end')->nullable()->after('check_out_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('duty_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_window_start',
                'check_in_window_end',
                'check_out_window_start',
                'check_out_window_end'
            ]);
        });
    }
};
