<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Context field to separate review documents from storage documents
            $table->enum('context', ['review', 'storage'])->default('review')->after('type');

            // Visibility for storage documents
            $table->enum('visibility', ['private', 'org', 'public'])->default('org')->after('context');

            // Additional storage-specific fields
            $table->text('description')->nullable()->after('title');
            $table->string('mime_type')->nullable()->after('type');
            $table->unsignedBigInteger('file_size')->default(0)->after('mime_type');
            $table->foreignId('uploaded_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('status');

            // Folder structure support (optional, for nested organization)
            $table->foreignId('parent_id')->nullable()->after('organization_id')->constrained('documents')->nullOnDelete();
            $table->boolean('is_folder')->default(false)->after('parent_id');

            // Indexing for performance
            $table->index(['organization_id', 'context', 'visibility']);
            $table->index(['parent_id', 'is_folder']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropForeign(['parent_id']);

            $table->dropIndex(['organization_id', 'context', 'visibility']);
            $table->dropIndex(['parent_id', 'is_folder']);
            $table->dropIndex(['uploaded_by']);

            $table->dropColumn([
                'context',
                'visibility',
                'description',
                'mime_type',
                'file_size',
                'uploaded_by',
                'published_at',
                'parent_id',
                'is_folder'
            ]);
        });
    }
};
