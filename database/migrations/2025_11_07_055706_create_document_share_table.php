<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
            $table->string('share_token')->unique(); // Random token for sharing
            $table->enum('access_level', ['org_only', 'public', 'link'])->default('org_only');
            // org_only: only org members can access
            // public: anyone with link can access
            // link: shareable link (same as public for now, can extend later)
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'access_level']);
            $table->index('share_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');
    }
};
