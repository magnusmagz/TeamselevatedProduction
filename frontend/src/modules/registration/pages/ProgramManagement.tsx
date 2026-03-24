import React, { useState, useEffect, useMemo } from 'react';
import { Program, ProgramType, ProgramStatus } from '../types';
import ProgramFormBuilder from '../components/ProgramFormBuilder';
import EmbedCodeModal from '../components/EmbedCodeModal';
import RegistrationsModal from '../components/RegistrationsModal';
import TryoutCreationWizard from '../components/TryoutCreationWizard';
import TryoutManagement from '../components/TryoutManagement';
import { useAuth } from '../../../hooks/useAuth';

const ProgramManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const { user } = useAuth();
  const [programs, setPrograms] = useState<Program[]>([]);
  const [showFormBuilder, setShowFormBuilder] = useState(false);
  const [selectedProgram, setSelectedProgram] = useState<Program | null>(null);
  const [embedProgram, setEmbedProgram] = useState<Program | null>(null);
  const [registrationsProgram, setRegistrationsProgram] = useState<Program | null>(null);
  const [loading, setLoading] = useState(true);
  const [showTryoutWizard, setShowTryoutWizard] = useState(false);
  const [editingTryout, setEditingTryout] = useState<Program | null>(null);
  const [manageTryoutProgram, setManageTryoutProgram] = useState<Program | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');

  const filteredPrograms = useMemo(() => {
    return programs.filter((program) => {
      if (statusFilter !== 'all' && program.status !== statusFilter) return false;
      if (typeFilter !== 'all' && program.type !== typeFilter) return false;
      return true;
    });
  }, [programs, statusFilter, typeFilter]);

  useEffect(() => {
    fetchPrograms();
  }, []);

  const fetchPrograms = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${API_URL}/registration/programs-api.php?path=list&club_id=1`);
      if (!response.ok) {
        console.error('Failed to fetch programs:', response.status);
        return;
      }
      const data = await response.json();
      if (Array.isArray(data)) {
        setPrograms(data);
      } else {
        console.error('Unexpected programs response:', data);
      }
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
    if (program.type === 'tryout') {
      setEditingTryout(program);
    } else if (program.type === 'tournament') {
      navigate(`/tournaments/${program.id}/edit`);
    } else {
      setSelectedProgram(program);
      setShowFormBuilder(true);
    }
  };

  const handleDeleteProgram = async (id: number) => {
    if (!window.confirm('Are you sure you want to delete this program?')) return;

    try {
      const response = await fetch(`${API_URL}/registration/programs-api.php?id=${id}`, {
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
    if (!type) return 'Unknown';
    return type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' ');
  };

  return (
    <div className="min-h-screen bg-white">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-8">
        {/* Header */}
        <div className="mb-4 sm:mb-8">
          <h1 className="text-2xl sm:text-3xl font-bold text-brand-primary uppercase tracking-wide">
            Program Management
          </h1>
          <p className="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">
            Create and manage registration programs
          </p>
        </div>

        {/* Action Bar */}
        <div className="mb-6 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
          <div className="flex gap-3">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1 sm:flex-none"
            >
              <option value="all">All Statuses</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <select
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
              className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1 sm:flex-none"
            >
              <option value="all">All Types</option>
              <option value="camp">Camp</option>
              <option value="clinic">Clinic</option>
              <option value="tryout">Tryout</option>
              <option value="league">League</option>
              <option value="tournament">Tournament</option>
            </select>
          </div>
          <div className="flex gap-3">
            <button
              onClick={handleCreateProgram}
              className="bg-brand-primary text-white px-4 py-2 sm:px-6 sm:py-3 rounded-md hover:bg-brand-primary uppercase font-semibold text-sm sm:text-base flex-1 sm:flex-none"
            >
              + Program
            </button>
            <button
              onClick={() => setShowTryoutWizard(true)}
              className="bg-brand-primary text-white px-4 py-2 sm:px-6 sm:py-3 rounded-md hover:bg-brand-primary-hover uppercase font-semibold text-sm sm:text-base flex-1 sm:flex-none"
            >
              + Tryout
            </button>
          </div>
        </div>

        {/* Programs List */}
        {loading ? (
          <div className="text-center py-12 text-brand-primary">Loading programs...</div>
        ) : filteredPrograms.length === 0 ? (
          <div className="border border-brand-secondary rounded-md p-12 text-center bg-white">
            <p className="text-gray-600 text-lg">No programs yet.</p>
            <p className="text-gray-500 mt-2">Create your first program to start accepting registrations.</p>
          </div>
        ) : (
          <>
            {/* Desktop Table View */}
            <div className="hidden md:block border border-brand-secondary rounded-md">
              <table className="min-w-full bg-white">
                <thead>
                  <tr className="border-b border-brand-secondary">
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Program Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Dates
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Registrations
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {filteredPrograms.map((program) => (
                    <tr key={program.id} className="border-b border-gray-300 hover:bg-gray-50">
                      <td className="px-6 py-4">
                        <div className="text-brand-primary font-medium">{program.name}</div>
                        {program.description && (
                          <div className="text-gray-500 text-sm mt-1 truncate max-w-xs">
                            {program.description}
                          </div>
                        )}
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-brand-primary">
                          {getProgramTypeLabel(program.type)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-brand-primary">
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
                        <button
                          onClick={() => setRegistrationsProgram(program)}
                          className="text-brand-primary hover:text-brand-primary-dark underline"
                        >
                          {program.registration_count || 0} registrations
                        </button>
                        {(program.pending_count ?? 0) > 0 && (
                          <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                            {program.pending_count} pending
                          </span>
                        )}
                        {program.capacity && (
                          <span className="text-gray-500 ml-1">/ {program.capacity}</span>
                        )}
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
                            className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                          >
                            Edit
                          </button>
                          {program.type === 'tryout' && (
                            <button
                              onClick={() => setManageTryoutProgram(program)}
                              className="text-green-600 hover:text-green-500 uppercase text-xs font-semibold"
                            >
                              Manage
                            </button>
                          )}
                          {program.status === 'published' && (
                            <>
                              <button
                                onClick={() => setEmbedProgram(program)}
                                className="text-brand-primary hover:text-brand-primary-dark uppercase text-xs font-semibold"
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
                                className="text-brand-primary hover:text-brand-primary-dark uppercase text-xs font-semibold"
                              >
                                Link
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
              {filteredPrograms.map((program) => (
                <div key={program.id} className="border border-brand-secondary rounded-md bg-white p-4">
                  {/* Program Header */}
                  <div className="mb-3">
                    <h3 className="text-lg font-bold text-brand-primary">{program.name}</h3>
                    {program.description && (
                      <p className="text-gray-600 text-sm mt-1">{program.description}</p>
                    )}
                  </div>

                  {/* Program Details */}
                  <div className="space-y-2 mb-4">
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Type:</span>
                      <span className="text-brand-primary text-sm">{getProgramTypeLabel(program.type)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Dates:</span>
                      <span className="text-brand-primary text-sm">
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
                    <div className="flex justify-between items-center">
                      <span className="text-gray-600 text-sm font-semibold uppercase">Registrations:</span>
                      <div className="flex items-center">
                        <button
                          onClick={() => setRegistrationsProgram(program)}
                          className="text-brand-primary text-sm hover:text-brand-primary-dark underline"
                        >
                          {program.registration_count || 0} registrations
                        </button>
                        {(program.pending_count ?? 0) > 0 && (
                          <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                            {program.pending_count} pending
                          </span>
                        )}
                        {program.capacity && (
                          <span className="text-gray-500 ml-1">/ {program.capacity}</span>
                        )}
                      </div>
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
                      className="border border-brand-secondary rounded-md text-brand-primary hover:bg-brand-secondary py-2 uppercase text-xs font-semibold"
                    >
                      Edit
                    </button>
                    {program.type === 'tryout' && (
                      <button
                        onClick={() => setManageTryoutProgram(program)}
                        className="border border-green-200 rounded-md text-green-600 hover:bg-green-50 py-2 uppercase text-xs font-semibold"
                      >
                        Manage
                      </button>
                    )}
                    {program.status === 'published' && (
                      <>
                        <button
                          onClick={() => setEmbedProgram(program)}
                          className="border border-brand-primary/30 rounded-md text-brand-primary hover:bg-brand-light py-2 uppercase text-xs font-semibold"
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
                          className="border border-brand-primary/30 rounded-md text-brand-primary hover:bg-brand-light py-2 uppercase text-xs font-semibold"
                        >
                          Link
                        </button>
                      </>
                    )}
                    <button
                      onClick={() => program.id && handleDeleteProgram(program.id)}
                      className="border border-red-200 rounded-md text-red-600 hover:bg-red-50 py-2 uppercase text-xs font-semibold"
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

        {/* Registrations Modal */}
        {registrationsProgram && (
          <RegistrationsModal
            program={registrationsProgram}
            onClose={() => {
              setRegistrationsProgram(null);
              fetchPrograms(); // Refresh counts after approval/rejection
            }}
          />
        )}

        {/* Tryout Creation Wizard */}
        {showTryoutWizard && (
          <TryoutCreationWizard
            clubId={1}
            onComplete={(tryoutId) => {
              setShowTryoutWizard(false);
              fetchPrograms();
            }}
            onCancel={() => setShowTryoutWizard(false)}
          />
        )}

        {/* Tryout Edit Wizard */}
        {editingTryout && (
          <TryoutCreationWizard
            clubId={1}
            existingProgram={editingTryout}
            onComplete={() => {
              setEditingTryout(null);
              fetchPrograms();
            }}
            onCancel={() => setEditingTryout(null)}
          />
        )}

        {/* Tryout Management */}
        {manageTryoutProgram && manageTryoutProgram.id && (
          <TryoutManagement
            programId={manageTryoutProgram.id}
            programName={manageTryoutProgram.name}
            currentUserId={user?.id || 0}
            onClose={() => {
              setManageTryoutProgram(null);
              fetchPrograms();
            }}
          />
        )}
      </div>
    </div>
  );
};

export default ProgramManagement;