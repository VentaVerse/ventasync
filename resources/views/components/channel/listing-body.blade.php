<div class="cl-body">
    <div class="cl-main">{{ $main }}</div>
    @if(trim($rail ?? '') !== '')
        <aside class="cl-rail">{{ $rail }}</aside>
    @endif
</div>
