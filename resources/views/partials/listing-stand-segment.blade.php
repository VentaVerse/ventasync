{{-- Where the chosen group's products stand with the store, above the table
     it narrows (App\Integrations\Listings\StatusMenu builds $menu). It sits
     here and not in the rail because it is a second cut through the same
     rows: the rail says which products, this says which of those are up.

     With a group chosen, the same line carries what acts on the group
     itself ($group: name, manage, send). Its Send uses the group's settings,
     which is why it lives here and not in the selection bar, where Send uses
     each listing's own. --}}
<div class="lss-row">
    <div class="lss" role="group" aria-label="Where it stands">
        @foreach($menu['stand'] as $place)
            <a href="{{ $place['url'] }}" class="lss__b{{ $place['on'] ? ' is-on' : '' }}"
               @if($place['on']) aria-current="true" @endif>
                <span class="lss__label">{{ $place['label'] }}</span>
                <span class="lss__n">{{ number_format($place['count']) }}</span>
            </a>
        @endforeach
    </div>

    @if(! empty($group))
        <div class="lss-group">
            @if(! empty($group['send']))
                <form method="POST" action="{{ $group['send']['action'] }}" data-bulk-form data-bulk-field="ids[]"
                      data-bulk-confirm="{{ $group['send']['confirm'] }}">
                    @csrf
                    <input type="hidden" name="_return" value="{{ $group['return'] ?? '' }}">
                    <button type="submit" class="x-btn x-btn--secondary x-btn--sm" :disabled="selected.length === 0">
                        <span x-text="selected.length ? 'Send ' + selected.length + ' on group settings' : 'Send on group settings'">Send on group settings</span>
                    </button>
                </form>
            @endif
            @if(! empty($group['manage']))
                <a href="{{ $group['manage'] }}" class="lss-group__manage">Manage group</a>
            @endif
        </div>
    @endif
</div>
