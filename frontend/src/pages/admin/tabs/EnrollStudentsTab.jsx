import { useCallback, useEffect, useState } from 'react';
import BulkWizard from './BulkWizard.jsx';
import SingleAccountForm from './SingleAccountForm.jsx';
import { previewEnrollments, confirmEnrollments } from '../../../components/admin/adminApi.js';

export default function EnrollStudentsTab({ onChanged, onDirtyChange, onReset }) {
  const [singleDirty, setSingleDirty] = useState(false);
  const [bulkDirty, setBulkDirty] = useState(false);
  const dirty = singleDirty || bulkDirty;
  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);
  const handleSingleDirty = useCallback((d) => setSingleDirty(!!d), []);
  const handleBulkDirty = useCallback((d) => setBulkDirty(!!d), []);
  return (
    <div className="stack-18">
      <SingleAccountForm role="Student" onCreated={() => onChanged?.()} onDirtyChange={handleSingleDirty} onReset={onReset} />
      <BulkWizard
        kind="student"
        previewFn={previewEnrollments}
        confirmFn={confirmEnrollments}
        templateType="student-enrollments"
        onConfirmed={() => onChanged?.()}
        onDirtyChange={handleBulkDirty}
        onReset={onReset}
      />
    </div>
  );
}
