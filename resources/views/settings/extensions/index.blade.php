@extends('layouts.blotter')
@section('title', 'Extensions')
@section('breadcrumb', 'Extensions')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_settings/extension') ?? false;

    $manager = app(\App\Extensions\ExtensionManager::class);
    $settingsRoute = fn (array $ext) => $ext['enabled']
        && ($route = $manager->getManifest($ext['id'])['settings']['route'] ?? null)
        && \App\Support\Navigation::allows($route) ? $route : null;

    $rows = collect($extensions);
    $enabled = $rows->where('enabled', true)->count();
    $installed = $rows->where('installed', true)->count();
@endphp

<div class="st-ext-page" x-data="{ installOpen: false }">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            @include('partials.back-to-settings')
            <h1 class="x-page-title">Extensions</h1>
            <p class="x-page-sub">
                {{ number_format($rows->count()) }} {{ Str::plural('extension', $rows->count()) }} on this server,
                {{ number_format($installed) }} installed, {{ number_format($enabled) }} running.
            </p>
        </div>
        @if($canManage)
            <div class="st-head-actions">
                <form method="POST" action="{{ route('extensions.refresh') }}">
                    @csrf
                    <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh</x-ui.button>
                </form>
                <x-ui.button type="button" @click="installOpen = !installOpen"
                             x-bind:aria-expanded="installOpen ? 'true' : 'false'">
                    <x-ui.icon name="plus" size="14" /> Install extension
                </x-ui.button>
            </div>
        @endif
    </div>

    @if($canManage)
    <form method="POST" action="{{ route('extensions.install') }}" enctype="multipart/form-data"
          class="st-install" x-show="installOpen" x-cloak x-transition.duration.120ms>
        @csrf
        <div class="st-install__body">
            <h2 class="st-install__title">Upload a package</h2>
            <p class="st-install__note">
                An <code class="st-key">.erpx</code> or <code class="st-key">.zip</code> archive containing an
                <code class="st-key">extension.json</code>, up to 50 MB. Its files are unpacked into
                <code class="st-key">extensions/</code>, its database migrations run, and it arrives disabled.
            </p>
            <div class="st-install__row">
                <div class="st-file">
                    <label class="st-file__btn" for="st-ext-file">Choose file</label>
                    <span class="st-file__name" data-file-name-for="st-ext-file">No file chosen</span>
                    <input type="file" id="st-ext-file" name="file" accept=".erpx,.zip" class="st-file__input" required>
                </div>
                <x-ui.button variant="primary" type="submit">Install</x-ui.button>
            </div>
        </div>
    </form>
    @endif

    @if($rows->isEmpty())
        <x-ui.empty
            title="No extensions on this server"
            description="An extension is a folder under extensions/ carrying an extension.json. Upload a package to add one." />
    @else

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Extension</th>
                <th scope="col" class="st-col-ver">Version</th>
                <th scope="col" class="st-col-author">Author</th>
                <th scope="col" class="st-col-stat">State</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach($rows as $ext)
            @php
                $state = ! $ext['installed']
                    ? ['label' => 'Not installed', 'tone' => 'warning']
                    : ($ext['enabled']
                        ? ['label' => 'Enabled', 'tone' => 'success']
                        : ['label' => 'Disabled', 'tone' => 'neutral']);
            @endphp
            <tr id="{{ $ext['id'] }}">
                <td data-label="Extension">
                    <div class="st-ext">
                        <span class="st-ext__name">{{ $ext['name'] }}</span>
                        <span class="st-ext__id">{{ $ext['id'] }}</span>
                        @if($ext['description'])
                            <span class="st-ext__desc">{{ $ext['description'] }}</span>
                        @endif
                    </div>
                </td>
                <td class="st-col-ver x-num x-cell-muted" data-label="Version">{{ $ext['version'] }}</td>
                <td class="st-col-author x-cell-muted" data-label="Author">{{ $ext['author'] ?: 'Not stated' }}</td>
                <td class="st-col-stat" data-label="State">
                    <x-ui.badge :tone="$state['tone']">{{ $state['label'] }}</x-ui.badge>
                </td>
                <td class="x-td-actions">
                    @php $settingsAt = $settingsRoute($ext); @endphp
                    @if($canManage || $settingsAt)
                    <x-ui.menu label="{{ $ext['name'] }} actions">
                        @if($settingsAt)
                            <a class="x-menu__item" href="{{ route($settingsAt) }}">Settings</a>
                            @if($canManage)<div class="x-menu__sep"></div>@endif
                        @endif
                        @if($canManage && $ext['installed'])
                            @if($ext['enabled'])
                                <button type="button" class="x-menu__item" data-confirm-tone="primary"
                                        data-confirm="Disable {{ $ext['name'] }}? Its pages stop being reachable. Its data and every group's access to it are kept for when it is enabled again."
                                        data-confirm-submit="st-ext-toggle-{{ $ext['id'] }}">Disable</button>
                            @else
                                <button type="button" class="x-menu__item" data-confirm-tone="primary"
                                        data-confirm="Enable {{ $ext['name'] }}? Its pages become reachable and its permissions are added back to the permission editor."
                                        data-confirm-submit="st-ext-toggle-{{ $ext['id'] }}">Enable</button>
                            @endif
                            <div class="x-menu__sep"></div>
                            <button type="button" class="x-menu__item x-menu__item--danger"
                                    data-confirm="Uninstall {{ $ext['name'] }}? Every row in the tables it declares is deleted and its permissions are removed. The files stay on disk so it can be installed again."
                                    data-confirm-submit="st-ext-uninstall-{{ $ext['id'] }}">Uninstall</button>
                        @elseif($canManage)
                            <button type="button" class="x-menu__item" data-confirm-tone="primary"
                                    data-confirm="Install {{ $ext['name'] }}? Its database migrations run now. It arrives disabled, so enable it afterwards to switch it on."
                                    data-confirm-submit="st-ext-install-{{ $ext['id'] }}">Install</button>
                        @endif
                    </x-ui.menu>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    @if($canManage)
        @foreach($rows as $ext)
            @if($ext['installed'])
                <form id="st-ext-toggle-{{ $ext['id'] }}" method="POST" action="{{ route('extensions.toggle', $ext['id']) }}" class="x-sr">@csrf</form>
                <form id="st-ext-uninstall-{{ $ext['id'] }}" method="POST" action="{{ route('extensions.uninstall', $ext['id']) }}" class="x-sr">
                    @csrf
                    @method('DELETE')
                </form>
            @else
                <form id="st-ext-install-{{ $ext['id'] }}" method="POST" action="{{ route('extensions.reinstall', $ext['id']) }}" class="x-sr">@csrf</form>
            @endif
        @endforeach
    @endif

    @endif
</div>
@endsection
