@props(['rating' => 0, 'size' => 13])

<span class="rv-stars" role="img" aria-label="{{ (int) $rating }} out of 5">
    @for($i = 1; $i <= 5; $i++)
        <span class="rv-star @if($i <= (int) $rating) rv-star--on @endif" aria-hidden="true">
            <x-ui.icon name="star" :size="$size" />
        </span>
    @endfor
</span>
