// SmartCart — Maps & Autocomplete Module
// Uses: Leaflet.js (maps) + Photon/Komoot (autocomplete) + Nominatim (geocoding)
// All free, no API key required.

'use strict';

window.SmartCartMaps = window.SmartCartMaps || {};

// ─────────────────────────────────────────────────────────
// Leaflet Map Helpers
// ─────────────────────────────────────────────────────────

/**
 * Create a Leaflet map centered on given coordinates.
 * @param {string} elementId
 * @param {number} lat
 * @param {number} lng
 * @param {number} zoom  — default 13
 * @returns {L.Map|null}
 */
SmartCartMaps.createMap = function(elementId, lat, lng, zoom) {
    zoom = zoom || 13;
    var el = document.getElementById(elementId);
    if (!el) return null;

    // scrollWheelZoom starts disabled so users can scroll the PAGE through the map
    // without accidentally zooming. It activates on hover (after a short delay)
    // and deactivates when the mouse leaves — matches user request.
    var map = L.map(elementId, { scrollWheelZoom: false }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    var hoverTimer = null;
    el.addEventListener('mouseenter', function() {
        // ~250ms delay prevents accidental activation when cursor only passes through
        hoverTimer = setTimeout(function() { map.scrollWheelZoom.enable(); }, 250);
    });
    el.addEventListener('mouseleave', function() {
        clearTimeout(hoverTimer);
        map.scrollWheelZoom.disable();
    });

    return map;
};

/**
 * Add a styled circle marker with optional popup.
 * @param {L.Map}  map
 * @param {number} lat
 * @param {number} lng
 * @param {string} popupHtml
 * @param {string} color  — fill color (default teal)
 * @returns {L.CircleMarker}
 */
SmartCartMaps.addMarker = function(map, lat, lng, popupHtml, color) {
    color = color || '#0D9488';
    var marker = L.circleMarker([lat, lng], {
        radius:      10,
        fillColor:   color,
        fillOpacity: 1,
        color:       '#fff',
        weight:      2,
    }).addTo(map);

    if (popupHtml) {
        marker.bindPopup(popupHtml, { maxWidth: 260 });
        marker.on('click', function() { marker.openPopup(); });
    }
    return marker;
};

/**
 * Fit the map view to a list of markers.
 * @param {L.Map}            map
 * @param {L.CircleMarker[]} markers
 */
SmartCartMaps.fitBounds = function(map, markers) {
    if (!markers || !markers.length) return;
    var group = new L.featureGroup(markers);
    map.fitBounds(group.getBounds().pad(0.2));
    if (markers.length === 1) map.setZoom(14);
};

/** Default center — Gush Dan */
SmartCartMaps.ISRAEL_CENTER = { lat: 32.0853, lng: 34.7818 };

/**
 * Add the user's location to the map as a blue pulsing marker.
 * @param {L.Map}  map
 * @param {number} lat
 * @param {number} lng
 * @returns {L.CircleMarker}
 */
SmartCartMaps.addMyLocationMarker = function(map, lat, lng) {
    var marker = L.circleMarker([lat, lng], {
        radius:      9,
        fillColor:   '#2563eb',
        fillOpacity: 1,
        color:       '#fff',
        weight:      3,
    }).addTo(map);
    marker.bindPopup('<strong>You are here</strong>', { maxWidth: 200 });
    // Also draw a faint accuracy ring
    L.circle([lat, lng], {
        radius:      400,
        fillColor:   '#2563eb',
        fillOpacity: 0.08,
        color:       '#2563eb',
        weight:      1,
        opacity:     0.4,
    }).addTo(map);
    return marker;
};

/**
 * Promise-based current location via browser geolocation API.
 * Resolves with { lat, lng, accuracy } or rejects with Error.
 * Pass { timeout, maximumAge } to override defaults.
 */
SmartCartMaps.getCurrentLocation = function(opts) {
    opts = Object.assign({ enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }, opts || {});
    return new Promise(function(resolve, reject) {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported by this browser'));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                resolve({
                    lat:      pos.coords.latitude,
                    lng:      pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                });
            },
            function(err) {
                var msgs = {
                    1: 'Location permission denied',
                    2: 'Location unavailable',
                    3: 'Location request timed out',
                };
                reject(new Error(msgs[err.code] || err.message || 'Location error'));
            },
            opts
        );
    });
};

/**
 * Haversine distance between two coords (km).
 */
SmartCartMaps.distanceKm = function(lat1, lng1, lat2, lng2) {
    var R = 6371;
    var toRad = function(d) { return d * Math.PI / 180; };
    var dLat = toRad(lat2 - lat1);
    var dLng = toRad(lng2 - lng1);
    var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng/2) * Math.sin(dLng/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
};

// ─────────────────────────────────────────────────────────
// Photon Autocomplete  (Israel bbox, 300 ms debounce)
// ─────────────────────────────────────────────────────────
var PHOTON_URL   = 'https://photon.komoot.io/api/';
var ISRAEL_BBOX  = 'bbox=34.2,29.4,35.9,33.4';

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Attach a city autocomplete dropdown to a text input.
 * Keys: ArrowUp/Down to navigate, Enter to select, Escape to close.
 *
 * @param {string}      inputId    — ID of the city text input
 * @param {string|null} latInputId — ID of hidden lat input (null = text-only mode)
 * @param {string|null} lngInputId — ID of hidden lng input (null = text-only mode)
 */
SmartCartMaps.initCityAutocomplete = function(inputId, latInputId, lngInputId) {
    var input = document.getElementById(inputId);
    var latIn = latInputId ? document.getElementById(latInputId) : null;
    var lngIn = lngInputId ? document.getElementById(lngInputId) : null;
    if (!input) return;

    // Wrapper must be relatively positioned for the dropdown
    var wrapper = input.parentElement;
    if (window.getComputedStyle(wrapper).position === 'static') {
        wrapper.style.position = 'relative';
    }

    // Build dropdown element
    var dropdown = document.createElement('div');
    dropdown.className = 'city-autocomplete-dropdown';
    dropdown.style.cssText = [
        'position:absolute',
        'z-index:9999',
        'background:#fff',
        'border:1px solid #e5e7eb',
        'border-radius:8px',
        'box-shadow:0 4px 16px rgba(0,0,0,.12)',
        'width:100%',
        'max-height:220px',
        'overflow-y:auto',
        'display:none',
        'top:calc(100% + 4px)',
        'left:0',
    ].join(';');
    wrapper.appendChild(dropdown);

    var debounceTimer = null;
    var lastQuery     = '';

    function fetchSuggestions(q) {
        if (q === lastQuery) return;
        lastQuery = q;
        fetch(PHOTON_URL + '?q=' + encodeURIComponent(q) + '&limit=6&lang=en&' + ISRAEL_BBOX)
            .then(function(r) { return r.json(); })
            .then(function(data) { renderDropdown(data.features || []); })
            .catch(function() { hideDropdown(); });
    }

    function renderDropdown(features) {
        dropdown.innerHTML = '';
        if (!features.length) { hideDropdown(); return; }

        features.forEach(function(f) {
            var props = f.properties || {};
            var city  = props.name || props.city || props.town || props.village || '';
            var state = props.state || props.county || '';
            if (!city) return;

            var lat = f.geometry ? f.geometry.coordinates[1] : null;
            var lng = f.geometry ? f.geometry.coordinates[0] : null;

            var item = document.createElement('div');
            item.style.cssText = [
                'padding:10px 14px',
                'cursor:pointer',
                'font-size:13px',
                'border-bottom:1px solid #f3f4f6',
                'transition:background .1s',
            ].join(';');
            item.innerHTML =
                '<span style="font-weight:600">' + escHtml(city) + '</span>' +
                (state ? '<span style="color:#6b7280;font-size:12px"> — ' + escHtml(state) + '</span>' : '');

            item.addEventListener('mouseenter', function() {
                clearActive();
                this.setAttribute('data-active', '1');
                this.style.background = '#f9fafb';
            });
            item.addEventListener('mouseleave', function() {
                this.style.background = '';
            });
            // mousedown fires before blur — keeps dropdown open
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectItem(city + (state ? ', ' + state : ''), lat, lng);
            });

            dropdown.appendChild(item);
        });

        dropdown.style.display = 'block';
    }

    function selectItem(label, lat, lng) {
        input.value = label;
        if (latIn !== null && lat !== null) latIn.value = lat;
        if (lngIn !== null && lng !== null) lngIn.value = lng;
        hideDropdown();
        input.dispatchEvent(new Event('change'));
    }

    function hideDropdown() {
        dropdown.style.display = 'none';
        lastQuery = '';
    }

    function clearActive() {
        dropdown.querySelectorAll('[data-active]').forEach(function(el) {
            el.removeAttribute('data-active');
            el.style.background = '';
        });
    }

    // ── Event listeners ──────────────────────────────────
    input.addEventListener('input', function() {
        var q = this.value.trim();
        if (latIn) latIn.value = '';
        if (lngIn) lngIn.value = '';

        clearTimeout(debounceTimer);
        if (q.length < 2) { hideDropdown(); return; }
        debounceTimer = setTimeout(function() { fetchSuggestions(q); }, 300);
    });

    input.addEventListener('blur', function() {
        setTimeout(hideDropdown, 200);
    });

    input.addEventListener('keydown', function(e) {
        var items  = Array.prototype.slice.call(dropdown.querySelectorAll('div'));
        var active = dropdown.querySelector('[data-active]');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!active) {
                items[0].setAttribute('data-active', '1');
                items[0].style.background = '#f9fafb';
            } else {
                var idx = items.indexOf(active);
                clearActive();
                var next = items[idx + 1] || items[0];
                next.setAttribute('data-active', '1');
                next.style.background = '#f9fafb';
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) {
                var idx2 = items.indexOf(active);
                clearActive();
                var prev = items[idx2 - 1] || items[items.length - 1];
                prev.setAttribute('data-active', '1');
                prev.style.background = '#f9fafb';
            }
        } else if (e.key === 'Enter') {
            if (active) {
                e.preventDefault();
                active.dispatchEvent(new MouseEvent('mousedown'));
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });
};

/**
 * Attach a full-address autocomplete (street + city) to a text input.
 * Same interface as initCityAutocomplete but returns street-level matches.
 */
SmartCartMaps.initAddressAutocomplete = function(inputId, latInputId, lngInputId) {
    var input = document.getElementById(inputId);
    var latIn = latInputId ? document.getElementById(latInputId) : null;
    var lngIn = lngInputId ? document.getElementById(lngInputId) : null;
    if (!input) return;

    var wrapper = input.parentElement;
    if (window.getComputedStyle(wrapper).position === 'static') wrapper.style.position = 'relative';

    var dropdown = document.createElement('div');
    dropdown.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);width:100%;max-height:260px;overflow-y:auto;display:none;top:calc(100% + 4px);left:0';
    wrapper.appendChild(dropdown);

    var debounceTimer = null;
    var lastQuery = '';

    function hide() { dropdown.style.display = 'none'; lastQuery = ''; }

    function fetchSuggestions(q) {
        if (q === lastQuery) return;
        lastQuery = q;
        // Use Nominatim directly for street-level addresses (Photon doesn't index Israel street-level well)
        fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(q + ', Israel') +
              '&format=json&limit=6&addressdetails=1&countrycodes=il', {
            headers: { 'Accept': 'application/json' },
        })
            .then(function(r) { return r.json(); })
            .then(function(data) { render(data || []); })
            .catch(hide);
    }

    function render(items) {
        dropdown.innerHTML = '';
        if (!items.length) { hide(); return; }
        items.forEach(function(it) {
            var div = document.createElement('div');
            div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f4f6';
            var primary  = it.display_name.split(',').slice(0, 3).join(',');
            var secondary = it.display_name.split(',').slice(3).join(',').trim();
            div.innerHTML =
                '<div style="font-weight:600;color:#111827">' + escHtml(primary) + '</div>' +
                (secondary ? '<div style="color:#6b7280;font-size:11px;margin-top:2px">' + escHtml(secondary) + '</div>' : '');
            div.addEventListener('mouseenter', function() { this.style.background = '#f9fafb'; });
            div.addEventListener('mouseleave', function() { this.style.background = ''; });
            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = primary;
                if (latIn) latIn.value = it.lat;
                if (lngIn) lngIn.value = it.lon;
                hide();
                input.dispatchEvent(new Event('change'));
            });
            dropdown.appendChild(div);
        });
        dropdown.style.display = 'block';
    }

    input.addEventListener('input', function() {
        var q = this.value.trim();
        if (latIn) latIn.value = '';
        if (lngIn) lngIn.value = '';
        clearTimeout(debounceTimer);
        if (q.length < 3) { hide(); return; }
        debounceTimer = setTimeout(function() { fetchSuggestions(q); }, 350);
    });
    input.addEventListener('blur', function() { setTimeout(hide, 200); });
};
