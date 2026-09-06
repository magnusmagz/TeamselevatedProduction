import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

/**
 * Organizations — the tier above the club (GOTR G1, migration 090).
 *
 * Girls on the Run is national -> division -> council -> site. A council IS a
 * club in this product, so this page builds only the part that does not exist
 * yet: the tree above the club, which councils hang off which node, and who
 * administers a tier.
 *
 * Super-admin only, by ProtectedSuperAdminRoute on the route and by the gate at
 * the top of api/super-admin-gateway.php. The route guard is convenience; the
 * gateway gate is the access control.
 *
 * Nothing else in the product reads any of this yet — building a tree here
 * changes nobody's access until the scope resolver is wired in.
 */

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

type OrgType = 'national' | 'division' | 'council';
type OrgRole = 'org_admin' | 'org_viewer';

interface OrgUnit {
  id: number;
  parent_id: number | null;
  type: OrgType;
  name: string;
  external_code: string | null;
  path: string;
  depth: number;
  club_count: number;
}

interface AttachedClub {
  id: number;
  name: string;
  org_unit_id: number;
}

interface OrgAccess {
  id: number;
  user_id: number;
  org_unit_id: number;
  role: OrgRole;
  email: string;
  first_name: string;
  last_name: string;
  org_unit_name: string;
}

interface Club {
  id: number;
  name: string;
}

const TYPES: OrgType[] = ['national', 'division', 'council'];
const ROLES: OrgRole[] = ['org_admin', 'org_viewer'];

const Organizations: React.FC = () => {
  const [available, setAvailable] = useState<boolean | null>(null);
  const [units, setUnits] = useState<OrgUnit[]>([]);
  const [attached, setAttached] = useState<AttachedClub[]>([]);
  const [access, setAccess] = useState<OrgAccess[]>([]);
  const [clubs, setClubs] = useState<Club[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Add-unit form
  const [newName, setNewName] = useState('');
  const [newType, setNewType] = useState<OrgType>('council');
  const [newParent, setNewParent] = useState<string>('');
  const [newCode, setNewCode] = useState('');

  // Attach-club form
  const [attachClub, setAttachClub] = useState<string>('');
  const [attachUnit, setAttachUnit] = useState<string>('');

  // Grant form
  const [grantEmail, setGrantEmail] = useState('');
  const [grantUnit, setGrantUnit] = useState<string>('');
  const [grantRole, setGrantRole] = useState<OrgRole>('org_admin');

  const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
    'Content-Type': 'application/json',
  });

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=org-units`, {
        headers: authHeaders(),
      });
      const data = await response.json();
      // `available: false` means migration 090 is not applied on this
      // environment. That is a different answer from "no organizations exist",
      // and conflating them would invite someone to build a tree that cannot save.
      setAvailable(data.available !== false);
      setUnits(Array.isArray(data.units) ? data.units : []);
      setAttached(Array.isArray(data.attached_clubs) ? data.attached_clubs : []);
      setAccess(Array.isArray(data.access) ? data.access : []);
    } catch (e) {
      setError('Could not load organizations.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadClubs = useCallback(async () => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=clubs`, {
        headers: authHeaders(),
      });
      const data = await response.json();
      setClubs(Array.isArray(data.clubs) ? data.clubs : []);
    } catch (e) {
      setClubs([]);
    }
  }, []);

  useEffect(() => {
    load();
    loadClubs();
  }, [load, loadClubs]);

  // Every write goes through here so the refusal message the server wrote is
  // the one shown. A generic "failed" would hide `not_empty`, which is the only
  // refusal a super admin can actually act on.
  const post = useCallback(async (action: string, body: unknown, method = 'POST') => {
    setError(null);
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=${action}`, {
        method,
        headers: authHeaders(),
        body: method === 'DELETE' ? undefined : JSON.stringify(body),
      });
      const data = await response.json();
      if (!data.success) {
        setError(data.error || 'Could not complete that change.');
        return false;
      }
      await load();
      return true;
    } catch (e) {
      setError('Could not reach the server.');
      return false;
    }
  }, [load]);

  const handleCreate = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!newName.trim()) return;
    const ok = await post('org-unit-save', {
      name: newName.trim(),
      type: newType,
      parent_id: newParent === '' ? null : Number(newParent),
      external_code: newCode.trim(),
    });
    if (ok) {
      setNewName('');
      setNewCode('');
    }
  };

  const handleRename = async (unit: OrgUnit) => {
    const name = window.prompt(`Rename "${unit.name}" to:`, unit.name);
    if (name === null || name.trim() === '') return;
    await post('org-unit-save', { id: unit.id, name: name.trim() });
  };

  const handleMove = async (unit: OrgUnit) => {
    const options = units
      .filter((u) => u.id !== unit.id)
      .map((u) => `${u.id} = ${u.name}`)
      .join('\n');
    const answer = window.prompt(
      `Move "${unit.name}" under which unit? Enter an id, or leave blank for the top level.\n\n${options}`,
      unit.parent_id ? String(unit.parent_id) : ''
    );
    if (answer === null) return;
    await post('org-unit-move', {
      id: unit.id,
      parent_id: answer.trim() === '' ? null : Number(answer.trim()),
    });
  };

  const handleDelete = async (unit: OrgUnit) => {
    if (!window.confirm(`Delete "${unit.name}"? Its clubs and children must be moved off it first.`)) return;
    await post(`org-unit-delete&id=${unit.id}`, null, 'DELETE');
  };

  const handleAttach = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!attachClub || !attachUnit) return;
    const ok = await post('org-unit-attach-club', {
      club_id: Number(attachClub),
      org_unit_id: Number(attachUnit),
    });
    if (ok) setAttachClub('');
  };

  const handleGrant = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!grantEmail.trim() || !grantUnit) return;
    const ok = await post('org-access-grant', {
      email: grantEmail.trim(),
      org_unit_id: Number(grantUnit),
      role: grantRole,
    });
    if (ok) setGrantEmail('');
  };

  const clubsByUnit = useMemo(() => {
    const map = new Map<number, AttachedClub[]>();
    attached.forEach((club) => {
      const list = map.get(club.org_unit_id) || [];
      list.push(club);
      map.set(club.org_unit_id, list);
    });
    return map;
  }, [attached]);

  if (loading) {
    return <main className="max-w-5xl mx-auto px-4 py-8">Loading organizations…</main>;
  }

  if (available === false) {
    return (
      <main className="max-w-5xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-brand-primary uppercase">Organizations</h1>
        <p className="mt-4 text-gray-700">
          Organizations are not set up on this environment yet. Migration 090 has not been applied.
        </p>
        <Link to="/super-admin" className="mt-4 inline-block text-brand-primary underline">
          Back to platform administration
        </Link>
      </main>
    );
  }

  return (
    <main className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-brand-primary uppercase">Organizations</h1>
        <p className="text-gray-600 mt-1">
          The tier above the club: national, division and council. A council is a club — attach it here.
        </p>
        <Link to="/super-admin" className="text-sm text-brand-primary underline">
          Back to platform administration
        </Link>
      </div>

      {error && (
        <div role="alert" className="mb-4 border border-red-300 bg-red-50 text-red-800 px-4 py-2 rounded">
          {error}
        </div>
      )}

      {/* Tree */}
      <section className="mb-8">
        <h2 className="text-lg font-semibold mb-2">Tree</h2>
        {units.length === 0 ? (
          <p className="text-gray-600">No organization units yet. Add one below.</p>
        ) : (
          <ul aria-label="Organization tree" className="border border-gray-200 rounded divide-y">
            {units.map((unit) => (
              <li key={unit.id} className="flex items-center justify-between px-3 py-2">
                <div style={{ paddingLeft: `${unit.depth * 20}px` }}>
                  <span className="font-medium">{unit.name}</span>{' '}
                  <span className="text-xs uppercase text-gray-500">{unit.type}</span>
                  {unit.external_code && (
                    <span className="ml-2 text-xs text-gray-500">code {unit.external_code}</span>
                  )}
                  <span className="ml-2 text-xs text-gray-500">
                    {unit.club_count} club{unit.club_count === 1 ? '' : 's'}
                  </span>
                  {(clubsByUnit.get(unit.id) || []).length > 0 && (
                    <div className="text-xs text-gray-600 mt-1">
                      {(clubsByUnit.get(unit.id) || []).map((club) => (
                        <span key={club.id} className="mr-2">
                          {club.name}
                          <button
                            type="button"
                            className="ml-1 underline"
                            onClick={() => post('org-unit-detach-club', { club_id: club.id })}
                          >
                            detach
                          </button>
                        </span>
                      ))}
                    </div>
                  )}
                </div>
                <div className="flex gap-2 text-sm">
                  <Link to={`/organizations/${unit.id}/onboarding`} className="underline">Onboarding</Link>
                  <button type="button" className="underline" onClick={() => handleRename(unit)}>Rename</button>
                  <button type="button" className="underline" onClick={() => handleMove(unit)}>Move</button>
                  <button type="button" className="underline text-red-700" onClick={() => handleDelete(unit)}>Delete</button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Add a unit */}
      <section className="mb-8">
        <h2 className="text-lg font-semibold mb-2">Add a unit</h2>
        <form onSubmit={handleCreate} className="flex flex-wrap gap-2 items-end">
          <label className="flex flex-col text-sm">
            Name
            <input
              className="border border-gray-300 rounded px-2 py-1"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
            />
          </label>
          <label className="flex flex-col text-sm">
            Type
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={newType}
              onChange={(e) => setNewType(e.target.value as OrgType)}
            >
              {TYPES.map((type) => (
                <option key={type} value={type}>{type}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col text-sm">
            Parent
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={newParent}
              onChange={(e) => setNewParent(e.target.value)}
            >
              <option value="">(top level)</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>{unit.name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col text-sm">
            External code
            <input
              className="border border-gray-300 rounded px-2 py-1"
              value={newCode}
              onChange={(e) => setNewCode(e.target.value)}
            />
          </label>
          <button type="submit" className="bg-brand-primary text-white px-3 py-1 rounded">Add unit</button>
        </form>
      </section>

      {/* Attach a club */}
      <section className="mb-8">
        <h2 className="text-lg font-semibold mb-2">Attach a club</h2>
        <form onSubmit={handleAttach} className="flex flex-wrap gap-2 items-end">
          <label className="flex flex-col text-sm">
            Club
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={attachClub}
              onChange={(e) => setAttachClub(e.target.value)}
            >
              <option value="">Select a club</option>
              {clubs.map((club) => (
                <option key={club.id} value={club.id}>{club.name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col text-sm">
            Unit
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={attachUnit}
              onChange={(e) => setAttachUnit(e.target.value)}
            >
              <option value="">Select a unit</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>{unit.name}</option>
              ))}
            </select>
          </label>
          <button type="submit" className="bg-brand-primary text-white px-3 py-1 rounded">Attach</button>
        </form>
      </section>

      {/* Access */}
      <section>
        <h2 className="text-lg font-semibold mb-2">Organization access</h2>
        <form onSubmit={handleGrant} className="flex flex-wrap gap-2 items-end mb-4">
          <label className="flex flex-col text-sm">
            Email
            <input
              className="border border-gray-300 rounded px-2 py-1"
              value={grantEmail}
              onChange={(e) => setGrantEmail(e.target.value)}
            />
          </label>
          <label className="flex flex-col text-sm">
            Unit
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={grantUnit}
              onChange={(e) => setGrantUnit(e.target.value)}
            >
              <option value="">Select a unit</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>{unit.name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col text-sm">
            Role
            <select
              className="border border-gray-300 rounded px-2 py-1"
              value={grantRole}
              onChange={(e) => setGrantRole(e.target.value as OrgRole)}
            >
              {ROLES.map((role) => (
                <option key={role} value={role}>{role}</option>
              ))}
            </select>
          </label>
          <button type="submit" className="bg-brand-primary text-white px-3 py-1 rounded">Grant</button>
        </form>

        {access.length === 0 ? (
          <p className="text-gray-600">Nobody has organization access yet.</p>
        ) : (
          <ul className="border border-gray-200 rounded divide-y">
            {access.map((row) => (
              <li key={row.id} className="flex items-center justify-between px-3 py-2 text-sm">
                <span>
                  {row.first_name} {row.last_name} ({row.email}) — {row.role} on {row.org_unit_name}
                </span>
                <button
                  type="button"
                  className="underline text-red-700"
                  onClick={() => post('org-access-revoke', {
                    user_id: row.user_id,
                    org_unit_id: row.org_unit_id,
                    role: row.role,
                  })}
                >
                  Revoke
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
};

export default Organizations;
