@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Product</h2>
        <a class="btn secondary" href="{{ route('products.index') }}">Back</a>
    </div>

    <div class="tabs" style="margin-top:10px;">
        <a class="tab active" href="#">General</a>
        <a class="tab disabled" href="#" onclick="return false;">Options</a>
    </div>



    <form method="POST" action="{{ route('products.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label class="required">Model</label>
                <input class="input" name="model" value="{{ old('model') }}" required>
            </div>

            <div>
                <label>SKU</label>
                <input class="input" name="sku" value="{{ old('sku') }}">
            </div>

            <div>
                <label>Price</label>
                <input class="input" name="price" value="{{ old('price', '0') }}" required>
            </div>

            <div>
                <label>Quantity</label>
                <input class="input" name="quantity" value="{{ old('quantity', '0') }}" required>
            </div>

            <div class="typeahead">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', 0) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result.</div>
            </div>

            <div class="typeahead">
                <label>Category</label>
                <input class="input" id="category_search" placeholder="Type to search..." autocomplete="off" name="category_name">
                <input type="hidden" name="category_id" id="category_id" value="{{ old('category_id', 0) }}">
                <div class="typeahead-list" id="category_list"></div>
                <div class="hint">Start typing, then click a result.</div>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status" required>
                    <option value="1" {{ old('status','1')=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status','1')=='0'?'selected':'' }}>Disabled</option>
                </select>
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', '') !!}</textarea>
            </div>
        </div>

        <div style="margin-top:16px;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function wordStartMatch(text, term){
    if(!text || !term) return false;
    var h = String(text).toLowerCase();
    var t = String(term).toLowerCase().trim();
    if(!t) return false;
    var esc = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var re = new RegExp('(^|[^a-z0-9])' + esc);
    return re.test(h);
}

function makeTypeahead(inputId, listId, hiddenId, url) {
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);
    const hidden = document.getElementById(hiddenId);
    if(!input || !list || !hidden) return;

    let timer = null;

    function close() { list.style.display = 'none'; list.innerHTML = ''; }

    async function search(q) {
        const res = await fetch(url + '?term=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return [];
        return await res.json();
    }

    function render(items, q){
        list.innerHTML = '';
        if(!items || !items.length){ close(); return; }

        // Extra client-side enforcement (word-start)
        items = items.filter(it => wordStartMatch(it.name || it.path || '', q));

        if(!items.length){ close(); return; }

        items.forEach(it => {
            const li = document.createElement('button');
            li.type = 'button';
            li.textContent = it.name || it.path || '';
            li.addEventListener('mousedown', (e) => {
                e.preventDefault();
                input.value = it.name || it.path || '';
                hidden.value = it.manufacturer_id || it.category_id || '';
                close();
            });
            list.appendChild(li);
        });

        list.style.display = 'block';
    }

    input.addEventListener('input', () => {
        const q = (input.value || '').trim();
        hidden.value = ''; // force selection
        if (q.length < 1) { close(); return; }

        clearTimeout(timer);
        timer = setTimeout(async () => {
            const items = await search(q);
            render(items, q);
        }, 120);
    });

    document.addEventListener('click', (e) => {
        if (!list.contains(e.target) && e.target !== input) close();
    });
}

makeTypeahead('manufacturer_search','manufacturer_list','manufacturer_id','/api/catalog/manufacturers');
makeTypeahead('category_search','category_list','category_id','/api/catalog/categories');
</script>
@endpush