{{--
    The "Products" card on every channel's product group form (the owner,
    2026-09-07: one shape on all five). Membership is not the form's
    business: products join and leave the group on its Products page,
    which is where this card points.

    Expected keys:
      mode          'create' | 'edit'
      productCount  int   how many products are in the group (edit)
      productsUrl   ?string  the group's Products page (edit)
--}}
<div class="fm-card">
    <div class="fm-card__head"><h2 class="fm-card__title">Products
        <x-ui.hint label="About the products">Products join and leave the group on its Products page, from this store's own list. The form holds only what the group lends them.</x-ui.hint></h2></div>
    <div class="fm-card__body">
        @if($mode === 'create')
            <p class="fm-section__note">Add products on the group's page once it is created.</p>
        @else
            <div class="fm-stats">
                <div class="fm-stat">
                    <span class="fm-stat__k">In this group</span>
                    <span class="fm-stat__v x-num">{{ number_format((int) $productCount) }}</span>
                </div>
            </div>
            <div class="cc-head-actions">
                <x-ui.button size="sm" :href="$productsUrl">View products</x-ui.button>
            </div>
        @endif
    </div>
</div>
