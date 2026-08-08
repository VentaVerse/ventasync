{{--
    A star rating. Five icons, filled up to the score.

    The star glyph comes from the shared icon set rather than the &#9733;
    character the old pages used: a text star renders at a different size
    and weight in every font on every platform and cannot be aligned to
    anything. `.rv-star--on` fills the outline through CSS.

    Props:
      rating  int   0 to 5
      size    int   icon size in px
--}}
@props(['rating' => 0, 'size' => 13])

<span class="rv-stars" role="img" aria-label="{{ (int) $rating }} out of 5">
    @for($i = 1; $i <= 5; $i++)
        <span class="rv-star @if($i <= (int) $rating) rv-star--on @endif" aria-hidden="true">
            <x-ui.icon name="star" :size="$size" />
        </span>
    @endfor
</span>
