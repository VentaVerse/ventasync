{{-- The status options grouped by what they mean, in the four buckets
     OrderStatusTone already knows: a flat list of sixteen with "Complete"
     beside "Completed" invited the wrong one (critique, 2026-08-27). Each
     option carries whether the status takes stock, so the page can say what
     a move will do before it is made.
       $statuses  the OrderStatus rows
       $selected  the selected order_status_id --}}
@php
    $buckets = ['warning' => 'Needs you', 'info' => 'Moving', 'success' => 'Done', 'danger' => 'Failed', 'neutral' => 'Other'];
    $grouped = [];
    foreach ($statuses as $s) {
        $grouped[\App\Support\OrderStatusTone::for($s->name)][] = $s;
    }
@endphp
@foreach($buckets as $tone => $bucketLabel)
    @if(!empty($grouped[$tone]))
        <optgroup label="{{ $bucketLabel }}">
            @foreach($grouped[$tone] as $s)
                <option value="{{ $s->order_status_id }}" data-subtract="{{ (int) ($s->subtract_stock ?? 0) }}"
                        @selected((int) $selected === (int) $s->order_status_id)>{{ $s->name }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
