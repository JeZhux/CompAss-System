import { useCallback, useEffect, useState } from 'react';
import BulkWizard from './BulkWizard.jsx';
import SingleAccountForm from './SingleAccountForm.jsx';
import { previewTeacherApplications, confirmTeacherApplications } from '../../../components/admin/adminApi.js';

export default function ApplyTeachersTab({ onChanged, onDirtyChange, onReset }) {
  const [singleDirty, setSingleDirty] = useState(false);
  const [bulkDirty, setBulkDirty] = useState(false);
  const dirty = singleDirty || bulkDirty;
  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);
  const handleSingleDirty = useCallback((d) => setSingleDirty(!!d), []);
  const handleBulkDirty = useCallback((d) => setBulkDirty(!!d), []);
  return (
    <div className="stack-18">
      <SingleAccountForm role="Teacher" onCreated={() => onChanged?.()} onDirtyChange={handleSingleDirty} onReset={onReset} />
      <BulkWizard
        kind="teacher"
        previewFn={previewTeacherApplications}
        confirmFn={confirmTeacherApplications}
        templateType="teacher-applications"
        onConfirmed={() => onChanged?.()}
        onDirtyChange={handleBulkDirty}
        onReset={onReset}
      />
    </div>
  );
}
