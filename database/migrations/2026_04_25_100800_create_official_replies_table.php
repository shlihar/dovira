<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('official_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_review_id')->constrained('profile_reviews')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
            $table->unique('profile_review_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_replies');
    }
};
