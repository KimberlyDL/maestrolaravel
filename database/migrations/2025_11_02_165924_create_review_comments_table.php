<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_org_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->longText('body');
            $table->boolean('is_internal')->default(false); // optional

            // nested replies (optional)
            $table->foreignId('parent_id')->nullable()->constrained('review_comments')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['review_request_id', 'created_at']);
            $table->index(['author_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};
