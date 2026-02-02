import React, { useState } from 'react';
import { EvaluationCriterion } from '../types';

interface EvaluationCriteriaBuilderProps {
  criteria: EvaluationCriterion[];
  onChange: (criteria: EvaluationCriterion[]) => void;
  readOnly?: boolean;
}

const EvaluationCriteriaBuilder: React.FC<EvaluationCriteriaBuilderProps> = ({
  criteria,
  onChange,
  readOnly = false
}) => {
  const [draggedIndex, setDraggedIndex] = useState<number | null>(null);
  const [dragOverIndex, setDragOverIndex] = useState<number | null>(null);
  const [editingCriterion, setEditingCriterion] = useState<EvaluationCriterion | null>(null);

  const handleDragStart = (index: number) => {
    setDraggedIndex(index);
  };

  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault();
    setDragOverIndex(index);
  };

  const handleDragLeave = () => {
    setDragOverIndex(null);
  };

  const handleDrop = (e: React.DragEvent, dropIndex: number) => {
    e.preventDefault();
    if (draggedIndex === null || draggedIndex === dropIndex) return;

    const newCriteria = [...criteria];
    const [dragged] = newCriteria.splice(draggedIndex, 1);
    newCriteria.splice(dropIndex, 0, dragged);

    // Update display_order
    newCriteria.forEach((c, i) => {
      c.display_order = i;
    });

    onChange(newCriteria);
    setDraggedIndex(null);
    setDragOverIndex(null);
  };

  const handleAddCriterion = () => {
    const newCriterion: EvaluationCriterion = {
      tempId: `new-${Date.now()}`,
      name: '',
      description: '',
      max_score: 5,
      weight: 1.0,
      display_order: criteria.length
    };
    setEditingCriterion(newCriterion);
  };

  const handleSaveCriterion = (criterion: EvaluationCriterion) => {
    if (!criterion.name.trim()) {
      alert('Please enter a name for the criterion');
      return;
    }

    // Only match by id if both have defined ids, or by tempId if both have tempIds
    const existingIndex = criteria.findIndex(
      c => (c.id !== undefined && criterion.id !== undefined && c.id === criterion.id) ||
           (c.tempId && criterion.tempId && c.tempId === criterion.tempId)
    );

    if (existingIndex >= 0) {
      const newCriteria = [...criteria];
      newCriteria[existingIndex] = criterion;
      onChange(newCriteria);
    } else {
      onChange([...criteria, criterion]);
    }

    setEditingCriterion(null);
  };

  const handleDeleteCriterion = (index: number) => {
    const newCriteria = criteria.filter((_, i) => i !== index);
    newCriteria.forEach((c, i) => {
      c.display_order = i;
    });
    onChange(newCriteria);
  };

  const handleEditCriterion = (criterion: EvaluationCriterion) => {
    setEditingCriterion({ ...criterion });
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h3 className="text-brand-primary font-semibold uppercase">Evaluation Criteria</h3>
        {!readOnly && (
          <button
            type="button"
            onClick={handleAddCriterion}
            className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
          >
            + Add Criterion
          </button>
        )}
      </div>

      <p className="text-sm text-gray-600">
        Define the criteria coaches will use to evaluate athletes. Drag to reorder.
      </p>

      {criteria.length === 0 && (
        <div className="text-center py-8 border-2 border-dashed border-gray-300 rounded-md text-gray-500">
          No evaluation criteria defined. Click "Add Criterion" to get started.
        </div>
      )}

      <div className="space-y-2">
        {criteria.map((criterion, index) => (
          <div key={criterion.id || criterion.tempId}>
            {dragOverIndex === index && (
              <div className="h-1 bg-brand-primary rounded-full mb-1" />
            )}
            <div
              draggable={!readOnly}
              onDragStart={() => handleDragStart(index)}
              onDragOver={(e) => handleDragOver(e, index)}
              onDragLeave={handleDragLeave}
              onDrop={(e) => handleDrop(e, index)}
              className={`bg-gray-50 border rounded-md p-4 ${
                readOnly ? '' : 'cursor-move hover:border-brand-primary'
              } ${draggedIndex === index ? 'opacity-50' : ''} border-brand-secondary`}
            >
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <div className="flex items-center space-x-3">
                    {!readOnly && (
                      <span className="text-gray-400 cursor-grab">&#9776;</span>
                    )}
                    <div>
                      <div className="font-medium text-brand-primary">{criterion.name}</div>
                      {criterion.description && (
                        <div className="text-sm text-gray-500 mt-1">{criterion.description}</div>
                      )}
                    </div>
                  </div>
                </div>
                <div className="flex items-center space-x-4 ml-4">
                  <div className="text-sm text-gray-600">
                    <span className="font-medium">1-{criterion.max_score}</span>
                    <span className="mx-1">|</span>
                    <span className="font-medium">{criterion.weight}x</span>
                  </div>
                  {!readOnly && (
                    <div className="flex space-x-2">
                      <button
                        type="button"
                        onClick={() => handleEditCriterion(criterion)}
                        className="text-brand-primary hover:text-brand-primary-hover text-xs font-semibold uppercase"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteCriterion(index)}
                        className="text-red-600 hover:text-red-500 text-xs font-semibold uppercase"
                      >
                        Delete
                      </button>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Criterion Editor Modal */}
      {editingCriterion && (
        <CriterionEditor
          criterion={editingCriterion}
          onSave={handleSaveCriterion}
          onClose={() => setEditingCriterion(null)}
        />
      )}
    </div>
  );
};

// Criterion Editor Component
interface CriterionEditorProps {
  criterion: EvaluationCriterion;
  onSave: (criterion: EvaluationCriterion) => void;
  onClose: () => void;
}

const CriterionEditor: React.FC<CriterionEditorProps> = ({ criterion, onSave, onClose }) => {
  const [form, setForm] = useState<EvaluationCriterion>({ ...criterion });

  const handleSave = () => {
    onSave(form);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white border border-brand-secondary rounded-md p-6 w-full max-w-md">
        <h3 className="text-brand-primary font-semibold mb-4 uppercase">
          {criterion.id || (criterion.tempId && !criterion.tempId.startsWith('new-'))
            ? 'Edit Criterion'
            : 'Add Criterion'}
        </h3>

        <div className="space-y-4">
          <div>
            <label className="block text-brand-primary text-sm font-medium mb-1">
              Name <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              className="w-full border border-brand-secondary rounded-md px-3 py-2"
              placeholder="e.g., Technical Skills"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </div>

          <div>
            <label className="block text-brand-primary text-sm font-medium mb-1">
              Description
            </label>
            <textarea
              className="w-full border border-brand-secondary rounded-md px-3 py-2"
              rows={2}
              placeholder="e.g., Ball control, passing, shooting accuracy"
              value={form.description || ''}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-1">
                Max Score
              </label>
              <select
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                value={String(form.max_score)}
                onChange={(e) => setForm({ ...form, max_score: parseInt(e.target.value) })}
              >
                <option value="3">1-3</option>
                <option value="5">1-5</option>
                <option value="10">1-10</option>
              </select>
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-1">
                Weight
              </label>
              <select
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                value={String(form.weight)}
                onChange={(e) => setForm({ ...form, weight: parseFloat(e.target.value) })}
              >
                <option value="0.5">0.5x (Low)</option>
                <option value="1">1.0x (Normal)</option>
                <option value="1.5">1.5x (High)</option>
                <option value="2">2.0x (Very High)</option>
              </select>
            </div>
          </div>

          <p className="text-xs text-gray-500">
            Weight affects how much this criterion contributes to the overall score.
            Higher weight = more impact on ranking.
          </p>
        </div>

        <div className="flex justify-end space-x-3 mt-6">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSave}
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-hover"
          >
            Save
          </button>
        </div>
      </div>
    </div>
  );
};

export default EvaluationCriteriaBuilder;
