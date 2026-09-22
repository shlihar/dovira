<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('profile_claims')
            ->where('status', 'approved')
            ->orderBy('id')
            ->select(['profile_id', 'user_id'])
            ->chunk(500, function ($claims): void {
                foreach ($claims as $claim) {
                    $profile = DB::table('profiles')
                        ->where('id', $claim->profile_id)
                        ->first(['owner_user_id']);

                    if (! $profile) {
                        continue;
                    }

                    DB::table('profiles')
                        ->where('id', $claim->profile_id)
                        ->update([
                            'owner_user_id' => $profile->owner_user_id ?: $claim->user_id,
                            'is_owner_verified' => true,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill only. Do not unset ownership verification on rollback.
    }
};
