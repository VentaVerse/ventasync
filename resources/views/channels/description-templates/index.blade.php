@extends('layouts.channel')
@section('title', $channelName . ' Description Templates')
@section('breadcrumb', 'Description Templates')

@section('content')
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Description Templates</h1>
        <p class="x-page-sub">Written once here, used by any listing on this store.</p>
    </div>
    @if($canManage)
        <x-ui.button variant="primary" data-dt-new>New template</x-ui.button>
    @endif
</div>

@if($templates->isEmpty())
    <x-ui.empty title="No templates yet">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" data-dt-new>New template</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Description</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>
        @foreach($templates as $t)
            <tr>
                <td data-label="Name">{{ $t->name }}</td>
                <td data-label="Description">{{ \Illuminate\Support\Str::limit(trim(strip_tags((string) $t->body)), 120) }}</td>
                <td class="x-td-actions">
                    @if($canManage)
                        <x-ui.button size="sm" data-dt-edit
                                     data-dt-url="{{ $t->update_url }}"
                                     data-dt-name="{{ $t->name }}"
                                     data-dt-body="{{ $t->body }}">Edit</x-ui.button>
                        <form method="POST" action="{{ $t->destroy_url }}"
                              data-confirm="Delete the template &quot;{{ $t->name }}&quot;? Listings pointing at it stop carrying its words on their next push." data-confirm-verb="Delete">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" size="sm">Delete</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif

@if($canManage)
    <div class="modal-backdrop" data-dt-modal>
        <div class="modal">
            <form method="POST" action="{{ $createUrl }}" data-dt-form>
                @csrf
                <input type="hidden" name="_method" value="POST" data-dt-method>
                <div class="modal-header">
                    <h3 data-dt-title>New template</h3>
                    <button type="button" class="modal-close" data-dt-cancel aria-label="Close">&times;</button>
                </div>
                <div class="dt-modal__body">
                    <div class="fm-fields">
                        <x-ui.field label="Name" for="dt-name" name="name" :required="true" wide>
                            <x-ui.input id="dt-name" name="name" maxlength="128" data-dt-field-name />
                        </x-ui.field>
                        <x-ui.field label="Description" for="dt-body" name="body" wide>
                            <x-ui.textarea id="dt-body" name="body" rows="8" maxlength="20000" data-dt-field-body
                                           :class="$richText ? 'wysiwyg' : ''"></x-ui.textarea>
                        </x-ui.field>
                    </div>
                </div>
                <div class="dt-modal__foot">
                    <x-ui.button type="button" data-dt-cancel>Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                </div>
            </form>
        </div>
    </div>
    @if($richText) @include('catalog.products.partials.wysiwyg') @endif
    @if($browseUrl)
        @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
    @endif
@endif
@endsection
