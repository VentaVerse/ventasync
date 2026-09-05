{{-- The workbench's two surfaces (#201, the owner 2026-09-05).

     THIS STORE is the daily table: only what this store carries, with the
     seller-center state filters. ADD PRODUCTS is your main catalogue, where a
     product is added into this store's list. A primary mode switch, text
     forward with a brand underline - the filled pill below is the state
     control's job, and two filled levels would collide.

     $listTab   'store' | 'add'   which surface is showing
     $storeCount (optional)       how many products this store carries --}}
@php $__wt = $listTab ?? 'store'; @endphp
<nav class="cc-worktabs" aria-label="Listings sections">
    <a href="{{ route('ext.lazada.products.index') }}"
       class="cc-worktab @if($__wt === 'store') is-active @endif"
       @if($__wt === 'store') aria-current="page" @endif>
        <span class="cc-worktab__label">This store</span>
        @isset($storeCount)
            <span class="cc-worktab__count">{{ number_format((int) $storeCount) }}</span>
        @endisset
    </a>
    <a href="{{ route('ext.lazada.products.index', ['list' => 'add']) }}"
       class="cc-worktab @if($__wt === 'add') is-active @endif"
       @if($__wt === 'add') aria-current="page" @endif>
        <span class="cc-worktab__label">Add products</span>
    </a>
</nav>
