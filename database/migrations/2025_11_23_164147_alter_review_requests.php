<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            // Add admin approval fields
            $table->boolean('requires_admin_approval')->default(false)->after('status');
            $table->enum('admin_approval_status', ['pending', 'approved', 'declined'])->nullable()->after('requires_admin_approval');
            $table->foreignId('approved_by')->nullable()->after('admin_approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('admin_note')->nullable()->after('approved_at');

            // Index for querying pending approvals
            $table->index(['publisher_org_id', 'admin_approval_status', 'created_at'], 'idx_org_approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex('idx_org_approval_status');
            $table->dropColumn([
                'requires_admin_approval',
                'admin_approval_status',
                'approved_by',
                'approved_at',
                'admin_note'
            ]);
        });
    }
};
