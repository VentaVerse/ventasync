<section class="mp" aria-labelledby="mp-h">
    <div class="mp__head">
        <h2 id="mp-h" class="mp__title">Money at each marketplace</h2>
        <span class="mp__aside">In each Seller Center's own words, as last read from its API</span>
    </div>
    <div class="mp__grid">
        @foreach($payoutCards as $card)
            @include('payouts.partials.money-panel', ['card' => $card])
        @endforeach
    </div>
</section>
