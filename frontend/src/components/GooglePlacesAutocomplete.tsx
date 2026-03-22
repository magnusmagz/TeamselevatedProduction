import React, { useEffect, useRef, useState, useCallback } from 'react';

declare global {
  interface Window {
    google: any;
  }
}

interface PlaceResult {
  formatted_address: string;
  address_line1: string;
  city: string;
  state: string;
  zip_code: string;
  country: string;
  lat?: number;
  lng?: number;
  map_url?: string;
}

interface GooglePlacesAutocompleteProps {
  onPlaceSelect: (place: PlaceResult) => void;
  placeholder?: string;
  defaultValue?: string;
  className?: string;
}

const loadGoogleMaps = (): Promise<void> => {
  return new Promise<void>((resolve, reject) => {
    if (window.google?.maps?.places) {
      resolve();
      return;
    }
    if (document.querySelector('script[src*="maps.googleapis.com"]')) {
      const check = setInterval(() => {
        if (window.google?.maps?.places) {
          clearInterval(check);
          resolve();
        }
      }, 100);
      return;
    }
    const apiKey = process.env.REACT_APP_GOOGLE_MAPS_API_KEY;
    if (!apiKey) {
      reject(new Error('Google Maps API key not configured'));
      return;
    }
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&loading=async`;
    script.async = true;
    script.defer = true;
    script.onload = () => {
      const wait = setInterval(() => {
        if (window.google?.maps?.places) {
          clearInterval(wait);
          resolve();
        }
      }, 100);
    };
    script.onerror = () => reject(new Error('Failed to load Google Maps'));
    document.head.appendChild(script);
  });
};

const GooglePlacesAutocomplete: React.FC<GooglePlacesAutocompleteProps> = ({
  onPlaceSelect,
  placeholder,
  defaultValue,
  className,
}) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const autocompleteRef = useRef<any>(null);
  const [loadError, setLoadError] = useState(false);
  const [noApiKey] = useState(!process.env.REACT_APP_GOOGLE_MAPS_API_KEY);

  const onPlaceSelectRef = useRef(onPlaceSelect);
  onPlaceSelectRef.current = onPlaceSelect;

  const handleManualChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    // Allow manual typing — the onPlaceSelect callback only fires
    // when the user picks a suggestion from the dropdown.
    // This keeps the input editable for manual corrections.
  }, []);

  useEffect(() => {
    if (noApiKey) return;

    let cancelled = false;

    loadGoogleMaps()
      .then(() => {
        if (cancelled || !inputRef.current || !window.google) return;

        const autocomplete = new window.google.maps.places.Autocomplete(inputRef.current, {
          types: ['address', 'establishment'],
          fields: ['address_components', 'formatted_address', 'geometry', 'name', 'url'],
          componentRestrictions: { country: 'us' },
        });

        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          if (!place.address_components) return;

          const components: Record<string, string> = {};
          place.address_components.forEach((comp: any) => {
            comp.types.forEach((type: string) => {
              components[type] = comp.long_name;
              if (type === 'administrative_area_level_1') {
                components['state_short'] = comp.short_name;
              }
            });
          });

          onPlaceSelectRef.current({
            formatted_address: place.formatted_address || '',
            address_line1: `${components['street_number'] || ''} ${components['route'] || ''}`.trim(),
            city: components['locality'] || components['sublocality'] || '',
            state: components['state_short'] || components['administrative_area_level_1'] || '',
            zip_code: components['postal_code'] || '',
            country: components['country'] || 'USA',
            lat: place.geometry?.location?.lat(),
            lng: place.geometry?.location?.lng(),
            map_url: place.url || '',
          });
        });

        autocompleteRef.current = autocomplete;
      })
      .catch((err) => {
        console.error('Google Maps load error:', err);
        if (!cancelled) setLoadError(true);
      });

    return () => {
      cancelled = true;
    };
  }, [noApiKey]);

  const inputClassName =
    className ||
    'w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent';

  if (noApiKey || loadError) {
    return (
      <div>
        <input
          type="text"
          defaultValue={defaultValue}
          placeholder={placeholder || 'Enter address manually'}
          className={inputClassName}
          onChange={handleManualChange}
        />
        <p className="text-gray-400 text-xs mt-1">
          Address search unavailable — enter address manually.
        </p>
      </div>
    );
  }

  return (
    <input
      ref={inputRef}
      type="text"
      defaultValue={defaultValue}
      placeholder={placeholder || 'Search for an address or place...'}
      className={inputClassName}
      onChange={handleManualChange}
    />
  );
};

export default GooglePlacesAutocomplete;
