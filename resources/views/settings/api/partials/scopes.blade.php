{{--
    The API scope matrix, shared by create and edit.

    Wire, byte for byte with the pre-redesign view and with
    ApiClientController@resolveScopes: one checkbox per offered action,
    named `scopes[{resource}][{action}]` with value 1. A resource that
    does not offer a tier renders a dash and no control at all, so the
    posted shape can never carry an action the catalog would drop.

    Expects:
      $scopes  ApiScopeRegistry::all(), keyed by resource
      $current array of "resource:action" strings already granted (edit
               page), or [] on create
--}}
@php $current = $current ?? []; @endphp

<div class="st-scopes">
    <div class="st-scopes__head">
        <span>Resource</span>
        <span class="st-scope__tiers">
            <span class="st-scopes__tier">Read</span>
            <span class="st-scopes__tier">Write</span>
        </span>
    </div>

    @foreach($scopes as $key => $meta)
        <div class="st-scope">
            <div>
                <div class="st-scope__name">{{ $meta['label'] }}</div>
                <div class="st-scope__desc">{{ $meta['description'] }}</div>
            </div>

            <div class="st-scope__tiers">
                @foreach(['read', 'write'] as $action)
                    @if(in_array($action, $meta['actions'], true))
                        <label class="st-tier">
                            <input type="checkbox" class="st-tier__box"
                                   name="scopes[{{ $key }}][{{ $action }}]" value="1"
                                   @checked(old("scopes.$key.$action", in_array("$key:$action", $current, true)))
                                   aria-label="{{ $meta['label'] }}, {{ $action }}">
                            <span class="st-tier__text">{{ ucfirst($action) }}</span>
                        </label>
                    @else
                        <span class="st-tier--none">
                            <span class="x-sr">{{ $meta['label'] }} offers no {{ $action }} access</span>
                            <span aria-hidden="true">-</span>
                        </span>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</div>

@error('scopes')<div class="fm-error">{{ $message }}</div>@enderror
