@foreach($statuses as $s)
    <option value="{{ $s->order_status_id }}" data-subtract="{{ (int) ($s->subtract_stock ?? 0) }}"
            @selected((int) $selected === (int) $s->order_status_id)>{{ $s->name }}</option>
@endforeach
