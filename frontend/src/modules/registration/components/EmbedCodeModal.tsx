import React, { useState } from 'react';
import { Program } from '../types';
import { useTheme } from '../../../contexts/ThemeContext';
import Button from '../../../components/ui/Button';

interface EmbedCodeModalProps {
  program: Program;
  onClose: () => void;
}

const EmbedCodeModal: React.FC<EmbedCodeModalProps> = ({ program, onClose }) => {
  const [embedType, setEmbedType] = useState<'iframe' | 'button'>('iframe');
  const [copied, setCopied] = useState(false);
  const { colors } = useTheme();

  const registrationUrl = `${window.location.origin}/register/${program.embed_code}`;
  const brandColor = colors.primary || '#12443e';

  const iframeCode = `<iframe
  src="${registrationUrl}"
  width="100%"
  height="800"
  frameborder="0"
  style="border: 2px solid ${brandColor}; max-width: 600px;">
</iframe>`;

  const buttonCode = `<a href="${registrationUrl}"
  target="_blank"
  style="display: inline-block; background-color: ${brandColor}; color: white; padding: 12px 24px; text-decoration: none; font-weight: bold; text-transform: uppercase;">
  Register Now
</a>`;

  const getEmbedCode = () => {
    return embedType === 'iframe' ? iframeCode : buttonCode;
  };

  const copyToClipboard = () => {
    navigator.clipboard.writeText(getEmbedCode());
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const copyLink = () => {
    navigator.clipboard.writeText(registrationUrl);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50" onClick={onClose}>
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-2xl max-h-[90vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-brand-primary uppercase">
            Embed Registration Form
          </h3>
          <Button variant="ghost" size="icon" aria-label="Close" onClick={onClose} className="text-2xl">
            ×
          </Button>
        </div>

        <div className="p-6 overflow-y-auto">
          <div className="mb-6">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Program</h4>
            <p className="text-gray-600">{program.name}</p>
          </div>

          {/* Direct Link */}
          <div className="mb-6">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Direct Link</h4>
            <div className="flex space-x-2">
              <input
                type="text"
                readOnly
                value={registrationUrl}
                className="flex-1 bg-gray-100 border border-brand-secondary rounded-md px-3 py-2 text-sm"
              />
              <Button onClick={copyLink}>{copied ? 'Copied!' : 'Copy'}</Button>
            </div>
          </div>

          {/* Embed Type Selection */}
          <div className="mb-4">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Embed Type</h4>
            <div className="flex space-x-4">
              <button
                onClick={() => setEmbedType('iframe')}
                className={`px-4 py-2 border rounded-md ${
                  embedType === 'iframe'
                    ? 'border-brand-primary bg-brand-primary text-white'
                    : 'border-brand-secondary text-brand-primary'
                } uppercase text-sm font-semibold`}
              >
                Iframe (Full Form)
              </button>
              <button
                onClick={() => setEmbedType('button')}
                className={`px-4 py-2 border rounded-md ${
                  embedType === 'button'
                    ? 'border-brand-primary bg-brand-primary text-white'
                    : 'border-brand-secondary text-brand-primary'
                } uppercase text-sm font-semibold`}
              >
                Button (Link)
              </button>
            </div>
          </div>

          {/* Embed Code */}
          <div className="mb-6">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Embed Code</h4>
            <textarea
              readOnly
              value={getEmbedCode()}
              className="w-full h-32 bg-gray-100 border border-brand-secondary rounded-md px-3 py-2 text-sm font-mono"
            />
            <Button onClick={copyToClipboard} className="mt-2">
              {copied ? 'Copied!' : 'Copy Code'}
            </Button>
          </div>

          {/* Preview */}
          <div className="mb-6">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Preview</h4>
            <div className="border border-gray-200 rounded-md p-4 bg-gray-50">
              {embedType === 'iframe' ? (
                <div className="text-center text-gray-500 py-8 border border-dashed border-gray-300 rounded-md">
                  The registration form will appear here when embedded on your website
                </div>
              ) : (
                <div className="text-center">
                  {/* A button, not an anchor: this is the inert preview of the
                      embedded widget, so there is no address to navigate to.
                      href="#" claimed to be a link and wasn't (jsx-a11y/anchor-is-valid). */}
                  <button
                    type="button"
                    className="inline-block bg-brand-primary text-white px-6 py-3 rounded-md no-underline font-bold uppercase"
                  >
                    Register Now
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* Instructions */}
          <div className="bg-gray-50 border border-brand-secondary rounded-md p-4">
            <h4 className="text-brand-primary font-semibold mb-2 uppercase">Instructions</h4>
            <ol className="list-decimal list-inside text-gray-600 space-y-1 text-sm">
              <li>Copy the embed code above</li>
              <li>Paste it into your website's HTML where you want the form to appear</li>
              <li>
                {embedType === 'iframe'
                  ? 'Adjust the width and height values as needed for your layout'
                  : 'Customize the button styling to match your website'}
              </li>
              <li>Test the form to ensure it's working properly</li>
            </ol>
          </div>
        </div>

        {/* Footer with Cancel */}
        <div className="border-t border-brand-secondary px-6 py-4 flex justify-end shrink-0">
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};

export default EmbedCodeModal;