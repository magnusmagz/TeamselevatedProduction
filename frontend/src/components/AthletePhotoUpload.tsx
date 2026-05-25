import React, { useState, useRef } from 'react';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface AthletePhotoUploadProps {
  athleteId: number;
  currentPhotoUrl?: string;
  firstName: string;
  lastName: string;
  onPhotoUpdated: (newUrl: string) => void;
  size?: 'small' | 'large';
}

const AthletePhotoUpload: React.FC<AthletePhotoUploadProps> = ({
  athleteId,
  currentPhotoUrl,
  firstName,
  lastName,
  onPhotoUpdated,
  size = 'large',
}) => {
  const [photoUrl, setPhotoUrl] = useState<string | undefined>(currentPhotoUrl);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const initials = `${firstName?.[0] || ''}${lastName?.[0] || ''}`.toUpperCase();
  const dimension = size === 'small' ? 'w-16 h-16' : 'w-[120px] h-[120px]';
  const textSize = size === 'small' ? 'text-xl' : 'text-3xl';
  const overlayTextSize = size === 'small' ? 'text-[10px]' : 'text-xs';
  const iconSize = size === 'small' ? 'w-4 h-4' : 'w-6 h-6';

  // Keep in sync if parent changes currentPhotoUrl
  React.useEffect(() => {
    setPhotoUrl(currentPhotoUrl);
  }, [currentPhotoUrl]);

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      setError('Only JPG, PNG, and WebP images are allowed.');
      return;
    }

    // Validate file size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
      setError('Image must be under 5MB.');
      return;
    }

    setUploading(true);
    setError('');

    try {
      const token = localStorage.getItem('auth_token');

      // Step 1: Upload the file
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', 'athlete-photos');

      const uploadRes = await fetch(`${API_URL}/api/upload.php`, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: formData,
      });

      if (!uploadRes.ok) {
        const errData = await uploadRes.json().catch(() => ({}));
        throw new Error(errData.error || 'Upload failed');
      }

      const uploadData = await uploadRes.json();
      if (!uploadData.success || !uploadData.url) {
        throw new Error('Upload did not return a valid URL');
      }

      const newUrl = uploadData.url;

      // Step 2: Update the athlete record with the new photo_url
      const updateRes = await fetch(
        `${API_URL}/legacy/athletes-gateway.php?id=${athleteId}`,
        {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ photo_url: newUrl }),
        }
      );

      if (!updateRes.ok) {
        throw new Error('Failed to update athlete record');
      }

      // Step 3: Update local state and notify parent
      setPhotoUrl(newUrl);
      onPhotoUpdated(newUrl);
    } catch (err: any) {
      setError(err.message || 'Upload failed');
    } finally {
      setUploading(false);
      // Reset input so the same file can be re-selected
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  return (
    <div className="flex flex-col items-center">
      <div
        className={`${dimension} rounded-full relative cursor-pointer group overflow-hidden flex-shrink-0`}
        onClick={() => !uploading && fileInputRef.current?.click()}
      >
        {/* Photo or initials fallback */}
        {photoUrl ? (
          <img
            src={photoUrl}
            alt={`${firstName} ${lastName}`}
            className="w-full h-full rounded-full object-cover border-2 border-brand-secondary"
          />
        ) : (
          <div className="w-full h-full rounded-full bg-brand-secondary flex items-center justify-center border-2 border-brand-secondary">
            <span className={`${textSize} font-bold text-brand-primary`}>{initials}</span>
          </div>
        )}

        {/* Loading spinner overlay */}
        {uploading && (
          <div className="absolute inset-0 rounded-full bg-black bg-opacity-50 flex items-center justify-center">
            <svg
              className={`${iconSize} animate-spin text-white`}
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
          </div>
        )}

        {/* Hover overlay */}
        {!uploading && (
          <div className="absolute inset-0 rounded-full bg-black bg-opacity-0 group-hover:bg-opacity-40 flex flex-col items-center justify-center transition-all duration-200">
            <svg
              className={`${iconSize} text-white opacity-0 group-hover:opacity-100 transition-opacity duration-200`}
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2}
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
              />
              <path strokeLinecap="round" strokeLinejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span
              className={`${overlayTextSize} text-white font-medium opacity-0 group-hover:opacity-100 transition-opacity duration-200 mt-1`}
            >
              Change Photo
            </span>
          </div>
        )}
      </div>

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        capture="environment"
        onChange={handleFileSelect}
        className="hidden"
      />

      {/* Error message */}
      {error && <p className="text-xs text-red-500 mt-1 text-center max-w-[150px]">{error}</p>}
    </div>
  );
};

export default AthletePhotoUpload;
