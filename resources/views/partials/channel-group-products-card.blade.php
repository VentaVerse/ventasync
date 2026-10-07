<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Products</h2>
        <x-ui.hint label="About the products">Products join and leave the group on its Products page, from this store's own list. The form holds only what the group lends them.</x-ui.hint>
    </div>
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
</section>
