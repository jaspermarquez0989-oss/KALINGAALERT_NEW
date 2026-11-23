export const intensityColors = ['#66bd63', '#d9ef8b', '#fee08b', '#fc8d59', '#d73027'];

export function intensityColorForLevel(level) {
    const numericLevel = Number(level);
    const safeLevel = Number.isFinite(numericLevel)
        ? Math.min(5, Math.max(1, numericLevel))
        : 3;

    return intensityColors[Math.round(safeLevel) - 1];
}

export function heatPaletteForScore(score) {
    const level = Math.min(5, Math.max(1, Math.ceil(Number(score) * 5)));
    const fill = intensityColorForLevel(level);
    const color = Number.parseInt(fill.slice(1), 16);
    const red = (color >> 16) & 255;
    const green = (color >> 8) & 255;
    const blue = color & 255;

    return { fill, spot: `rgba(${red}, ${green}, ${blue}, .5)` };
}
