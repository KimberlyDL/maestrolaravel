<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_comment_id')->nullable()->constrained('review_comments')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('file_path');
            $table->string('label')->nullable();

            $table->timestamps();

            $table->index(['review_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_attachments');
    }
};
