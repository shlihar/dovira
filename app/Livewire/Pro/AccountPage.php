<?php

namespace App\Livewire\Pro;

use App\Services\ProAccountPageDataBuilder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AccountPage extends Component
{
    use WithPagination;

    #[Url(history: true)]
    public string $tab = 'overview';

    #[Url(as: 'profile', history: true)]
    public ?int $selectedProfileId = null;

    #[Url(as: 'analytics_period', history: true)]
    public string $analyticsPeriod = 'last_30';

    #[Url(as: 'claim_profile', history: true)]
    public ?int $selectedClaimProfileId = null;

    #[Url(as: 'q', history: true)]
    public string $claimSearch = '';

    /**
     * @param  array<string, mixed>  $initialState
     */
    public function mount(array $initialState = []): void
    {
        if (array_key_exists('tab', $initialState)) {
            $this->tab = (string) $initialState['tab'];
        }

        if (array_key_exists('selectedProfileId', $initialState)) {
            $this->selectedProfileId = $initialState['selectedProfileId'] ? (int) $initialState['selectedProfileId'] : null;
        }

        if (array_key_exists('analyticsPeriod', $initialState)) {
            $this->analyticsPeriod = (string) $initialState['analyticsPeriod'];
        }

        if (array_key_exists('claimSearch', $initialState)) {
            $this->claimSearch = trim((string) $initialState['claimSearch']);
        }

        if (array_key_exists('selectedClaimProfileId', $initialState)) {
            $this->selectedClaimProfileId = $initialState['selectedClaimProfileId'] ? (int) $initialState['selectedClaimProfileId'] : null;
        }

        $this->syncState();
    }

    public function render(ProAccountPageDataBuilder $builder)
    {
        return view('livewire.pro.account-page', $builder->build(auth()->user(), [
            'tab' => $this->tab,
            'selectedProfileId' => $this->selectedProfileId,
            'analyticsPeriod' => $this->analyticsPeriod,
            'claimSearch' => $this->claimSearch,
            'selectedClaimProfileId' => $this->selectedClaimProfileId,
        ]));
    }

    public function selectTab(string $tab, ?string $scrollTo = null): void
    {
        $this->tab = $tab;
        $this->syncState(resetReviewsPage: $tab !== 'reviews');

        if ($scrollTo) {
            $this->dispatch('pro-account-scroll-to', id: $scrollTo);
        }
    }

    public function selectProfile(int $profileId, ?string $tab = null, ?string $scrollTo = null): void
    {
        $this->selectedProfileId = $profileId;
        $this->tab = $tab ?? $this->tab;
        $this->syncState();

        if ($scrollTo) {
            $this->dispatch('pro-account-scroll-to', id: $scrollTo);
        }
    }

    public function openOwnedClaimProfile(int $profileId): void
    {
        $this->selectedProfileId = $profileId;
        $this->tab = 'overview';
        $this->syncState();
    }

    public function setAnalyticsPeriod(string $period, string $tab = 'overview'): void
    {
        $this->analyticsPeriod = $period;
        $this->tab = $tab;
        $this->syncState(resetReviewsPage: false);
        $this->dispatch('pro-account-scroll-to', id: $this->tab === 'analytics' ? 'analytics-workspace' : 'overview-analytics');
    }

    public function applyClaimSearch(): void
    {
        $this->tab = 'claims';
        $this->claimSearch = trim($this->claimSearch);

        if ($this->claimSearch === '') {
            $this->selectedClaimProfileId = null;
        }
    }

    public function updatedTab(): void
    {
        $this->syncState(resetReviewsPage: $this->tab !== 'reviews');
    }

    public function updatedSelectedProfileId(): void
    {
        $this->syncState();
    }

    public function updatedAnalyticsPeriod(): void
    {
        $this->syncState(resetReviewsPage: false);
    }

    public function updatedClaimSearch(): void
    {
        $this->claimSearch = trim($this->claimSearch);

        if ($this->claimSearch === '') {
            $this->selectedClaimProfileId = null;
        }
    }

    public function updatedSelectedClaimProfileId(): void
    {
        if (($this->selectedClaimProfileId ?? 0) <= 0) {
            $this->selectedClaimProfileId = null;
        }
    }

    private function syncState(bool $resetReviewsPage = true): void
    {
        $this->tab = in_array($this->tab, ['overview', 'profile', 'reviews', 'analytics', 'notifications', 'billing', 'claims'], true)
            ? $this->tab
            : 'overview';

        $this->analyticsPeriod = in_array($this->analyticsPeriod, ['today', 'last_7', 'last_30', 'last_90'], true)
            ? $this->analyticsPeriod
            : 'last_30';

        $this->claimSearch = trim($this->claimSearch);
        $this->selectedClaimProfileId = ($this->selectedClaimProfileId ?? 0) > 0 ? $this->selectedClaimProfileId : null;

        $ownedProfileIds = auth()->user()
            ->ownedProfiles()
            ->orderByDesc('is_pro')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ownedProfileIds->isEmpty()) {
            $this->selectedProfileId = null;
            $this->tab = 'claims';
        } elseif (! $ownedProfileIds->contains((int) $this->selectedProfileId)) {
            $this->selectedProfileId = (int) $ownedProfileIds->first();
        }

        if ($resetReviewsPage) {
            $this->resetPage('reviews_page');
        }
    }
}
