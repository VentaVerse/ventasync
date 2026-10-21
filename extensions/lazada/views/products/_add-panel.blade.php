<x-channel.add-panel
    id="lazada-add-panel"
    :search-url="route('ext.lazada.products.catalog_search')"
    :add-url="route('ext.lazada.products.add_to_store', ['productId' => '__PID__'])"
    sub="Search your Master Catalog. Each product you add joins this store's list, ready to configure and push to Lazada."
    toggle-label="Show products already on this store"
    in-label="On this store"
    scope="this store"
    empty-default="Every enabled product is already on this store."
    empty-all="Your Master Catalog has no enabled products."
    list-label="Master Catalog products"
    :full-url="route('ext.lazada.products.index', ['list' => 'add'])" />
