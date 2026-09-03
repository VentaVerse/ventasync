@props(['name'])

{{-- The import pages' busy overlay (cleanup point 11). One id across every
     channel's Import page: pages/channel-import.js keys on it to bind the
     catalog typeahead and to raise this while a scan or import is out. --}}
<div id="channel-import-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="channel-import-progress-title" aria-describedby="channel-import-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="channel-import-progress-title" data-progress-title>Talking to {{ $name }}</div>
                <div class="cc-progress__note" id="channel-import-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>
