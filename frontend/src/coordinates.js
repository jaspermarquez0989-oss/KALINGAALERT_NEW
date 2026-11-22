function formatCoordinate(value, positiveDirection, negativeDirection) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const coordinate = Number(value);
    if (!Number.isFinite(coordinate)) {
        return null;
    }

    const direction = coordinate < 0 ? negativeDirection : positiveDirection;

    return `${Math.abs(coordinate).toFixed(5)}° ${direction}`;
}

export function formatLatitude(value) {
    return formatCoordinate(value, 'N', 'S');
}

export function formatLongitude(value) {
    return formatCoordinate(value, 'E', 'W');
}

export function formatCoordinatePair(latitude, longitude) {
    const formattedLatitude = formatLatitude(latitude);
    const formattedLongitude = formatLongitude(longitude);

    return formattedLatitude && formattedLongitude
        ? `${formattedLatitude}, ${formattedLongitude}`
        : null;
}
