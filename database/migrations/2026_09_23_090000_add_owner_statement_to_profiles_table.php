<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Позиція компанії» — публічна відповідь власника на досьє.
 *
 * Досьє складає ШІ з відкритих джерел, і власник донедавна міг лише приватно
 * попросити перевірку (dossier_revision_requests). Цього мало: поки триває
 * перевірка, відвідувач бачить тільки одну сторону. Тепер власник може
 * опублікувати свою позицію поруч із досьє — після модерації, щоб блок не
 * перетворився на канал для реклами чи наїздів.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->text('owner_statement')->nullable()->after('dossier_exported_at');
            // pending | approved | rejected
            $table->string('owner_statement_status', 20)->nullable()->after('owner_statement');
            $table->string('owner_statement_note', 500)->nullable()->after('owner_statement_status');
            $table->timestamp('owner_statement_submitted_at')->nullable()->after('owner_statement_note');
            $table->timestamp('owner_statement_published_at')->nullable()->after('owner_statement_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'owner_statement',
                'owner_statement_status',
                'owner_statement_note',
                'owner_statement_submitted_at',
                'owner_statement_published_at',
            ]);
        });
    }
};
