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
            @if(! empty($group['send_all']))
                <form id="automation-run-group-send" method="POST" action="{{ $group['send_all']['begin'] }}" x-show="selected.length === 0"
                      data-run-name="{{ $group['send_all']['name'] }}"
                      data-confirm="{{ $group['send_all']['confirm'] }}" data-confirm-tone="primary" data-confirm-verb="Send">
                    @csrf
                    <button type="submit" class="x-btn x-btn--secondary x-btn--sm" @disabled(($group['send_all']['count'] ?? 0) === 0)>Send group</button>
                </form>
            @endif
            @if(! empty($group['send']))
                <form method="POST" action="{{ $group['send']['action'] }}" data-bulk-form data-bulk-field="ids[]"
                      data-bulk-confirm="{{ $group['send']['confirm'] }}"
                      @if(! empty($group['send_all'])) x-show="selected.length > 0" x-cloak @endif>
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
@if(! empty($group['send_all']))
    @include('partials.automation-run-modal', ['stepUrl' => $group['send_all']['step'], 'stopUrl' => $group['send_all']['stop']])
@endif
