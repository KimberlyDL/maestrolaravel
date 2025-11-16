<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('publisher_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();

            $table->string('subject');
            $table->longText('body')->nullable();

            // overall thread status
            $table->enum('status', [
                'draft',
                'sent',
                'in_review',
                'changes_requested',
                'approved',
                'declined',
                'closed',
                'cancelled'
            ])->default('draft');

            $table->timestamp('due_at')->nullable(); // optional global due date/window

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['publisher_org_id', 'status', 'due_at']);
            $table->index(['submitted_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
    }
};
