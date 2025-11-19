<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_share_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained('document_shares')->onDelete('cascade');
            $table->foreignId('document_id')->nullable()->constrained('documents')->onDelete('cascade');
            $table->string('type'); // 'document_accessed', 'document_downloaded', 'invalid_token', 'expired_token', etc.
            $table->boolean('success')->default(true);
            $table->string('ip_address', 45); // Support both IPv4 and IPv6
            $table->text('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index('share_id');
            $table->index('document_id');
            $table->index('type');
            $table->index('success');
            $table->index('ip_address');
            $table->index('created_at');
            $table->index(['share_id', 'created_at']);
            $table->index(['share_id', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_share_access_logs');
    }
};
