<?php
// database/migrations/2024_XX_XX_XXXXXX_update_document_shares_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing document_shares table with new columns
        Schema::table('document_shares', function (Blueprint $table) {
            // Drop old columns if they exist and recreate
            if (Schema::hasColumn('document_shares', 'share_token')) {
                // Keep share_token as is
            } else {
                $table->string('share_token', 60)->unique();
            }

            if (!Schema::hasColumn('document_shares', 'password')) {
                $table->string('password')->nullable()->after('access_level');
            }

            if (!Schema::hasColumn('document_shares', 'max_downloads')) {
                $table->integer('max_downloads')->nullable()->after('password');
            }

            if (!Schema::hasColumn('document_shares', 'download_count')) {
                $table->integer('download_count')->default(0)->after('max_downloads');
            }

            if (!Schema::hasColumn('document_shares', 'allowed_ips')) {
                $table->json('allowed_ips')->nullable()->after('download_count');
            }

            if (!Schema::hasColumn('document_shares', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users');
            }

            if (!Schema::hasColumn('document_shares', 'revoked_by')) {
                $table->foreignId('revoked_by')->nullable()->constrained('users');
            }

            if (!Schema::hasColumn('document_shares', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable();
            }

            // Update indexes
            $table->index('access_level');
            $table->index('created_by');
            $table->index('revoked_at');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_shares', function (Blueprint $table) {
            $table->dropForeignIdFor('updated_by');
            $table->dropForeignIdFor('revoked_by');

            $table->dropColumn([
                'password',
                'max_downloads',
                'download_count',
                'allowed_ips',
                'updated_by',
                'revoked_by',
                'revoked_at',
            ]);

            $table->dropIndex(['access_level']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['expires_at']);
        });
    }
};