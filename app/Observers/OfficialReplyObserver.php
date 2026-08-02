<?php

namespace App\Observers;

use App\Models\OfficialReply;
use App\Services\ReviewNotificationService;

class OfficialReplyObserver
{
    public function created(OfficialReply $reply): void
    {
        $review = $reply->review;

        if (!$review) {
            return;
        }

        app(ReviewNotificationService::class)->onOfficialReply($review, (string) $reply->body);
    }
}
