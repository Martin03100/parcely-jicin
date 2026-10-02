'use strict';

const PARCEL_MIN_ZOOM = 14; // keep in sync with the backend
const JICIN = [15.3513, 50.4368];

// Land type colors (ČÚZK code list names).
const LAND_COLORS = {
  'orná půda': '#d9b26f',
  'trvalý travní porost': '#8fcf6b',
  'zahrada': '#c2e08a',
  'ovocný sad': '#f29e8e',
  'lesní pozemek': '#3f8f55',
  'vodní plocha': '#6fb7e8',
  'zastavěná plocha a nádvoří': '#c46b6b',
  'ostatní plocha': '#b8b8b8',
};
const OTHER_COLOR = '#d0d0d0';
const SEARCH_LIMIT = 20; // matches the API limit

// Backend URLs relative to the page; without a backend (file://) only the base map is shown.
const API_BASE = location.href.replace(/[?#].*$/, '').replace(/[^/]*$/, '');
const HAS_BACKEND = location.protocol !== 'file:';

const $ = (id) => document.getElementById(id);
const numberFormat = new Intl.NumberFormat('cs-CZ');

function landColorExpression() {
  return ['match', ['get', 'land_type'], ...Object.entries(LAND_COLORS).flat(), OTHER_COLOR];
}

function buildStyle() {
  const selectedOr = (selected, hover, normal) =>
    ['case', ['boolean', ['feature-state', 'selected'], false], selected,
      ['boolean', ['feature-state', 'hover'], false], hover, normal];

  const style = {
    version: 8,
    sources: {
      osm: {
        type: 'raster',
        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
        tileSize: 256,
        maxzoom: 19,
        attribution: '© přispěvatelé OpenStreetMap',
      },
      parcels: {
        type: 'vector',
        tiles: [`${API_BASE}tiles/{z}/{x}/{y}.pbf`],
        minzoom: 0,
        maxzoom: 16, // overzoom above
        attribution: 'Parcely: ČÚZK',
      },
    },
    layers: [
      { id: 'osm', type: 'raster', source: 'osm' },
      {
        id: 'ku-fill', type: 'fill', source: 'parcels', 'source-layer': 'ku', maxzoom: PARCEL_MIN_ZOOM,
        paint: { 'fill-color': '#0b6bcb', 'fill-opacity': 0.08 },
      },
      {
        id: 'ku-line', type: 'line', source: 'parcels', 'source-layer': 'ku', maxzoom: PARCEL_MIN_ZOOM,
        paint: { 'line-color': '#0b6bcb', 'line-width': 1.5 },
      },
      {
        id: 'parcels-fill', type: 'fill', source: 'parcels', 'source-layer': 'parcels', minzoom: PARCEL_MIN_ZOOM,
        paint: { 'fill-color': landColorExpression(), 'fill-opacity': selectedOr(0.9, 0.75, 0.5) },
      },
      {
        id: 'parcels-line', type: 'line', source: 'parcels', 'source-layer': 'parcels', minzoom: PARCEL_MIN_ZOOM,
        paint: {
          'line-color': selectedOr('#d10000', '#222', '#555'),
          'line-width': selectedOr(3, 1.5, 0.6),
        },
      },
    ],
  };
  if (!HAS_BACKEND) {
    delete style.sources.parcels;
    style.layers = style.layers.filter((l) => l.source === 'osm');
  }
  return style;
}

const hadHash = location.hash.length > 1; // read before MapLibre writes it

const map = new maplibregl.Map({
  container: 'map',
  style: buildStyle(),
  center: JICIN,
  zoom: 12,
  maxZoom: 19,
  hash: true,
});
map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-right');

// --- selection ---

let selectedId = null;
let hoverId = null;
let detailRequest = null;

function setState(id, state) {
  if (id !== null) map.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id }, state);
}

async function selectParcel(id, { fly = false } = {}) {
  detailRequest?.abort(); // only the latest request wins
  detailRequest = new AbortController();

  setState(selectedId, { selected: false });
  selectedId = id;
  setState(id, { selected: true });

  try {
    const res = await fetch(`${API_BASE}api/parcels/${id}`, { signal: detailRequest.signal });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const p = await res.json();
    showDetail(p);
    if (fly) map.fitBounds(p.bbox, { padding: 120, maxZoom: 18, duration: 800 });
  } catch (err) {
    if (err.name !== 'AbortError') setHint('Detail parcely se nepodařilo načíst.');
  }
}

function kuText(p) {
  if (p.cadastralAreaName) return `${p.cadastralAreaName} (${p.cadastralAreaCode})`;
  return p.cadastralAreaCode ?? '–';
}

function setHint(text) {
  $('hint').textContent = text;
  $('hint').hidden = false;
}

function showDetail(p) {
  $('d-label').textContent = p.label;
  $('d-ku').textContent = kuText(p);
  $('d-area').textContent = p.areaM2 === null ? '–' : `${numberFormat.format(p.areaM2)} m²`;
  $('d-type').textContent = p.landType ?? 'neuvedeno';
  $('d-source').textContent = p.source === 'demo' ? 'syntetická demo data' : 'ČÚZK';
  const kn = $('d-kn');
  kn.hidden = !p.knUrl;
  if (p.knUrl) kn.href = p.knUrl;
  $('detail').hidden = false;
  $('hint').hidden = true;
}

map.on('click', 'parcels-fill', (e) => {
  const feature = e.features?.[0];
  if (feature?.id !== undefined) selectParcel(Number(feature.id));
});
map.on('mousemove', 'parcels-fill', (e) => {
  map.getCanvas().style.cursor = 'pointer';
  const id = e.features?.[0]?.id;
  if (id === undefined || id === hoverId) return;
  setState(hoverId, { hover: false });
  hoverId = id;
  setState(hoverId, { hover: true });
});
map.on('mouseleave', 'parcels-fill', () => {
  map.getCanvas().style.cursor = '';
  setState(hoverId, { hover: false });
  hoverId = null;
});

// --- search ---

function resultItem(text, onClick) {
  const li = document.createElement('li');
  if (!onClick) {
    li.className = 'empty';
    li.textContent = text;
    return li;
  }
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = text;
  btn.addEventListener('click', onClick);
  li.append(btn);
  return li;
}

$('search').addEventListener('submit', async (e) => {
  e.preventDefault();
  const list = $('results');
  list.replaceChildren();
  list.hidden = true;
  const q = $('q').value.trim();
  if (!q) return;

  try {
    const res = await fetch(`${API_BASE}api/search?q=${encodeURIComponent(q)}`);
    if (res.status === 400) {
      list.append(resultItem('Zadejte číslo parcely, např. 123/4, st. 56 nebo 123/4 Jičín.'));
      list.hidden = false;
      return;
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const { results } = await res.json();
    if (results.length === 1) {
      selectParcel(results[0].id, { fly: true });
      return;
    }
    if (results.length === 0) list.append(resultItem('Nic nenalezeno.'));
    for (const r of results) {
      const ku = r.cadastralAreaName ?? r.cadastralAreaCode ?? '?';
      list.append(resultItem(`${r.label} – k. ú. ${ku}`, () => {
        list.hidden = true;
        selectParcel(r.id, { fly: true });
      }));
    }
    if (results.length === SEARCH_LIMIT) list.append(resultItem(`Zobrazeno prvních ${SEARCH_LIMIT} výsledků.`));
    list.hidden = false;
  } catch {
    setHint('Vyhledávání selhalo, zkuste to prosím znovu.');
  }
});

// --- initial view, legend, FPS (?debug) ---

function renderLegend(landTypes) {
  const ul = $('legend').querySelector('ul');
  const entries = landTypes.map((t) => [t, LAND_COLORS[t] ?? OTHER_COLOR]);
  entries.push(['neuvedeno', OTHER_COLOR]);
  for (const [name, color] of entries) {
    const li = document.createElement('li');
    const swatch = document.createElement('i');
    swatch.style.background = color;
    li.append(swatch, name);
    ul.append(li);
  }
}

if (HAS_BACKEND) {
  fetch(`${API_BASE}api/meta`)
    .then((r) => r.json())
    .then((meta) => {
      $('stats').textContent = `${numberFormat.format(meta.parcels)} parcel · ${meta.cadastralAreas} katastrálních území`;
      renderLegend(meta.landTypes ?? []);
      // keep the view from the URL hash
      if (meta.bounds && !hadHash) map.fitBounds(meta.bounds, { padding: 40, duration: 0 });
    })
    .catch(() => {});
} else {
  setHint('Parcely se načítají ze serveru, spusťte aplikaci přes docker compose.');
}

if (new URLSearchParams(location.search).has('debug')) {
  const box = $('fps');
  box.hidden = false;
  let frames = 0;
  let last = performance.now();
  const tick = (now) => {
    frames++;
    if (now - last >= 1000) {
      box.textContent = `${frames} FPS · zoom ${map.getZoom().toFixed(1)}`;
      frames = 0;
      last = now;
    }
    requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}
