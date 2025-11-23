<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->longText('message');
            $table->enum('message_type', ['text', 'version_update', 'status_change'])->default('text');

            // For version updates
            $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();

            // For status changes
            $table->string('status_change')->nullable();

            // Read tracking
            $table->json('read_by')->nullable(); // Array of user IDs who have read this message

            $table->timestamps();

            $table->index(['review_request_id', 'created_at']);
            $table->index(['sender_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_messages');
    }
};
