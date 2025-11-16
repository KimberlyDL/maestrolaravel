<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // owner org
            $table->string('title');
            $table->enum('type', ['finance_report', 'event_proposal', 'other'])->default('other');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Will add the FK for this in a later migration to avoid circular dependency
            $table->unsignedBigInteger('latest_version_id')->nullable();
            // optional high-level state (your app may compute this from review threads)
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
