import React, { useState, useEffect } from 'react';
import { Program, ProgramType, ProgramStatus } from '../types';
import ProgramFormBuilder from '../components/ProgramFormBuilder';
import EmbedCodeModal from '../components/EmbedCodeModal';

const ProgramManagement: React.FC = () => {
  const [programs, setPrograms] = useState<Program[]>([]);
  const [showFormBuilder, setShowFormBuilder] = useState(false);
  const [selectedProgram, setSelectedProgram] = useState<Program | null>(null);
  const [embedProgram, setEmbedProgram] = useState<Program | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchPrograms();
  }, []);

  const fetchPrograms = async () => {
    try {
      const response = await fetch('http://localhost:8889/registration/programs-api.php?path=list&club_id=1');
      const data = await response.json();
      setPrograms(data);
    } catch (error) {
      console.error('Error fetching programs:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateProgram = () => {
    setSelectedProgram(null);
    setShowFormBuilder(true);
  };

  const handleEditProgram = (program: Program) => {
    setSelectedProgram(program);
    setShowFormBuilder(true);
  };

  const handleDeleteProgram = async (id: number) => {
    if (!window.confirm('Are you sure you want to delete this program?')) return;

    try {
      const response = await fetch(`http://localhost:8889/registration/programs-api.php?id=${id}`, {
        method: 'DELETE'
      });
      if (response.ok) {
        fetchPrograms();
      }
    } catch (error) {
      console.error('Error deleting program:', error);
    }
  };

  const getStatusColor = (status: ProgramStatus) => {
    switch (status) {
      case 'published': return 'text-green-600';
      case 'draft': return 'text-gray-600';
      case 'closed': return 'text-red-600';
      case 'cancelled': return 'text-orange-600';
      default: return 'text-gray-600';
    }
  };

  const getProgramTypeLabel = (type: ProgramType) => {
    return type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' ');
  };

  return (
    <div className="min-h-screen bg-white p-8">
      <div className="max-w-6xl mx-auto">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">
            Program Management
          </h1>
          <p className="text-gray-600 mt-2">
            Create and manage registration programs for camps, clinics, tryouts, and more
          </p>
        </div>

        {/* Action Bar */}
        <div className="mb-6 flex justify-between items-center">
          <div className="text-gray-600">
            {programs.length} program{programs.length !== 1 ? 's' : ''}
          </div>
          <button
            onClick={handleCreateProgram}
            className="bg-forest-800 text-white px-6 py-3 hover:bg-forest-700 uppercase font-semibold"
          >
            + Create New Program
          </button>
        </div>

        {/* Programs List */}
        {loading ? (
          <div className="text-center py-12 text-forest-800">Loading programs...</div>
        ) : programs.length === 0 ? (
          <div className="border-2 border-forest-800 p-12 text-center bg-white">
            <p className="text-gray-600 text-lg">No programs yet.</p>
            <p className="text-gray-500 mt-2">Create your first program to start accepting registrations.</p>
          </div>
        ) : (
          <>
            {/* Desktop Table View */}
            <div className="hidden md:block border-2 border-forest-800">
              <table className="min-w-full bg-white">
                <thead>
                  <tr className="border-b-2 border-forest-800">
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Program Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Dates
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Registrations
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {programs.map((program) => (
                    <tr key={program.id} className="border-b border-gray-300 hover:bg-gray-50">
                      <td className="px-6 py-4">
                        <div className="text-forest-800 font-medium">{program.name}</div>
                        {program.description && (
                          <div className="text-gray-500 text-sm mt-1 truncate max-w-xs">
                            {program.description}
                          </div>
                        )}
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-forest-800">
                          {getProgramTypeLabel(program.type)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-forest-800">
                          {program.start_date ? (
                            <>
                              {new Date(program.start_date).toLocaleDateString()}
                              {program.end_date && (
                                <> - {new Date(program.end_date).toLocaleDateString()}</>
                              )}
                            </>
                          ) : (
                            <span className="text-gray-400">No dates set</span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-forest-800">
                          {program.registration_count || 0}
                          {program.capacity && (
                            <span className="text-gray-500"> / {program.capacity}</span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`uppercase text-xs font-semibold ${getStatusColor(program.status)}`}>
                          {program.status}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex space-x-3">
                          <button
                            onClick={() => handleEditProgram(program)}
                            className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
                          >
                            Edit
                          </button>
                          {program.status === 'published' && (
                            <>
                              <button
                                onClick={() => setEmbedProgram(program)}
                                className="text-blue-600 hover:text-blue-500 uppercase text-xs font-semibold"
                              >
                                Embed
                              </button>
                              <button
                                onClick={() => {
                                  navigator.clipboard.writeText(
                                    `${window.location.origin}/register/${program.embed_code}`
                                  );
                                  alert('Registration link copied to clipboard!');
                                }}
                                className="text-blue-600 hover:text-blue-500 uppercase text-xs font-semibold"
                              >
                                Copy Link
                              </button>
                            </>
                          )}
                          <button
                            onClick={() => program.id && handleDeleteProgram(program.id)}
                            className="text-red-600 hover:text-red-500 uppercase text-xs font-semibold"
                          >
                            Delete
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Card View */}
            <div className="md:hidden space-y-4">
              {programs.map((program) => (
                <div key={program.id} className="border-2 border-forest-800 bg-white p-4">
                  {/* Program Header */}
                  <div className="mb-3">
                    <h3 className="text-lg font-bold text-forest-800">{program.name}</h3>
                    {program.description && (
                      <p className="text-gray-600 text-sm mt-1">{program.description}</p>
                    )}
                  </div>

                  {/* Program Details */}
                  <div className="space-y-2 mb-4">
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Type:</span>
                      <span className="text-forest-800 text-sm">{getProgramTypeLabel(program.type)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Dates:</span>
                      <span className="text-forest-800 text-sm">
                        {program.start_date ? (
                          <>
                            {new Date(program.start_date).toLocaleDateString()}
                            {program.end_date && (
                              <> - {new Date(program.end_date).toLocaleDateString()}</>
                            )}
                          </>
                        ) : (
                          <span className="text-gray-400">No dates set</span>
                        )}
                      </span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Registrations:</span>
                      <span className="text-forest-800 text-sm">
                        {program.registration_count || 0}
                        {program.capacity && (
                          <span className="text-gray-500"> / {program.capacity}</span>
                        )}
                      </span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Status:</span>
                      <span className={`uppercase text-xs font-semibold ${getStatusColor(program.status)}`}>
                        {program.status}
                      </span>
                    </div>
                  </div>

                  {/* Action Buttons */}
                  <div className="grid grid-cols-2 gap-2">
                    <button
                      onClick={() => handleEditProgram(program)}
                      className="border-2 border-forest-800 text-forest-800 hover:bg-forest-50 py-2 uppercase text-xs font-semibold"
                    >
                      Edit
                    </button>
                    {program.status === 'published' && (
                      <>
                        <button
                          onClick={() => setEmbedProgram(program)}
                          className="border-2 border-blue-600 text-blue-600 hover:bg-blue-50 py-2 uppercase text-xs font-semibold"
                        >
                          Embed
                        </button>
                        <button
                          onClick={() => {
                            navigator.clipboard.writeText(
                              `${window.location.origin}/register/${program.embed_code}`
                            );
                            alert('Registration link copied to clipboard!');
                          }}
                          className="border-2 border-blue-600 text-blue-600 hover:bg-blue-50 py-2 uppercase text-xs font-semibold"
                        >
                          Copy Link
                        </button>
                      </>
                    )}
                    <button
                      onClick={() => program.id && handleDeleteProgram(program.id)}
                      className="border-2 border-red-600 text-red-600 hover:bg-red-50 py-2 uppercase text-xs font-semibold"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}

        {/* Form Builder Modal */}
        {showFormBuilder && (
          <ProgramFormBuilder
            program={selectedProgram}
            onClose={() => {
              setShowFormBuilder(false);
              fetchPrograms();
            }}
          />
        )}

        {/* Embed Code Modal */}
        {embedProgram && (
          <EmbedCodeModal
            program={embedProgram}
            onClose={() => setEmbedProgram(null)}
          />
        )}
      </div>
    </div>
  );
};

export default ProgramManagement;