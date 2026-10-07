@if(!empty($href))
    <a class="lcx-btn" href="{{ $href }}"><span class="lcx-btn__dot" aria-hidden="true"></span>Catalog change</a>
@else
    <button type="button" class="lcx-btn" data-lcx-open aria-haspopup="dialog" aria-controls="lcx-panel"><span class="lcx-btn__dot" aria-hidden="true"></span>Catalog change</button>
@endif
