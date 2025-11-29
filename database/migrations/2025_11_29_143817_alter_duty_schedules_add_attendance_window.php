<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_schedules', function (Blueprint $table) {
            // AM Session
            $table->time('am_check_in_start')->nullable()->after('start_time');
            $table->time('am_check_in_end')->nullable()->after('am_check_in_start');
            $table->time('am_check_out_start')->nullable()->after('am_check_in_end');
            $table->time('am_check_out_end')->nullable()->after('am_check_out_start');

            // PM Session
            $table->time('pm_check_in_start')->nullable()->after('am_check_out_end');
            $table->time('pm_check_in_end')->nullable()->after('pm_check_in_start');
            $table->time('pm_check_out_start')->nullable()->after('pm_check_in_end');
            $table->time('pm_check_out_end')->nullable()->after('pm_check_out_start');

            // Flags
            $table->boolean('has_am_session')->default(true);
            $table->boolean('has_pm_session')->default(false);
        });

        Schema::table('duty_assignments', function (Blueprint $table) {
            // Split check-in/out into AM and PM
            $table->timestamp('am_check_in_at')->nullable()->after('check_in_at');
            $table->timestamp('am_check_out_at')->nullable()->after('am_check_in_at');
            $table->timestamp('pm_check_in_at')->nullable()->after('am_check_out_at');
            $table->timestamp('pm_check_out_at')->nullable()->after('pm_check_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('duty_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'am_check_in_start',
                'am_check_in_end',
                'am_check_out_start',
                'am_check_out_end',
                'pm_check_in_start',
                'pm_check_in_end',
                'pm_check_out_start',
                'pm_check_out_end',
                'has_am_session',
                'has_pm_session'
            ]);
        });

        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropColumn(['am_check_in_at', 'am_check_out_at', 'pm_check_in_at', 'pm_check_out_at']);
        });
    }
};
