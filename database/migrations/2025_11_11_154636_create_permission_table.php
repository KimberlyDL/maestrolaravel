<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'manage_members'
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->string('category'); // e.g., 'members', 'documents', 'reviews'
            $table->timestamps();
        });

        // Create organization_user_permissions pivot table
        Schema::create('organization_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'permission_id'], 'org_user_perm_unique');
            $table->index(['organization_id', 'user_id']);
        });

        // Add custom_role column to organization_user
        Schema::table('organization_user', function (Blueprint $table) {
            $table->string('custom_role')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropColumn('custom_role');
        });

        Schema::dropIfExists('organization_user_permissions');
        Schema::dropIfExists('permissions');
    }
};
