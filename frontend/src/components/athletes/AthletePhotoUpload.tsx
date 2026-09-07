import React, { useState, useRef } from 'react';
import Button from '../ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  athleteId: number;
  currentPhotoUrl: string | null;
  onUploaded: (photoUrl: string) => void;
}

const AthletePhotoUpload: React.FC<Props> = ({ athleteId, currentPhotoUrl, onUploaded }) => {
  const [preview, setPreview] = useState<string | null>(currentPhotoUrl);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file type
    if (!file.type.startsWith('image/')) {
      setError('Please select an image file');
      return;
    }

    // Validate file size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
      setError('Image must be under 5MB');
      return;
    }

    // Preview
    const reader = new FileReader();
    reader.onloadend = () => setPreview(reader.result as string);
    reader.readAsDataURL(file);

    // Upload
    setUploading(true);
    setError('');

    const formData = new FormData();
    formData.append('photo', file);

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${API_URL}/api/athletes.php?action=upload-photo&id=${athleteId}`,
        {
          method: 'POST',
          headers: { Authorization: `Bearer ${token}` },
          body: formData,
        }
      );

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Upload failed');
      }

      const data = await res.json();
      onUploaded(data.photo_url);
    } catch (err: any) {
      setError(err.message || 'Upload failed');
      setPreview(currentPhotoUrl); // Revert preview
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="flex items-center space-x-4">
      {/* Photo display */}
      <div
        className="w-20 h-20 rounded-full bg-gray-200 overflow-hidden flex-shrink-0 cursor-pointer border-2 border-gray-300 hover:border-brand-primary transition-colors"
        onClick={() => fileInputRef.current?.click()}
      >
        {preview ? (
          <img src={preview} alt="Athlete photo" className="w-full h-full object-cover" />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-gray-400">
            <svg className="w-10 h-10" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
            </svg>
          </div>
        )}
      </div>

      <div>
        <Button
          variant="link"
          onClick={() => fileInputRef.current?.click()}
          disabled={uploading}
        >
          {uploading ? 'Uploading...' : preview ? 'Change Photo' : 'Upload Photo'}
        </Button>
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*"
          onChange={handleFileSelect}
          className="hidden"
        />
        {error && <p className="text-xs text-red-500 mt-1">{error}</p>}
        <p className="text-xs text-gray-400 mt-0.5">JPG, PNG. Max 5MB.</p>
      </div>
    </div>
  );
};

export default AthletePhotoUpload;
