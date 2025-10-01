interface ColorResult {
  hex: string;
  prominence: number;
  rgb: { r: number; g: number; b: number };
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