<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            
            // Notification type (duty.assigned, duty.swap_requested, etc.)
            $table->string('type');
            
            // Notification title and message
            $table->string('title');
            $table->text('message');
            
            // Related entity (polymorphic)
            $table->nullableMorphs('notifiable');
            
            // Action URL (where to go when clicked)
            $table->string('action_url')->nullable();
            
            // Additional metadata
            $table->json('data')->nullable();
            
            // Read status
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            
            // Priority (low, normal, high, urgent)
            $table->string('priority')->default('normal');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
public function down(): void
    {
        // Change 'notification' to 'notifications'
        Schema::dropIfExists('notifications'); 
    }
};
