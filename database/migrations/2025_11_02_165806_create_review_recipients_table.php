<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_org_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->enum('status', ['pending', 'viewed', 'commented', 'approved', 'declined', 'expired'])
                ->default('pending');

            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();

            $table->unique(['review_request_id', 'reviewer_user_id']);
            $table->index(['reviewer_user_id', 'status']);
            $table->index(['review_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_recipients');
    }
};
