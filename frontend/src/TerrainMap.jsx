import { useEffect, useRef, useState } from 'react';
import { LngLatBounds, Map as MapLibreMap, NavigationControl, Popup } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

export default function TerrainMap({ areas, areaData, popupContent }) {
    const elementRef = useRef(null);
    const mapRef = useRef(null);
    const areasRef = useRef(areas);
    const areaDataRef = useRef(areaData);
    const [mapError, setMapError] = useState('');
    areasRef.current = areas;
    areaDataRef.current = areaData;

    useEffect(() => {
        const map = new MapLibreMap({
            container: elementRef.current,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [121.4056, 12.7074],
            zoom: 11,
            pitch: 58,
            bearing: -18,
            maxPitch: 80,
            antialias: true,
        });
        mapRef.current = map;
        map.addControl(new NavigationControl({ visualizePitch: true }), 'top-right');
        map.on('error', () => {
            setMapError((current) => current || '3D map tiles are unavailable. Check your internet connection and reload.');
        });

        map.on('load', () => {
            map.addSource('terrain-dem', {
                type: 'raster-dem',
                tiles: ['https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png'],
                tileSize: 256,
                maxzoom: 15,
                encoding: 'terrarium',
                attribution: 'Terrain data &copy; AWS Terrain Tiles',
            });
            map.setTerrain({ source: 'terrain-dem', exaggeration: 1.25 });
            map.addSource('risk-area-polygons', {
                type: 'geojson',
                data: areaDataRef.current.polygons,
            });
            map.addSource('risk-area-centers', {
                type: 'geojson',
                data: areaDataRef.current.centers,
            });
            map.addLayer({
                id: 'risk-area-fill',
                type: 'fill',
                source: 'risk-area-polygons',
                paint: {
                    'fill-color': ['get', 'fillColor'],
                    'fill-opacity': 0.3,
                },
            });
            map.addLayer({
                id: 'risk-area-outline',
                type: 'line',
                source: 'risk-area-polygons',
                paint: {
                    'line-color': ['get', 'fillColor'],
                    'line-width': 2,
                },
            });
            map.addLayer({
                id: 'risk-area-heat',
                type: 'circle',
                source: 'risk-area-centers',
                paint: {
                    'circle-color': ['get', 'spotColor'],
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 9, 18, 14, 56],
                    'circle-opacity': 0.4,
                    'circle-blur': 0.85,
                    'circle-pitch-alignment': 'map',
                    'circle-pitch-scale': 'map',
                },
            });
            map.on('click', 'risk-area-fill', (event) => {
                const areaIndex = event.features?.[0]?.properties?.areaIndex;
                const area = areasRef.current[Number(areaIndex)];

                if (area) {
                    new Popup({ maxWidth: '420px' })
                        .setLngLat(event.lngLat)
                        .setDOMContent(popupContent(area))
                        .addTo(map);
                }
            });
            map.on('mouseenter', 'risk-area-fill', () => {
                map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', 'risk-area-fill', () => {
                map.getCanvas().style.cursor = '';
            });

            const firstRing = areasRef.current.find((area) => area.boundary?.type === 'Polygon')?.boundary?.coordinates?.[0];
            if (firstRing?.length) {
                const bounds = new LngLatBounds();
                areasRef.current.forEach((area) => {
                    const ring = area.boundary?.type === 'Polygon' ? area.boundary.coordinates?.[0] : [];
                    ring?.forEach((coordinate) => bounds.extend(coordinate));
                });
                if (!bounds.isEmpty()) {
                    map.fitBounds(bounds, { padding: 48, maxZoom: 14, pitch: 58, bearing: -18 });
                }
            }
        });

        return () => {
            map.remove();
            mapRef.current = null;
        };
    }, [popupContent]);

    useEffect(() => {
        const map = mapRef.current;
        if (!map?.isStyleLoaded()) return;

        map.getSource('risk-area-polygons')?.setData(areaData.polygons);
        map.getSource('risk-area-centers')?.setData(areaData.centers);
    }, [areaData]);

    return (
        <>
            {mapError && <p className="map-error" role="status">{mapError}</p>}
            <div className="leaflet-map terrain-map" ref={elementRef} />
        </>
    );
}
