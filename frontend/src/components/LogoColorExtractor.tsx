import React, { useState, useRef, useCallback } from 'react';
import { ColorExtractor, ColorExtractionResult } from '../utils/colorExtractor';

export interface LogoColorData {
  logoBase64: string;
  logoFilename: string;
  primaryColor: string;
  secondaryColor: string;
  accentColor: string;
}

interface LogoColorExtractorProps {
  initialData?: {
    logoData?: string;
    logoFilename?: string;
    primaryColor?: string;
    secondaryColor?: string;
    accentColor?: string;
  };
  onSave?: (data: LogoColorData) => Promise<void>;
  maxFileSize?: number;
}

export const LogoColorExtractor: React.FC<LogoColorExtractorProps> = ({
  initialData = {},
  onSave,
  maxFileSize = 500 * 1024 // 500KB default
}) => {
  const [logoData, setLogoData] = useState<string | undefined>(initialData.logoData);
  const [logoFilename, setLogoFilename] = useState<string | undefined>(initialData.logoFilename);
  const [primaryColor, setPrimaryColor] = useState<string>(initialData.primaryColor || '#234F1E');
  const [secondaryColor, setSecondaryColor] = useState<string>(initialData.secondaryColor || '#1F2937');
  const [accentColor, setAccentColor] = useState<string>(initialData.accentColor || '#10B981');
  const [extractedColors, setExtractedColors] = useState<ColorExtractionResult | null>(null);
  const [isDragOver, setIsDragOver] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
  }, []);

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);

    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) {
      handleFileSelection(files[0]);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      handleFileSelection(file);
    }
  };

  const handleFileSelection = async (file: File) => {
    if (!file.type.startsWith('image/')) {
      setError('Please select an image file');
      return;
    }

    if (file.size > maxFileSize) {
      setError(`File size must be less than ${Math.round(maxFileSize / 1024)}KB`);
      return;
    }

    setError(null);
    setLoading(true);

    try {
      // Convert file to base64
      const base64 = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });

      setLogoData(base64);
      setLogoFilename(file.name);

      // Extract colors
      const colors = await ColorExtractor.extractColorsFromFile(file);
      setExtractedColors(colors);

      setPrimaryColor(colors.primary);
      setSecondaryColor(colors.secondary);
      setAccentColor(colors.accent);
    } catch (error) {
      setError('Failed to process image');
      console.error('Logo processing error:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async () => {
    if (!logoData || !logoFilename) {
      setError('Please upload a logo first');
      return;
    }

    if (!onSave) {
      console.warn('No onSave handler provided');
      return;
    }

    setSaving(true);
    setError(null);
    setSuccess(null);

    try {
      await onSave({
        logoBase64: logoData,
        logoFilename,
        primaryColor,
        secondaryColor,
        accentColor
      });

      setSuccess('Logo and colors saved successfully!');
      setTimeout(() => setSuccess(null), 3000);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Failed to save');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Logo Upload Section */}
      <div className="bg-white border-2 border-forest-800 p-6">
        <h2 className="text-xl font-semibold text-forest-800 mb-4 uppercase">Club Logo</h2>

        <div
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
          className={`border-2 ${isDragOver ? 'border-forest-600 bg-gray-50' : 'border-dashed border-forest-800'} p-8 text-center relative`}
        >
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            onChange={handleFileChange}
            className="hidden"
          />

          {logoData ? (
            <div className="space-y-4">
              <img
                src={logoData}
                alt="Club logo"
                className="mx-auto h-32 w-auto object-contain"
              />
              <div>
                <p className="text-forest-800 font-medium">{logoFilename}</p>
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="mt-2 text-forest-800 hover:text-forest-600 uppercase text-sm font-medium"
                >
                  Change Logo
                </button>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <svg className="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
              </svg>
              <div>
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 uppercase font-medium"
                >
                  Upload Logo
                </button>
                <p className="mt-2 text-sm text-gray-600">or drag and drop</p>
              </div>
              <p className="text-xs text-gray-500">
                PNG, JPG, GIF up to {Math.round(maxFileSize / 1024)}KB
              </p>
            </div>
          )}

          {loading && (
            <div className="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center">
              <div className="text-forest-800">Processing...</div>
            </div>
          )}
        </div>
      </div>

      {/* Colors Section */}
      {extractedColors && (
        <div className="bg-white border-2 border-forest-800 p-6">
          <h2 className="text-xl font-semibold text-forest-800 mb-4 uppercase">Brand Colors</h2>
          <p className="text-gray-600 mb-6">Colors automatically extracted from your logo. You can adjust them if needed.</p>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Primary Color
              </label>
              <div className="flex space-x-2">
                <input
                  type="color"
                  value={primaryColor}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                  className="h-10 w-20 border-2 border-forest-800 cursor-pointer"
                />
                <input
                  type="text"
                  value={primaryColor}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                  className="flex-1 bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600 uppercase"
                  placeholder="#000000"
                />
              </div>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Secondary Color
              </label>
              <div className="flex space-x-2">
                <input
                  type="color"
                  value={secondaryColor}
                  onChange={(e) => setSecondaryColor(e.target.value)}
                  className="h-10 w-20 border-2 border-forest-800 cursor-pointer"
                />
                <input
                  type="text"
                  value={secondaryColor}
                  onChange={(e) => setSecondaryColor(e.target.value)}
                  className="flex-1 bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600 uppercase"
                  placeholder="#000000"
                />
              </div>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Accent Color
              </label>
              <div className="flex space-x-2">
                <input
                  type="color"
                  value={accentColor}
                  onChange={(e) => setAccentColor(e.target.value)}
                  className="h-10 w-20 border-2 border-forest-800 cursor-pointer"
                />
                <input
                  type="text"
                  value={accentColor}
                  onChange={(e) => setAccentColor(e.target.value)}
                  className="flex-1 bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600 uppercase"
                  placeholder="#000000"
                />
              </div>
            </div>
          </div>

          {/* Extracted Color Palette */}
          {extractedColors.allColors.length > 0 && (
            <div className="mt-6 pt-6 border-t border-gray-300">
              <h3 className="text-sm font-medium text-forest-800 mb-3 uppercase">Extracted Palette</h3>
              <div className="flex space-x-2">
                {extractedColors.allColors.map((color, index) => (
                  <button
                    key={index}
                    type="button"
                    onClick={() => setPrimaryColor(color.hex)}
                    className="h-10 w-10 border-2 border-forest-800 hover:scale-110 transition-transform"
                    style={{ backgroundColor: color.hex }}
                    title={`${color.hex} (${Math.round(color.prominence * 100)}%)`}
                  />
                ))}
              </div>
              <p className="text-xs text-gray-500 mt-2">Click any color to set as primary</p>
            </div>
          )}
        </div>
      )}

      {/* Error/Success Messages */}
      {error && (
        <div className="bg-red-50 border-2 border-red-500 text-red-700 p-4">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border-2 border-green-500 text-green-700 p-4">
          {success}
        </div>
      )}

      {/* Save Button */}
      {onSave && logoData && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={handleSave}
            disabled={saving}
            className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-3 hover:bg-forest-700 font-semibold uppercase disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Save Logo & Colors'}
          </button>
        </div>
      )}
    </div>
  );
};