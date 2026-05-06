@props([
    'invoice'   => null,   // The buyer_invoice array (or null)
    'reference' => '',     // Order reference (used to build a unique modal id)
])

@php
    if (!is_array($invoice) || ($invoice['is_requested'] ?? false) !== true) {
        return;
    }

    $modalId    = 'invoiceModal_' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $reference);
    $type       = ucfirst((string) ($invoice['type'] ?? ''));
    $name       = (string) ($invoice['name'] ?? '');
    $tin        = (string) ($invoice['tin'] ?? '');
    $email      = (string) ($invoice['email'] ?? '');
    $phone      = (string) ($invoice['phone'] ?? '');
    $address    = is_array($invoice['address'] ?? null) ? $invoice['address'] : [];
    $fullAddr   = (string) ($address['full'] ?? '');
@endphp

<button type="button"
        class="btn-invoice-req"
        title="Buyer requested an invoice — click for details"
        onclick="document.getElementById('{{ $modalId }}').classList.add('active')">
    <svg class="btn-invoice-req__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 1.5h7.5L13 4v10a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V2a.5.5 0 0 1 .5-.5z"/>
        <path d="M5.5 6.5h5"/>
        <path d="M5.5 9h5"/>
        <path d="M5.5 11.5h3"/>
    </svg>
    Invoice
</button>

<div id="{{ $modalId }}" class="modal-backdrop">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3>Invoice Request</h3>
            <button type="button" class="modal-close"
                    onclick="document.getElementById('{{ $modalId }}').classList.remove('active')">&times;</button>
        </div>
        <div class="inv-modal-body">
            <div class="inv-row">
                <span class="inv-row__label">Buyer</span>
                <span class="inv-row__value">{{ $name !== '' ? $name : '—' }}</span>
                <span></span>
            </div>
            @if($type !== '')
                <div class="inv-row">
                    <span class="inv-row__label">Type</span>
                    <span class="inv-row__value">{{ $type }}</span>
                    <span></span>
                </div>
            @endif
            <div class="inv-row">
                <span class="inv-row__label">TIN</span>
                <span class="inv-row__value inv-row__value--mono">{{ $tin !== '' ? $tin : '—' }}</span>
                @if($tin !== '')
                    <button type="button" class="inv-copy-btn" data-copy="{{ $tin }}">Copy</button>
                @else
                    <span></span>
                @endif
            </div>
            <div class="inv-row">
                <span class="inv-row__label">Email</span>
                <span class="inv-row__value {{ $email === '' ? 'inv-row__value--muted' : '' }}">{{ $email !== '' ? $email : '—' }}</span>
                @if($email !== '')
                    <button type="button" class="inv-copy-btn" data-copy="{{ $email }}">Copy</button>
                @else
                    <span></span>
                @endif
            </div>
            <div class="inv-row">
                <span class="inv-row__label">Phone</span>
                <span class="inv-row__value {{ $phone === '' ? 'inv-row__value--muted' : '' }}">{{ $phone !== '' ? $phone : '—' }}</span>
                <span></span>
            </div>
            <div class="inv-row">
                <span class="inv-row__label">Address</span>
                <span class="inv-row__value">{{ $fullAddr !== '' ? $fullAddr : '—' }}</span>
                @if($fullAddr !== '')
                    <button type="button" class="inv-copy-btn" data-copy="{{ $fullAddr }}">Copy</button>
                @else
                    <span></span>
                @endif
            </div>
        </div>
    </div>
</div>
