{{--
    THE LISTING BODY: one shape for the record, on every channel.

    Draft C, picked 2026-09-08. Below the strip the page is quiet: the work
    is one sheet whose sections are separated by a rule and air rather than
    by seven raised cards, and the rail is a labelled column rather than a
    stack of boxes. Only what you can operate carries an edge.

    Rendered INSIDE the page's own form, because the form's action, its
    hidden fields and its method belong to the channel, and the arrangement
    does not.

    The shared cards a channel drops in here (Images that go up, Discount,
    Product group) keep their own markup and lose their chrome to
    channel-listing-console.css, the same way the fm- anatomy already
    flattens a sheet inside a sheet. A channel with something nobody else
    has puts it in this main slot as its own section, in the one order the
    contract keeps.

      main  slot  what the listing SAYS: content, category, pictures, and
                  whatever only this channel has
      rail  slot  what it COSTS: the price rule, the discount, the group
--}}
<div class="cl-body">
    <div class="cl-main">{{ $main }}</div>
    @if(trim($rail ?? '') !== '')
        <aside class="cl-rail">{{ $rail }}</aside>
    @endif
</div>
