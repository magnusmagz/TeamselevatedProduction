interface ColorResult {
  hex: string;
  prominence: number;
  rgb: { r: number; g: number; b: number };
}

// HSL color type
interface HSL {
  h: number;
  s: number;
  l: number;
}

/**
 * Convert hex color to HSL
 */
export function hexToHSL(hex: string): HSL {
  // Remove # if present
  hex = hex.replace(/^#/, '');

  const r = parseInt(hex.substring(0, 2), 16) / 255;
  const g = parseInt(hex.substring(2, 4), 16) / 255;
  const b = parseInt(hex.substring(4, 6), 16) / 255;

  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  let h = 0;
  let s = 0;
  const l = (max + min) / 2;

  if (max !== min) {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);

    switch (max) {
      case r:
        h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
        break;
      case g:
        h = ((b - r) / d + 2) / 6;
        break;
      case b:
        h = ((r - g) / d + 4) / 6;
        break;
    }
  }

  return {
    h: Math.round(h * 360),
    s: Math.round(s * 100),
    l: Math.round(l * 100)
  };
}

/**
 * Convert HSL to hex color
 */
export function hslToHex(h: number, s: number, l: number): string {
  s /= 100;
  l /= 100;

  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs((h / 60) % 2 - 1));
  const m = l - c / 2;
  let r = 0, g = 0, b = 0;

  if (0 <= h && h < 60) {
    r = c; g = x; b = 0;
  } else if (60 <= h && h < 120) {
    r = x; g = c; b = 0;
  } else if (120 <= h && h < 180) {
    r = 0; g = c; b = x;
  } else if (180 <= h && h < 240) {
    r = 0; g = x; b = c;
  } else if (240 <= h && h < 300) {
    r = x; g = 0; b = c;
  } else if (300 <= h && h < 360) {
    r = c; g = 0; b = x;
  }

  const toHex = (n: number) => {
    const hex = Math.round((n + m) * 255).toString(16);
    return hex.length === 1 ? '0' + hex : hex;
  };

  return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

/**
 * Lighten a hex color by a percentage
 * @param hex - Hex color string (e.g., "#12443e")
 * @param percent - Percentage to lighten (0-100)
 */
export function lightenColor(hex: string, percent: number): string {
  const hsl = hexToHSL(hex);
  // Increase lightness, but cap at 95 to avoid pure white
  const newLightness = Math.min(95, hsl.l + percent);
  return hslToHex(hsl.h, hsl.s, newLightness);
}

/**
 * Darken a hex color by a percentage
 * @param hex - Hex color string (e.g., "#12443e")
 * @param percent - Percentage to darken (0-100)
 */
export function darkenColor(hex: string, percent: number): string {
  const hsl = hexToHSL(hex);
  // Decrease lightness, but keep at least 5 to avoid pure black
  const newLightness = Math.max(5, hsl.l - percent);
  return hslToHex(hsl.h, hsl.s, newLightness);
}

/**
 * Generate a complete color palette from a primary color
 */
export function generateColorPalette(primaryHex: string) {
  return {
    primary: primaryHex,
    primaryHover: darkenColor(primaryHex, 10),
    primaryDark: darkenColor(primaryHex, 15),
    secondary: lightenColor(primaryHex, 40),
    secondaryHover: lightenColor(primaryHex, 30),
    accent: lightenColor(primaryHex, 20),
    light: lightenColor(primaryHex, 50),
    muted: lightenColor(primaryHex, 25),
  };
}

export interface ColorExtractionResult {
  primary: string;
  secondary: string;
  accent: string;
  allColors: ColorResult[];
}

export class ColorExtractor {
  static async extractColorsFromFile(file: File): Promise<ColorExtractionResult> {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const reader = new FileReader();

      reader.onload = (e) => {
        img.src = e.target?.result as string;
      };

      img.onload = () => {
        try {
          const canvas = document.createElement('canvas');
          const ctx = canvas.getContext('2d');

          if (!ctx) {
            throw new Error('Could not get canvas context');
          }

          // Scale down image for faster processing
          const maxSize = 100;
          let width = img.width;
          let height = img.height;

          if (width > height) {
            if (width > maxSize) {
              height *= maxSize / width;
              width = maxSize;
            }
          } else {
            if (height > maxSize) {
              width *= maxSize / height;
              height = maxSize;
            }
          }

          canvas.width = width;
          canvas.height = height;
          ctx.drawImage(img, 0, 0, width, height);

          const imageData = ctx.getImageData(0, 0, width, height);
          const colors = this.extractColors(imageData);

          resolve(colors);
        } catch (error) {
          reject(error);
        }
      };

      img.onerror = () => {
        reject(new Error('Failed to load image'));
      };

      reader.readAsDataURL(file);
    });
  }

  private static extractColors(imageData: ImageData): ColorExtractionResult {
    const pixels = imageData.data;
    const colorMap = new Map<string, number>();

    // Sample every 5th pixel for speed
    for (let i = 0; i < pixels.length; i += 20) {
      const r = pixels[i];
      const g = pixels[i + 1];
      const b = pixels[i + 2];
      const a = pixels[i + 3];

      // Skip transparent/nearly transparent pixels
      if (a < 128) continue;

      // Round colors to reduce noise
      const roundedR = Math.round(r / 10) * 10;
      const roundedG = Math.round(g / 10) * 10;
      const roundedB = Math.round(b / 10) * 10;

      const key = `${roundedR},${roundedG},${roundedB}`;
      colorMap.set(key, (colorMap.get(key) || 0) + 1);
    }

    // Sort colors by frequency
    const sortedColors = Array.from(colorMap.entries())
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10)
      .map(([color, count]) => {
        const [r, g, b] = color.split(',').map(Number);
        return {
          rgb: { r, g, b },
          hex: this.rgbToHex(r, g, b),
          prominence: count
        };
      });

    // Filter out very light colors but keep dark colors including black
    const filteredColors = sortedColors.filter(color => {
      const brightness = (color.rgb.r + color.rgb.g + color.rgb.b) / 3;
      // Allow black and dark colors (brightness >= 0), but filter out very light colors
      return brightness >= 0 && brightness < 240;
    });

    // If not enough colors after filtering, use original
    const colorsToUse = filteredColors.length >= 3 ? filteredColors : sortedColors;

    // Normalize prominence values
    const totalCount = colorsToUse.reduce((sum, color) => sum + color.prominence, 0);
    colorsToUse.forEach(color => {
      color.prominence = color.prominence / totalCount;
    });

    return {
      primary: colorsToUse[0]?.hex || '#3B82F6',
      secondary: colorsToUse[1]?.hex || '#1F2937',
      accent: colorsToUse[2]?.hex || '#10B981',
      allColors: colorsToUse
    };
  }

  private static rgbToHex(r: number, g: number, b: number): string {
    const toHex = (n: number) => {
      const hex = n.toString(16);
      return hex.length === 1 ? '0' + hex : hex;
    };
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
  }
}