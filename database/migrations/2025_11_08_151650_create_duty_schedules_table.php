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
        Schema::create('duty_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location')->nullable();
            $table->enum('status', ['draft', 'published', 'completed', 'cancelled'])->default('draft');
            $table->enum('recurrence_type', ['none', 'daily', 'weekly', 'biweekly', 'monthly'])->default('none');
            $table->json('recurrence_days')->nullable(); // [0,1,2,3,4,5,6] for days of week
            $table->date('recurrence_end_date')->nullable();
            $table->integer('required_officers')->default(1);
            $table->json('metadata')->nullable(); // For additional data
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_schedule_id')->constrained()->onDelete('cascade');
            $table->foreignId('officer_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['assigned', 'confirmed', 'declined', 'completed', 'no_show'])->default('assigned');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['duty_schedule_id', 'officer_id']);
        });

        Schema::create('duty_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('availability_type', ['available', 'unavailable', 'preferred'])->default('available');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });

        Schema::create('duty_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_assignment_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_officer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('to_officer_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->text('reason');
            $table->enum('status', ['pending', 'accepted', 'approved', 'declined', 'cancelled'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('duty_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('required_officers')->default(1);
            $table->json('default_days')->nullable(); // Days of week this applies to
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('duty_swap_requests');
        Schema::dropIfExists('duty_availability');
        Schema::dropIfExists('duty_assignments');
        Schema::dropIfExists('duty_schedules');
        Schema::dropIfExists('duty_templates');
    }
};
