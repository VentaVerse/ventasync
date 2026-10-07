@extends('layouts.blotter')
@section('title', 'Changelog')
@section('breadcrumb', 'Changelog')

@section('content')
<div class="cl-page">
    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">{{ \App\Support\AppVersion::label() }}</h1>
            <p class="x-page-sub">What changed in each release.</p>
        </div>
    </div>

    @foreach($releases as $release)
        <section class="od-card cl-release">
            <div class="od-card__head">
                <h2 class="od-card__title">{{ $release['version'] }}</h2>
                @if($release['date'])<span class="cl-date">{{ $release['date'] }}</span>@endif
            </div>
            <ul class="od-card__body cl-lines">
                @foreach($release['lines'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if($extensions !== [])
        <h2 class="cl-heading">Extensions</h2>
        @foreach($extensions as $extension)
            <section class="od-card cl-release">
                <div class="od-card__head">
                    <h3 class="od-card__title">{{ $extension['name'] }}</h3>
                    <span class="cl-date">{{ $extension['version'] }}</span>
                </div>
                <div class="od-card__body">
                    @forelse($extension['releases'] as $release)
                        <div class="cl-ext-release">
                            <p class="cl-ext-version">{{ $release['version'] }}@if($release['date']) <span class="cl-date">{{ $release['date'] }}</span>@endif</p>
                            <ul class="cl-lines">
                                @foreach($release['lines'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="cl-date">No changelog yet.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    @endif
</div>
@endsection
