<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_org_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('action'); // submitted, sent, viewed, commented, requested_changes, approved, declined, reassigned, closed, reopened, reminded, version_uploaded
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['review_request_id', 'action', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_actions');
    }
};
