import React, { useState, useEffect, useMemo } from 'react';
import { TournamentDivision, SportPreset, DivisionFormat, Gender, ScoringSystem, CompetitiveLevel, COMPETITIVE_LEVEL_LABELS, OvertimeMode, OvertimeRules, OVERTIME_MODE_LABELS } from '../types';
import { getSportPresets } from '../api/tournamentApi';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  tournamentId: number;
  sport: string;
  division: TournamentDivision | null; // null = create mode
  onSave: () => void;
  onCancel: () => void;
}

// Fallback list shown only if the sport_presets API call fails entirely.
// Authoritative list lives in the sport_presets table; see migration 035.
const FALLBACK_AGE_GROUPS = ['U5', 'U6', 'U7', 'U8', 'U9', 'U10', 'U11', 'U12', 'U13', 'U14', 'U15', 'U16', 'U17', 'U18', 'U19', 'U20', 'Adult'];
const FORMATS: { value: DivisionFormat; label: string }[] = [
  { value: 'group_knockout', label: 'Group Stage + Knockout' },
  { value: 'round_robin', label: 'Round Robin' },
  { value: 'single_elimination', label: 'Single Elimination' },
  { value: 'double_elimination', label: 'Double Elimination' },
];

const DEFAULT_TIEBREAKERS = ['points', 'head_to_head', 'goal_difference', 'goals_for', 'goals_against', 'wins', 'penalty_shootout', 'coin_flip'];
const TIEBREAKER_LABELS: Record<string, string> = {
  points: 'Points',
  head_to_head: 'Head-to-Head',
  goal_difference: 'Goal Difference',
  goals_for: 'Goals For',
  goals_against: 'Goals Against (fewest)',
  wins: 'Most Wins',
  penalty_shootout: 'Penalty Shootout',
  coin_flip: 'Coin Flip',
};

const DivisionForm: React.FC<Props> = ({ tournamentId, sport, division, onSave, onCancel }) => {
  const isEdit = Boolean(division);

  const [presets, setPresets] = useState<SportPreset[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  // Form state
  const [name, setName] = useState(division?.name || '');
  const [ageGroup, setAgeGroup] = useState(division?.age_group || '');
  const [gender, setGender] = useState<Gender>(division?.gender || 'coed');
  const [format, setFormat] = useState<DivisionFormat>(division?.format || 'group_knockout');
  const [gameDuration, setGameDuration] = useState(division?.game_duration_minutes || 50);
  const [halfDuration, setHalfDuration] = useState(division?.half_duration_minutes || 25);
  const [maxRoster, setMaxRoster] = useState(division?.max_roster_size || 18);
  const [minRoster, setMinRoster] = useState(division?.min_roster_size || 7);
  const [maxTeams, setMaxTeams] = useState<number | ''>(division?.max_teams || '');
  const [teamsPerGroup, setTeamsPerGroup] = useState(division?.teams_per_group || 4);
  const [teamsAdvancing, setTeamsAdvancing] = useState(division?.teams_advancing_per_group || 2);
  const [goalDiffCap, setGoalDiffCap] = useState<number | ''>(division?.goal_differential_cap || '');
  const [scoringSystem, setScoringSystem] = useState<ScoringSystem>(division?.scoring_system || 'standard');
  const [competitiveLevel, setCompetitiveLevel] = useState<CompetitiveLevel | ''>(division?.competitive_level || '');
  const [pointsWin, setPointsWin] = useState(division?.points_for_win || 3);
  const [pointsDraw, setPointsDraw] = useState(division?.points_for_draw || 1);
  const [pointsLoss, setPointsLoss] = useState(division?.points_for_loss || 0);
  const [maxOnField, setMaxOnField] = useState<number | ''>(division?.max_players_on_field || '');
  const [ruleNotes, setRuleNotes] = useState<string[]>(division?.sport_rule_notes || []);
  const [tiebreakers, setTiebreakers] = useState<string[]>(division?.tiebreaker_rules || DEFAULT_TIEBREAKERS);
  const [otMode, setOtMode] = useState<OvertimeMode>(division?.overtime_rules?.mode || 'none');
  const [otPeriods, setOtPeriods] = useState<number>(division?.overtime_rules?.ot_periods ?? 2);
  const [otMinutes, setOtMinutes] = useState<number>(division?.overtime_rules?.ot_minutes_per_period ?? 5);
  const [otGoldenGoal, setOtGoldenGoal] = useState<boolean>(!!division?.overtime_rules?.golden_goal);
  const [maxGuestPlayers, setMaxGuestPlayers] = useState<number | ''>(
    division?.max_guest_players ?? ''
  );
  const [guestMustBeSameClub, setGuestMustBeSameClub] = useState<boolean>(
    !!division?.guest_must_be_same_club
  );

  // Load presets
  useEffect(() => {
    getSportPresets(sport)
      .then((data) => setPresets(data.presets || []))
      .catch(() => {});
  }, [sport]);

  // U-bracket numeric ascending, then non-numeric (Adult, Senior, etc.) trailing.
  const ageGroupOptions = useMemo(() => {
    const fromPresets = presets.map((p) => p.age_group).filter(Boolean);
    const list = fromPresets.length > 0 ? fromPresets : FALLBACK_AGE_GROUPS;
    return Array.from(new Set(list)).sort((a, b) => {
      const an = parseInt(a.replace(/[^0-9]/g, ''), 10);
      const bn = parseInt(b.replace(/[^0-9]/g, ''), 10);
      const aIsNum = !isNaN(an);
      const bIsNum = !isNaN(bn);
      if (aIsNum && bIsNum) return an - bn;
      if (aIsNum) return -1;
      if (bIsNum) return 1;
      return a.localeCompare(b);
    });
  }, [presets]);

  // Auto-fill from preset when age group changes (only in create mode)
  useEffect(() => {
    if (isEdit || !ageGroup || presets.length === 0) return;

    const preset = presets.find((p) => p.age_group === ageGroup);
    if (preset) {
      setGameDuration(preset.game_duration_minutes);
      setHalfDuration(preset.half_duration_minutes);
      setMaxRoster(preset.max_roster_size);
      setMinRoster(preset.min_roster_size);
      setMaxOnField(preset.max_players_on_field);
      const notes = typeof preset.rule_notes === 'string'
        ? JSON.parse(preset.rule_notes)
        : preset.rule_notes;
      setRuleNotes(notes || []);

      // Auto-generate name if empty
      if (!name) {
        setName(`${ageGroup} ${gender === 'coed' ? '' : gender.charAt(0).toUpperCase() + gender.slice(1)}`
          .trim());
      }
    }
  }, [ageGroup, presets, isEdit]);

  const moveTiebreaker = (index: number, direction: -1 | 1) => {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= tiebreakers.length) return;
    const updated = [...tiebreakers];
    [updated[index], updated[newIndex]] = [updated[newIndex], updated[index]];
    setTiebreakers(updated);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !ageGroup) {
      setError('Name and age group are required');
      return;
    }

    setSaving(true);
    setError('');

    // Backend re-locks point values when scoring_system === 'ten_point';
    // sending the user's last typed standard values is harmless either way.
    const body = {
      name: name.trim(),
      age_group: ageGroup,
      gender,
      format,
      game_duration_minutes: gameDuration,
      half_duration_minutes: halfDuration,
      max_roster_size: maxRoster,
      min_roster_size: minRoster,
      max_teams: maxTeams || null,
      teams_per_group: teamsPerGroup,
      teams_advancing_per_group: teamsAdvancing,
      goal_differential_cap: goalDiffCap || null,
      scoring_system: scoringSystem,
      competitive_level: competitiveLevel || null,
      points_for_win: scoringSystem === 'ten_point' ? 6 : pointsWin,
      points_for_draw: scoringSystem === 'ten_point' ? 3 : pointsDraw,
      points_for_loss: scoringSystem === 'ten_point' ? 0 : pointsLoss,
      max_players_on_field: maxOnField || null,
      sport_rule_notes: ruleNotes.length > 0 ? ruleNotes : null,
      tiebreaker_rules: tiebreakers,
      overtime_rules: ((): OvertimeRules | null => {
        if (otMode === 'none') return { mode: 'none' };
        if (otMode === 'pks_only') return { mode: 'pks_only' };
        return {
          mode: 'overtime_then_pks',
          ot_periods: otPeriods,
          ot_minutes_per_period: otMinutes,
          golden_goal: otGoldenGoal,
        };
      })(),
      max_guest_players: maxGuestPlayers === '' ? null : Number(maxGuestPlayers),
      guest_must_be_same_club: guestMustBeSameClub,
    };

    const token = localStorage.getItem('auth_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    };

    try {
      const url = isEdit
        ? `${API_URL}/api/tournament-gateway.php?action=division-update&id=${division!.id}`
        : `${API_URL}/api/tournament-gateway.php?action=division-create&tournament_id=${tournamentId}`;

      const res = await fetch(url, {
        method: isEdit ? 'PUT' : 'POST',
        headers,
        body: JSON.stringify(body),
      });

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Failed to save division');
      }

      onSave();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const showGroupFields = format === 'group_knockout' || format === 'round_robin';

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="flex justify-between items-center">
        <h3 className="text-lg font-semibold text-gray-900">
          {isEdit ? 'Edit Division' : 'Add Division'}
        </h3>
        <button type="button" onClick={onCancel} className="text-sm text-gray-500 hover:text-gray-700">
          Cancel
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>
      )}

      {/* Basic Info */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Age Group *</label>
            <select
              value={ageGroup}
              onChange={(e) => setAgeGroup(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            >
              <option value="">Select...</option>
              {ageGroupOptions.map((ag) => (
                <option key={ag} value={ag}>{ag}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Gender</label>
            <select
              value={gender}
              onChange={(e) => setGender(e.target.value as Gender)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            >
              <option value="boys">Boys</option>
              <option value="girls">Girls</option>
              <option value="coed">Coed</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Division Name *</label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              placeholder="U12 Boys Gold"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Competitive level</label>
            <select
              value={competitiveLevel}
              onChange={(e) => setCompetitiveLevel(e.target.value as CompetitiveLevel | '')}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            >
              <option value="">— Not specified —</option>
              {(Object.keys(COMPETITIVE_LEVEL_LABELS) as CompetitiveLevel[]).map((lvl) => (
                <option key={lvl} value={lvl}>{COMPETITIVE_LEVEL_LABELS[lvl]}</option>
              ))}
            </select>
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Format</label>
          <select
            value={format}
            onChange={(e) => setFormat(e.target.value as DivisionFormat)}
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
          >
            {FORMATS.map((f) => (
              <option key={f.value} value={f.value}>{f.label}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Game Settings (auto-filled from preset) */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
        <h4 className="text-sm font-semibold text-gray-900">
          Game Settings
          {!isEdit && ageGroup && (
            <span className="ml-2 text-xs font-normal text-green-600">Auto-filled from {sport} {ageGroup} preset</span>
          )}
        </h4>

        <div className="grid grid-cols-4 gap-4">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Game Duration (min)</label>
            <input type="number" value={gameDuration} onChange={(e) => setGameDuration(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Half Duration (min)</label>
            <input type="number" value={halfDuration} onChange={(e) => setHalfDuration(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Players on Field</label>
            <input type="number" value={maxOnField} onChange={(e) => setMaxOnField(e.target.value ? Number(e.target.value) : '')} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" placeholder="11" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Goal Diff Cap</label>
            <input type="number" value={goalDiffCap} onChange={(e) => setGoalDiffCap(e.target.value ? Number(e.target.value) : '')} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" placeholder="No cap" />
          </div>
        </div>

        <div className="grid grid-cols-4 gap-4">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Min Roster</label>
            <input type="number" value={minRoster} onChange={(e) => setMinRoster(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Max Roster</label>
            <input type="number" value={maxRoster} onChange={(e) => setMaxRoster(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Max Teams</label>
            <input type="number" value={maxTeams} onChange={(e) => setMaxTeams(e.target.value ? Number(e.target.value) : '')} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" placeholder="Unlimited" />
          </div>
          {showGroupFields && (
            <div>
              <label className="block text-xs text-gray-500 mb-1">Teams per Group</label>
              <input type="number" value={teamsPerGroup} onChange={(e) => setTeamsPerGroup(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>
          )}
        </div>

        {showGroupFields && (
          <div className="grid grid-cols-4 gap-4">
            <div>
              <label className="block text-xs text-gray-500 mb-1">Teams Advancing</label>
              <input type="number" value={teamsAdvancing} onChange={(e) => setTeamsAdvancing(Number(e.target.value))} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>
          </div>
        )}

        {/* Rule Notes */}
        {ruleNotes.length > 0 && (
          <div>
            <label className="block text-xs text-gray-500 mb-1">Rule Notes</label>
            <div className="flex flex-wrap gap-1">
              {ruleNotes.map((note, i) => (
                <span key={i} className="inline-flex items-center px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                  {note}
                  <button
                    type="button"
                    onClick={() => setRuleNotes(ruleNotes.filter((_, j) => j !== i))}
                    className="ml-1 text-yellow-600 hover:text-yellow-900"
                  >
                    &times;
                  </button>
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Points System */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
        <h4 className="text-sm font-semibold text-gray-900">Points System</h4>

        <div>
          <label className="block text-xs text-gray-500 mb-1">Scoring system</label>
          <select
            value={scoringSystem}
            onChange={(e) => setScoringSystem(e.target.value as ScoringSystem)}
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
          >
            <option value="standard">Standard (3 win / 1 draw / 0 loss)</option>
            <option value="ten_point">10-Point Cup System (6/3/0 + goal &amp; shutout bonuses)</option>
          </select>
          {scoringSystem === 'ten_point' && (
            <p className="text-xs text-gray-500 mt-2">
              <strong>10-point system:</strong> 6 for a win, 3 for a tie, 0 for a loss, +1 per goal scored (capped at 3 per game), +1 for a shutout (any result, including 0-0). Maximum 10 points per game.
            </p>
          )}
        </div>

        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Win</label>
            <input
              type="number"
              value={scoringSystem === 'ten_point' ? 6 : pointsWin}
              onChange={(e) => setPointsWin(Number(e.target.value))}
              disabled={scoringSystem === 'ten_point'}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-500"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Draw</label>
            <input
              type="number"
              value={scoringSystem === 'ten_point' ? 3 : pointsDraw}
              onChange={(e) => setPointsDraw(Number(e.target.value))}
              disabled={scoringSystem === 'ten_point'}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-500"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Loss</label>
            <input
              type="number"
              value={scoringSystem === 'ten_point' ? 0 : pointsLoss}
              onChange={(e) => setPointsLoss(Number(e.target.value))}
              disabled={scoringSystem === 'ten_point'}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-500"
            />
          </div>
        </div>
      </div>

      {/* Tiebreaker Order */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3">
        <h4 className="text-sm font-semibold text-gray-900">Tiebreaker Order</h4>
        <p className="text-xs text-gray-500">Drag to reorder. Applied top to bottom when teams are tied on points.</p>
        <div className="space-y-1">
          {tiebreakers.map((tb, i) => (
            <div key={tb} className="flex items-center space-x-2 bg-gray-50 rounded px-3 py-2">
              <span className="text-xs text-gray-400 w-5">{i + 1}.</span>
              <span className="flex-1 text-sm text-gray-900">{TIEBREAKER_LABELS[tb] || tb}</span>
              <button type="button" onClick={() => moveTiebreaker(i, -1)} disabled={i === 0} className="text-gray-400 hover:text-gray-600 disabled:opacity-30 text-xs">
                &#9650;
              </button>
              <button type="button" onClick={() => moveTiebreaker(i, 1)} disabled={i === tiebreakers.length - 1} className="text-gray-400 hover:text-gray-600 disabled:opacity-30 text-xs">
                &#9660;
              </button>
            </div>
          ))}
        </div>
      </div>

      {/* Overtime / tiebreaker resolution */}
      {format !== 'round_robin' && (
        <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3">
          <h4 className="text-sm font-semibold text-gray-900">Overtime Rules</h4>
          <p className="text-xs text-gray-500">
            How knockout matches resolve when tied at full time. Round-robin is unaffected (ties stand and go to standings tiebreakers).
          </p>

          <div>
            <label className="block text-xs text-gray-500 mb-1">Mode</label>
            <select
              value={otMode}
              onChange={(e) => setOtMode(e.target.value as OvertimeMode)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            >
              {(Object.keys(OVERTIME_MODE_LABELS) as OvertimeMode[]).map((m) => (
                <option key={m} value={m}>{OVERTIME_MODE_LABELS[m]}</option>
              ))}
            </select>
          </div>

          {otMode === 'overtime_then_pks' && (
            <>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-gray-500 mb-1">OT periods</label>
                  <input
                    type="number" min={1} max={4}
                    value={otPeriods}
                    onChange={(e) => setOtPeriods(parseInt(e.target.value, 10) || 2)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-xs text-gray-500 mb-1">Minutes per period</label>
                  <input
                    type="number" min={1} max={30}
                    value={otMinutes}
                    onChange={(e) => setOtMinutes(parseInt(e.target.value, 10) || 5)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                  />
                </div>
              </div>
              <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={otGoldenGoal}
                  onChange={(e) => setOtGoldenGoal(e.target.checked)}
                  className="rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
                />
                Golden goal — first to score wins
              </label>
            </>
          )}
        </div>
      )}

      {/* Guest Player Policy */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3">
        <h4 className="text-sm font-semibold text-gray-900">Guest Player Policy</h4>
        <p className="text-xs text-gray-500">
          Optional limits on guest players added to a roster. Existing rules
          (age eligibility, sanctioning, competitive level, roster size) apply
          to all players including guests; these two are guest-specific.
        </p>

        <div>
          <label className="block text-xs text-gray-500 mb-1">Max guest players per team</label>
          <input
            type="number"
            min={0}
            value={maxGuestPlayers}
            onChange={(e) => setMaxGuestPlayers(e.target.value === '' ? '' : Math.max(0, parseInt(e.target.value, 10) || 0))}
            placeholder="No limit"
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
          />
          <p className="text-xs text-gray-400 mt-1">Leave blank for no cap.</p>
        </div>

        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={guestMustBeSameClub}
            onChange={(e) => setGuestMustBeSameClub(e.target.checked)}
            className="rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
          />
          Guest players must be from the same club as the registering team
        </label>
      </div>

      {/* Actions */}
      <div className="flex justify-end space-x-3">
        <button type="button" onClick={onCancel} className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
          {saving ? 'Saving...' : isEdit ? 'Save Changes' : 'Add Division'}
        </button>
      </div>
    </form>
  );
};

export default DivisionForm;
