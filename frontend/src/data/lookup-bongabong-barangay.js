import bongabongBoundary from './bongabong-boundary.json';

const barangayAliases = {
    'Bagumbayan I': ['Bagong Bayan I'],
    'Bagumbayan II': ['Bagong Bayan II'],
    Polusahi: ['Pulosahi'],
};

function isWithinBongabong(longitude, latitude) {
    const ring = bongabongBoundary.geometry.coordinates[0];
    let inside = false;

    for (let current = 0, previous = ring.length - 1; current < ring.length; previous = current++) {
        const [currentLongitude, currentLatitude] = ring[current];
        const [previousLongitude, previousLatitude] = ring[previous];
        const crossesLatitude = (currentLatitude > latitude) !== (previousLatitude > latitude);
        const intersection = ((previousLongitude - currentLongitude) * (latitude - currentLatitude)
            / ((previousLatitude - currentLatitude) || Number.EPSILON))
            + currentLongitude;

        if (crossesLatitude && longitude < intersection) {
            inside = !inside;
        }
    }

    return inside;
}

export async function lookupBongabongBarangay(barangay) {
    const aliases = barangayAliases[barangay] ?? [];
    const names = [barangay, ...aliases].map((name) => name.toLowerCase());
    const query = new URLSearchParams({
        q: `${barangay}, Oriental Mindoro, Philippines`,
        lat: '12.7074',
        lon: '121.4056',
        zoom: '12',
        limit: '20',
    });
    const response = await fetch(`https://photon.komoot.io/api/?${query}`);

    if (!response.ok) {
        throw new Error('The map search service is unavailable. Try again or enter coordinates from the official incident record.');
    }

    const result = await response.json();
    const features = Array.isArray(result.features) ? result.features : [];
    const isLocalResult = (feature) => {
        const properties = feature.properties ?? {};
        const coordinates = feature.geometry?.coordinates;
        const longitude = Number(coordinates?.[0]);
        const latitude = Number(coordinates?.[1]);
        const matchedName = [properties.name, properties.locality, properties.city]
            .some((name) => typeof name === 'string' && names.includes(name.toLowerCase()));
        const inOrientalMindoro = !properties.state
            || (typeof properties.state === 'string' && properties.state.toLowerCase() === 'oriental mindoro');

        return matchedName
            && properties.countrycode === 'PH'
            && inOrientalMindoro
            && Number.isFinite(latitude)
            && Number.isFinite(longitude)
            && isWithinBongabong(longitude, latitude);
    };
    const feature = features.find((candidate) => {
        const properties = candidate.properties ?? {};
        return properties.osm_key === 'place'
            && names.includes(String(properties.name ?? '').toLowerCase())
            && isLocalResult(candidate);
    }) ?? features.find(isLocalResult);

    if (!feature) {
        throw new Error(`Could not find ${barangay} on the map. Enter coordinates from the official incident record.`);
    }

    const [longitude, latitude] = feature.geometry.coordinates.map(Number);

    return { latitude, longitude };
}
