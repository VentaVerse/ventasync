{{-- The status options, in the order the Order statuses settings page
     lists them (order_status_id), and in no other arrangement.

     They were grouped into four buckets - Needs you, Moving, Done,
     Failed - read off OrderStatusTone's hardcoded name lists. The owner,
     2026-09-09: "This isnt planned. First time Im seeing this. And what
     determins the group? There is no crud. There is no move status in
     this group in the order status settings page. What If I add a
     status? Where does it go to? This is hardcoded that I didnt ask
     for."

     He is right, and the last question is the one that settles it: a
     status an admin adds falls into "Other" and nothing on the settings
     page says so or lets them move it. A grouping nobody can edit is a
     grouping that goes stale the first time somebody uses the feature
     the table exists for. One flat list, in the same order as the page
     that owns these rows, is the answer both pages can keep.

     Each option still carries whether the status takes stock, so the
     form can say what a move will do before it is made.

       $statuses  the OrderStatus rows
       $selected  the selected order_status_id --}}
@foreach($statuses as $s)
    <option value="{{ $s->order_status_id }}" data-subtract="{{ (int) ($s->subtract_stock ?? 0) }}"
            @selected((int) $selected === (int) $s->order_status_id)>{{ $s->name }}</option>
@endforeach
