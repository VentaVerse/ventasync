{{-- The push-review modal shells (cleanup 7+9, approved from the draft
     2026-09-03). Feedback without teleport: a READY push opens a modal
     showing exactly what goes up - price with its arithmetic, category,
     couriers, images, variations - with title and description as the two
     editable fields, and the editor one click behind. The bulk state
     leads with the verdict and groups blockers BY GAP, one fix each.

     resources/js/pages/push-review.js fills these shells from the JSON
     endpoints (push_review / bulk_push_review) with createElement and
     textContent only; nothing off the wire is ever markup. Without JS
     the intercepted forms submit as they always did. --}}
<div class="pr-scrim" data-pr-scrim hidden></div>

<div class="pr-modal" data-pr-single data-store="Shopee" role="dialog" aria-modal="true" aria-label="Push to Shopee" hidden>
    <div class="pr-modal__head">
        <h2 class="pr-modal__title">Push to Shopee</h2>
        <span class="pr-modal__sub" data-pr-sub></span>
        <button type="button" class="pr-x" data-pr-close aria-label="Close">&times;</button>
    </div>
    <div class="pr-modal__body">
        <dl class="pr-facts" data-pr-facts></dl>
        <div class="pr-editables">
            <label class="pr-field">
                <span class="pr-field__k">Title on Shopee</span>
                <input class="x-input" data-pr-title maxlength="255">
            </label>
            <label class="pr-field">
                <span class="pr-field__k">Description on Shopee</span>
                <textarea class="x-input" data-pr-description rows="3" maxlength="5000"></textarea>
            </label>
            <p class="pr-mut pr-note">Edits here are saved as this listing's own wording, then pushed. Everything else is edited on the listing page.</p>
        </div>
    </div>
    <div class="pr-modal__foot">
        <a href="#" class="cc-quiet" data-pr-listing-link>Open the listing instead</a>
        <span class="pr-spring"></span>
        <button type="button" class="x-btn x-btn--secondary" data-pr-close>Cancel</button>
        <button type="button" class="x-btn x-btn--primary" data-pr-push>Push to Shopee</button>
    </div>
</div>
