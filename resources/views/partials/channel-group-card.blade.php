{{--
    "Product group": the one group this product is in on this store, on
    every channel's listing page (the owner, 2026-09-07: one rail on all five;
    one product, one group). The card names the group, links to its page,
    and says what the group lends when the listing has nothing of its own.

    Expected keys:
      storeName  the channel or store's name, for the words
      group      ?array{name: string, url: string, lends: list<string>}
      groupsUrl  the store's Product groups page, offered when the product is in none
--}}
<div class="fm-card">
    <div class="fm-card__head"><h2 class="fm-card__title">Product group
        <x-ui.hint label="About the group">A product is in one group per store. The group lends its settings to a listing that has none of its own; what is set on this page always wins.</x-ui.hint></h2></div>
    <div class="fm-card__body">
        @if($group)
            <div class="fm-stats">
                <div class="fm-stat">
                    <span class="fm-stat__k">In</span>
                    <span class="fm-stat__v"><a href="{{ $group['url'] }}">{{ $group['name'] }}</a></span>
                </div>
                <div class="fm-stat">
                    <span class="fm-stat__k">Lends</span>
                    <span class="fm-stat__v">{{ $group['lends'] !== [] ? implode(', ', $group['lends']) : 'nothing yet' }}</span>
                </div>
            </div>
        @else
            <p class="fm-section__note">In no product group on {{ $storeName }}. <a href="{{ $groupsUrl }}">Add it to one</a> to lend it the group's settings.</p>
        @endif
    </div>
</div>
