<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add settings columns to organizations table
        Schema::table('organizations', function (Blueprint $table) {
            // Access Control Settings
            $table->boolean('auto_accept_invites')->default(false)->after('invite_code');
            $table->boolean('public_profile')->default(true)->after('auto_accept_invites');
            $table->boolean('member_can_invite')->default(false)->after('public_profile');

            // Data & Privacy Settings
            $table->integer('log_retention_days')->default(365)->after('member_can_invite');

            // Organization Status
            $table->boolean('is_archived')->default(false)->after('log_retention_days');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        // Add image column to announcements table
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('content');
        });

        // Create organization_ownership_transfers table for tracking ownership transfers
        Schema::create('organization_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('to_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'auto_accept_invites',
                'public_profile',
                'member_can_invite',
                'log_retention_days',
                'is_archived',
                'archived_at'
            ]);
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::dropIfExists('organization_ownership_transfers');
    }
};
