/* ============================================================
   Address suggestions for the request form.
   <input data-addr-suggest data-fill="destination_address" data-lat="dest_lat" data-lng="dest_lng"
          data-fill-mode="name|address">
   Provider from <form data-addr-provider="photon|google" data-addr-key="...">:
     photon  OpenStreetMap through photon.komoot.io (free, no key), Malaysia only
     google  Places API (New), with the key from System Settings
   Typing works as before; picking a suggestion fills the address and the coordinates.
   Editing the text afterwards clears the coordinates (they would no longer match).
   ARIA combobox: arrow keys move, Enter picks, Escape closes.
   ============================================================ */
(function () {
    'use strict';
    var form = document.querySelector('[data-addr-provider]');
    if (!form) { return; }
    var provider = form.getAttribute('data-addr-provider');
    var key = form.getAttribute('data-addr-key') || '';
    if (provider === 'off') { return; }
    var lang = document.documentElement.lang === 'ms' ? 'ms' : 'en';
    var MY_BBOX = '99.6,0.8,119.3,7.4';                    // Malaysia
    var NEAR = {lat: 3.0738, lon: 101.5183};                 // Shah Alam: nearer places first
    var uid = 0;

    function photonSearch(q, signal) {
        var url = 'https://photon.komoot.io/api/?limit=6&lang=en&bbox=' + MY_BBOX + '&lat=' + NEAR.lat + '&lon=' + NEAR.lon + '&q=' + encodeURIComponent(q);
        return fetch(url, {signal: signal}).then(function (r) { return r.json(); }).then(function (j) {
            return (j.features || []).map(function (f) {
                var p = f.properties || {}, c = (f.geometry || {}).coordinates || [];
                var street = [p.housenumber, p.street].filter(Boolean).join(' ');
                var town = [p.postcode, p.city || p.district || p.county].filter(Boolean).join(' ');
                var parts = [p.name, street, town, p.state].filter(function (x, i, a) { return x && a.indexOf(x) === i; });
                return {name: p.name || street || town, address: parts.join(', '), sub: parts.slice(1).join(', '), lat: c[1], lng: c[0]};
            }).filter(function (s) { return s.name; });
        });
    }

    function googleSearch(q, signal) {
        return fetch('https://places.googleapis.com/v1/places:autocomplete', {
            method: 'POST', signal: signal,
            headers: {'Content-Type': 'application/json', 'X-Goog-Api-Key': key},
            body: JSON.stringify({input: q, includedRegionCodes: ['my'], languageCode: lang,
                locationBias: {circle: {center: {latitude: NEAR.lat, longitude: NEAR.lon}, radius: 50000}}})
        }).then(function (r) { return r.json(); }).then(function (j) {
            return (j.suggestions || []).filter(function (s) { return s.placePrediction; }).map(function (s) {
                var p = s.placePrediction, f = p.structuredFormat || {};
                return {name: (f.mainText || {}).text || (p.text || {}).text, sub: (f.secondaryText || {}).text || '', placeId: p.placeId};
            });
        });
    }

    function googleDetails(s) {
        return fetch('https://places.googleapis.com/v1/places/' + encodeURIComponent(s.placeId) + '?languageCode=' + lang,
            {headers: {'X-Goog-Api-Key': key, 'X-Goog-FieldMask': 'displayName,formattedAddress,location'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                return {name: (d.displayName || {}).text || s.name, address: d.formattedAddress || [s.name, s.sub].filter(Boolean).join(', '),
                        lat: (d.location || {}).latitude, lng: (d.location || {}).longitude};
            });
    }

    var search = provider === 'google' ? googleSearch : photonSearch;

    document.querySelectorAll('input[data-addr-suggest]').forEach(function (input) {
        var id = 'addrList' + (++uid);
        var fill = document.getElementById(input.getAttribute('data-fill') || '');
        var latEl = document.getElementById(input.getAttribute('data-lat') || '');
        var lngEl = document.getElementById(input.getAttribute('data-lng') || '');
        var wrap = document.createElement('div');
        wrap.className = 'addr-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var list = document.createElement('ul');
        list.id = id; list.className = 'addr-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
        wrap.appendChild(list);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id);
        input.setAttribute('autocomplete', 'off');

        var items = [], active = -1, timer = null, ctrl = null, picked = input.value;

        function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
        function mark(i) {
            active = i;
            [].forEach.call(list.children, function (li, n) { li.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
            if (i >= 0 && list.children[i]) { input.setAttribute('aria-activedescendant', list.children[i].id); list.children[i].scrollIntoView({block: 'nearest'}); }
        }
        function render() {
            list.innerHTML = '';
            items.forEach(function (s, i) {
                var li = document.createElement('li');
                li.id = id + '_' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false');
                var b = document.createElement('span'); b.className = 'addr-name'; b.textContent = s.name; li.appendChild(b);
                if (s.sub) { var m = document.createElement('span'); m.className = 'addr-sub'; m.textContent = s.sub; li.appendChild(m); }
                li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
                list.appendChild(li);
            });
            var credit = document.createElement('li');
            credit.className = 'addr-credit'; credit.setAttribute('role', 'presentation');
            credit.textContent = provider === 'google' ? 'Suggestions by Google' : 'Suggestions © OpenStreetMap contributors';
            if (items.length) { list.appendChild(credit); }
            list.hidden = !items.length;
            input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
            mark(-1);
        }
        function apply(s) {
            var mode = input.getAttribute('data-fill-mode') || 'address';
            input.value = mode === 'name' ? s.name : s.address;
            if (fill) { fill.value = s.address; fill.dispatchEvent(new Event('input', {bubbles: true})); fill.setAttribute('data-picked', s.address); }
            if (latEl && s.lat != null) { latEl.value = Number(s.lat).toFixed(6); }
            if (lngEl && s.lng != null) { lngEl.value = Number(s.lng).toFixed(6); }
            picked = input.value;
        }
        function choose(i) {
            var s = items[i]; if (!s) { return; }
            close();
            if (s.placeId) { googleDetails(s).then(apply).catch(function () { apply({name: s.name, address: [s.name, s.sub].filter(Boolean).join(', ')}); }); }
            else { apply(s); }
        }
        function clearCoords() { if (latEl) { latEl.value = ''; } if (lngEl) { lngEl.value = ''; } }

        input.addEventListener('input', function () {
            if (input.value !== picked) { clearCoords(); }
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 3) { items = []; render(); return; }
            timer = setTimeout(function () {
                if (ctrl) { ctrl.abort(); }
                ctrl = window.AbortController ? new AbortController() : null;
                search(q, ctrl && ctrl.signal).then(function (r) { items = r; render(); }).catch(function () { items = []; render(); });
            }, 300);
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); mark(Math.min(active + 1, items.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mark(Math.max(active - 1, 0)); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 120); });
        // typing in the address field by hand also means the coordinates no longer match
        if (fill && fill !== input) {
            fill.addEventListener('input', function (e) { if (e.isTrusted && fill.value !== fill.getAttribute('data-picked')) { clearCoords(); } });
        }
    });
})();
