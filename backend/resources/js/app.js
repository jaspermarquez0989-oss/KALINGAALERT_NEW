import bongabongBoundary from './data/bongabong-boundary.json';

function makePopup(area) {
    const content = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = area.name;
    const location = document.createElement('div');
    location.textContent = [area.sitio, area.barangay].filter(Boolean).join(', ');
    const classification = document.createElement('div');
    classification.textContent = `Risk area · ${area.classification}`;
    content.append(title, location, classification);

    if (area.alerts?.length) {
        area.alerts.forEach((alert) => {
            const details = document.createElement('section');
            details.className = 'map-alert-details';

            const alertTitle = document.createElement('strong');
            const alertMessage = document.createElement('p');
            const instructions = document.createElement('p');

            if (typeof alert === 'string') {
                alertTitle.textContent = `Active alert: ${alert}`;
                details.append(alertTitle);
            } else {
                alertTitle.textContent = `${alert.title} · ${alert.severity}`;
                alertMessage.textContent = alert.message;
                instructions.textContent = `Safety instructions: ${alert.safety_instructions}`;
                details.append(alertTitle, alertMessage, instructions);
            }

            content.append(details);
        });
    }

    return content;
}

function createMap(element, editor = false) {
    if (!window.L) {
        element.textContent = 'The map library did not load. Check your internet connection and reload.';
        return;
    }

    const municipality = window.L.geoJSON(bongabongBoundary);
    const municipalityBounds = municipality.getBounds();
    const municipalityViewportBounds = municipalityBounds.pad(0.3);
    const map = window.L.map(element, {
        scrollWheelZoom: false,
        maxBounds: municipalityBounds.pad(0.45),
        maxBoundsViscosity: 1,
        maxZoom: 16,
    });
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors · Municipality boundary: geoBoundaries (NAMRIA/PSA/OCHA), CC BY 3.0 IGO',
    }).addTo(map);
    const minimumZoom = map.getBoundsZoom(municipalityViewportBounds, false, [18, 18]);
    map.setMinZoom(minimumZoom);
    map.fitBounds(municipalityViewportBounds, { padding: [18, 18], maxZoom: minimumZoom });

    const municipalityRing = bongabongBoundary.geometry.coordinates[0]
        .map(([longitude, latitude]) => [latitude, longitude]);
    window.L.polygon([
        [[85, -180], [85, 180], [-85, 180], [-85, -180]],
        municipalityRing,
    ], {
        color: '#f6f7f3',
        fillColor: '#f6f7f3',
        fillOpacity: 0.78,
        fillRule: 'evenodd',
        interactive: false,
        stroke: false,
    }).addTo(map);
    municipality.setStyle({
        color: '#1d5b45',
        fillOpacity: 0,
        interactive: false,
        weight: 2,
    }).addTo(map);

    const boundaryField = document.querySelector('#boundary');
    if (editor) {
        let points = [];
        let drawing = false;
        let preview = null;
        let existing = null;
        const pointMarkers = [];
        const controls = document.querySelector('#boundary-controls');
        const drawButton = document.querySelector('#start-drawing');
        const finishButton = document.querySelector('#finish-drawing');

        const loadExisting = () => {
            if (existing) {
                map.removeLayer(existing);
                existing = null;
            }

            if (!boundaryField.value) {
                return;
            }

            try {
                const polygon = JSON.parse(boundaryField.value);
                const ring = polygon.coordinates?.[0] ?? [];
                if (polygon.type === 'Polygon' && ring.length >= 4) {
                    existing = window.L.polygon(ring.map(([longitude, latitude]) => [latitude, longitude]), {
                        color: '#477958',
                        fillOpacity: 0.22,
                    }).addTo(map);
                    map.fitBounds(existing.getBounds().pad(0.2), { padding: [25, 25] });
                }
            } catch {
                const error = document.createElement('p');
                error.className = 'notice notice-error';
                error.setAttribute('role', 'alert');
                error.textContent = 'The saved boundary could not be read. Draw a new boundary before saving.';
                controls?.after(error);
            }
        };

        loadExisting();
        controls?.addEventListener('click', (event) => {
            if (event.target === drawButton) {
                drawing = true;
                map.getContainer().classList.add('is-drawing');
                points = [];
                if (preview) {
                    map.removeLayer(preview);
                    preview = null;
                }
                pointMarkers.forEach((marker) => map.removeLayer(marker));
                pointMarkers.length = 0;
                if (existing) {
                    map.removeLayer(existing);
                    existing = null;
                }
                boundaryField.value = '';
                drawButton.disabled = true;
                finishButton.disabled = true;
            }

            if (event.target === finishButton && points.length >= 3) {
                points.push(points[0]);
                drawing = false;
                map.getContainer().classList.remove('is-drawing');
                if (preview) {
                    map.removeLayer(preview);
                }
                existing = window.L.polygon(points, { color: '#477958', fillOpacity: 0.22 }).addTo(map);
                boundaryField.value = JSON.stringify({
                    type: 'Polygon',
                    coordinates: [points.map(([latitude, longitude]) => [longitude, latitude])],
                });
                drawButton.disabled = false;
                finishButton.disabled = true;
            }
        });

        map.on('click', (event) => {
            if (!drawing) {
                return;
            }

            points.push([event.latlng.lat, event.latlng.lng]);
            finishButton.disabled = points.length < 3;
            pointMarkers.push(window.L.circleMarker(event.latlng, { radius: 4, color: '#477958', fillOpacity: 1 }).addTo(map));
            if (preview) {
                map.removeLayer(preview);
            }
            preview = window.L.polyline(points, { color: '#477958', dashArray: '5 5' }).addTo(map);
        });
        return;
    }

    const dataElement = document.querySelector('#risk-map-data');
    if (!dataElement) {
        return;
    }

    const areas = JSON.parse(dataElement.textContent || '[]');
    const polygons = [];
    areas.forEach((area) => {
        const ring = area.boundary?.coordinates?.[0] ?? [];
        if (area.boundary?.type !== 'Polygon' || ring.length < 4) {
            return;
        }

        const polygon = window.L.polygon(
            ring.map(([longitude, latitude]) => [latitude, longitude]),
            { color: area.alerts?.length ? '#c66a44' : '#477958', fillOpacity: area.alerts?.length ? 0.3 : 0.18 },
        ).addTo(map);
        polygon.bindPopup(makePopup(area));
        if (area.alerts?.length) {
            polygon.on('mouseover', () => polygon.openPopup());
            polygon.on('mouseout', () => polygon.closePopup());
        }
        polygons.push(polygon);
    });

    if (polygons.length) {
        const group = window.L.featureGroup(polygons);
        map.fitBounds(group.getBounds().pad(0.2), { padding: [25, 25], maxZoom: 14 });
    }
}

document.querySelectorAll('[data-risk-map]').forEach((element) => {
    createMap(element, element.dataset.mapMode === 'editor');
});

const alertCountElement = document.querySelector('[data-alert-status-url]');
const notificationStack = document.querySelector('[data-resident-alert-notifications]');
const alertSyncStatus = document.querySelector('[data-resident-alert-sync-status]');

if (alertCountElement && notificationStack && alertSyncStatus) {
    const seenAlertIds = new Set();
    let hasLoadedInitialStatus = false;

    const showNewAlert = (alert) => {
        const notification = document.createElement('article');
        notification.className = 'resident-alert-notification';
        notification.setAttribute('role', 'status');

        const heading = document.createElement('strong');
        heading.textContent = 'New safety alert';

        const link = document.createElement('a');
        link.href = alert.url;
        link.textContent = alert.title;

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.textContent = 'Dismiss';
        dismiss.setAttribute('aria-label', `Dismiss notification: ${alert.title}`);
        dismiss.addEventListener('click', () => notification.remove());

        notification.append(heading, link, dismiss);
        notificationStack.prepend(notification);
    };

    const refreshAlertStatus = async () => {
        const response = await fetch(alertCountElement.dataset.alertStatusUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Alert status request failed with HTTP ${response.status}.`);
        }

        const status = await response.json();

        if (!Number.isInteger(status.active_alert_count)
            || !Number.isInteger(status.unread_count)
            || !Array.isArray(status.alerts)) {
            throw new Error('Alert status response has an invalid format.');
        }

        document.querySelectorAll('[data-active-alert-count]').forEach((element) => {
            element.textContent = status.active_alert_count;
        });
        document.querySelectorAll('[data-alert-unread-count]').forEach((element) => {
            element.textContent = status.unread_count;
        });

        status.alerts.forEach((alert) => {
            if (!Number.isInteger(alert.id) || typeof alert.title !== 'string' || typeof alert.url !== 'string') {
                throw new Error('Alert notification has an invalid format.');
            }

            if (hasLoadedInitialStatus && !seenAlertIds.has(alert.id)) {
                showNewAlert(alert);
            }

            seenAlertIds.add(alert.id);
        });

        hasLoadedInitialStatus = true;
        alertSyncStatus.hidden = true;
    };

    const pollForAlerts = async () => {
        try {
            await refreshAlertStatus();
        } catch (error) {
            alertSyncStatus.textContent = 'Alert updates are temporarily unavailable. The page will retry shortly.';
            alertSyncStatus.hidden = false;
            console.error('Unable to refresh resident alert status.', error);
        }
    };

    void pollForAlerts();
    window.setInterval(pollForAlerts, 30_000);
}
