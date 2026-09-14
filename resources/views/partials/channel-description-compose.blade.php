{{-- A listing's Description field, framed by the store's chosen prefix
     and suffix templates: greyed, read-only, and never form fields. Only the
     description and the two template ids are saved. The pickers stay on the
     page; this reads them by id. resources/js/pages/description-preview.js
     repaints a block when its picker changes.

     Expected keys:
       id         string  the textarea's id
       label      string  the field's label
       name       string  the field name on the wire
       value      string  the description to show
       rich       bool    a rich-text editor; false for plain text
       disabled   bool
       rows       int
       maxlength  ?int
       prefixFor  string  the prefix select's id
       suffixFor  string  the suffix select's id
       prefix     ?int    the saved prefix template id
       suffix     ?int    the saved suffix template id
       options    Collection of description templates (id, body) --}}
@php
    /* Each template as the push sends it, already safe to print: words,
       escaped, on a plain-text channel; sanitised HTML elsewhere. A blank
       template adds nothing, so it has no preview. */
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

    /* The editor turns this into live markup, so it gets the catalog's own
       reading: decoded when stored entity-encoded, sanitised, and with
       library paths as URLs any page can load. Plain text is shown as is. */
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
        {{-- Set to 1 by the editor on change; see ListingContent::descriptionToSave. --}}
        <input type="hidden" name="description_edited" value="0" data-rte-edited="{{ $id }}">
        @include('catalog.products.partials.wysiwyg')
    @endif
</x-ui.field>
