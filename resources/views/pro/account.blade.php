@extends('static.layout')

@section('title', 'PRO кабінет | DOVIRA')
@section('body_class', 'page-account page-pro-account')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/account.css') }}?v={{ @filemtime(public_path('static/css/pages/account.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/pro-account.css') }}?v={{ @filemtime(public_path('static/css/pages/pro-account.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/lawyer.css') }}?v={{ @filemtime(public_path('static/css/pages/lawyer.css')) }}">
@endpush

@section('content')
    @livewire('pro.account-page', ['initialState' => $initialState ?? []])
@endsection
