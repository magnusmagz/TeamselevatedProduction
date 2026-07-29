import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';
import EmailEditor, { EditorRef, EmailEditorProps } from 'react-email-editor';
import { useTheme } from '../contexts/ThemeContext';

// Compose modal is loaded on demand (same pattern as CommunicationLog/History):
// keeps it out of the editor's initial chunk and avoids eager-import init issues.
const EmailCompose = React.lazy(() =>
  import('../components/communications/EmailCompose').then((m) => ({ default: m.EmailCompose }))
);

interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  category: string;
  scope: 'club' | 'personal';
  team_visibility: number[];
  is_active: boolean;
  created_by: number;
  design_json: object;
  html_output: string;
  updated_at: string;
  created_at: string;
}

interface MergeField {
  key: string;
  name: string;
  value: string;
  sample?: string;
}

interface Team {
  id: number;
  name: string;
}

interface Branding {
  club_name: string;
  has_logo: boolean;
  header_html: string;
  footer_html: string;
}

/**
 * The club logo header and footer are applied at send time by
 * lib/EmailBranding.php — they are deliberately not part of any template, since
 * one platform template serves every club. To make that visible while editing,
 * they are injected into the design as display-only rows here and stripped again
 * before the design, the exported HTML, a preview, or a send leaves this page.
 * Nothing below is ever persisted.
 */
const BRAND_ROW_IDS = ['te_brand_header_row', 'te_brand_footer_row'];
const BRAND_ROW_CLASS = 'te_brand_injected';

/** Locked: not selectable, draggable, duplicatable, deletable or hideable. */
const BRAND_ROW_FLAGS = {
  selectable: false,
  draggable: false,
  duplicatable: false,
  deletable: false,
  hideable: false,
};

const brandRow = (rowId: string, html: string) => ({
  id: rowId,
  cells: [1],
  columns: [
    {
      id: `${rowId}_col`,
      contents: [
        {
          id: `${rowId}_content`,
          type: 'text',
          values: {
            ...BRAND_ROW_FLAGS,
            containerPadding: '0px',
            anchor: '',
            textAlign: 'center',
            lineHeight: '140%',
            hideDesktop: false,
            displayCondition: null,
            _meta: { htmlID: `${rowId}_content`, htmlClassNames: BRAND_ROW_CLASS },
            text: html,
          },
        },
      ],
      values: {
        backgroundColor: '',
        padding: '0px',
        border: {},
        borderRadius: '0px',
        _meta: { htmlID: `${rowId}_col`, htmlClassNames: BRAND_ROW_CLASS },
      },
    },
  ],
  values: {
    ...BRAND_ROW_FLAGS,
    displayCondition: null,
    columns: false,
    backgroundColor: '',
    columnsBackgroundColor: '',
    padding: '0px',
    anchor: '',
    hideDesktop: false,
    _meta: { htmlID: rowId, htmlClassNames: BRAND_ROW_CLASS },
  },
});

const stripBrandRows = (design: any): any => {
  const rows = design?.body?.rows;
  if (!Array.isArray(rows)) return design;
  return {
    ...design,
    body: { ...design.body, rows: rows.filter((r: any) => !BRAND_ROW_IDS.includes(r?.id)) },
  };
};

const injectBrandRows = (design: any, brand: Branding | null): any => {
  if (!brand) return design;
  const base = stripBrandRows(design);
  const rows = Array.isArray(base?.body?.rows) ? base.body.rows : [];
  const injected = [
    ...(brand.header_html ? [brandRow(BRAND_ROW_IDS[0], brand.header_html)] : []),
    ...rows,
    ...(brand.footer_html ? [brandRow(BRAND_ROW_IDS[1], brand.footer_html)] : []),
  ];
  return { ...base, body: { ...base.body, rows: injected } };
};

/**
 * Remove the injected branding from exported HTML.
 *
 * Marker-driven, matching EmailBranding::stripBranding() in PHP — the markers are
 * emitted by the branding itself, so this holds whether or not Unlayer propagates
 * our class names into the export. The DOM pass then clears the row wrapper the
 * removal leaves empty, which regex cannot do reliably through Unlayer's nesting.
 */
const BRAND_MARKERS: Array<[string, string]> = [
  ['<!--te-brand-header-->', '<!--/te-brand-header-->'],
  ['<!--te-brand-footer-->', '<!--/te-brand-footer-->'],
];

const stripBrandHtml = (html: string): string => {
  if (!html || !html.includes('<!--te-brand-')) return html;

  let out = html;
  BRAND_MARKERS.forEach(([open, close]) => {
    const pattern = new RegExp(
      `${open.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')}[\\s\\S]*?${close.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')}`,
      'g'
    );
    out = out.replace(pattern, '');
  });

  try {
    const doc = new DOMParser().parseFromString(out, 'text/html');
    doc.querySelectorAll(`.${BRAND_ROW_CLASS}`).forEach((el) => {
      (el.closest('.u-row-container') || el.closest('table') || el).remove();
    });
    return `<!DOCTYPE html>${doc.documentElement.outerHTML}`;
  } catch {
    return out;
  }
};

const CATEGORIES = ['General', 'Marketing', 'Transactional', 'Newsletter'] as const;

const TemplateEditor: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { activeContext } = useOrg();
  const { colors } = useTheme();

  const editorRef = useRef<any>(null);
  const editorReady = useRef(false);
  const [editorIsReady, setEditorIsReady] = useState(false);
  const hasUnsavedChanges = useRef(false);
  // Tracks whether the saved design has already been loaded into the editor,
  // so we never load it twice regardless of fetch/ready ordering (CA-53).
  const designLoaded = useRef(false);

  const isNew = !id || id === 'new';
  const clubProfileId = activeContext?.scope_id;
  const isAdmin = user?.activeRole?.role === 'club_admin' || user?.system_role === 'super_admin';

  const [templateName, setTemplateName] = useState('');
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState<string>('General');
  const [scope, setScope] = useState<'club' | 'personal' | 'platform'>('club');
  const [teamVisibility, setTeamVisibility] = useState<number[]>([]);
  const [isActive, setIsActive] = useState(true);
  const [showJsonModal, setShowJsonModal] = useState(false);
  const [jsonInput, setJsonInput] = useState('');
  const [jsonMode, setJsonMode] = useState<'import' | 'export'>('import');
  const isSuperAdmin = user?.system_role === 'super_admin';

  const [teams, setTeams] = useState<Team[]>([]);
  const [mergeFields, setMergeFields] = useState<MergeField[]>([]);
  const [existingDesign, setExistingDesign] = useState<object | null>(null);
  const existingDesignRef = useRef<object | null>(null);

  // The club's real email header/footer markup, rendered by lib/EmailBranding.php.
  // Shown on the canvas as display-only rows; never saved (see stripBrandRows).
  const [branding, setBranding] = useState<Branding | null>(null);
  const brandingRef = useRef<Branding | null>(null);

  const [loading, setLoading] = useState(!isNew);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [showPreview, setShowPreview] = useState(false);
  const [previewHtml, setPreviewHtml] = useState('');
  const [showTeamVisibility, setShowTeamVisibility] = useState(false);
  const [showMergeTags, setShowMergeTags] = useState(false);
  const [mergeTagNotice, setMergeTagNotice] = useState<string | null>(null);

  // Send-from-editor: open the Compose modal preloaded with this template.
  const [showCompose, setShowCompose] = useState(false);
  const [composeTemplate, setComposeTemplate] = useState<{
    id: number;
    name: string;
    subject: string;
    body_html: string;
    variables: string[];
    requires_event: boolean;
  } | null>(null);

  // Tracks the subject <input> and the caret position within it, so a merge tag
  // can be inserted at the cursor when the subject field is the active target.
  const subjectInputRef = useRef<HTMLInputElement | null>(null);
  const subjectCaretRef = useRef<number | null>(null);

  // Fetch template data if editing
  useEffect(() => {
    if (!isNew && id) {
      fetchTemplate(parseInt(id, 10));
    }
  }, [id]);

  // Fetch teams, merge fields and the club's email branding
  useEffect(() => {
    if (clubProfileId) {
      fetchTeams();
      fetchMergeFields();
      fetchBranding();
    }
  }, [clubProfileId]);

  // Warn on unsaved changes
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedChanges.current) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, []);

  // Clear success message after delay
  useEffect(() => {
    if (successMessage) {
      const timer = setTimeout(() => setSuccessMessage(null), 3000);
      return () => clearTimeout(timer);
    }
  }, [successMessage]);

  // Clear merge-tag insert notice after delay
  useEffect(() => {
    if (mergeTagNotice) {
      const timer = setTimeout(() => setMergeTagNotice(null), 4000);
      return () => clearTimeout(timer);
    }
  }, [mergeTagNotice]);

  const fetchTemplate = async (templateId: number) => {
    setLoading(true);
    // The loading skeleton unmounts the Unlayer editor, but this component
    // instance survives same-route param changes (e.g. the post-save navigate
    // from /new to /:id). Stale ready flags from the previous editor instance
    // made the design-load effect call loadDesign on a remounted-but-not-ready
    // editor — a TypeError with no error boundary, i.e. a white screen. Reset
    // them so only the remounted editor's own onReady re-arms the load.
    editorReady.current = false;
    setEditorIsReady(false);
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=get&id=${templateId}`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) throw new Error('Failed to fetch template');
      const data = await response.json();
      const template: EmailTemplate = data.template || data;

      setTemplateName(template.name);
      setSubject(template.subject);
      setCategory(template.category);
      setScope(template.scope);
      setTeamVisibility(template.team_visibility || []);
      setIsActive(template.is_active);

      if (template.design_json) {
        const brandedDesign = applyBrandColors(template.design_json);
        // New design arriving (e.g. switching templates) — allow it to load.
        designLoaded.current = false;
        existingDesignRef.current = brandedDesign;
        setExistingDesign(brandedDesign);
      }
    } catch (err) {
      console.error('Error fetching template:', err);
      setError('Failed to load template.');
    } finally {
      setLoading(false);
    }
  };

  const fetchTeams = async () => {
    try {
      const response = await fetch(
        `${API_URL}/api/teams.php?club_profile_id=${clubProfileId}`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) return;
      const data = await response.json();
      setTeams(Array.isArray(data) ? data : data.teams || []);
    } catch (err) {
      console.error('Error fetching teams:', err);
    }
  };

  const fetchBranding = async () => {
    try {
      const response = await fetch(
        `${API_URL}/api/communications?action=branding&club_profile_id=${clubProfileId}`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) return;
      const data = await response.json();
      if (!data?.header_html && !data?.footer_html) return;
      brandingRef.current = data;
      setBranding(data);
    } catch (err) {
      // Canvas simply shows the design without the branding strips.
      console.error('Error fetching club branding:', err);
    }
  };

  const fetchMergeFields = async () => {
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=merge-fields`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) return;
      const data = await response.json();
      setMergeFields(Array.isArray(data) ? data : data.merge_fields || []);
    } catch (err) {
      console.error('Error fetching merge fields:', err);
      // Set default merge fields as fallback
      setMergeFields([
        { key: 'athlete_first_name', name: 'Athlete First Name', value: '{{athlete_first_name}}' },
        { key: 'athlete_last_name', name: 'Athlete Last Name', value: '{{athlete_last_name}}' },
        { key: 'parent_first_name', name: 'Parent First Name', value: '{{parent_first_name}}' },
        { key: 'parent_last_name', name: 'Parent Last Name', value: '{{parent_last_name}}' },
        { key: 'team_name', name: 'Team Name', value: '{{team_name}}' },
        { key: 'club_name', name: 'Club Name', value: '{{club_name}}' },
        { key: 'event_name', name: 'Event Name', value: '{{event_name}}' },
        { key: 'event_date', name: 'Event Date', value: '{{event_date}}' },
        { key: 'event_time', name: 'Event Time', value: '{{event_time}}' },
        { key: 'event_location', name: 'Event Location', value: '{{event_location}}' },
        { key: 'unsubscribe_url', name: 'Unsubscribe URL', value: '{{unsubscribe_url}}' },
      ]);
    }
  };

  const buildMergeTags = useCallback(() => {
    const tags: Record<string, { name: string; value: string; sample?: string }> = {};
    mergeFields.forEach((field) => {
      tags[field.key] = {
        name: field.name,
        value: field.value,
        sample: field.sample || field.value,
      };
    });

    // Always include defaults if not provided by API
    if (!tags['athlete_first_name']) {
      tags['athlete_first_name'] = { name: 'Athlete First Name', value: '{{athlete_first_name}}', sample: 'John' };
    }
    if (!tags['athlete_last_name']) {
      tags['athlete_last_name'] = { name: 'Athlete Last Name', value: '{{athlete_last_name}}', sample: 'Doe' };
    }
    if (!tags['parent_first_name']) {
      tags['parent_first_name'] = { name: 'Parent First Name', value: '{{parent_first_name}}', sample: 'Jane' };
    }
    if (!tags['parent_last_name']) {
      tags['parent_last_name'] = { name: 'Parent Last Name', value: '{{parent_last_name}}', sample: 'Doe' };
    }
    if (!tags['team_name']) {
      tags['team_name'] = { name: 'Team Name', value: '{{team_name}}', sample: 'Eagles U14' };
    }
    if (!tags['club_name']) {
      tags['club_name'] = { name: 'Club Name', value: '{{club_name}}', sample: 'Metro FC' };
    }
    if (!tags['event_name']) {
      tags['event_name'] = { name: 'Event Name', value: '{{event_name}}', sample: 'Team Dinner' };
    }
    if (!tags['event_date']) {
      tags['event_date'] = { name: 'Event Date', value: '{{event_date}}', sample: 'March 25, 2026' };
    }
    if (!tags['event_time']) {
      tags['event_time'] = { name: 'Event Time', value: '{{event_time}}', sample: '6:00 PM' };
    }
    if (!tags['event_location']) {
      tags['event_location'] = { name: 'Event Location', value: '{{event_location}}', sample: '123 Main St' };
    }
    if (!tags['unsubscribe_url']) {
      tags['unsubscribe_url'] = { name: 'Unsubscribe URL', value: '{{unsubscribe_url}}', sample: '#' };
    }

    return tags;
  }, [mergeFields]);

  const getEditor = () => {
    // react-email-editor v1.x: actual Unlayer instance is on .editor
    return editorRef.current?.editor || editorRef.current;
  };

  // Insert a merge tag (e.g. "{{team_name}}") at the cursor.
  //  - If the subject input is the active target, splice it into the subject at
  //    the caret position.
  //  - Otherwise insert into the Unlayer body via the editor API when available,
  //    falling back to copying the tag to the clipboard so it can be pasted.
  const insertMergeTag = useCallback((tagValue: string) => {
    // Subject field path: insert at the tracked caret position.
    if (subjectInputRef.current && document.activeElement === subjectInputRef.current) {
      const input = subjectInputRef.current;
      const pos = subjectCaretRef.current ?? input.value.length;
      const next = subject.slice(0, pos) + tagValue + subject.slice(pos);
      setSubject(next);
      hasUnsavedChanges.current = true;
      // Restore focus and place caret after the inserted tag.
      requestAnimationFrame(() => {
        input.focus();
        const caret = pos + tagValue.length;
        input.setSelectionRange(caret, caret);
        subjectCaretRef.current = caret;
      });
      setMergeTagNotice(`Inserted ${tagValue} into the subject line.`);
      return;
    }

    // Body path: try the Unlayer editor's insertion API. react-email-editor 1.x
    // exposes the underlying Unlayer instance; different builds expose the
    // insert helper under slightly different names, so probe for any of them.
    const editor: any = getEditor();
    const tryInsert =
      editor &&
      (editor.insertMergeTag ||
        editor.insertTag ||
        (editor.editor && (editor.editor.insertMergeTag || editor.editor.insertTag)));

    if (typeof tryInsert === 'function') {
      try {
        tryInsert.call(editor.insertMergeTag ? editor : editor.editor || editor, tagValue);
        hasUnsavedChanges.current = true;
        setMergeTagNotice(`Inserted ${tagValue} at the cursor.`);
        return;
      } catch (err) {
        // fall through to clipboard fallback
      }
    }

    // Fallback: copy to clipboard. The tag is also available in the editor's
    // built-in "Merge Tags" toolbar dropdown (registered via the mergeTags option).
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(tagValue).catch(() => {});
    }
    setMergeTagNotice(
      `${tagValue} copied — click into a text block and paste, or pick it from the editor's Merge Tags menu.`
    );
  }, [subject]);

  // Replace placeholder colors in design JSON with the club's actual brand colors
  // Platform templates use these placeholder hex values:
  //   #1A3C5E → brand primary
  //   #C9A96E → brand secondary/accent
  const applyBrandColors = (design: any): any => {
    const json = JSON.stringify(design);
    const branded = json
      .replace(/#1a3c5e/gi, colors.primary)
      .replace(/#1A3C5E/gi, colors.primary)
      // Teams Elevated green — the seeded [Youth] designs hardcode it where the
      // rest of the library uses the #1a3c5e palette colour. Swapped too, so a
      // club editing one of those sees its own colour rather than ours.
      .replace(/#12443e/gi, colors.primary)
      .replace(/#c9a96e/gi, colors.accent)
      .replace(/#C9A96E/gi, colors.accent);
    return JSON.parse(branded);
  };

  const loadDesignIntoEditor = useCallback((design: object) => {
    const editor = getEditor();
    // getEditor() can return the component wrapper while the Unlayer iframe is
    // still initializing (its .editor is null); never call into it then.
    if (!editor || typeof editor.loadDesign !== 'function' || !editorReady.current) return;
    // Show the club's real send-time header/footer around the design. Injected
    // for display only — stripped again on save, preview and send.
    editor.loadDesign(injectBrandRows(design, brandingRef.current) as any);
    designLoaded.current = true;
    // Loading a saved design is not a user edit.
    hasUnsavedChanges.current = false;
  }, []);

  const onEditorReady: EmailEditorProps['onReady'] = () => {
    editorReady.current = true;
    setEditorIsReady(true);

    const editor = getEditor();
    // Update design tags with current brand colors
    if (editor?.setDesignTags) {
      editor.setDesignTags({
        brand_color: colors.primary,
        brand_secondary: colors.secondary,
        brand_accent: colors.accent,
        club_name: activeContext?.scope_name || 'Your Club',
      });
    }

    // Track changes
    if (editor) {
      editor.addEventListener('design:updated', () => {
        hasUnsavedChanges.current = true;
      });
    }

    // If the template fetch already resolved before the editor became ready,
    // load the saved design now (handles the "ready arrives last" ordering).
    if (!designLoaded.current && existingDesignRef.current) {
      loadDesignIntoEditor(existingDesignRef.current);
    }
  };

  // If the editor was ready before the template fetch resolved, load the design
  // once it arrives (handles the "design arrives last" ordering). The
  // designLoaded ref guarantees we never double-load across either path (CA-53).
  useEffect(() => {
    if (existingDesign && editorIsReady && !designLoaded.current) {
      loadDesignIntoEditor(existingDesign);
    }
  }, [existingDesign, editorIsReady, loadDesignIntoEditor]);

  // Branding can resolve after the canvas already has a design on it — and on a
  // new template there is no fetch to hang the injection off at all. Re-inject
  // from the editor's own current design so nothing already typed is lost.
  useEffect(() => {
    if (!branding || !editorIsReady) return;
    const editor = getEditor();
    if (typeof editor?.saveDesign !== 'function') return;
    editor.saveDesign((design: any) => {
      const alreadyInjected = design?.body?.rows?.some((r: any) => BRAND_ROW_IDS.includes(r?.id));
      if (alreadyInjected) return;
      const dirty = hasUnsavedChanges.current;
      editor.loadDesign(injectBrandRows(design, branding) as any);
      hasUnsavedChanges.current = dirty;
    });
  }, [branding, editorIsReady]);

  const handleSave = () => {
    if (!templateName.trim()) {
      setError('Please enter a template name.');
      return;
    }
    const editor = getEditor();
    if (!editor || !editorReady.current) {
      setError('Editor is not ready. Please wait and try again.');
      return;
    }

    setSaving(true);
    setError(null);

    editor.saveDesign((design: object) => {
      editor.exportHtml((data: { html: string }) => {
        // Strip the injected branding: the stored template must stay
        // club-agnostic, and EmailSendService::processHtml() adds the real
        // header/footer per sending club at send time.
        saveTemplate(stripBrandRows(design), stripBrandHtml(data.html));
      });
    });
  };

  const saveTemplate = async (designJson: object, htmlOutput: string) => {
    try {
      const payload = {
        name: templateName.trim(),
        subject: subject.trim(),
        category,
        scope,
        team_visibility: teamVisibility,
        is_active: isActive,
        design_json: designJson,
        html_output: htmlOutput,
        club_profile_id: clubProfileId,
      };

      const url = isNew
        ? `${API_URL}/api/email-templates.php?action=create`
        : `${API_URL}/api/email-templates.php?action=update&id=${id}`;

      const method = isNew ? 'POST' : 'PUT';

      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const errData = await response.json().catch(() => ({}));
        throw new Error(errData.message || 'Failed to save template');
      }

      const result = await response.json();
      hasUnsavedChanges.current = false;
      setSuccessMessage('Template saved successfully!');

      // If this was a new template, navigate to the edit URL
      if (isNew && result.id) {
        navigate(`/email-templates/${result.id}`, { replace: true });
      }
    } catch (err: any) {
      console.error('Error saving template:', err);
      setError(err.message || 'Failed to save template.');
    } finally {
      setSaving(false);
    }
  };

  const handlePreview = () => {
    const editor = getEditor();
    if (!editor || !editorReady.current) {
      setError('Editor is not ready.');
      return;
    }

    editor.exportHtml(async (data: { html: string }) => {
      // Preview through the backend, not the raw Unlayer export: the club-branded
      // header/footer (logo, colours, socials, unsubscribe) is applied at send
      // time, so previewing the bare design would show something recipients never
      // get. The canvas copy is stripped first — the backend adds the real one,
      // with a live unsubscribe link, and would otherwise stack a second set.
      const bodyHtml = stripBrandHtml(data.html);
      setPreviewHtml(data.html);
      setShowPreview(true);
      if (!clubProfileId) return;
      try {
        const response = await fetch(`${API_URL}/api/communications?action=preview-email`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
          body: JSON.stringify({
            club_profile_id: clubProfileId,
            subject,
            body_html: bodyHtml,
          }),
        });
        if (!response.ok) return;
        const preview = await response.json();
        if (preview?.preview_html) setPreviewHtml(preview.preview_html);
      } catch {
        // keep the raw export already showing
      }
    });
  };

  // Send this template: export the current HTML and open the Compose modal
  // preloaded with it, so the user picks recipients and sends from there.
  const handleSendClick = () => {
    const editor = getEditor();
    if (!editor || !editorReady.current) {
      setError('Editor is not ready.');
      return;
    }
    if (!clubProfileId) {
      setError('No active club — cannot send.');
      return;
    }
    if (!subject.trim()) {
      setError('Add a subject line before sending.');
      return;
    }

    editor.exportHtml((data: { html: string }) => {
      // Without the canvas branding — the send path brands it per recipient club.
      const html = stripBrandHtml(data.html);
      // Derive template variables from the subject + body, and flag whether an
      // event is needed — mirrors the /api/email-templates enrichment so the
      // Compose modal shows variable hints and the event picker when relevant.
      const found = new Set<string>();
      const re = /\{\{\s*(\w+)\s*\}\}/g;
      let m: RegExpExecArray | null;
      while ((m = re.exec(`${subject} ${html}`)) !== null) found.add(m[1]);
      const variables = Array.from(found);
      const eventVars = ['event_name', 'event_date', 'event_time', 'location', 'team_name'];

      setComposeTemplate({
        id: isNew ? 0 : parseInt(id as string, 10),
        name: templateName || 'Untitled template',
        subject,
        body_html: html,
        variables,
        requires_event: variables.some((v) => eventVars.includes(v)),
      });
      setShowCompose(true);
    });
  };

  const handleBack = () => {
    if (hasUnsavedChanges.current) {
      const confirmed = window.confirm('You have unsaved changes. Are you sure you want to leave?');
      if (!confirmed) return;
    }
    navigate('/email-templates');
  };

  const toggleTeamVisibility = (teamId: number) => {
    setTeamVisibility((prev) =>
      prev.includes(teamId) ? prev.filter((id) => id !== teamId) : [...prev, teamId]
    );
    hasUnsavedChanges.current = true;
  };

  // Loading state
  if (loading) {
    return (
      <div className="flex flex-col bg-gray-50" style={{ height: 'calc(100vh - 132px)' }}>
        <div className="flex items-center justify-between px-4 py-3 bg-white border-b border-gray-200">
          <div className="flex items-center gap-4">
            <div className="h-8 w-24 bg-gray-200 rounded animate-pulse" />
            <div className="h-8 w-64 bg-gray-200 rounded animate-pulse" />
          </div>
          <div className="flex items-center gap-3">
            <div className="h-8 w-24 bg-gray-200 rounded animate-pulse" />
            <div className="h-8 w-20 bg-gray-200 rounded animate-pulse" />
          </div>
        </div>
        <div className="flex-1 flex items-center justify-center">
          <div className="text-center">
            <div className="w-10 h-10 border-4 border-brand-primary border-t-transparent rounded-full animate-spin mx-auto mb-3" />
            <p className="text-gray-500 text-sm">Loading template...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col bg-gray-50" style={{ height: 'calc(100vh - 140px)' }}>
      {/* Top bar */}
      <div className="flex-shrink-0 flex flex-col sm:flex-row items-start sm:items-center justify-between px-4 py-3 bg-white border-b border-gray-200 gap-3">
        <div className="flex items-center gap-3 flex-1 min-w-0">
          <button
            onClick={handleBack}
            className="flex items-center text-sm text-brand-primary hover:text-brand-primary/80 whitespace-nowrap uppercase font-semibold"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Templates
          </button>
          <div className="h-6 w-px bg-gray-300" />
          <input
            type="text"
            placeholder="Template name..."
            value={templateName}
            onChange={(e) => {
              setTemplateName(e.target.value);
              hasUnsavedChanges.current = true;
            }}
            className="flex-1 min-w-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
          />
          <input
            ref={subjectInputRef}
            type="text"
            placeholder="Subject line..."
            value={subject}
            onChange={(e) => {
              setSubject(e.target.value);
              subjectCaretRef.current = e.target.selectionStart;
              hasUnsavedChanges.current = true;
            }}
            onFocus={(e) => { subjectInputRef.current = e.currentTarget; subjectCaretRef.current = e.currentTarget.selectionStart; }}
            onSelect={(e) => { subjectCaretRef.current = (e.target as HTMLInputElement).selectionStart; }}
            onClick={(e) => { subjectCaretRef.current = (e.currentTarget as HTMLInputElement).selectionStart; }}
            onKeyUp={(e) => { subjectCaretRef.current = (e.currentTarget as HTMLInputElement).selectionStart; }}
            className="flex-1 min-w-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none hidden lg:block"
          />
        </div>
        <div className="flex items-center gap-2 flex-shrink-0">
          <select
            value={category}
            onChange={(e) => {
              setCategory(e.target.value);
              hasUnsavedChanges.current = true;
            }}
            className="px-2 py-1.5 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
          >
            {CATEGORIES.map((cat) => (
              <option key={cat} value={cat}>
                {cat}
              </option>
            ))}
          </select>
          <button
            onClick={() => setShowTeamVisibility(!showTeamVisibility)}
            className={`px-4 py-2 border rounded-md text-sm uppercase font-semibold transition-colors ${
              showTeamVisibility
                ? 'border-brand-secondary text-brand-primary bg-brand-primary bg-opacity-5'
                : 'border-brand-secondary text-brand-primary hover:bg-gray-50'
            }`}
            title="Team visibility"
          >
            <svg className="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Teams
            {teamVisibility.length > 0 && (
              <span className="ml-1 px-1.5 py-0.5 bg-brand-primary text-white text-xs rounded-full">
                {teamVisibility.length}
              </span>
            )}
          </button>
          <button
            onClick={() => setShowMergeTags(!showMergeTags)}
            className={`px-4 py-2 border rounded-md text-sm uppercase font-semibold transition-colors ${
              showMergeTags
                ? 'border-brand-secondary text-brand-primary bg-brand-primary bg-opacity-5'
                : 'border-brand-secondary text-brand-primary hover:bg-gray-50'
            }`}
            title="Insert a merge tag at the cursor"
          >
            <svg className="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z" />
            </svg>
            Tags
          </button>
          <button
            onClick={() => {
              setJsonMode('import');
              setJsonInput('');
              setShowJsonModal(true);
            }}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
            title="Import/Export JSON"
          >
            JSON
          </button>
          <button
            onClick={handlePreview}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
          >
            Preview
          </button>
          <button
            onClick={handleSendClick}
            disabled={!editorIsReady || !clubProfileId}
            title="Send this template to recipients"
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg className="w-4 h-4 inline-block mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
            Send
          </button>
          <button
            onClick={handleSave}
            disabled={saving}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {saving ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>

      {/* Subject line on small screens */}
      <div className="lg:hidden flex-shrink-0 px-4 py-2 bg-white border-b border-gray-200">
        <input
          type="text"
          placeholder="Subject line..."
          value={subject}
          onChange={(e) => {
            setSubject(e.target.value);
            subjectCaretRef.current = e.target.selectionStart;
            hasUnsavedChanges.current = true;
          }}
          onFocus={(e) => { subjectInputRef.current = e.currentTarget; subjectCaretRef.current = e.currentTarget.selectionStart; }}
          onSelect={(e) => { subjectCaretRef.current = (e.target as HTMLInputElement).selectionStart; }}
          onClick={(e) => { subjectCaretRef.current = (e.currentTarget as HTMLInputElement).selectionStart; }}
          onKeyUp={(e) => { subjectCaretRef.current = (e.currentTarget as HTMLInputElement).selectionStart; }}
          className="w-full px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
        />
      </div>

      {/* Messages */}
      {error && (
        <div className="flex-shrink-0 mx-4 mt-2 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg flex justify-between items-center text-sm">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-red-500 hover:text-red-700 ml-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      )}
      {successMessage && (
        <div className="flex-shrink-0 mx-4 mt-2 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
          {successMessage}
        </div>
      )}

      {/* Team Visibility Panel */}
      {showTeamVisibility && (
        <div className="flex-shrink-0 mx-4 mt-2 p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
          <div className="flex justify-between items-center mb-3">
            <h3 className="text-sm font-semibold text-gray-900">Team Visibility</h3>
            <p className="text-xs text-gray-500">
              {teamVisibility.length === 0
                ? 'Visible to all teams'
                : `Visible to ${teamVisibility.length} team${teamVisibility.length === 1 ? '' : 's'}`}
            </p>
          </div>
          <p className="text-xs text-gray-400 mb-3">
            Leave all unchecked to make this template visible to all teams.
          </p>
          <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
            {teams.length === 0 ? (
              <p className="text-xs text-gray-400">No teams found.</p>
            ) : (
              teams.map((team) => (
                <label
                  key={team.id}
                  className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer text-sm transition-colors ${
                    teamVisibility.includes(team.id)
                      ? 'border-brand-primary bg-brand-primary bg-opacity-5 text-brand-primary'
                      : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={teamVisibility.includes(team.id)}
                    onChange={() => toggleTeamVisibility(team.id)}
                    className="sr-only"
                  />
                  <span
                    className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
                      teamVisibility.includes(team.id)
                        ? 'bg-brand-primary border-brand-primary'
                        : 'border-gray-300 bg-white'
                    }`}
                  >
                    {teamVisibility.includes(team.id) && (
                      <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                      </svg>
                    )}
                  </span>
                  {team.name}
                </label>
              ))
            )}
          </div>

          {/* Scope toggle for admins */}
          {isAdmin && (
            <div className="mt-4 pt-3 border-t border-gray-100 flex items-center gap-4">
              <span className="text-xs font-medium text-gray-600">Template Scope:</span>
              <label className="inline-flex items-center gap-1.5 cursor-pointer">
                <input
                  type="radio"
                  name="scope"
                  value="club"
                  checked={scope === 'club'}
                  onChange={() => {
                    setScope('club');
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary"
                />
                <span className="text-xs text-gray-700">Club Template</span>
              </label>
              <label className="inline-flex items-center gap-1.5 cursor-pointer">
                <input
                  type="radio"
                  name="scope"
                  value="personal"
                  checked={scope === 'personal'}
                  onChange={() => {
                    setScope('personal');
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary"
                />
                <span className="text-xs text-gray-700">Personal Template</span>
              </label>
              {isSuperAdmin && (
                <label className="inline-flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="scope"
                    value="platform"
                    checked={scope === 'platform'}
                    onChange={() => {
                      setScope('platform');
                      hasUnsavedChanges.current = true;
                    }}
                    className="text-brand-primary focus:ring-brand-primary"
                  />
                  <span className="text-xs text-gray-700">Platform Template</span>
                </label>
              )}
              <label className="inline-flex items-center gap-1.5 cursor-pointer ml-4">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => {
                    setIsActive(e.target.checked);
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary rounded"
                />
                <span className="text-xs text-gray-700">Active</span>
              </label>
            </div>
          )}
        </div>
      )}

      {/* Merge Tags Panel (CA-52) */}
      {showMergeTags && (
        <div className="flex-shrink-0 mx-4 mt-2 p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
          <div className="flex justify-between items-center mb-2">
            <h3 className="text-sm font-semibold text-gray-900">Insert Merge Tag</h3>
            <button
              onClick={() => setShowMergeTags(false)}
              className="text-gray-400 hover:text-gray-600"
              title="Close"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <p className="text-xs text-gray-400 mb-3">
            Click into the subject line or a text block in the editor, then choose a tag to insert it at the cursor.
          </p>
          <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
            {mergeFields.length === 0 ? (
              <p className="text-xs text-gray-400">No merge tags available.</p>
            ) : (
              mergeFields.map((field) => (
                <button
                  key={field.key}
                  type="button"
                  onClick={() => insertMergeTag(field.value)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-700 text-sm hover:border-brand-primary hover:bg-brand-primary hover:bg-opacity-5 hover:text-brand-primary transition-colors"
                  title={`Insert ${field.value}`}
                >
                  <span className="font-medium">{field.name}</span>
                  <span className="text-xs text-gray-400 font-mono">{field.value}</span>
                </button>
              ))
            )}
          </div>
          {mergeTagNotice && (
            <p className="mt-3 text-xs text-brand-primary">{mergeTagNotice}</p>
          )}
        </div>
      )}

      {/* Unlayer Editor */}
      <div style={{ flex: 1, position: 'relative', overflow: 'hidden' }}>
        <EmailEditor
          ref={editorRef}
          onReady={onEditorReady}
          options={{
            mergeTags: buildMergeTags(),
            designTags: {
              brand_color: colors.primary,
              brand_secondary: colors.secondary,
              brand_accent: colors.accent,
              club_name: activeContext?.scope_name || 'Your Club',
            },
            customCSS: [
              `.blockbuilder-branding { display: none !important; }`,
            ],
            features: {
              textEditor: {
                spellChecker: true,
                tables: true,
              },
              colorPicker: {
                presets: [
                  colors.primary,
                  colors.secondary,
                  colors.accent,
                  colors.primaryHover,
                  '#ffffff',
                  '#000000',
                  '#f3f4f6',
                  '#374151',
                  '#ef4444',
                  '#f59e0b',
                  '#22c55e',
                  '#3b82f6',
                ],
              },
            },
            appearance: {
              theme: 'light',
              panels: {
                tools: {
                  dock: 'left',
                },
              },
            },
          }}
        />
      </div>

      {/* Preview Modal */}
      {showPreview && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4"
          onClick={() => setShowPreview(false)}
        >
          <div
            className="bg-white rounded-lg shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200">
              <div>
                <h3 className="text-sm font-semibold text-gray-900">Email Preview</h3>
                {subject && (
                  <p className="text-xs text-gray-500 mt-0.5">Subject: {subject}</p>
                )}
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setShowPreview(false)}
                  className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
                >
                  Close
                </button>
              </div>
            </div>
            <div className="flex-1 overflow-auto bg-gray-100 p-4">
              <div className="bg-white mx-auto max-w-2xl shadow-sm rounded">
                <iframe
                  srcDoc={previewHtml}
                  title="Email Preview"
                  className="w-full border-0 rounded"
                  style={{ minHeight: '600px', height: '100%' }}
                  sandbox="allow-same-origin"
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* JSON Import/Export Modal */}
      {showJsonModal && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4"
          onClick={() => setShowJsonModal(false)}
        >
          <div
            className="bg-white rounded-md shadow-2xl w-full max-w-3xl flex flex-col max-h-[85vh]"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-6 py-4 border-b border-brand-secondary">
              <h3 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
                {jsonMode === 'import' ? 'Import Design JSON' : 'Export Design JSON'}
              </h3>
              <div className="flex items-center gap-3">
                <div className="flex rounded-md border border-brand-secondary overflow-hidden">
                  <button
                    onClick={() => {
                      setJsonMode('import');
                      setJsonInput('');
                    }}
                    className={`px-3 py-1.5 text-xs uppercase font-semibold ${
                      jsonMode === 'import' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary'
                    }`}
                  >
                    Import
                  </button>
                  <button
                    onClick={() => {
                      setJsonMode('export');
                      if (editorRef.current && editorReady.current) {
                        editorRef.current.saveDesign((design: object) => {
                          // Without the display-only branding rows — exporting them
                          // would re-import a copy of one club's header/footer.
                          setJsonInput(JSON.stringify(stripBrandRows(design), null, 2));
                        });
                      }
                    }}
                    className={`px-3 py-1.5 text-xs uppercase font-semibold ${
                      jsonMode === 'export' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary'
                    }`}
                  >
                    Export
                  </button>
                </div>
                <button
                  onClick={() => setShowJsonModal(false)}
                  className="text-gray-400 hover:text-gray-600 text-xl font-bold"
                >
                  ✕
                </button>
              </div>
            </div>
            <div className="p-6 flex-1 overflow-auto">
              {jsonMode === 'import' ? (
                <>
                  <p className="text-sm text-gray-600 mb-3">
                    Paste an Unlayer design JSON below to load it into the editor. This will replace the current design.
                  </p>
                  <textarea
                    value={jsonInput}
                    onChange={(e) => setJsonInput(e.target.value)}
                    placeholder='{"body":{"rows":[...],"values":{...}}}'
                    className="w-full h-64 font-mono text-xs border border-brand-secondary rounded-md px-3 py-2 focus:ring-brand-primary focus:border-brand-primary resize-y"
                  />
                </>
              ) : (
                <>
                  <p className="text-sm text-gray-600 mb-3">
                    Copy this JSON to save or share the template design. Paste it into Import to restore.
                  </p>
                  <textarea
                    value={jsonInput}
                    readOnly
                    className="w-full h-64 font-mono text-xs border border-brand-secondary rounded-md px-3 py-2 bg-gray-50 resize-y"
                  />
                </>
              )}
            </div>
            <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-brand-secondary">
              <button
                onClick={() => setShowJsonModal(false)}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
              {jsonMode === 'import' ? (
                <button
                  onClick={() => {
                    try {
                      // Sanitize: replace smart quotes and unicode whitespace
                      const sanitized = jsonInput
                        .replace(/[\u2018\u2019\u0060]/g, "'")
                        .replace(/[\u201C\u201D]/g, '"')
                        .replace(/\u00A0/g, ' ')
                        .trim();
                      const design = injectBrandRows(
                        applyBrandColors(JSON.parse(sanitized)),
                        brandingRef.current
                      );
                      if (editorRef.current && editorReady.current) {
                        editorRef.current.loadDesign(design);
                        hasUnsavedChanges.current = true;
                        setShowJsonModal(false);
                      }
                    } catch (err) {
                      console.error('JSON parse error:', err);
                      alert('Invalid JSON. Please check the format and try again. Error: ' + (err as Error).message);
                    }
                  }}
                  disabled={!jsonInput.trim()}
                  className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Load Design
                </button>
              ) : (
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(jsonInput);
                    alert('JSON copied to clipboard!');
                  }}
                  className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase font-semibold text-sm"
                >
                  Copy to Clipboard
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Send: Compose modal preloaded with this template (lazy-loaded) */}
      {showCompose && clubProfileId && composeTemplate && (
        <React.Suspense fallback={null}>
          <EmailCompose
            isOpen={showCompose}
            onClose={() => setShowCompose(false)}
            clubProfileId={clubProfileId}
            preselectedTemplate={composeTemplate}
          />
        </React.Suspense>
      )}
    </div>
  );
};

export default TemplateEditor;
