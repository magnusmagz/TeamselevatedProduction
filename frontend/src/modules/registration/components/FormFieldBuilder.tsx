import React, { useState, useEffect } from 'react';
import { FormField, FieldType, DragDropField } from '../types';

interface FormFieldBuilderProps {
  programId?: number;
  onSave?: (fields: FormField[]) => void;
}

const FormFieldBuilder: React.FC<FormFieldBuilderProps> = ({ programId, onSave }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [fields, setFields] = useState<DragDropField[]>([]);
  const [draggedField, setDraggedField] = useState<DragDropField | null>(null);
  const [draggedOverIndex, setDraggedOverIndex] = useState<number | null>(null);
  const [editingField, setEditingField] = useState<DragDropField | null>(null);

  // Available field types to drag from
  const availableFieldTypes: { type: FieldType; label: string }[] = [
    { type: 'text', label: 'Text Input' },
    { type: 'email', label: 'Email' },
    { type: 'tel', label: 'Phone' },
    { type: 'number', label: 'Number' },
    { type: 'date', label: 'Date' },
    { type: 'select', label: 'Dropdown' },
    { type: 'checkbox', label: 'Checkbox' },
    { type: 'radio', label: 'Radio Button' },
    { type: 'textarea', label: 'Text Area' }
  ];

  // Sections for organizing fields
  const sections = [
    'athlete_info',
    'parent_info',
    'emergency',
    'medical',
    'additional',
    'waiver'
  ];

  useEffect(() => {
    // Load existing fields if editing
    if (programId) {
      loadExistingFields();
    } else {
      // Load default fields for new programs
      setFields(getDefaultFields());
    }
  }, [programId]);

  const getDefaultFields = (): DragDropField[] => {
    return [
      {
        tempId: '1',
        field_name: 'athlete_first',
        field_label: 'Athlete First',
        field_type: 'text',
        required: true,
        section: 'athlete_info',
        display_order: 0
      },
      {
        tempId: '2',
        field_name: 'athlete_last',
        field_label: 'Athlete Last',
        field_type: 'text',
        required: true,
        section: 'athlete_info',
        display_order: 1
      },
      {
        tempId: '3',
        field_name: 'athlete_birthday',
        field_label: 'Athlete Birthday',
        field_type: 'date',
        required: true,
        section: 'athlete_info',
        display_order: 2
      },
      {
        tempId: '4',
        field_name: 'athlete_gender',
        field_label: 'Athlete Gender',
        field_type: 'select',
        required: true,
        section: 'athlete_info',
        display_order: 3,
        options: ['Male', 'Female', 'Non-binary', 'Prefer not to say']
      },
      {
        tempId: '5',
        field_name: 'athlete_grade',
        field_label: 'Athlete Grade',
        field_type: 'select',
        required: true,
        section: 'athlete_info',
        display_order: 4,
        options: ['Pre-K', 'Kindergarten', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th']
      },
      {
        tempId: '6',
        field_name: 'guardian_first',
        field_label: 'Crew First',
        field_type: 'text',
        required: true,
        section: 'parent_info',
        display_order: 5
      },
      {
        tempId: '7',
        field_name: 'guardian_last',
        field_label: 'Crew Last',
        field_type: 'text',
        required: true,
        section: 'parent_info',
        display_order: 6
      },
      {
        tempId: '8',
        field_name: 'guardian_email',
        field_label: 'Crew Email',
        field_type: 'email',
        required: true,
        section: 'parent_info',
        display_order: 7
      },
      {
        tempId: '9',
        field_name: 'mobile_phone',
        field_label: 'Mobile Phone',
        field_type: 'tel',
        required: true,
        section: 'parent_info',
        display_order: 8
      }
    ];
  };

  const loadExistingFields = async () => {
    try {
      const response = await fetch(`${API_URL}/registration/programs-api.php?path=details&id=${programId}`, {
        headers: { 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` }
      });
      const data = await response.json();
      if (data.form_fields && data.form_fields.length > 0) {
        setFields(data.form_fields.map((f: FormField, i: number) => ({
          ...f,
          tempId: `existing-${i}`
        })));
      } else {
        // No existing fields, load defaults
        setFields(getDefaultFields());
      }
    } catch (error) {
      console.error('Error loading fields:', error);
      // On error, load defaults
      setFields(getDefaultFields());
    }
  };

  const generateFieldName = (fieldType: FieldType): string => {
    // Create base name from field type
    const baseName = fieldType === 'tel' ? 'phone_field' : `${fieldType}_field`;

    // Check if this name already exists
    const existingNames = fields.map(f => f.field_name);

    // If base name doesn't exist, use it
    if (!existingNames.includes(baseName)) {
      return baseName;
    }

    // Otherwise, find the next available number
    let counter = 1;
    while (existingNames.includes(`${baseName}_${counter}`)) {
      counter++;
    }
    return `${baseName}_${counter}`;
  };

  const handleDragStart = (e: React.DragEvent, field: DragDropField | { type: FieldType; label: string }) => {
    if ('field_name' in field) {
      // Dragging existing field
      setDraggedField(field);
    } else {
      // Dragging new field type from palette
      const newField: DragDropField = {
        tempId: `new-${Date.now()}`,
        field_name: generateFieldName(field.type),
        field_label: field.label,
        field_type: field.type,
        required: false,
        section: 'additional',
        display_order: fields.length
      };
      setDraggedField(newField);
    }
  };

  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault();
    setDraggedOverIndex(index);
  };

  const handleDragLeave = () => {
    setDraggedOverIndex(null);
  };

  const handleDrop = (e: React.DragEvent, dropIndex: number) => {
    e.preventDefault();
    if (!draggedField) return;

    const newFields = [...fields];

    // Check if it's a reorder or a new field
    const existingIndex = fields.findIndex(f => f.tempId === draggedField.tempId);

    if (existingIndex >= 0) {
      // Reordering existing field
      newFields.splice(existingIndex, 1);
      newFields.splice(dropIndex, 0, draggedField);
    } else {
      // Adding new field
      newFields.splice(dropIndex, 0, draggedField);
    }

    // Update display_order
    newFields.forEach((field, index) => {
      field.display_order = index;
    });

    setFields(newFields);
    setDraggedField(null);
    setDraggedOverIndex(null);
  };

  const handleDropOnEnd = (e: React.DragEvent) => {
    e.preventDefault();
    if (!draggedField) return;

    const existingIndex = fields.findIndex(f => f.tempId === draggedField.tempId);

    if (existingIndex < 0) {
      // Adding new field at the end
      setFields([...fields, { ...draggedField, display_order: fields.length }]);
    }

    setDraggedField(null);
    setDraggedOverIndex(null);
  };

  const handleFieldEdit = (field: DragDropField) => {
    setEditingField(field);
  };

  const handleFieldUpdate = (updatedField: DragDropField) => {
    setFields(fields.map(f => f.tempId === updatedField.tempId ? updatedField : f));
    setEditingField(null);
  };

  const handleFieldDelete = (tempId: string) => {
    setFields(fields.filter(f => f.tempId !== tempId));
  };

  const handleSaveFields = async () => {
    if (!programId) {
      alert('No program ID found. Please save the program details first.');
      return;
    }

    console.log('Saving fields for program:', programId);
    console.log('Fields to save:', fields);

    try {
      const payload = {
        program_id: programId,
        fields: fields.map(({ tempId, ...field }) => field)
      };

      console.log('Sending payload:', payload);

      const response = await fetch(`${API_URL}/registration/programs-api.php?path=update-fields`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` },
        body: JSON.stringify(payload)
      });

      console.log('Response status:', response.status);
      const data = await response.json();
      console.log('Response data:', data);

      if (response.ok) {
        alert('Form configuration saved successfully!');
        if (onSave) {
          onSave(fields);
        }
      } else {
        console.error('Server error:', data);
        alert(`Failed to save: ${data.error || 'Unknown error'}`);
      }
    } catch (error) {
      console.error('Error saving fields:', error);
      alert('An error occurred while saving. Please check the console for details.');
    }
  };

  const getSectionLabel = (section: string) => {
    return section.split('_').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join(' ');
  };


  return (
    <div className="flex space-x-6">
      {/* Left Panel - Available Fields */}
      <div className="w-64 bg-gray-50 border border-brand-secondary rounded-md p-4">
        <h3 className="text-brand-primary font-semibold mb-4 uppercase">Available Fields</h3>
        <div className="space-y-2">
          {availableFieldTypes.map((fieldType) => (
            <div
              key={fieldType.type}
              draggable
              onDragStart={(e) => handleDragStart(e, fieldType)}
              className="bg-white border border-brand-secondary rounded-md p-3 cursor-move hover:bg-gray-100"
            >
              <span className="text-brand-primary">{fieldType.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Middle Panel - Form Canvas */}
      <div className="flex-1 bg-white border border-brand-secondary rounded-md p-6">
        <h3 className="text-brand-primary font-semibold mb-4 uppercase">Registration Form</h3>

        <div
          className="min-h-[400px] space-y-2"
          onDragOver={(e) => e.preventDefault()}
          onDrop={handleDropOnEnd}
        >
          {fields.length === 0 && (
            <div className="text-gray-500 text-center py-12 border-2 border-dashed border-gray-300">
              Drag fields here to build your form
            </div>
          )}

          {fields.map((field, index) => (
            <div key={field.tempId}>
              {draggedOverIndex === index && (
                <div className="h-2 bg-brand-primary opacity-50"></div>
              )}

              <div
                draggable
                onDragStart={(e) => handleDragStart(e, field)}
                onDragOver={(e) => handleDragOver(e, index)}
                onDragLeave={handleDragLeave}
                onDrop={(e) => handleDrop(e, index)}
                className={`bg-gray-50 border rounded-md ${
                  field.required ? 'border-brand-accent' : 'border-brand-secondary'
                } p-4 cursor-move hover:border-brand-primary flex items-center justify-between`}
              >
                <div>
                  <div className="font-medium text-brand-primary">
                      {field.field_label}
                      {field.required && <span className="text-red-500 ml-1">*</span>}
                    </div>
                    <div className="text-xs text-gray-500">
                      {field.field_name} • {getSectionLabel(field.section || 'general')}
                    </div>
                </div>

                <div className="flex space-x-2">
                  <button
                    onClick={() => handleFieldEdit(field)}
                    className="text-brand-primary hover:text-brand-primary-hover px-2 uppercase text-xs font-semibold"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => handleFieldDelete(field.tempId!)}
                    className="text-red-600 hover:text-red-500 px-2 uppercase text-xs font-semibold"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>
          ))}

          {draggedOverIndex === fields.length && (
            <div className="h-2 bg-brand-primary opacity-50"></div>
          )}
        </div>

        {programId && (
          <div className="mt-6 flex justify-end">
            <button
              onClick={handleSaveFields}
              className="bg-brand-primary text-white px-6 py-2 rounded-md hover:bg-brand-primary uppercase font-semibold"
            >
              Save Form Configuration
            </button>
          </div>
        )}
      </div>

      {/* Field Editor Modal */}
      {editingField && (
        <FieldEditor
          field={editingField}
          onUpdate={handleFieldUpdate}
          onClose={() => setEditingField(null)}
        />
      )}
    </div>
  );
};

// Field Editor Component
const FieldEditor: React.FC<{
  field: DragDropField;
  onUpdate: (field: DragDropField) => void;
  onClose: () => void;
}> = ({ field, onUpdate, onClose }) => {
  const [editedField, setEditedField] = useState<DragDropField>({ ...field });

  const handleSave = () => {
    onUpdate(editedField);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white border border-brand-secondary rounded-md p-6 w-96">
        <h3 className="text-brand-primary font-semibold mb-4 uppercase">Edit Field</h3>

        <div className="space-y-4">
          <div>
            <label className="block text-brand-primary text-sm font-medium mb-1">Field Label</label>
            <input
              type="text"
              className="w-full border border-brand-secondary rounded-md px-3 py-2"
              value={editedField.field_label}
              onChange={(e) => setEditedField({ ...editedField, field_label: e.target.value })}
            />
          </div>

          <div>
            <label className="block text-brand-primary text-sm font-medium mb-1">Field Name</label>
            <input
              type="text"
              className="w-full border border-brand-secondary rounded-md px-3 py-2"
              value={editedField.field_name}
              onChange={(e) => setEditedField({ ...editedField, field_name: e.target.value })}
            />
          </div>

          <div>
            <label className="block text-brand-primary text-sm font-medium mb-1">Section</label>
            <select
              className="w-full border border-brand-secondary rounded-md px-3 py-2"
              value={editedField.section || 'general'}
              onChange={(e) => setEditedField({ ...editedField, section: e.target.value })}
            >
              <option value="athlete_info">Athlete Info</option>
              <option value="parent_info">Parent Info</option>
              <option value="emergency">Emergency</option>
              <option value="medical">Medical</option>
              <option value="additional">Additional</option>
              <option value="waiver">Waiver</option>
            </select>
          </div>

          <div>
            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={editedField.required}
                onChange={(e) => setEditedField({ ...editedField, required: e.target.checked })}
              />
              <span className="text-brand-primary">Required Field</span>
            </label>
          </div>

          {(editedField.field_type === 'select' || editedField.field_type === 'radio') && (
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-1">Options (one per line)</label>
              <textarea
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                rows={3}
                placeholder="Option 1&#10;Option 2&#10;Option 3"
                value={Array.isArray(editedField.options) ? editedField.options.join('\n') : ''}
                onChange={(e) => setEditedField({
                  ...editedField,
                  options: e.target.value.split('\n').filter(o => o.trim())
                })}
              />
            </div>
          )}
        </div>

        <div className="flex justify-end space-x-3 mt-6">
          <button
            onClick={onClose}
            className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary"
          >
            Save
          </button>
        </div>
      </div>
    </div>
  );
};

export default FormFieldBuilder;