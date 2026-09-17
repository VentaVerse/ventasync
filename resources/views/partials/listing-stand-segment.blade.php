{{-- Where the chosen group's products stand with the store, above the table
     it narrows (App\Integrations\Listings\StatusMenu builds $menu). It sits
     here and not in the rail because it is a second cut through the same
     rows: the rail says which products, this says which of those are up. --}}
<div class="lss" role="group" aria-label="Where it stands">
    @foreach($menu['stand'] as $place)
        <a href="{{ $place['url'] }}" class="lss__b{{ $place['on'] ? ' is-on' : '' }}"
           @if($place['on']) aria-current="true" @endif>
            <span class="lss__label">{{ $place['label'] }}</span>
            <span class="lss__n">{{ number_format($place['count']) }}</span>
        </a>
    @endforeach
</div>
