(function () {
    'use strict';

    /*
    search.js

    QUOI : Interface de recherche publique — requêtes débouncées, cache mémoire, géolocalisation optionnelle et affichage primaire / secondaire des événements.

    COMMENT : Appelle `GET /api/search` avec `q`, `lat`/`lng` ; gère l’UI (spinner, grilles, message vide, pastille temporelle) et réapplique les classes reveal sur les cartes injectées.

    OÙ : Page d’accueil côté client, couplé à `style.css` et aux endpoints API de recherche.

    POURQUOI : Offrir une recherche sémantique fluide avec tri par pertinence et suggestions, sans rechargement complet de page.
    */

    const MIN_QUERY_LENGTH = 2;
    const DEBOUNCE_MS = 300;
    const PAGE_SIZE = 12;
    const GEO_STORAGE_KEY = 'opaleNewsUserPosition';
    const GEO_MAX_AGE_MS = 1000 * 60 * 30;

    const searchInput = document.getElementById('search-input');
    const primaryGrid = document.getElementById('events-grid');
    const secondaryGrid = document.getElementById('secondary-grid');
    const secondarySection = document.getElementById('secondary-section');
    const emptyMessage = document.getElementById('results-empty');
    const countLabel = document.getElementById('results-count');
    const spinner = document.getElementById('search-spinner');
    const geoButton = document.getElementById('geo-button');
    const geoButtonLabel = document.getElementById('geo-button-label');
    const geoStatus = document.getElementById('geo-status');
    const loadMoreBtn = document.getElementById('load-more-btn');
    const sortToggle = document.getElementById('sort-toggle');
    const facetChips = document.getElementById('facet-chips');
    const filtersButton = document.getElementById('filters-button');
    const filtersPanel = document.getElementById('filters-panel');
    const filtersBadge = document.getElementById('filters-badge');
    const mapToggle = document.getElementById('map-toggle');
    const mapSection = document.getElementById('home-map-section');
    const mapClose = document.getElementById('map-close');
    const geoManual = document.getElementById('geo-manual');
    const geoManualToggle = document.getElementById('geo-manual-toggle');
    const geoManualPanel = document.getElementById('geo-manual-panel');
    const geoManualInput = document.getElementById('geo-manual-input');
    const geoManualSuggestions = document.getElementById('geo-manual-suggestions');
    const geoManualSpinner = document.getElementById('geo-manual-spinner');
    const geoManualHint = document.getElementById('geo-manual-hint');

    if (!searchInput || !primaryGrid) {
        return;
    }

    function updateFiltersBadge() {
        if (!filtersBadge) return;
        const count = activeFilters.categories.size;
        if (count > 0) {
            filtersBadge.textContent = String(count);
            filtersBadge.hidden = false;
        } else {
            filtersBadge.hidden = true;
        }
    }

    function setFiltersPanelOpen(open) {
        if (!filtersButton || !filtersPanel) return;
        filtersButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        filtersPanel.hidden = !open;
    }

    function setMapOpen(open) {
        if (!mapToggle || !mapSection) return;
        mapToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        mapSection.classList.toggle('is-collapsed', !open);
        mapSection.setAttribute('aria-hidden', open ? 'false' : 'true');
        const label = mapToggle.querySelector('.toolbar-pill__label');
        if (label) {
            label.textContent = open ? 'Masquer la carte' : 'Voir la carte';
        }
        if (open) {
            // Le conteneur Leaflet a besoin que ses dimensions soient mises à jour
            // après affichage (sinon tuiles grises).
            setTimeout(function () {
                if (window.OPALE_HOME_MAP && typeof window.OPALE_HOME_MAP.invalidateSize === 'function') {
                    window.OPALE_HOME_MAP.invalidateSize();
                }
                window.dispatchEvent(new Event('resize'));
            }, 320);
        }
    }

    if (filtersButton && filtersPanel) {
        filtersButton.addEventListener('click', () => {
            const isOpen = filtersButton.getAttribute('aria-expanded') === 'true';
            setFiltersPanelOpen(!isOpen);
        });
    }

    if (mapToggle && mapSection) {
        mapToggle.addEventListener('click', () => {
            const isOpen = mapToggle.getAttribute('aria-expanded') === 'true';
            setMapOpen(!isOpen);
        });
    }

    if (mapClose) {
        mapClose.addEventListener('click', () => {
            setMapOpen(false);
            if (mapToggle && typeof mapToggle.focus === 'function') {
                mapToggle.focus();
            }
        });
    }

    const activeFilters = {
        sort: 'relevance',
        page: 1,
        categories: new Set(),
        period: 'all',
        freeOnly: false,
    };

    // Tri choisi manuellement par l'utilisateur (Pertinence/Date) — restauré quand
    // la géoloc est désactivée. Permet d'avoir « distance » comme tri auto temporaire.
    let userPreferredSort = 'relevance';

    function setSortToggleVisualState(currentSort) {
        if (!sortToggle) return;
        const isDistance = currentSort === 'distance';
        sortToggle.classList.toggle('is-distance-mode', isDistance);
        sortToggle.querySelectorAll('[data-sort]').forEach((el) => {
            // En mode distance, aucun des deux boutons sort visibles n'est actif.
            const targetSort = isDistance ? userPreferredSort : currentSort;
            const isActive = el.dataset.sort === targetSort && !isDistance;
            el.classList.toggle('is-active', isActive);
            el.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    let userPosition = loadStoredPosition();

    function loadStoredPosition() {
        try {
            const raw = sessionStorage.getItem(GEO_STORAGE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (Date.now() - parsed.timestamp > GEO_MAX_AGE_MS) return null;
            return parsed;
        } catch (_) {
            return null;
        }
    }

    function savePosition(pos) {
        userPosition = pos;
        try {
            sessionStorage.setItem(GEO_STORAGE_KEY, JSON.stringify(pos));
        } catch (_) {}
    }

    function updateGeoUI(state, message = '') {
        if (!geoButton || !geoButtonLabel) return;
        geoButton.classList.remove('is-active', 'is-error', 'is-loading');
        geoButton.setAttribute('aria-pressed', state === 'active' ? 'true' : 'false');

        if (state === 'active') {
            geoButton.classList.add('is-active');
            geoButtonLabel.textContent = 'Tri par distance';
            // Bascule auto sur tri distance (geoloc → résultats les plus proches d'abord).
            activeFilters.sort = 'distance';
        } else if (state === 'loading') {
            geoButton.classList.add('is-loading');
            geoButtonLabel.textContent = 'Localisation…';
        } else if (state === 'error') {
            geoButton.classList.add('is-error');
            geoButtonLabel.textContent = 'Ma position';
            activeFilters.sort = userPreferredSort;
        } else {
            geoButtonLabel.textContent = 'Ma position';
            activeFilters.sort = userPreferredSort;
        }

        setSortToggleVisualState(activeFilters.sort);

        if (geoStatus) {
            if (message) {
                geoStatus.textContent = message;
                geoStatus.hidden = false;
            } else {
                geoStatus.hidden = true;
                geoStatus.textContent = '';
            }
        }
    }

    function requestGeolocation() {
        if (!('geolocation' in navigator)) {
            updateGeoUI('error', 'La géolocalisation n\'est pas supportée par ce navigateur.');
            return;
        }

        updateGeoUI('loading');

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const pos = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    timestamp: Date.now(),
                };
                savePosition(pos);
                updateGeoUI('active');
                // Ouvre la carte et y matérialise la position utilisateur : feedback
                // immédiat « voici où je suis, voici les events autour ».
                setMapOpen(true);
                if (window.opaleMap && typeof window.opaleMap.setUserPosition === 'function') {
                    window.opaleMap.setUserPosition(pos);
                }
                memoryCache.clear();
                activeFilters.page = 1;
                runSearch(searchInput.value.trim(), { append: false });
            },
            (error) => {
                userPosition = null;
                let msg = 'Position indisponible.';
                if (error.code === error.PERMISSION_DENIED) msg = 'Permission refusée.';
                else if (error.code === error.POSITION_UNAVAILABLE) msg = 'Position indisponible.';
                else if (error.code === error.TIMEOUT) msg = 'Délai dépassé.';
                updateGeoUI('error', msg);
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
        );
    }

    if (geoButton) {
        geoButton.addEventListener('click', () => {
            if (userPosition) {
                userPosition = null;
                try { sessionStorage.removeItem(GEO_STORAGE_KEY); } catch (_) {}
                updateGeoUI('idle');
                if (window.opaleMap && typeof window.opaleMap.clearUserPosition === 'function') {
                    window.opaleMap.clearUserPosition();
                }
                memoryCache.clear();
                activeFilters.page = 1;
                runSearch(searchInput.value.trim(), { append: false });
            } else {
                requestGeolocation();
            }
        });

        if (userPosition) {
            updateGeoUI('active');
            // Restauration depuis sessionStorage : place le marqueur sans forcément
            // ouvrir la carte (l'utilisateur n'a pas re-cliqué sur Ma position).
            // L'appel à setUserPosition est différé pour laisser home-map.js s'init.
            window.addEventListener('load', () => {
                if (window.opaleMap && typeof window.opaleMap.setUserPosition === 'function') {
                    window.opaleMap.setUserPosition(userPosition);
                }
            }, { once: true });
            // La grille initiale rendue par Twig est triée par pertinence sans
            // distance : sans ce refresh, le bouton resterait « actif » mais
            // l'ordre des cartes ignorerait la position restaurée.
            // setTimeout(0) : `runSearch` et `memoryCache` sont déclarés plus bas
            // (const → TDZ), on défère pour que l'IIFE finisse son init d'abord.
            setTimeout(() => {
                activeFilters.page = 1;
                runSearch(searchInput.value.trim(), { append: false });
            }, 0);
        }
    }

    const escapeHtml = (str) =>
        String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

    const pertinenceClass = (pct) => {
        if (pct >= 85) return 'event-card__pertinence--high';
        if (pct >= 65) return 'event-card__pertinence--mid';
        return 'event-card__pertinence--low';
    };

    const formatDistance = (km) => {
        if (typeof km !== 'number') return '';
        if (km < 1) return `${Math.round(km * 1000)} m`;
        if (km < 10) return `${km.toFixed(1).replace('.0', '')} km`;
        return `${Math.round(km)} km`;
    };

    const ICON_PIN = `<svg class="icon-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s-8-7.58-8-13a8 8 0 0 1 16 0c0 5.42-8 13-8 13z"/><circle cx="12" cy="9" r="3"/></svg>`;

    const renderCard = (event, { tier = 'primary' } = {}) => {
        const hasPertinence = typeof event.pertinence === 'number';
        const hasDistance = typeof event.distanceKm === 'number';
        const isFeatured = event.featured === true;

        const pertinenceBadge = hasPertinence
            ? `<span class="event-card__pertinence ${pertinenceClass(event.pertinence)}" title="Pertinence sémantique : ${event.pertinence}%">${event.pertinence}<span class="event-card__pertinence__percent">%</span></span>`
            : '';

        const distanceBadge = hasDistance
            ? `<span class="event-card__distance" title="Distance à vol d'oiseau">${formatDistance(event.distanceKm)}</span>`
            : '';

        const featuredBadge = isFeatured
            ? `<span class="event-card__featured-badge" aria-label="Événement incontournable"><span class="event-card__featured-icon" aria-hidden="true">✦</span>Incontournable</span>`
            : '';

        const tierClass = tier === 'secondary' ? ' event-card--secondary' : '';
        const featuredClass = isFeatured ? ' event-card--featured' : '';
        const detailUrl = event.url
            || (event.slug
                ? `/evenement/${encodeURIComponent(event.id)}/${encodeURIComponent(event.slug)}`
                : `/evenement/${encodeURIComponent(event.id)}/evenement`);

        return `
        <a href="${escapeHtml(detailUrl)}" class="event-card-link">
        <article class="event-card${hasPertinence ? ' event-card--scored' : ''}${tierClass}${featuredClass}" data-event-id="${escapeHtml(event.id)}">
            ${featuredBadge}
            ${pertinenceBadge}
            <div class="event-card__header">
                <span class="event-card__category">${escapeHtml(event.categorie)}</span>
                <span class="event-card__date">${escapeHtml(event.date)}</span>
            </div>
            <h2 class="event-card__title">${escapeHtml(event.titre)}</h2>
            <p class="event-card__ville">
                ${ICON_PIN}<span>${escapeHtml(event.ville)}</span>
                ${distanceBadge}
            </p>
            <p class="event-card__description">${escapeHtml(event.description)}</p>
        </article>
        </a>`;
    };

    const updateCount = (primaryCount, secondaryCount, isSemantic) => {
        if (!countLabel) return;

        if (!isSemantic) {
            countLabel.textContent = `${primaryCount} événement${primaryCount > 1 ? 's' : ''} à découvrir`;
            return;
        }

        if (primaryCount === 0 && secondaryCount === 0) {
            countLabel.textContent = 'Aucun résultat';
            return;
        }

        const parts = [];
        if (primaryCount > 0) {
            parts.push(`${primaryCount} résultat${primaryCount > 1 ? 's' : ''} pertinent${primaryCount > 1 ? 's' : ''}`);
        }
        if (secondaryCount > 0) {
            parts.push(`${secondaryCount} suggestion${secondaryCount > 1 ? 's' : ''}`);
        }
        countLabel.textContent = parts.join(' · ');
    };

    const setLoading = (loading) => {
        if (spinner) spinner.hidden = !loading;
        searchInput.setAttribute('aria-busy', loading ? 'true' : 'false');
    };

    let currentController = null;
    const memoryCache = new Map();

    const renderTemporalChip = (temporal) => {
        const existing = document.getElementById('temporal-chip');
        if (existing) existing.remove();
        if (!temporal) return;

        const chip = document.createElement('div');
        chip.id = 'temporal-chip';
        chip.className = 'toolbar-pill toolbar-pill--date';
        const dateText = temporal.singleDay
            ? temporal.fromHuman
            : `${temporal.fromHuman} → ${temporal.toHuman}`;
        chip.innerHTML = `
            <svg class="toolbar-pill__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span class="toolbar-pill__label">${escapeHtml(temporal.label)}</span>
            <span class="toolbar-pill__separator" aria-hidden="true"></span>
            <span class="toolbar-pill__sub">${escapeHtml(dateText)}</span>
        `;
        const target = document.getElementById('search-toolbar');
        if (target) target.appendChild(chip);
    };

    const updateLoadMoreVisibility = (hasMore) => {
        if (!loadMoreBtn) return;
        loadMoreBtn.hidden = !hasMore;
        loadMoreBtn.disabled = false;
        loadMoreBtn.textContent = "Charger plus d'événements";
    };

    const renderResults = (payload, isSemantic, { append = false } = {}) => {
        const primary = Array.isArray(payload.primary) ? payload.primary : [];
        const secondary = Array.isArray(payload.secondary) ? payload.secondary : [];
        const hasMore = payload.hasMore === true;

        renderTemporalChip(payload.temporal);

        const primaryHtml = primary.map((e) => renderCard(e, { tier: 'primary' })).join('');

        if (append) {
            primaryGrid.insertAdjacentHTML('beforeend', primaryHtml);
        } else {
            primaryGrid.innerHTML = primaryHtml;
            primaryGrid.classList.remove('is-revealed');
            requestAnimationFrame(() => primaryGrid.classList.add('is-revealed'));
        }

        if (!append && secondaryGrid && secondarySection) {
            if (secondary.length > 0) {
                secondaryGrid.innerHTML = secondary.map((e) => renderCard(e, { tier: 'secondary' })).join('');
                secondarySection.hidden = false;
                secondaryGrid.classList.remove('is-revealed');
                requestAnimationFrame(() => secondaryGrid.classList.add('is-revealed'));
            } else {
                secondaryGrid.innerHTML = '';
                secondarySection.hidden = true;
            }
        }

        if (!append && emptyMessage) {
            if (primary.length === 0) {
                emptyMessage.hidden = false;
                emptyMessage.classList.toggle('results-empty--with-suggestions', secondary.length > 0);
                const dateContext = payload.temporal ? ` pour « ${payload.temporal.label} »` : '';
                if (secondary.length === 0) {
                    emptyMessage.innerHTML = `
                        <span class="results-empty__icon" aria-hidden="true">🔍</span>
                        <strong>Aucune correspondance</strong>${dateContext}
                        <span class="results-empty__hint">Essayez d'autres mots-clés ou une autre période.</span>
                    `;
                } else {
                    emptyMessage.innerHTML = `
                        <span class="results-empty__icon" aria-hidden="true">🤔</span>
                        <strong>Aucune correspondance exacte</strong>${dateContext}
                        <span class="results-empty__hint">Mais voici quelques suggestions ci-dessous ↓</span>
                    `;
                }
            } else {
                emptyMessage.hidden = true;
                emptyMessage.classList.remove('results-empty--with-suggestions');
            }
        }

        if (!append) {
            updateCount(primary.length, secondary.length, isSemantic);
        }

        updateLoadMoreVisibility(hasMore);

        if (!append && window.opaleMap) {
            const mapEvents = Array.isArray(payload.map)
                ? payload.map
                : [...primary, ...secondary].filter(
                    (e) => e.latitude != null && e.longitude != null,
                );
            window.opaleMap.setMarkers(mapEvents);
        }
    };

    const buildSearchUrl = (query) => {
        const params = new URLSearchParams();
        params.set('q', query);
        params.set('sort', activeFilters.sort);
        params.set('page', String(activeFilters.page));
        if (activeFilters.period !== 'all') {
            params.set('period', activeFilters.period);
        }
        if (activeFilters.freeOnly) {
            params.set('freeOnly', '1');
        }
        activeFilters.categories.forEach((cat) => {
            params.append('categories[]', cat);
        });
        if (userPosition) {
            params.set('lat', userPosition.lat.toFixed(6));
            params.set('lng', userPosition.lng.toFixed(6));
        }
        return '/api/search?' + params.toString();
    };

    const runSearch = async (query, { append = false } = {}) => {
        if (currentController) {
            currentController.abort();
        }
        currentController = new AbortController();
        const controller = currentController;

        const cacheKey = [
            query,
            activeFilters.sort,
            activeFilters.page,
            activeFilters.period,
            activeFilters.freeOnly ? '1' : '0',
            [...activeFilters.categories].sort().join(','),
            userPosition ? `${userPosition.lat},${userPosition.lng}` : '',
        ].join('|');

        if (memoryCache.has(cacheKey)) {
            renderResults(memoryCache.get(cacheKey), query !== '', { append });
            setLoading(false);
            return;
        }

        setLoading(true);
        if (append && loadMoreBtn) {
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = 'Chargement…';
        }

        try {
            const response = await fetch(buildSearchUrl(query), {
                headers: { 'Accept': 'application/json' },
                signal: controller.signal,
            });

            if (controller.signal.aborted) return;

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            if (data && (Array.isArray(data.primary) || Array.isArray(data.secondary))) {
                memoryCache.set(cacheKey, data);
                if (memoryCache.size > 50) {
                    const firstKey = memoryCache.keys().next().value;
                    memoryCache.delete(firstKey);
                }
                renderResults(data, query !== '', { append });
            } else if (data && data.error) {
                if (!append) {
                    primaryGrid.innerHTML = '';
                    if (secondaryGrid) secondaryGrid.innerHTML = '';
                    if (secondarySection) secondarySection.hidden = true;
                    if (emptyMessage) {
                        emptyMessage.hidden = false;
                        emptyMessage.textContent = 'Erreur de recherche : ' + data.error;
                    }
                }
                updateLoadMoreVisibility(false);
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (!append) {
                primaryGrid.innerHTML = '';
                if (secondaryGrid) secondaryGrid.innerHTML = '';
                if (secondarySection) secondarySection.hidden = true;
                if (emptyMessage) {
                    emptyMessage.hidden = false;
                    emptyMessage.textContent = 'Impossible de joindre le serveur de recherche.';
                }
            }
            updateLoadMoreVisibility(false);
        } finally {
            if (currentController === controller) {
                setLoading(false);
            }
            if (append && loadMoreBtn) {
                loadMoreBtn.disabled = false;
                loadMoreBtn.textContent = "Charger plus d'événements";
            }
        }
    };

    const resetAndSearch = () => {
        activeFilters.page = 1;
        runSearch(searchInput.value.trim(), { append: false });
    };

    if (sortToggle) {
        sortToggle.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-sort]');
            if (!btn) return;
            const nextSort = btn.dataset.sort === 'date' ? 'date' : 'relevance';
            userPreferredSort = nextSort;

            // Si la géoloc est active, le tri reste « distance » mais on mémorise
            // la préférence pour la restaurer dès que la géoloc est désactivée.
            const effectiveSort = userPosition ? 'distance' : nextSort;
            if (effectiveSort === activeFilters.sort) return;

            activeFilters.sort = effectiveSort;
            setSortToggleVisualState(activeFilters.sort);
            resetAndSearch();
        });
    }

    if (facetChips) {
        facetChips.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-facet]');
            if (!btn) return;

            const facet = btn.dataset.facet;
            const value = btn.dataset.value;

            if (facet === 'category') {
                if (activeFilters.categories.has(value)) {
                    activeFilters.categories.delete(value);
                    btn.classList.remove('is-active');
                    btn.setAttribute('aria-pressed', 'false');
                } else {
                    activeFilters.categories.add(value);
                    btn.classList.add('is-active');
                    btn.setAttribute('aria-pressed', 'true');
                }
                updateFiltersBadge();
            } else if (facet === 'period') {
                const nextPeriod = activeFilters.period === value ? 'all' : value;
                activeFilters.period = nextPeriod;
                facetChips.querySelectorAll('[data-facet="period"]').forEach((el) => {
                    const isActive = el.dataset.value === nextPeriod;
                    el.classList.toggle('is-active', isActive);
                    el.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            } else if (facet === 'freeOnly') {
                activeFilters.freeOnly = !activeFilters.freeOnly;
                btn.classList.toggle('is-active', activeFilters.freeOnly);
                btn.setAttribute('aria-pressed', activeFilters.freeOnly ? 'true' : 'false');
            }

            resetAndSearch();
        });
    }

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => {
            activeFilters.page += 1;
            runSearch(searchInput.value.trim(), { append: true });
        });
    }

    /* ===================== Saisie manuelle d'adresse =====================
       Popover déclenché par #geo-manual-toggle, autocomplete sur /api/geocode
       (proxy Google Geocoding côté serveur, clé jamais exposée). Sur sélection,
       on met à jour `userPosition`, on déplace le marqueur carte, et on relance
       la recherche pour ramener les events à proximité de la nouvelle position. */

    const GEO_MANUAL_DEBOUNCE_MS = 280;
    const GEO_MANUAL_MIN_LENGTH = 3;
    let geoManualDebounceId = null;
    let geoManualController = null;
    let geoManualActiveIndex = -1;

    function setGeoManualOpen(open) {
        if (!geoManualToggle || !geoManualPanel) return;
        geoManualToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        geoManualPanel.hidden = !open;
        if (geoManual) {
            geoManual.classList.toggle('is-open', open);
        }
        if (open && geoManualInput) {
            geoManualInput.focus();
            geoManualInput.select();
        }
    }

    function clearGeoManualSuggestions() {
        if (!geoManualSuggestions) return;
        geoManualSuggestions.innerHTML = '';
        geoManualSuggestions.hidden = true;
        geoManualActiveIndex = -1;
    }

    function setGeoManualHint(text) {
        if (geoManualHint) {
            geoManualHint.textContent = text;
            geoManualHint.hidden = !text;
        }
    }

    function renderGeoManualSuggestions(suggestions) {
        if (!geoManualSuggestions) return;
        if (!Array.isArray(suggestions) || suggestions.length === 0) {
            clearGeoManualSuggestions();
            setGeoManualHint('Aucune adresse trouvée — précisez votre recherche.');
            return;
        }

        geoManualSuggestions.innerHTML = suggestions.map((s, idx) => `
            <li role="option" class="geo-manual__suggestion" data-index="${idx}" data-lat="${s.lat}" data-lng="${s.lng}" tabindex="-1">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s-8-7.58-8-13a8 8 0 0 1 16 0c0 5.42-8 13-8 13z"/>
                    <circle cx="12" cy="9" r="3"/>
                </svg>
                <span>${escapeHtml(s.label)}</span>
            </li>
        `).join('');
        geoManualSuggestions.hidden = false;
        geoManualActiveIndex = -1;
        setGeoManualHint('');
    }

    function selectGeoManualSuggestion(lat, lng, label) {
        const pos = { lat: Number(lat), lng: Number(lng), timestamp: Date.now() };
        if (Number.isNaN(pos.lat) || Number.isNaN(pos.lng)) return;

        savePosition(pos);
        updateGeoUI('active', `Position définie : ${label}`);
        setMapOpen(true);
        if (window.opaleMap && typeof window.opaleMap.setUserPosition === 'function') {
            window.opaleMap.setUserPosition(pos);
        }
        setGeoManualOpen(false);
        clearGeoManualSuggestions();
        memoryCache.clear();
        activeFilters.page = 1;
        runSearch(searchInput.value.trim(), { append: false });
    }

    async function fetchGeoManualSuggestions(query) {
        if (geoManualController) geoManualController.abort();
        geoManualController = new AbortController();
        const controller = geoManualController;

        if (geoManualSpinner) geoManualSpinner.hidden = false;

        try {
            const response = await fetch('/api/geocode?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json' },
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            renderGeoManualSuggestions(data.suggestions || []);
        } catch (err) {
            if (err.name !== 'AbortError') {
                clearGeoManualSuggestions();
                setGeoManualHint('Erreur lors de la recherche d\'adresse.');
            }
        } finally {
            if (geoManualController === controller && geoManualSpinner) {
                geoManualSpinner.hidden = true;
            }
        }
    }

    if (geoManualToggle) {
        geoManualToggle.addEventListener('click', () => {
            const open = geoManualToggle.getAttribute('aria-expanded') === 'true';
            setGeoManualOpen(!open);
        });
    }

    if (geoManualInput) {
        geoManualInput.addEventListener('input', (event) => {
            const value = event.target.value.trim();
            if (geoManualDebounceId) clearTimeout(geoManualDebounceId);

            if (value.length < GEO_MANUAL_MIN_LENGTH) {
                clearGeoManualSuggestions();
                setGeoManualHint('Tapez au moins ' + GEO_MANUAL_MIN_LENGTH + ' caractères.');
                return;
            }

            geoManualDebounceId = setTimeout(() => fetchGeoManualSuggestions(value), GEO_MANUAL_DEBOUNCE_MS);
        });

        geoManualInput.addEventListener('keydown', (event) => {
            if (!geoManualSuggestions || geoManualSuggestions.hidden) return;
            const items = geoManualSuggestions.querySelectorAll('.geo-manual__suggestion');
            if (items.length === 0) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                geoManualActiveIndex = (geoManualActiveIndex + 1) % items.length;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                geoManualActiveIndex = (geoManualActiveIndex - 1 + items.length) % items.length;
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const target = geoManualActiveIndex >= 0 ? items[geoManualActiveIndex] : items[0];
                if (target) {
                    selectGeoManualSuggestion(target.dataset.lat, target.dataset.lng, target.textContent.trim());
                }
                return;
            } else if (event.key === 'Escape') {
                setGeoManualOpen(false);
                return;
            } else {
                return;
            }

            items.forEach((el, idx) => {
                el.classList.toggle('is-active', idx === geoManualActiveIndex);
            });
            if (geoManualActiveIndex >= 0) {
                items[geoManualActiveIndex].scrollIntoView({ block: 'nearest' });
            }
        });
    }

    if (geoManualSuggestions) {
        geoManualSuggestions.addEventListener('click', (event) => {
            const item = event.target.closest('.geo-manual__suggestion');
            if (!item) return;
            selectGeoManualSuggestion(item.dataset.lat, item.dataset.lng, item.textContent.trim());
        });
    }

    // Fermer le popover si on clique en dehors.
    document.addEventListener('click', (event) => {
        if (!geoManual || geoManual.classList.contains('is-open') === false) return;
        if (!geoManual.contains(event.target)) {
            setGeoManualOpen(false);
        }
    });

    let debounceId;
    searchInput.addEventListener('input', (event) => {
        clearTimeout(debounceId);
        const query = event.target.value.trim();

        if (query.length > 0 && query.length < MIN_QUERY_LENGTH) {
            return;
        }

        debounceId = setTimeout(() => {
            activeFilters.page = 1;
            runSearch(query, { append: false });
        }, DEBOUNCE_MS);
    });

    searchInput.addEventListener('search', (event) => {
        clearTimeout(debounceId);
        activeFilters.page = 1;
        runSearch(event.target.value.trim(), { append: false });
    });
})();
