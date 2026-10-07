@php
    $previews = collect($options)->mapWithKeys(function ($template) use ($rich) {
        $body = trim((string) ($template->body ?? ''));
        $safe = $rich
            ? trim(\App\Support\HtmlSanitizer::sanitize($body))
            : e(\App\Support\Catalog\DescriptionText::of($body));

        return [(int) $template->id => $safe];
    })->filter(fn ($safe) => $safe !== '');

    $prefixShown = $previews->get((int) old('description_prefix_id', $prefix ?? 0));
    $suffixShown = $previews->get((int) old('description_suffix_id', $suffix ?? 0));
    $blockClass = 'dp-block' . ($rich ? '' : ' dp-block--plain');

    $shown = $rich ? \App\Support\Catalog\DescriptionHtml::forEditor((string) $value) : $value;
@endphp
<x-ui.field :label="$label" :for="$id" :name="$name" wide>
    <div class="dp-box" data-dp-box data-dp-prefix-for="{{ $prefixFor }}" data-dp-suffix-for="{{ $suffixFor }}" data-dp-kind="{{ $rich ? 'html' : 'text' }}">
        <div class="{{ $blockClass }} dp-block--prefix" data-dp-block="prefix" @if($prefixShown === null) hidden @endif>{!! $prefixShown ?? '' !!}</div>
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ (int) $rows }}" class="x-input{{ $rich ? ' wysiwyg' : '' }}" @if($maxlength ?? null) maxlength="{{ (int) $maxlength }}" @endif @disabled($disabled)>{{ $shown }}</textarea>
        <div class="{{ $blockClass }} dp-block--suffix" data-dp-block="suffix" @if($suffixShown === null) hidden @endif>{!! $suffixShown ?? '' !!}</div>
        @foreach($previews as $templateId => $safe)
            <template data-dt-preview="{{ $templateId }}">{!! $safe !!}</template>
        @endforeach
    </div>
    @if($rich)
        <input type="hidden" name="description_edited" value="0" data-rte-edited="{{ $id }}">
        @include('catalog.products.partials.wysiwyg')
    @endif
</x-ui.field>
