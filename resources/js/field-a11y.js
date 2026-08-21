/**
 * Say out loud what the form already says on screen.
 *
 * x-ui.field renders a label, a control, an optional hint and an optional
 * error. Visually they are obviously one group. Programmatically they were
 * four unrelated elements: no aria-describedby, so a hint like "Share of the
 * selling price" and every validation message on both product forms were
 * invisible to a screen reader, which heard only the generic "Nothing was
 * saved. Check the fields marked below." And the "Required" pill was decorative
 * text - measured, 7 fields showed it and 0 carried `required` or
 * `aria-required`.
 *
 * WHY THIS IS WIRED HERE AND NOT IN THE BLADE COMPONENT. x-ui.field takes its
 * control as an opaque slot, and Blade cannot add attributes to slot content.
 * Its 49 callers pass everything from x-ui.input to a bare <select> to
 * hand-rolled markup, so there is no single child component to push the
 * attribute through either. The alternative was editing 49 call sites and
 * hoping the 50th remembers. This runs once, finds the structure the component
 * already guarantees, and is one place to correct if the structure changes.
 *
 * It only ever ADDS. A control that already declares its own describedby,
 * required state or invalid state is left exactly as the author wrote it.
 */
function wireField(field) {
    // The control is the first thing in the field that can hold a value.
    // Not [class], not the first child: a field may open with a wrapper.
    const control = field.querySelector('input:not([type="hidden"]), select, textarea');
    if (!control) return;

    const described = [];

    const hint = field.querySelector(':scope > .fm-hint');
    if (hint && hint.id) described.push(hint.id);

    const error = field.querySelector(':scope > .fm-error');
    if (error && error.id) described.push(error.id);

    if (described.length && !control.hasAttribute('aria-describedby')) {
        control.setAttribute('aria-describedby', described.join(' '));
    }

    // An error is a STATE, not just a message under the box.
    if (error && !control.hasAttribute('aria-invalid')) {
        control.setAttribute('aria-invalid', 'true');
    }

    /* aria-required, not the required attribute.
     *
     * The marker means "this form expects a value here", and several of these
     * fields are nullable in the controller - adding a hard `required` would
     * change what the browser lets through and could block a save that used to
     * work. aria-required announces the expectation without moving the
     * goalposts. Where a field is genuinely mandatory the attribute belongs in
     * the markup and in the controller's own rules, which is a different job. */
    if (field.querySelector('[data-required-marker]')
        && !control.hasAttribute('aria-required')
        && !control.hasAttribute('required')) {
        control.setAttribute('aria-required', 'true');
    }
}

function wireAllFields(root) {
    (root || document).querySelectorAll('.fm-field').forEach(wireField);
}

document.addEventListener('DOMContentLoaded', () => wireAllFields());

// Fields can arrive later - the image manager and the variations grid both
// build markup after load.
window.wireFieldAccessibility = wireAllFields;
