<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('latest_version_id')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete(); // if version is deleted, null the pointer
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['latest_version_id']);
        });
    }
};
