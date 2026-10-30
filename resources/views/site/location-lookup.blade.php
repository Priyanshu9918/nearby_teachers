@extends('site.layout')

@section('title', 'Coordinate & locality lookup')
@section('description', 'Look up locality and address details from latitude and longitude.')

@section('content')
<main class="lookup-shell">
    <a class="back-link" href="{{ route('website.home') }}">← Nearby Teacher</a>
    <header class="hero">
        <span class="eyebrow">Free location tool · no sign-in</span>
        <h1>Coordinate &amp; locality lookup</h1>
        <p>Enter latitude and longitude to find the closest mapped address, neighbourhood, city, district, state, country, postcode, and other available OpenStreetMap details.</p>
    </header>

    <section class="lookup-card" aria-labelledby="coordinates-heading">
        <div class="card-heading">
            <div><h2 id="coordinates-heading">Coordinates</h2><p>Latitude: −90 to 90 · Longitude: −180 to 180</p></div>
            <span class="access-note">Public tool · no account needed</span>
        </div>
        <form id="lookup-form" action="{{ route('website.location-lookup.reverse') }}" method="post">
            @csrf
            <div class="coordinate-grid">
                <label>Latitude
                    <input id="latitude" name="latitude" type="number" inputmode="decimal" min="-90" max="90" step="any" placeholder="e.g. 28.6139" required>
                </label>
                <label>Longitude
                    <input id="longitude" name="longitude" type="number" inputmode="decimal" min="-180" max="180" step="any" placeholder="e.g. 77.2090" required>
                </label>
            </div>
            <div class="form-actions">
                <button class="button primary" id="lookup-button" type="submit">Find locality details</button>
                <button class="button secondary" id="use-location" type="button">Use my current location</button>
                <button class="button quiet" id="clear-form" type="reset">Clear</button>
            </div>
            <p class="privacy-note">Coordinates are sent to this application and forwarded to OpenStreetMap's Nominatim service to retrieve address details. Results depend on available map data and may identify the nearest mapped address rather than an exact property.</p>
        </form>
    </section>

    <div id="lookup-message" class="message" role="status" aria-live="polite" hidden></div>

    <section id="result-panel" class="result-panel" aria-live="polite" hidden>
        <div class="result-heading">
            <div><span class="eyebrow">Reverse geocoding result</span><h2 id="result-name"></h2><p id="result-address"></p></div>
            <div class="result-actions">
                <a class="button secondary" id="map-link" href="#" target="_blank" rel="noopener noreferrer">Open map ↗</a>
                <button class="button secondary" id="copy-json" type="button">Copy JSON</button>
            </div>
        </div>
        <div class="summary-grid" id="summary-grid"></div>
        <section class="details-card">
            <div class="details-heading"><div><h3>All address components</h3><p>Every locality/address field returned by the map data.</p></div><span id="component-count" class="component-count"></span></div>
            <div class="table-wrap"><table><thead><tr><th>Component</th><th>Value</th></tr></thead><tbody id="address-components"></tbody></table></div>
        </section>
        <details class="details-card metadata">
            <summary>Place metadata and raw response</summary>
            <div id="place-metadata" class="metadata-grid"></div>
            <pre id="raw-json" class="raw-json"></pre>
        </details>
        <p class="attribution">Geocoding data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. Powered by Nominatim.</p>
    </section>
</main>

<style>
    .lookup-shell{max-width:1060px;margin:0 auto;padding:34px 22px 64px}.back-link{display:inline-flex;margin-bottom:24px;color:#185fa5;text-decoration:none;font-size:13px;font-weight:600}.hero{max-width:760px;margin-bottom:24px}.eyebrow{display:inline-block;color:#2764cf;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em}.hero h1{margin:8px 0 10px;font-size:clamp(28px,5vw,42px);line-height:1.1;letter-spacing:-.04em;color:#172841}.hero p{max-width:720px;margin:0;color:#6e7b8f;font-size:15px;line-height:1.7}.lookup-card,.details-card{background:#fff;border:1px solid #e0e6ee;border-radius:15px;box-shadow:0 7px 28px rgba(26,49,83,.05)}.lookup-card{padding:22px}.card-heading,.result-heading,.details-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.card-heading h2,.result-heading h2,.details-heading h3{margin:0;color:#1e304a}.card-heading h2{font-size:16px}.card-heading p,.details-heading p{margin:5px 0 0;color:#8490a1;font-size:11px}.access-note{padding:7px 10px;border-radius:99px;background:#eff8f2;color:#24764f;font-size:10px;font-weight:700}.coordinate-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:22px}.coordinate-grid label{display:grid;gap:7px;color:#34445b;font-size:12px;font-weight:700}.coordinate-grid input{height:45px;width:100%;padding:0 12px;border:1px solid #d4deea;border-radius:9px;background:#fff;color:#172841;font:inherit;font-size:14px;outline:none}.coordinate-grid input:focus{border-color:#5687e6;box-shadow:0 0 0 3px rgba(44,103,218,.12)}.form-actions,.result-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:16px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:8px 13px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#33445c;text-decoration:none;font:inherit;font-size:11px;font-weight:750;cursor:pointer}.button:hover{background:#f6f8fb}.button.primary{background:#2864dc;border-color:#2864dc;color:#fff}.button.primary:hover{background:#2052ba}.button:disabled{opacity:.65;cursor:wait}.button.quiet{border-color:transparent;color:#718096}.privacy-note{margin:14px 0 0;color:#8793a3;font-size:10px;line-height:1.55}.message{margin-top:14px;padding:12px 14px;border:1px solid #d5e2f9;border-radius:9px;background:#f3f7ff;color:#35547d;font-size:12px;line-height:1.5}.message.error{border-color:#f0c4c9;background:#fff3f4;color:#9f2834}.result-panel{margin-top:22px}.result-heading{align-items:center;margin:0 0 14px}.result-heading h2{margin:5px 0;font-size:22px;letter-spacing:-.025em}.result-heading p{margin:0;color:#758297;font-size:12px;line-height:1.5}.result-actions{margin:0}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:13px}.summary-item{min-width:0;background:#fff;border:1px solid #e0e6ee;border-radius:11px;padding:13px}.summary-item span{display:block;margin-bottom:6px;color:#8b96a6;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.summary-item strong{display:block;color:#273b58;font-size:12px;line-height:1.5;overflow-wrap:anywhere}.details-card{margin-top:13px;padding:17px 19px}.details-heading{align-items:center;margin-bottom:12px}.details-heading h3{font-size:13px}.component-count{padding:5px 8px;border-radius:99px;background:#f0f3f8;color:#6c798d;font-size:9px;font-weight:750}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:9px 8px;border-top:1px solid #edf0f4;font-size:11px}th{color:#8390a2;font-size:9px;text-transform:uppercase;letter-spacing:.08em}td:first-child{width:34%;color:#68778d;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}td:last-child{color:#263b58;overflow-wrap:anywhere}.metadata summary{cursor:pointer;color:#34445b;font-size:12px;font-weight:750}.metadata-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:14px}.metadata-item{padding:9px;background:#f7f9fc;border-radius:7px;overflow-wrap:anywhere}.metadata-item span{display:block;color:#8591a2;font-size:9px;margin-bottom:4px}.metadata-item strong{color:#34445b;font-size:10px}.raw-json{max-height:400px;overflow:auto;margin:12px 0 0;padding:13px;border-radius:8px;background:#111c2c;color:#d9e4f4;font:10px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.attribution{text-align:right;color:#858f9e;font-size:10px;margin:12px 2px}.attribution a{color:#42689b}
    @media(max-width:680px){.lookup-shell{padding:23px 14px 45px}.lookup-card{padding:17px}.coordinate-grid{grid-template-columns:1fr;gap:12px;margin-top:17px}.card-heading,.result-heading{flex-direction:column}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.metadata-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.result-actions{margin-top:3px}}
    .lookup-shell{color:var(--app-text-primary)}.back-link,.attribution a{color:var(--app-primary)}.eyebrow{color:var(--app-map-radius)}.hero h1{color:var(--app-text-primary)}.hero p,.privacy-note,.result-heading p,.attribution{color:var(--app-text-secondary)}
    .lookup-card,.details-card,.summary-item{background:var(--app-surface);border-color:var(--app-border);box-shadow:0 7px 28px #102b4e0d}.card-heading h2,.result-heading h2,.details-heading h3{color:var(--app-primary)}.card-heading p,.details-heading p,.summary-item span,th,td:first-child,.metadata-item span{color:var(--app-text-secondary)}
    .access-note{background:var(--app-primary-soft);color:var(--app-success)}.coordinate-grid label,.metadata summary{color:var(--app-text-primary)}.coordinate-grid input{border-color:var(--app-border);background:var(--app-surface);color:var(--app-text-primary)}.coordinate-grid input:focus{border-color:var(--app-map-radius);box-shadow:0 0 0 3px #2f80ff20}
    .button{border-color:var(--app-border);background:var(--app-surface);color:var(--app-primary)}.button:hover{background:var(--app-primary-soft)}.button.primary,.button.primary:hover{background:var(--app-primary);border-color:var(--app-primary);color:var(--app-surface)}.button.quiet{color:var(--app-text-secondary)}
    .message{border-color:var(--app-border);background:var(--app-primary-soft);color:var(--app-primary)}.message.error{border-color:var(--app-danger);background:var(--app-surface);color:var(--app-danger)}.component-count,.metadata-item{background:var(--app-map-background);color:var(--app-text-secondary)}th,td{border-color:var(--app-border)}td:last-child,.summary-item strong,.metadata-item strong{color:var(--app-text-primary)}.raw-json{background:var(--app-primary);color:var(--app-primary-soft)}
</style>

<script>
(() => {
    const form = document.getElementById('lookup-form');
    const latitude = document.getElementById('latitude');
    const longitude = document.getElementById('longitude');
    const lookupButton = document.getElementById('lookup-button');
    const message = document.getElementById('lookup-message');
    const resultPanel = document.getElementById('result-panel');
    const addressComponents = document.getElementById('address-components');
    const summaryGrid = document.getElementById('summary-grid');
    const metadataGrid = document.getElementById('place-metadata');
    const rawJson = document.getElementById('raw-json');
    let currentResult = null;

    function setMessage(text, isError = false) {
        message.textContent = text;
        message.classList.toggle('error', isError);
        message.hidden = !text;
    }

    function addSummary(label, value) {
        if (value === undefined || value === null || value === '') return;
        const item = document.createElement('div');
        item.className = 'summary-item';
        const name = document.createElement('span');
        name.textContent = label;
        const valueText = document.createElement('strong');
        valueText.textContent = value;
        item.append(name, valueText);
        summaryGrid.append(item);
    }

    function addMetadata(label, value) {
        if (value === undefined || value === null || value === '') return;
        const item = document.createElement('div');
        item.className = 'metadata-item';
        const name = document.createElement('span');
        name.textContent = label;
        const valueText = document.createElement('strong');
        valueText.textContent = Array.isArray(value) ? value.join(', ') : String(value);
        item.append(name, valueText);
        metadataGrid.append(item);
    }

    function renderLocation(location) {
        currentResult = location;
        const address = location.address || {};
        document.getElementById('result-name').textContent = location.name || address.amenity || address.building || address.shop || 'Location details';
        document.getElementById('result-address').textContent = location.display_name || 'No formatted address is available.';
        summaryGrid.replaceChildren();
        [
            ['Neighbourhood', address.neighbourhood || address.suburb || address.quarter || address.hamlet],
            ['Locality', address.city || address.town || address.village || address.municipality],
            ['District / county', address.county || address.district],
            ['State / region', address.state || address.region],
            ['Postcode', address.postcode],
            ['Country', address.country],
            ['Coordinates', `${location.lat}, ${location.lon}`],
            ['Place type', location.addresstype || location.type],
        ].forEach(([label, value]) => addSummary(label, value));

        addressComponents.replaceChildren();
        const entries = Object.entries(address).sort(([left], [right]) => left.localeCompare(right));
        document.getElementById('component-count').textContent = `${entries.length} component${entries.length === 1 ? '' : 's'}`;
        entries.forEach(([key, value]) => {
            const row = document.createElement('tr');
            const keyCell = document.createElement('td');
            const valueCell = document.createElement('td');
            keyCell.textContent = key.replaceAll('_', ' ');
            valueCell.textContent = String(value);
            row.append(keyCell, valueCell);
            addressComponents.append(row);
        });

        metadataGrid.replaceChildren();
        [
            ['Place ID', location.place_id],
            ['OSM type', location.osm_type],
            ['OSM ID', location.osm_id],
            ['Category', location.category],
            ['Type', location.type],
            ['Address type', location.addresstype],
            ['Place rank', location.place_rank],
            ['Importance', location.importance],
            ['Bounding box', location.boundingbox],
            ...Object.entries(location.extratags || {}).map(([key, value]) => [`Extra · ${key}`, value]),
            ...Object.entries(location.namedetails || {}).map(([key, value]) => [`Name · ${key}`, value]),
        ].forEach(([label, value]) => addMetadata(label, value));
        rawJson.textContent = JSON.stringify(location, null, 2);
        const mapUrl = new URL('https://www.openstreetmap.org/');
        mapUrl.searchParams.set('mlat', location.lat);
        mapUrl.searchParams.set('mlon', location.lon);
        document.getElementById('map-link').href = mapUrl.toString();
        resultPanel.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        setMessage('');
        resultPanel.hidden = true;
        lookupButton.disabled = true;
        lookupButton.textContent = 'Looking up…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.elements._token.value,
                },
                body: JSON.stringify({latitude: latitude.value, longitude: longitude.value}),
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.message || `Location lookup failed (${response.status}).`);
            }
            renderLocation(payload.location);
            setMessage('Address details loaded. Locality names reflect the nearest available OpenStreetMap data.');
            resultPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
        } catch (error) {
            setMessage(error.message || 'The location lookup failed. Please try again.', true);
        } finally {
            lookupButton.disabled = false;
            lookupButton.textContent = 'Find locality details';
        }
    });

    document.getElementById('use-location').addEventListener('click', () => {
        if (!navigator.geolocation) {
            setMessage('This browser does not support location access. Enter coordinates manually.', true);
            return;
        }
        setMessage('Waiting for browser location permission…');
        navigator.geolocation.getCurrentPosition((position) => {
            latitude.value = position.coords.latitude.toFixed(6);
            longitude.value = position.coords.longitude.toFixed(6);
            setMessage('Coordinates filled. Select “Find locality details” to continue.');
        }, (error) => {
            const errors = {
                1: 'Location access was denied. You can enter coordinates manually.',
                2: 'Your current location is unavailable. Try again or enter coordinates manually.',
                3: 'Location request timed out. Try again or enter coordinates manually.',
            };
            setMessage(errors[error.code] || 'Could not read your location. Enter coordinates manually.', true);
        }, {enableHighAccuracy: true, timeout: 12000, maximumAge: 30000});
    });

    document.getElementById('copy-json').addEventListener('click', async () => {
        if (!currentResult) return;
        try {
            await navigator.clipboard.writeText(JSON.stringify(currentResult, null, 2));
            setMessage('Full location response copied to clipboard.');
        } catch {
            setMessage('Clipboard access is unavailable in this browser. The raw JSON is shown below.', true);
        }
    });

    document.getElementById('clear-form').addEventListener('click', () => {
        resultPanel.hidden = true;
        currentResult = null;
        setMessage('');
    });
})();
</script>
@endsection
