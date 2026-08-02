<?php

namespace App\Jobs;

use App\Services\ProfileReviewAiAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshProfileReviewAiAnalysis implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public int $profileId)
    {
        $this->onConnection((string) config('ai_enrichment.connection', config('queue.default', 'database')));
        $this->onQueue((string) config('ai_enrichment.review_analysis.queue', 'ai-review-analysis'));
    }

    public function uniqueId(): string
    {
        return 'profile-review-ai-analysis:' . $this->profileId;
    }

    public int $uniqueFor = 120;

    public function handle(ProfileReviewAiAnalysisService $service): void
    {
        $service->refreshForProfileId($this->profileId);
    }
}
