function initShopifyOrders() {
    const modal = document.getElementById('sfPayloadModal');
    if (!modal) return;

    const pre = document.getElementById('sfPayloadPre');
    const title = document.getElementById('sfPayloadTitle');
    if (!pre) return;

    let lastTrigger = null;

    function close() {
        modal.classList.remove('active');
        pre.textContent = '';
        if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
        lastTrigger = null;
    }

    function open(trigger) {
        const island = document.getElementById(trigger.getAttribute('data-sf-payload') || '');
        const order = trigger.getAttribute('data-sf-order') || '';

        if (title) title.textContent = order ? 'Raw payload ' + order : 'Raw payload';

        const text = island ? (island.textContent || '').trim() : '';
        pre.textContent = text !== '' && text !== 'null'
            ? text
            : 'Nothing was stored for this order.';

        lastTrigger = trigger;
        modal.classList.add('active');
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-sf-payload]');
        if (trigger) {
            event.preventDefault();
            open(trigger);
            return;
        }

        if (event.target === modal) close();
    });

    ['btnCloseSfPayload', 'btnCloseSfPayload2'].forEach(function (id) {
        const button = document.getElementById(id);
        if (button) button.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) close();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initShopifyOrders);
} else {
    initShopifyOrders();
}
