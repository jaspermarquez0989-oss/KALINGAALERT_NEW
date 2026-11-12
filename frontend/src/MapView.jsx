import { useEffect, useRef, useState } from 'react';
import bongabongBoundary from './data/bongabong-boundary.json';

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

    for (const alert of area.alerts ?? []) {
        const detail = document.createElement('section');
        const heading = document.createElement('strong');
        const message = document.createElement('p');
        heading.textContent = `${alert.title} · ${alert.severity}`;
        message.textContent = alert.message;
        detail.append(heading, message);

        if (alert.safety_instructions) {
            const instructions = document.createElement('p');
            instructions.textContent = `Safety instructions: ${alert.safety_instructions}`;
            detail.append(instructions);
        }

        root.append(detail);
    }

    return root;
}

export default function MapView({ areas = emptyAreas, boundary, editable = false, onBoundaryChange }) {
    const elementRef = useRef(null);
    const boundaryCallback = useRef(onBoundaryChange);
    const initialBoundary = useRef(boundary);
    const [drawing, setDrawing] = useState(false);
    const [pointCount, setPointCount] = useState(0);
    const [mapError, setMapError] = useState('');
    boundaryCallback.current = onBoundaryChange;

    useEffect(() => {
        const element = elementRef.current;

        if (!element || !window.L) {
            setMapError('Map tiles are unavailable. Check your internet connection and reload.');
            return undefined;
        }

        const L = window.L;
        const municipality = L.geoJSON(bongabongBoundary);
        const bounds = municipality.getBounds();
        const map = L.map(element, {
            scrollWheelZoom: false,
            maxBounds: bounds.pad(0.45),
            maxBoundsViscosity: 1,
            maxZoom: 16,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors · Municipality boundary: geoBoundaries (NAMRIA/PSA/OCHA), CC BY 3.0 IGO',
        }).addTo(map);
        map.fitBounds(bounds.pad(0.3), { padding: [18, 18] });

        const municipalityRing = bongabongBoundary.geometry.coordinates[0]
            .map(([longitude, latitude]) => [latitude, longitude]);
        L.polygon([
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
        municipality.setStyle({ color: '#1d5b45', fillOpacity: 0, interactive: false, weight: 2 }).addTo(map);

        if (editable) {
            const points = [];
            const markers = [];
            let preview = null;
            let savedPolygon = null;
            let isDrawing = false;
            const ring = initialBoundary.current?.coordinates?.[0] ?? [];

            if (initialBoundary.current?.type === 'Polygon' && ring.length >= 4) {
                savedPolygon = L.polygon(
                    ring.map(([longitude, latitude]) => [latitude, longitude]),
                    { color: '#477958', fillOpacity: 0.22 },
                ).addTo(map);
                map.fitBounds(savedPolygon.getBounds().pad(0.2), { padding: [25, 25] });
            }

            map.on('click', (event) => {
                if (!isDrawing) {
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
        } else {
            const polygons = [];

            for (const area of areas) {
                const ring = area.boundary?.coordinates?.[0] ?? [];

                if (area.boundary?.type !== 'Polygon' || ring.length < 4) {
                    continue;
                }

                const polygon = L.polygon(
                    ring.map(([longitude, latitude]) => [latitude, longitude]),
                    { color: area.alerts?.length ? '#c66a44' : '#477958', fillOpacity: area.alerts?.length ? 0.3 : 0.18 },
                ).addTo(map);
                polygon.bindPopup(popupContent(area));
                polygons.push(polygon);
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
        };
    }, [areas, editable]);

    return (
        <div className="map-component">
            {editable && (
                <div className="map-toolbar">
                    <span className="field-hint">Click at least three points inside Bongabong to outline the coverage.</span>
                    <div className="inline-actions">
                        <button className="button button-muted button-small" type="button" onClick={() => {
                            elementRef.current?.__startDrawing?.();
                            setDrawing(true);
                        }}>Start drawing</button>
                        <button className="button button-small" type="button" disabled={!drawing || pointCount < 3} onClick={() => {
                            elementRef.current?.__finishDrawing?.();
                            setDrawing(false);
                        }}>Finish boundary</button>
                    </div>
                </div>
            )}
            {mapError && <p className="map-error" role="status">{mapError}</p>}
            <div className={`leaflet-map ${editable && drawing ? 'is-drawing' : ''}`} ref={elementRef} />
        </div>
    );
}
