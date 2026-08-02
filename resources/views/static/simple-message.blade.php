@extends('static.layout')
@section('title', ($title ?? 'Dovira') . ' | DOVIRA')
@section('robots', 'noindex, nofollow')
@section('content')
    <section class="section" style="min-height:50vh;display:flex;align-items:center;justify-content:center;padding:60px 16px;">
        <div style="max-width:440px;text-align:center;">
            <h1 style="font-size:24px;margin:0 0 10px;color:#1f2f52;">{{ $title ?? 'Готово' }}</h1>
            <p style="font-size:15px;line-height:1.6;color:#5a6b8c;margin:0 0 22px;">{{ $message ?? '' }}</p>
            <a href="{{ route('home') }}" class="btn btn--primary">На головну</a>
        </div>
    </section>
@endsection
