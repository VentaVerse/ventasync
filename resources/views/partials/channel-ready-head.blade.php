{{-- The Readiness column's header, one for every channel's Listings page and
     group page (partials.channel-ready draws the cells). The tooltip is
     the column's whole definition; there is no paragraph under the table
     (descriptions are tooltips). --}}
<th scope="col" class="cc-col-ready">Readiness <x-ui.hint label="What Readiness means">Ready means every check VentaSync runs before sending passes, so a push cannot be refused here. The store can still refuse for what only it knows; Last sync says so.</x-ui.hint></th>
