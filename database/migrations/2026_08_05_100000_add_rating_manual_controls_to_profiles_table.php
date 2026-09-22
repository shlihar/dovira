<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('rating_placement', 16)->default('auto')->after('sort_priority');
            $table->integer('rating_priority')->default(0)->after('rating_placement');
            $table->index(['rating_placement', 'rating_priority']);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex(['rating_placement', 'rating_priority']);
            $table->dropColumn(['rating_placement', 'rating_priority']);
        });
    }
};
