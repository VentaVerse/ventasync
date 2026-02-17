@extends('layouts.app')
@section('breadcrumb', 'Settings / Profile')

@section('content')
<div class="page-header">
    <h2>Profile</h2>
</div>

<div class="card mb-16">
    @include('profile.partials.update-profile-information-form')
</div>

<div class="card mb-16">
    @include('profile.partials.update-password-form')
</div>

<div class="card mb-16">
    @include('profile.partials.delete-user-form')
</div>
@endsection
