{{-- The Add products slide-over (#201, Draft C - the owner 2026-09-05): the shared
     component, given Lazada's two endpoints and this store's words. Each
     addition joins this store's list (unpushed, the push workflow unchanged)
     and lands in the table behind when the panel closes; the full catalogue
     page is the overflow for a browse. --}}
<x-channel.add-panel
    id="lazada-add-panel"
    :search-url="route('ext.lazada.products.catalogue_search')"
    :add-url="route('ext.lazada.products.add_to_store', ['productId' => '__PID__'])"
    sub="Search your Master Catalog. Each product you add joins this store's list, ready to configure and push to Lazada."
    toggle-label="Show products already on this store"
    in-label="On this store"
    scope="this store"
    empty-default="Every enabled product is already on this store."
    empty-all="Your catalogue has no enabled products."
    list-label="Catalogue products"
    :full-url="route('ext.lazada.products.index', ['list' => 'add'])" />
