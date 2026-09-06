{{-- The Last sync cell, one drawing for every channel's Listings page and
     group pages (the owner, 2026-09-05): a refusal in red with its full words
     one click away; otherwise when the product last synced and what it
     was; otherwise Never.

     $sync  one entry of ShopeeListingStates::lastSync() --}}
@php $sync = $sync ?? ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null]; @endphp
@if($sync['tone'] === 'failed')
    @php
        $reason = trim((string) ($sync['detail'] ?? ''));
        $reason = $reason !== '' ? $reason : 'No reason was recorded.';
        // 120 shown against a column stored at 480: an untitled truncation
        // left up to 360 characters of what Shopee actually said unreachable.
        $clipped = \Illuminate\Support\Str::limit($reason, 120);
    @endphp
    <span class="cc-err">{{ $sync['headline'] }}</span>
    @if($clipped === $reason)
        <span class="cc-err">{{ $reason }}</span>
    @else
        {{-- <details>, not a tooltip: a title attribute waits a second,
             never appears on a phone, and cannot be reached from the keyboard. --}}
        <details class="cc-reason">
            <summary class="cc-reason__summary">
                <span class="cc-err cc-reason__clip">{{ $clipped }}</span>
                <span class="cc-reason__toggle">
                    <x-ui.icon name="chevron-down" size="12" class="cc-reason__chevron" />
                    <span class="cc-reason__label">Full reason</span>
                </span>
            </summary>
            <p class="cc-err cc-reason__full">{{ $reason }}</p>
        </details>
    @endif
@elseif($sync['tone'] === 'ok')
    {{-- Two short lines instead of one sentence wrapped four deep in a
         180px column (polish, 2026-09-06): when, then what it was, and
         the one fact that asks for a person in the attention tone. --}}
    <span class="cc-when" @if($sync['at']) title="{{ $sync['at']->toDayDateTimeString() }}" @endif>{{ $sync['headline'] }}</span>
    @if(!empty($sync['what']) || !empty($sync['stale']))
        <span class="cc-when__what">{{ ucfirst((string) ($sync['what'] ?? '')) }}@if(!empty($sync['stale']))<span class="cc-when__stale">{{ !empty($sync['what']) ? ', ' : '' }}catalog changed since</span>@endif</span>
    @endif
@else
    <span class="x-cell-muted">Never</span>
@endif
