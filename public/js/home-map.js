(function () {
    'use strict';

    /*
    home-map.js

    QUOI : Carte Leaflet sur l’accueil avec un pin par événement géolocalisé ; popup titre + lien fiche.

    COMMENT : Lit `window.OPALE_MAP_EVENTS` au chargement ; expose `window.opaleMap.setMarkers()` pour `search.js`.

    OÙ : Page d’accueil (`home/index.html.twig`).

    POURQUOI : Explorer la Côte d’Opale visuellement et identifier un événement (ex. pin à Calais) avant d’ouvrir la fiche.
    */

    const mapEl = document.getElementById('home-events-map');
    if (!mapEl || !window.L) {
        return;
    }

    const DEFAULT_CENTER = [50.63, 1.75];
    const DEFAULT_ZOOM = 10;
    const OPALE_BOUNDS = L.latLngBounds([50.35, 1.35], [51.05, 2.25]);

    const map = L.map(mapEl, {
        scrollWheelZoom: false,
        zoomControl: true,
        maxBounds: OPALE_BOUNDS.pad(0.15),
        minZoom: 8,
        maxZoom: 16,
    }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

    // Exposé pour permettre à `search.js` d'appeler `invalidateSize()`
    // après ouverture du panneau « Voir la carte » (sinon tuiles grises).
    window.OPALE_HOME_MAP = map;

    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 19,
    }).addTo(map);

    const markerLayer = L.layerGroup().addTo(map);
    let markersById = new Map();

    const pinIcon = L.divIcon({
        className: 'home-map-marker-wrap',
        html: '<div class="home-map-marker" role="img" aria-hidden="true"></div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -16],
    });

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const popupHtml = (event) => `
        <div class="home-map-popup">
            <p class="home-map-popup__category">${escapeHtml(event.categorie)}</p>
            <h3 class="home-map-popup__title">${escapeHtml(event.titre)}</h3>
            <p class="home-map-popup__meta">${escapeHtml(event.date)} · ${escapeHtml(event.ville)}</p>
            <a class="home-map-popup__link" href="${escapeHtml(event.url)}">Voir l'événement →</a>
        </div>
    `;

    const fitToMarkers = () => {
        const latLngs = [];
        markerLayer.eachLayer((layer) => {
            if (layer.getLatLng) {
                latLngs.push(layer.getLatLng());
            }
        });

        if (latLngs.length === 0) {
            map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
            return;
        }

        if (latLngs.length === 1) {
            map.setView(latLngs[0], 13);
            return;
        }

        map.fitBounds(L.latLngBounds(latLngs), { padding: [40, 40], maxZoom: 13 });
    };

    const setEmptyState = (visible) => {
        const empty = document.getElementById('home-map-empty');
        if (empty) {
            empty.hidden = !visible;
        }
        mapEl.classList.toggle('home-events-map--empty', visible);
    };

    const addMarker = (event) => {
        const lat = parseFloat(event.latitude);
        const lng = parseFloat(event.longitude);
        if (Number.isNaN(lat) || Number.isNaN(lng)) {
            return;
        }

        const marker = L.marker([lat, lng], { icon: pinIcon, title: event.titre });
        marker.bindPopup(popupHtml(event), { maxWidth: 280, className: 'home-map-popup-wrap' });
        marker.on('click', () => marker.openPopup());
        marker.addTo(markerLayer);
        markersById.set(String(event.id), marker);
    };

    const setMarkers = (events) => {
        markerLayer.clearLayers();
        markersById = new Map();

        const list = Array.isArray(events) ? events : [];
        list.forEach(addMarker);

        setEmptyState(list.length === 0);
        fitToMarkers();

        requestAnimationFrame(() => map.invalidateSize());
    };

    const initial = Array.isArray(window.OPALE_MAP_EVENTS) ? window.OPALE_MAP_EVENTS : [];
    setMarkers(initial);

    window.opaleMap = { setMarkers };

    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => map.invalidateSize());
        ro.observe(mapEl);
    }
})();
