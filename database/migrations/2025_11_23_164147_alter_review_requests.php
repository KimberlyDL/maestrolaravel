<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            // Add approval workflow fields
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->after('status');
            $table->foreignId('approved_by')->nullable()->constrained('users')->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');

            // Add index for filtering
            $table->index(['publisher_org_id', 'approval_status']);
            $table->index(['submitted_by', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropIndex(['publisher_org_id', 'approval_status']);
            $table->dropIndex(['submitted_by', 'approval_status']);

            $table->dropColumn([
                'approval_status',
                'approved_by',
                'approved_at',
                'rejection_reason',
                'rejected_by',
                'rejected_at'
            ]);
        });
    }
};
