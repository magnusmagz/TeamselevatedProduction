import React, { useState } from 'react';
import { Program } from '../types';

interface EmbedCodeModalProps {
  program: Program;
  onClose: () => void;
}

const EmbedCodeModal: React.FC<EmbedCodeModalProps> = ({ program, onClose }) => {
  const [embedType, setEmbedType] = useState<'iframe' | 'button'>('iframe');
  const [copied, setCopied] = useState(false);

  const registrationUrl = `${window.location.origin}/register/${program.embed_code}`;

  const iframeCode = `<iframe
  src="${registrationUrl}"
  width="100%"
  height="800"
  frameborder="0"
  style="border: 2px solid #14532d; max-width: 600px;">
</iframe>`;

  const buttonCode = `<a href="${registrationUrl}"
  target="_blank"
  style="display: inline-block; background-color: #14532d; color: white; padding: 12px 24px; text-decoration: none; font-weight: bold; text-transform: uppercase;">
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
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-2xl">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase">
            Embed Registration Form
          </h3>
          <button
            onClick={onClose}
            className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <div className="p-6">
          <div className="mb-6">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Program</h4>
            <p className="text-gray-600">{program.name}</p>
          </div>

          {/* Direct Link */}
          <div className="mb-6">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Direct Link</h4>
            <div className="flex space-x-2">
              <input
                type="text"
                readOnly
                value={registrationUrl}
                className="flex-1 bg-gray-100 border-2 border-forest-800 px-3 py-2 text-sm"
              />
              <button
                onClick={copyLink}
                className="bg-forest-800 text-white px-4 py-2 hover:bg-forest-700 uppercase text-sm font-semibold"
              >
                {copied ? 'Copied!' : 'Copy'}
              </button>
            </div>
          </div>

          {/* Embed Type Selection */}
          <div className="mb-4">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Embed Type</h4>
            <div className="flex space-x-4">
              <button
                onClick={() => setEmbedType('iframe')}
                className={`px-4 py-2 border-2 ${
                  embedType === 'iframe'
                    ? 'border-forest-800 bg-forest-800 text-white'
                    : 'border-forest-800 text-forest-800'
                } uppercase text-sm font-semibold`}
              >
                Iframe (Full Form)
              </button>
              <button
                onClick={() => setEmbedType('button')}
                className={`px-4 py-2 border-2 ${
                  embedType === 'button'
                    ? 'border-forest-800 bg-forest-800 text-white'
                    : 'border-forest-800 text-forest-800'
                } uppercase text-sm font-semibold`}
              >
                Button (Link)
              </button>
            </div>
          </div>

          {/* Embed Code */}
          <div className="mb-6">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Embed Code</h4>
            <textarea
              readOnly
              value={getEmbedCode()}
              className="w-full h-32 bg-gray-100 border-2 border-forest-800 px-3 py-2 text-sm font-mono"
            />
            <button
              onClick={copyToClipboard}
              className="mt-2 bg-forest-800 text-white px-6 py-2 hover:bg-forest-700 uppercase font-semibold"
            >
              {copied ? 'Copied!' : 'Copy Code'}
            </button>
          </div>

          {/* Preview */}
          <div className="mb-6">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Preview</h4>
            <div className="border-2 border-gray-300 p-4 bg-gray-50">
              {embedType === 'iframe' ? (
                <div className="text-center text-gray-500 py-8 border-2 border-dashed border-gray-400">
                  The registration form will appear here when embedded on your website
                </div>
              ) : (
                <div className="text-center">
                  <a
                    href="#"
                    onClick={(e) => e.preventDefault()}
                    className="inline-block bg-forest-800 text-white px-6 py-3 no-underline font-bold uppercase"
                  >
                    Register Now
                  </a>
                </div>
              )}
            </div>
          </div>

          {/* Instructions */}
          <div className="bg-gray-50 border-2 border-forest-800 p-4">
            <h4 className="text-forest-800 font-semibold mb-2 uppercase">Instructions</h4>
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
      </div>
    </div>
  );
};

export default EmbedCodeModal;