// SmartCart — Google Maps utilities
// This file is loaded alongside the Maps SDK and provides shared mapping functions.
// Individual pages implement their own initMap() callbacks.

/**
 * Creates a styled SmartCart marker.
 */
function createMarker(map, lat, lng, title, infoHtml) {
    const marker = new google.maps.Marker({
        position: { lat: parseFloat(lat), lng: parseFloat(lng) },
        map,
        title,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#0D9488',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2,
        },
    });

    if (infoHtml) {
        const info = new google.maps.InfoWindow({ content: infoHtml });
        marker.addListener('click', () => info.open(map, marker));
    }
    return marker;
}

/**
 * Fit map bounds to all markers.
 */
function fitBounds(map, markers) {
    if (!markers.length) return;
    const bounds = new google.maps.LatLngBounds();
    markers.forEach(m => bounds.extend(m.getPosition()));
    map.fitBounds(bounds);
    if (markers.length === 1) map.setZoom(13);
}

/**
 * Default Israeli map center (Gush Dan / Tel Aviv area)
 */
const ISRAEL_CENTER = { lat: 32.0853, lng: 34.7818 };

window.SmartCartMaps = { createMarker, fitBounds, ISRAEL_CENTER };
