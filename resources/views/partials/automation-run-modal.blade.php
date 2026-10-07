<div class="modal-backdrop" data-automation-run data-step-url="{{ $stepUrl }}" data-stop-url="{{ $stopUrl }}">
    <div class="modal co-modal co-modal--sm ar-modal" role="dialog" aria-modal="true" aria-live="polite" aria-labelledby="ar-modal-title" data-ar-modal data-state="running">
        <div class="ar-modal__mark" aria-hidden="true">
            <span class="ar-modal__ring" data-ar-spinner></span>
            <svg class="ar-modal__glyph ar-modal__glyph--done" data-ar-mark-done hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            <svg class="ar-modal__glyph ar-modal__glyph--failed" data-ar-mark-failed hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>
        </div>
        <div class="ar-modal__title" id="ar-modal-title" data-ar-title>Running</div>
        <div class="ar-modal__text" data-ar-text></div>
        <div class="ar-modal__foot">
            <button type="button" class="x-btn x-btn--secondary" data-ar-stop>Stop</button>
            <button type="button" class="x-btn x-btn--primary" data-ar-ok hidden>OK</button>
        </div>
    </div>
</div>
