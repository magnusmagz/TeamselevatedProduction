import React, { useRef, useEffect, useState } from 'react';
import { LoadScript, Autocomplete } from '@react-google-maps/api';
import { GOOGLE_MAPS_CONFIG } from '../config/googleMaps';

interface AddressComponents {
  street_number?: string;
  route?: string;
  city?: string;
  state?: string;
  country?: string;
  postal_code?: string;
}

interface AddressAutocompleteProps {
  onAddressSelect: (address: {
    formatted: string;
    components: AddressComponents;
    coordinates: { lat: number; lng: number };
  }) => void;
  initialValue?: string;
  placeholder?: string;
  className?: string;
  disabled?: boolean;
}

const AddressAutocomplete: React.FC<AddressAutocompleteProps> = ({
  onAddressSelect,
  initialValue = '',
  placeholder = 'Enter venue address...',
  className = '',
  disabled = false
}) => {
  const [autocomplete, setAutocomplete] = useState<google.maps.places.Autocomplete | null>(null);
  const [inputValue, setInputValue] = useState(initialValue);
  const [isLoaded, setIsLoaded] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    setInputValue(initialValue);
  }, [initialValue]);

  const onLoad = (autocompleteInstance: google.maps.places.Autocomplete) => {
    setAutocomplete(autocompleteInstance);
    setIsLoaded(true);
  };

  const onPlaceChanged = () => {
    if (autocomplete) {
      const place = autocomplete.getPlace();

      if (!place.address_components || !place.geometry) {
        console.error('No address details available for this place.');
        return;
      }

      // Parse address components
      const components: AddressComponents = {};
      place.address_components.forEach((component) => {
        const types = component.types;
        if (types.includes('street_number')) {
          components.street_number = component.long_name;
        }
        if (types.includes('route')) {
          components.route = component.long_name;
        }
        if (types.includes('locality')) {
          components.city = component.long_name;
        }
        if (types.includes('administrative_area_level_1')) {
          components.state = component.short_name;
        }
        if (types.includes('country')) {
          components.country = component.short_name;
        }
        if (types.includes('postal_code')) {
          components.postal_code = component.long_name;
        }
      });

      // Get coordinates
      const coordinates = {
        lat: place.geometry.location?.lat() || 0,
        lng: place.geometry.location?.lng() || 0
      };

      // Update input value
      setInputValue(place.formatted_address || '');

      // Callback with parsed data
      onAddressSelect({
        formatted: place.formatted_address || '',
        components,
        coordinates
      });
    }
  };

  // Check if API key is configured
  const hasApiKey = GOOGLE_MAPS_CONFIG.apiKey && GOOGLE_MAPS_CONFIG.apiKey !== 'YOUR_API_KEY_HERE';

  if (!hasApiKey) {
    // Fallback to regular input if no API key
    return (
      <div>
        <input
          ref={inputRef}
          type="text"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          onBlur={() => {
            // Basic address parsing without validation
            onAddressSelect({
              formatted: inputValue,
              components: {
                route: inputValue
              },
              coordinates: { lat: 0, lng: 0 }
            });
          }}
          placeholder={placeholder}
          disabled={disabled}
          className={className || "w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"}
        />
        <p className="text-xs text-gray-500 mt-1">
          ⚠️ Google Maps not configured. Add API key for address validation.
        </p>
      </div>
    );
  }

  return (
    <LoadScript
      googleMapsApiKey={GOOGLE_MAPS_CONFIG.apiKey}
      libraries={GOOGLE_MAPS_CONFIG.libraries}
    >
      <Autocomplete
        onLoad={onLoad}
        onPlaceChanged={onPlaceChanged}
        options={GOOGLE_MAPS_CONFIG.autocompleteOptions}
      >
        <input
          ref={inputRef}
          type="text"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          placeholder={placeholder}
          disabled={disabled}
          className={className || "w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"}
        />
      </Autocomplete>
      {isLoaded && (
        <p className="text-xs text-green-600 mt-1">
          ✓ Address validation enabled
        </p>
      )}
    </LoadScript>
  );
};

export default AddressAutocomplete;