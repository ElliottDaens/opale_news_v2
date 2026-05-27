(function () {
    'use strict';

    /*
    home-map.js

    QUOI : Carte Leaflet sur l’accueil avec un pin par lieu (pas par événement) ; popup titre + lien fiche, ou popup-liste si plusieurs événements partagent les mêmes coordonnées.

    COMMENT : Lit `window.OPALE_MAP_EVENTS` au chargement ; expose `window.opaleMap.setMarkers()` pour `search.js`. Les événements sont regroupés par coordonnées arrondies à 5 décimales (~1 m) avant rendu.

    OÙ : Page d’accueil (`home/index.html.twig`).

    POURQUOI : Explorer la Côte d’Opale visuellement sans perdre d’événements lorsqu’une salle ou une mairie héberge plusieurs sorties au même point GPS.
    */

    const mapEl = document.getElementById('home-events-map');
    if (!mapEl || !window.L) {
        return;
    }

    const DEFAULT_CENTER = [50.63, 1.75];
    const DEFAULT_ZOOM = 10;
    const OPALE_BOUNDS = L.latLngBounds([50.35, 1.35], [51.05, 2.25]);
    // Précision de regroupement : 5 décimales ≈ 1 m. Deux événements considérés
    // "au même endroit" si latitude ET longitude coïncident à cette précision.
    const COLOCATION_PRECISION = 5;

    const map = L.map(mapEl, {
        // Zoom molette activé pour respecter l'attente utilisateur ; le hijack du
        // scroll page n'est pas un problème ici car la carte est repliable.
        scrollWheelZoom: true,
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
    let userMarker = null;
    let hasUserPosition = false;

    const singleIcon = L.divIcon({
        className: 'home-map-marker-wrap',
        html: '<div class="home-map-marker" role="img" aria-hidden="true"></div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -16],
    });

    const clusterIcon = (count) => L.divIcon({
        className: 'home-map-marker-wrap',
        html: `<div class="home-map-marker home-map-marker--cluster" role="img" aria-label="${count} événements à ce lieu"><span class="home-map-marker__count">${count}</span></div>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
        popupAnchor: [0, -20],
    });

    const userIcon = L.divIcon({
        className: 'home-map-user-wrap',
        html: '<div class="home-map-user-marker" role="img" aria-label="Ma position"><span class="home-map-user-marker__pulse" aria-hidden="true"></span><span class="home-map-user-marker__dot" aria-hidden="true"></span></div>',
        iconSize: [22, 22],
        iconAnchor: [11, 11],
    });

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const singlePopupHtml = (event) => `
        <div class="home-map-popup">
            <p class="home-map-popup__category">${escapeHtml(event.categorie)}</p>
            <h3 class="home-map-popup__title">${escapeHtml(event.titre)}</h3>
            <p class="home-map-popup__meta">${escapeHtml(event.date)} · ${escapeHtml(event.ville)}</p>
            <a class="home-map-popup__link" href="${escapeHtml(event.url)}">Voir l'événement →</a>
        </div>
    `;

    const clusterPopupHtml = (events) => {
        const first = events[0];
        const items = events.map((event) => `
            <li class="home-map-popup-list__item">
                <a class="home-map-popup-list__link" href="${escapeHtml(event.url)}">
                    <span class="home-map-popup-list__category">${escapeHtml(event.categorie)}</span>
                    <span class="home-map-popup-list__title">${escapeHtml(event.titre)}</span>
                    <span class="home-map-popup-list__date">${escapeHtml(event.date)}</span>
                </a>
            </li>
        `).join('');

        return `
            <div class="home-map-popup home-map-popup--cluster">
                <header class="home-map-popup__header">
                    <span class="home-map-popup__badge" aria-hidden="true">${events.length}</span>
                    <div>
                        <p class="home-map-popup__eyebrow">${events.length} événements à ce lieu</p>
                        <p class="home-map-popup__meta">${escapeHtml(first.ville)}</p>
                    </div>
                </header>
                <ul class="home-map-popup-list">${items}</ul>
            </div>
        `;
    };

    const fitToMarkers = (groups) => {
        // Si la position utilisateur est active, on ne re-zoome PAS sur l'ensemble
        // des events : la vue reste centrée sur l'utilisateur pour préserver le
        // contexte « events à proximité de moi ».
        if (hasUserPosition) {
            return;
        }

        const points = groups.map((g) => g.latLng);

        if (points.length === 0) {
            map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
            return;
        }

        if (points.length === 1) {
            map.setView(points[0], 13);
            return;
        }

        const bounds = L.latLngBounds(points);
        // Si tous les groupes restent (presque) au même endroit, fitBounds dézoome
        // à l'infini → on fixe explicitement le zoom.
        if (!bounds.isValid() || bounds.getNorthEast().equals(bounds.getSouthWest())) {
            map.setView(points[0], 13);
            return;
        }
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
    };

    /**
     * Place (ou met à jour) le marqueur « Ma position » et recentre la carte dessus.
     * Appelé par `search.js` après une géolocalisation réussie.
     */
    const setUserPosition = (pos) => {
        if (!pos || typeof pos.lat !== 'number' || typeof pos.lng !== 'number') {
            return;
        }
        const latLng = [pos.lat, pos.lng];

        if (userMarker) {
            userMarker.setLatLng(latLng);
        } else {
            userMarker = L.marker(latLng, {
                icon: userIcon,
                interactive: false,
                keyboard: false,
                zIndexOffset: 1000,
            });
            userMarker.addTo(map);
        }

        hasUserPosition = true;
        // Zoom 13 ≈ 3 km de rayon visible → bon compromis pour voir les events
        // à pied / en voiture proches. `flyTo` plutôt que `setView` pour la fluidité.
        map.flyTo(latLng, 13, { duration: 0.6 });
    };

    /**
     * Retire le marqueur utilisateur et restaure le fit automatique sur les events.
     */
    const clearUserPosition = () => {
        hasUserPosition = false;
        if (userMarker) {
            map.removeLayer(userMarker);
            userMarker = null;
        }
    };

    const setEmptyState = (visible) => {
        const empty = document.getElementById('home-map-empty');
        if (empty) {
            empty.hidden = !visible;
        }
        mapEl.classList.toggle('home-events-map--empty', visible);
    };

    /**
     * Regroupe une liste d’événements par coordonnées arrondies.
     * Retourne `[{ latLng: [lat, lng], events: Event[] }, …]`.
     */
    const groupByCoordinates = (events) => {
        const groups = new Map();
        events.forEach((event) => {
            const lat = parseFloat(event.latitude);
            const lng = parseFloat(event.longitude);
            if (Number.isNaN(lat) || Number.isNaN(lng)) {
                return;
            }
            const key = `${lat.toFixed(COLOCATION_PRECISION)},${lng.toFixed(COLOCATION_PRECISION)}`;
            let group = groups.get(key);
            if (!group) {
                group = { latLng: [lat, lng], events: [] };
                groups.set(key, group);
            }
            group.events.push(event);
        });
        return Array.from(groups.values());
    };

    const addGroupMarker = (group) => {
        const isCluster = group.events.length > 1;
        const icon = isCluster ? clusterIcon(group.events.length) : singleIcon;
        const title = isCluster
            ? `${group.events.length} événements à ce lieu`
            : group.events[0].titre;

        const marker = L.marker(group.latLng, { icon, title });
        const html = isCluster ? clusterPopupHtml(group.events) : singlePopupHtml(group.events[0]);
        // `maxHeight` délègue le scroll à Leaflet (.leaflet-popup-scrolled) au lieu
        // d'un sur-scroll interne à la liste : la popup ne dépasse jamais le viewport
        // carte, même quand `home-map-section` clippe (overflow: hidden pour l'anim).
        // `autoPanPadding` garantit une marge confortable autour du popup ouvert.
        marker.bindPopup(html, {
            maxWidth: isCluster ? 280 : 260,
            // 180 px : tient même sur la carte mobile (280 px de haut) avec marge
            // pour le pin et l'autopan. Au-delà → scroll interne géré par Leaflet.
            maxHeight: isCluster ? 180 : null,
            autoPan: true,
            autoPanPadding: [16, 32],
            keepInView: true,
            className: isCluster
                ? 'home-map-popup-wrap home-map-popup-wrap--cluster'
                : 'home-map-popup-wrap',
        });
        marker.addTo(markerLayer);
    };

    const setMarkers = (events) => {
        markerLayer.clearLayers();

        const list = Array.isArray(events) ? events : [];
        const groups = groupByCoordinates(list);
        groups.forEach(addGroupMarker);

        setEmptyState(groups.length === 0);
        fitToMarkers(groups);

        requestAnimationFrame(() => map.invalidateSize());
    };

    const initial = Array.isArray(window.OPALE_MAP_EVENTS) ? window.OPALE_MAP_EVENTS : [];
    setMarkers(initial);

    window.opaleMap = { setMarkers, setUserPosition, clearUserPosition };

    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => map.invalidateSize());
        ro.observe(mapEl);
    }
})();
