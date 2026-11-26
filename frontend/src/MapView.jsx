import { useEffect, useRef, useState } from 'react';
import { formatCoordinatePair } from './coordinates.js';
import { heatPaletteForScore } from './intensity.js';

const emptyAreas = [];

function popupContent(area) {
    const root = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = area.name;
    const location = document.createElement('div');
    location.textContent = [area.sitio, area.barangay].filter(Boolean).join(', ');
    const classification = document.createElement('div');
    classification.textContent = `Risk area · ${area.classification}`;
    root.append(title, location, classification);

    if (area.latitude !== null && area.latitude !== undefined
        && area.longitude !== null && area.longitude !== undefined
        && Number.isFinite(Number(area.latitude)) && Number.isFinite(Number(area.longitude))) {
        const coordinates = document.createElement('div');
        coordinates.textContent = `Center: ${formatCoordinatePair(area.latitude, area.longitude)}`;
        root.append(coordinates);
    }

    for (const alert of area.alerts ?? []) {
        const detail = document.createElement('section');
        detail.className = 'map-alert-details';
        const heading = document.createElement('strong');
        const message = document.createElement('p');
        message.className = 'map-alert-message';
        heading.textContent = `${alert.title} · ${alert.severity}`;
        message.textContent = alert.message;
        detail.append(heading, message);

        if (alert.intensity !== null && alert.intensity !== undefined) {
            const intensity = document.createElement('div');
            intensity.textContent = `Advisory level: ${alert.intensity}/5`;
            detail.append(intensity);
        }

        if (alert.safety_instructions) {
            const instructions = document.createElement('div');
            const instructionsHeading = document.createElement('strong');
            const instructionsText = document.createElement('div');
            const instructionText = alert.safety_instructions.trim();
            const instructionSteps = instructionText.split(/(?=\s+\d+\.\s+)/).map((step) => step.trim()).filter(Boolean);

            instructions.className = 'map-alert-instructions';
            instructionsHeading.textContent = 'Safety instructions';
            instructions.append(instructionsHeading);

            if (/^\d+\.\s/.test(instructionText) && instructionSteps.length > 1) {
                const list = document.createElement('ol');
                instructionSteps.forEach((step) => {
                    const item = document.createElement('li');
                    item.textContent = step.replace(/^\d+\.\s*/, '');
                    list.append(item);
                });
                instructionsText.append(list);
            } else {
                instructionsText.textContent = instructionText;
            }

            instructions.append(instructionsText);
            detail.append(instructions);
        }

        root.append(detail);
    }

    return root;
}

const severityScores = { advisory: 0.3, watch: 0.55, warning: 0.78, emergency: 1 };

function heatForArea(area) {
    return Math.max(0.2, ...(area.alerts ?? []).map((alert) => {
        const intensity = Number(alert.intensity);
        if (Number.isInteger(intensity) && intensity >= 1 && intensity <= 5) {
            return intensity / 5;
        }

        return severityScores[alert.severity] ?? 0.2;
    }));
}

function boundaryCenter(ring) {
    if (!ring.length) return null;
    const longitudes = ring.map(([longitude]) => longitude);
    const latitudes = ring.map(([, latitude]) => latitude);
    return [
        (Math.min(...latitudes) + Math.max(...latitudes)) / 2,
        (Math.min(...longitudes) + Math.max(...longitudes)) / 2,
    ];
}

function areaCenter(area, ring) {
    if (area.latitude !== null && area.latitude !== undefined
        && area.longitude !== null && area.longitude !== undefined
        && Number.isFinite(Number(area.latitude)) && Number.isFinite(Number(area.longitude))) {
        return [Number(area.latitude), Number(area.longitude)];
    }

    return boundaryCenter(ring);
}

export default function MapView({
    areas = emptyAreas,
    boundary,
    editable = false,
    focusLocation,
    latitude,
    longitude,
    onBoundaryChange,
    onLocationChange,
}) {
    const elementRef = useRef(null);
    const boundaryCallback = useRef(onBoundaryChange);
    const locationCallback = useRef(onLocationChange);
    const locationRef = useRef({ latitude, longitude });
    const initialBoundary = useRef(boundary);
    const [drawing, setDrawing] = useState(false);
    const [pointCount, setPointCount] = useState(0);
    const [mapError, setMapError] = useState('');
    boundaryCallback.current = onBoundaryChange;
    locationCallback.current = onLocationChange;
    locationRef.current = { latitude, longitude };

    useEffect(() => {
        elementRef.current?.__setCenterLocation?.(latitude, longitude);
    }, [latitude, longitude]);

    useEffect(() => {
        const element = elementRef.current;

        if (!element || !window.L) {
            setMapError('Map tiles are unavailable. Check your internet connection and reload.');
            return undefined;
        }

        const L = window.L;
        const map = L.map(element, {
            scrollWheelZoom: false,
            maxZoom: 16,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);
        map.setView([12.7074, 121.4056], 11);
        let barangayMarker = null;
        element.__focusLocation = (lat, lng, zoom = 14, barangay) => {
            map.setView([lat, lng], zoom, { animate: true });

            const label = document.createElement('span');
            label.textContent = `${barangay} (selected barangay)`;

            if (barangayMarker) {
                barangayMarker.setLatLng([lat, lng]);
                barangayMarker.setTooltipContent(label);
            } else {
                barangayMarker = L.circleMarker([lat, lng], {
                    radius: 9,
                    color: '#fff',
                    weight: 3,
                    fillColor: '#c65c3b',
                    fillOpacity: 1,
                }).addTo(map);
                barangayMarker.bindTooltip(label, {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -8],
                });
            }
        };
        element.__clearFocusLocation = () => {
            if (barangayMarker) {
                map.removeLayer(barangayMarker);
                barangayMarker = null;
            }
        };

        if (editable) {
            const points = [];
            const markers = [];
            let preview = null;
            let savedPolygon = null;
            let centerMarker = null;
            let isDrawing = false;
            const ring = initialBoundary.current?.coordinates?.[0] ?? [];

            const setCenterLocation = (lat, lng) => {
                if (lat === '' || lat === null || lat === undefined || lng === '' || lng === null || lng === undefined) {
                    if (centerMarker) map.removeLayer(centerMarker);
                    centerMarker = null;
                    return;
                }

                const coordinates = [Number(lat), Number(lng)];
                if (!coordinates.every(Number.isFinite)
                    || coordinates[0] < -90
                    || coordinates[0] > 90
                    || coordinates[1] < -180
                    || coordinates[1] > 180) {
                    if (centerMarker) map.removeLayer(centerMarker);
                    centerMarker = null;
                    return;
                }

                if (centerMarker) {
                    centerMarker.setLatLng(coordinates);
                } else {
                    centerMarker = L.circleMarker(coordinates, {
                        radius: 7,
                        color: '#fff',
                        weight: 2,
                        fillColor: '#1d5b45',
                        fillOpacity: 1,
                    }).addTo(map);
                }

                map.setView(coordinates, Math.max(map.getZoom(), 15), { animate: false });
            };
            element.__setCenterLocation = setCenterLocation;

            if (initialBoundary.current?.type === 'Polygon' && ring.length >= 4) {
                savedPolygon = L.polygon(
                    ring.map(([longitude, latitude]) => [latitude, longitude]),
                    { color: '#477958', fillOpacity: 0.22 },
                ).addTo(map);
                map.fitBounds(savedPolygon.getBounds().pad(0.2), { padding: [25, 25] });
            }

            setCenterLocation(locationRef.current.latitude, locationRef.current.longitude);

            map.on('click', (event) => {
                if (!isDrawing) {
                    setCenterLocation(event.latlng.lat, event.latlng.lng);
                    locationCallback.current?.({
                        latitude: event.latlng.lat,
                        longitude: event.latlng.lng,
                    });
                    return;
                }

                points.push([event.latlng.lat, event.latlng.lng]);
                markers.push(L.circleMarker(event.latlng, { radius: 4, color: '#477958', fillOpacity: 1 }).addTo(map));

                if (preview) {
                    map.removeLayer(preview);
                }

                preview = L.polyline(points, { color: '#477958', dashArray: '5 5' }).addTo(map);
                setPointCount(points.length);
            });

            element.__startDrawing = () => {
                isDrawing = true;
                map.getContainer().classList.add('is-drawing');
                points.length = 0;
                setPointCount(0);
                markers.forEach((marker) => map.removeLayer(marker));
                markers.length = 0;
                if (preview) map.removeLayer(preview);
                if (savedPolygon) map.removeLayer(savedPolygon);
                preview = null;
                savedPolygon = null;
                boundaryCallback.current?.(null);
            };

            element.__finishDrawing = () => {
                if (points.length < 3) {
                    return;
                }

                points.push(points[0]);
                isDrawing = false;
                map.getContainer().classList.remove('is-drawing');
                if (preview) map.removeLayer(preview);
                savedPolygon = L.polygon(points, { color: '#477958', fillOpacity: 0.22 }).addTo(map);
                boundaryCallback.current?.({
                    type: 'Polygon',
                    coordinates: [points.map(([latitude, longitude]) => [longitude, latitude])],
                });
            };

            element.__undoDrawing = () => {
                if (!isDrawing || points.length === 0) {
                    return;
                }

                points.pop();
                const marker = markers.pop();
                if (marker) map.removeLayer(marker);
                if (preview) map.removeLayer(preview);
                preview = points.length > 0
                    ? L.polyline(points, { color: '#477958', dashArray: '5 5' }).addTo(map)
                    : null;
                setPointCount(points.length);
            };
        } else {
            const polygons = [];

            for (const area of areas) {
                const ring = area.boundary?.coordinates?.[0] ?? [];
                const intensity = heatForArea(area);
                const palette = heatPaletteForScore(intensity);

                if (area.boundary?.type !== 'Polygon' || ring.length < 4) {
                    continue;
                }

                const polygon = L.polygon(
                    ring.map(([longitude, latitude]) => [latitude, longitude]),
                    { color: palette.fill, fillColor: palette.fill, fillOpacity: 0.24, weight: 2 },
                ).addTo(map);
                polygon.bindPopup(popupContent(area), {
                    maxWidth: Math.min(420, Math.max(240, window.innerWidth - 40)),
                    maxHeight: 320,
                });
                polygons.push(polygon);

                const center = areaCenter(area, ring);

                if (center) {
                    const diameter = Math.round(130 + intensity * 120);
                    const icon = L.divIcon({
                        className: 'heatmap-marker',
                        html: `<span class="heatmap-spot" style="--spot-size:${diameter}px;--spot-color:${palette.spot}"></span>`,
                        iconSize: [diameter, diameter],
                        iconAnchor: [diameter / 2, diameter / 2],
                    });
                    L.marker(center, { icon, interactive: false, keyboard: false, zIndexOffset: -100 }).addTo(map);
                }
            }

            if (polygons.length) {
                map.fitBounds(L.featureGroup(polygons).getBounds().pad(0.2), { padding: [25, 25], maxZoom: 14 });
            }
        }

        setMapError('');

        return () => {
            map.remove();
            delete element.__startDrawing;
            delete element.__finishDrawing;
            delete element.__undoDrawing;
            delete element.__setCenterLocation;
            delete element.__focusLocation;
            delete element.__clearFocusLocation;
        };
    }, [areas, editable]);

    useEffect(() => {
        if (focusLocation) {
            elementRef.current?.__focusLocation?.(
                focusLocation.latitude,
                focusLocation.longitude,
                focusLocation.zoom,
                focusLocation.barangay,
            );
        } else {
            elementRef.current?.__clearFocusLocation?.();
        }
    }, [focusLocation]);

    return (
        <div className="map-component">
            {editable && (
                <div className="map-toolbar">
                    <span className="field-hint">Click to set the area center; start drawing to outline the boundary.</span>
                    <div className="inline-actions">
                        <button className="button button-muted button-small" type="button" onClick={() => {
                            elementRef.current?.__startDrawing?.();
                            setDrawing(true);
                        }}>Start drawing</button>
                        <button className="button button-muted button-small" type="button" disabled={!drawing || pointCount === 0} onClick={() => {
                            elementRef.current?.__undoDrawing?.();
                        }}>Undo last point</button>
                        <button className="button button-small" type="button" disabled={!drawing || pointCount < 3} onClick={() => {
                            elementRef.current?.__finishDrawing?.();
                            setDrawing(false);
                        }}>Finish boundary</button>
                    </div>
                </div>
            )}
            {!editable && (
                <div className="heatmap-legend" aria-label="Advisory level scale from 1 to 5">
                    <span>Advisory level</span>
                    {[1, 2, 3, 4, 5].map((level) => (
                        <span className="heatmap-legend-step" key={level}>
                            <i style={{ backgroundColor: heatPaletteForScore(level / 5).fill }} />
                            {level}
                        </span>
                    ))}
                </div>
            )}
            {mapError && <p className="map-error" role="status">{mapError}</p>}
            <div className={`leaflet-map ${editable && drawing ? 'is-drawing' : ''}`} ref={elementRef} />
        </div>
    );
}
