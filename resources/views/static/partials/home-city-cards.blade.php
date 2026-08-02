@php
    $profileUrl = fn (string $slug) => route('profile.show', [
        'slug' => $slug,
    ]);
@endphp
@foreach ($profiles as $profile)
    @include('static.partials.profile-card-catalog', ['profile' => $profile, 'profileUrl' => $profileUrl])
@endforeach
