import React, { useState, useEffect } from 'react';
import { EvaluationCriterion, TryoutRegistration, TryoutEvaluation } from '../types';
import {
  CriterionScoreList,
  ScoringCriterion,
  computeWeightedOverall,
} from '../../../components/evaluations/ScoringForm';

const tryoutAuthHeaders = () => ({
  Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
});

interface EvaluationModalProps {
  registration: TryoutRegistration;
  programId: number;
  evaluatorId: number;
  sessionId?: number;
  onClose: () => void;
  onSave: () => void;
}

const EvaluationModal: React.FC<EvaluationModalProps> = ({
  registration,
  programId,
  evaluatorId,
  sessionId,
  onClose,
  onSave
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [criteria, setCriteria] = useState<EvaluationCriterion[]>([]);
  const [scores, setScores] = useState<Record<string, number>>({});
  const [notes, setNotes] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [existingEvaluation, setExistingEvaluation] = useState<TryoutEvaluation | null>(null);

  useEffect(() => {
    loadData();
  }, [registration.id, programId]);

  const loadData = async () => {
    setLoading(true);
    try {
      // Load criteria and existing evaluation in parallel
      const [criteriaRes, evaluationsRes] = await Promise.all([
        fetch(`${API_URL}/registration/tryouts-api.php?path=criteria&program_id=${programId}`, { headers: tryoutAuthHeaders() }),
        fetch(`${API_URL}/registration/tryouts-api.php?path=evaluations&registration_id=${registration.id}`, { headers: tryoutAuthHeaders() })
      ]);

      const criteriaData = await criteriaRes.json();
      const evaluationsData = await evaluationsRes.json();

      setCriteria(criteriaData);

      // Check if this evaluator already has an evaluation
      const myEvaluation = evaluationsData.find(
        (e: TryoutEvaluation) => e.evaluator_id === evaluatorId
      );

      if (myEvaluation) {
        setExistingEvaluation(myEvaluation);
        setScores(myEvaluation.scores || {});
        setNotes(myEvaluation.notes || '');
      } else {
        // Initialize with empty scores
        const initialScores: Record<string, number> = {};
        criteriaData.forEach((c: EvaluationCriterion) => {
          if (c.id) initialScores[c.id.toString()] = 0;
        });
        setScores(initialScores);
      }
    } catch (error) {
      console.error('Error loading evaluation data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleScoreChange = (key: string, score: number) => {
    setScores({ ...scores, [key]: score });
  };

  // The shared scoring form is keyed by an opaque string. Tryout evaluations key
  // their scores by criterion ID (that is what tryout_evaluations.scores stores),
  // so the key here is the id — the payload shape is unchanged.
  const scoringCriteria: ScoringCriterion[] = criteria.map((c) => ({
    key: c.id!.toString(),
    name: c.name,
    description: c.description,
    max_score: c.max_score,
    weight: c.weight,
  }));

  const handleSubmit = async () => {
    // Validate all criteria have scores
    const missingScores = criteria.filter(c => !scores[c.id!.toString()] || scores[c.id!.toString()] === 0);
    if (missingScores.length > 0) {
      alert('Please provide scores for all criteria');
      return;
    }

    setSaving(true);
    try {
      const response = await fetch(`${API_URL}/registration/tryouts-api.php?path=evaluate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...tryoutAuthHeaders() },
        body: JSON.stringify({
          registration_id: registration.id,
          evaluator_id: evaluatorId,
          session_id: sessionId,
          scores,
          notes
        })
      });

      const data = await response.json();

      if (response.ok && data.success) {
        onSave();
      } else {
        alert(data.error || 'Failed to save evaluation');
      }
    } catch (error) {
      console.error('Error saving evaluation:', error);
      alert('An error occurred while saving the evaluation');
    } finally {
      setSaving(false);
    }
  };

  // Shared with the mid-year athlete evaluation modal so the same sheet cannot
  // preview two different numbers. Behaviour is byte-for-byte what this function
  // used to do; see computeWeightedOverall.
  const calculatePreviewScore = () => computeWeightedOverall(scoringCriteria, scores);

  if (loading) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div className="bg-white border border-brand-secondary rounded-md p-8">
          <div className="text-brand-primary">Loading evaluation form...</div>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <div>
            <h2 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
              Evaluate Athlete
            </h2>
            <p className="text-gray-600 mt-1">
              {registration.first_name} {registration.last_name}
            </p>
          </div>
          <button
            onClick={onClose}
            className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
          >
            &times;
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {existingEvaluation && (
            <div className="mb-4 p-3 bg-brand-light/50 border border-brand-secondary text-brand-primary-dark rounded-md text-sm">
              You have already submitted an evaluation for this athlete.
              Updating will overwrite your previous scores.
            </div>
          )}

          {/* Score Preview */}
          <div className="mb-6 p-4 bg-gray-50 rounded-md border border-brand-secondary">
            <div className="flex justify-between items-center">
              <span className="text-brand-primary font-medium">Overall Score Preview</span>
              <span className="text-2xl font-bold text-brand-primary">
                {calculatePreviewScore()}
              </span>
            </div>
          </div>

          {/* Criteria Scoring */}
          <CriterionScoreList
            criteria={scoringCriteria}
            scores={scores}
            onScoreChange={handleScoreChange}
          />

          {/* Notes */}
          <div className="mt-6">
            <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
              Notes
            </label>
            <textarea
              className="w-full border border-brand-secondary rounded-md px-4 py-2"
              rows={3}
              placeholder="Additional observations about this athlete..."
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>
        </div>

        {/* Footer */}
        <div className="border-t border-brand-secondary px-6 py-4 flex justify-end space-x-3">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={saving}
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-hover font-semibold uppercase disabled:opacity-50"
          >
            {saving ? 'Saving...' : existingEvaluation ? 'Update Evaluation' : 'Submit Evaluation'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default EvaluationModal;
