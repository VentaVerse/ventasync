function wireField(field) {
    const control = field.querySelector('input:not([type="hidden"]), select, textarea');
    if (!control) return;

    const described = [];

    const hint = field.querySelector(':scope > .fm-label-row .bl-hint__bubble, :scope > .fm-hint');
    if (hint && hint.id) described.push(hint.id);

    const error = field.querySelector(':scope > .fm-error');
    if (error && error.id) described.push(error.id);

    if (described.length && !control.hasAttribute('aria-describedby')) {
        control.setAttribute('aria-describedby', described.join(' '));
    }

    if (error && !control.hasAttribute('aria-invalid')) {
        control.setAttribute('aria-invalid', 'true');
    }

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

window.wireFieldAccessibility = wireAllFields;
