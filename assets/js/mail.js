(() => {
  'use strict';
  const randomKey = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) => value.toString(16).padStart(2, '0')).join('');
  const storageGet = (key) => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; } };
  const storageSet = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* Server autosave remains available. */ } };
  document.querySelectorAll('[data-clear-compose]').forEach((node) => {
    try {
      for (const key of Object.keys(sessionStorage)) {
        if (key.startsWith('npm-compose:') && storageGet(key)?.client_key === node.dataset.clearCompose) sessionStorage.removeItem(key);
      }
    } catch { /* Storage can be disabled. */ }
  });
  document.querySelectorAll('[data-auth-type]').forEach((select) => {
    const form = select.form;
    const toggle = () => {
      const oauth = select.value === 'oauth2';
      form.querySelector('[data-oauth-settings]').hidden = !oauth;
      form.querySelector('[data-oauth-settings]').disabled = !oauth;
      ['smtp_password', 'imap_password'].forEach((name) => {
        form.elements[name].disabled = oauth;
        form.elements[name].required = !oauth && form.elements.id.value === '0';
      });
    };
    select.addEventListener('change', toggle); toggle();
    form.querySelector('[data-provider-defaults]').addEventListener('click', () => {
      const google = form.elements.oauth_provider.value === 'google';
      const values = { smtp_host: google ? 'smtp.gmail.com' : 'smtp.office365.com', smtp_port: '587', smtp_security: 'tls',
        imap_host: google ? 'imap.gmail.com' : 'outlook.office365.com', imap_port: '993', imap_security: 'ssl',
        smtp_username: form.elements.email.value, imap_username: form.elements.email.value,
        folder_sent: google ? '[Gmail]/Sent Mail' : 'Sent Items', folder_drafts: google ? '[Gmail]/Drafts' : 'Drafts',
        folder_trash: google ? '[Gmail]/Trash' : 'Deleted Items' };
      Object.entries(values).forEach(([name, value]) => { form.elements[name].value = value; });
    });
  });
  document.querySelectorAll('[data-compose]').forEach((form) => {
    const fields = ['account_id', 'to', 'cc', 'bcc', 'subject', 'body', 'format', 'in_reply_to'];
    const status = form.querySelector('[data-save-status]');
    const fileInput = form.elements['attachments[]'];
    const pointer = `npm-compose-pointer:${form.dataset.user}:${form.dataset.context}`;
    const storageKey = () => `npm-compose:${form.dataset.user}:${form.elements.client_key.value}`;
    const values = () => Object.fromEntries(fields.map((name) => [name, form.elements[name].value]));
    const fingerprint = () => JSON.stringify([values(), Array.from(fileInput.files, (file) => [file.name, file.size, file.lastModified]),
      Array.from(form.querySelectorAll('[name="remove_attachments[]"]:checked'), (box) => box.value)]);
    const persist = () => {
      const data = { ...values(), client_key: form.elements.client_key.value, draft_id: form.elements.draft_id.value,
        revision: form.elements.revision.value, time: Date.now(), files: fileInput.files.length };
      storageSet(storageKey(), data); storageSet(pointer, storageKey());
    };
    let dirty = false, timer, inFlight = null, pending = null, conflict = false, submitting = false, submitRequested = false, recoveryKey = null;
    const candidate = storageGet(storageKey()) || storageGet(storageGet(pointer));
    const restore = form.querySelector('[data-restore-compose]');
    if (candidate && candidate.time > Date.now() - 86400000 && (!Number(form.elements.draft_id.value)
      || Number(candidate.draft_id) === Number(form.elements.draft_id.value))
      && fields.some((name) => String(candidate[name] ?? '') !== form.elements[name].value)) {
      restore.hidden = false;
      restore.addEventListener('click', () => {
        fields.forEach((name) => { form.elements[name].value = candidate[name] ?? ''; });
        if (!Number(form.elements.draft_id.value)) {
          form.elements.client_key.value = candidate.client_key;
          form.elements.draft_id.value = candidate.draft_id || '0';
          form.elements.revision.value = candidate.revision || '0';
          recoveryKey = candidate.client_key;
        }
        restore.hidden = true; dirty = true; persist();
        status.textContent = candidate.files ? '入力を復元しました。未保存の添付ファイルは再選択してください。' : '入力を復元しました。';
        schedule();
      });
    }
    const renderFiles = (files) => {
      const checked = new Set(Array.from(form.querySelectorAll('[name="remove_attachments[]"]:checked'), (box) => box.value));
      const container = form.querySelector('[data-draft-files]'); container.replaceChildren();
      files.forEach((file) => {
        const label = document.createElement('label'), box = document.createElement('input');
        box.type = 'checkbox'; box.name = 'remove_attachments[]'; box.value = String(file.id); box.checked = checked.has(box.value);
        label.append(box, document.createTextNode(` 📎 ${file.filename}（チェックして削除）`)); container.append(label);
      });
    };
    const save = () => {
      if (inFlight) return inFlight;
      if ((!dirty && !pending) || conflict || submitting || !navigator.onLine) return Promise.resolve(false);
      inFlight = (async () => {
        try {
          if (recoveryKey) {
            const url = new URL(form.dataset.recoveryUrl, location.href); url.searchParams.set('client_key', recoveryKey);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('保存済み下書きの確認に失敗しました。');
            const { draft } = await response.json();
            if (draft) {
              form.elements.draft_id.value = draft.id; form.elements.revision.value = draft.revision;
              renderFiles(draft.attachments);
            }
            recoveryKey = null;
          }
          if (!pending) {
            form.elements.request_id.value = randomKey();
            pending = { data: new FormData(form), fingerprint: fingerprint(), files: Array.from(fileInput.files) };
          }
          const snapshot = pending; status.textContent = '下書きを保存中…'; fileInput.disabled = true;
          const response = await fetch(form.dataset.autosaveUrl, { method: 'POST', body: snapshot.data, credentials: 'same-origin',
            headers: { Accept: 'application/json' } });
          const result = await response.json();
          if (!response.ok) {
            conflict = response.status === 409; pending = null;
            throw new Error(result.error || '自動保存に失敗しました。');
          }
          const changed = fingerprint() !== snapshot.fingerprint;
          form.elements.draft_id.value = result.id; form.elements.revision.value = result.revision;
          form.elements.client_key.value = result.client_key;
          if (Array.from(fileInput.files).every((file, i) => file === snapshot.files[i]) && fileInput.files.length === snapshot.files.length) fileInput.value = '';
          renderFiles(result.attachments); pending = null; dirty = changed; persist();
          const url = new URL(location.href); url.searchParams.set('message', result.id); url.searchParams.delete('mode');
          history.replaceState(null, '', url);
          status.textContent = `下書きを保存しました（${new Date().toLocaleTimeString()}）`;
          return true;
        } catch (error) {
          dirty = true; persist();
          const message = error instanceof SyntaxError ? '保存結果を確認できません。ログイン状態と通信を確認してください。' : error.message || '通信に失敗しました。';
          status.textContent = `${message} 入力内容はこのタブに保持されています。`;
          return false;
        } finally { fileInput.disabled = false; inFlight = null; }
      })();
      return inFlight;
    };
    const schedule = () => { clearTimeout(timer); timer = setTimeout(save, 1500); };
    const changed = () => { dirty = true; if (!inFlight) pending = null; persist();
      status.textContent = conflict ? '競合のため自動保存を停止しています。入力を保持して下書きを開き直してください。' : '未保存の変更があります。'; schedule(); };
    form.addEventListener('input', changed); form.addEventListener('change', changed);
    setInterval(() => { if (dirty) save(); }, 5000);
    window.addEventListener('offline', () => { persist(); status.textContent = 'オフラインです。入力内容はこのタブに保持されています。'; });
    window.addEventListener('online', save);
    window.addEventListener('beforeunload', (event) => { if (!submitting && (dirty || inFlight)) { persist(); event.preventDefault(); event.returnValue = ''; } });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (submitting || submitRequested || conflict) return;
      submitRequested = true;
      clearTimeout(timer);
      if (inFlight) await inFlight;
      if (pending && !await save()) { submitRequested = false; return; }
      if (conflict) { submitRequested = false; return; }
      submitting = true; persist(); form.elements.request_id.value = randomKey();
      if (event.submitter?.hasAttribute('formaction')) form.action = event.submitter.formAction;
      form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
      HTMLFormElement.prototype.submit.call(form);
    });
  });
  document.querySelectorAll('[data-sync-status-url]').forEach((panel) => {
    const labels = { idle: '未実行', running: '実行中', complete: '完了', failed: '失敗・再試行待ち' };
    setInterval(async () => {
      if (document.hidden) return;
      try {
        const response = await fetch(panel.dataset.syncStatusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const { jobs } = await response.json();
        jobs.forEach((job) => {
          const row = panel.querySelector(`[data-job-account="${Number(job.account_id)}"]`); if (!row) return;
          row.querySelector('[data-job-status]').textContent = labels[job.status] || '未実行';
          row.querySelector('[data-job-finished]').textContent = job.finished_at ? new Date(Number(job.finished_at) * 1000).toLocaleString() : '—';
        });
      } catch { /* Keep the most recent status while offline. */ }
    }, 10000);
  });
  document.querySelectorAll('[data-select-all]').forEach((master) => {
    master.addEventListener('change', () => {
      const form = master.closest('form');
      form?.querySelectorAll('input[name="ids[]"]').forEach((box) => { box.checked = master.checked; });
    });
  });
  document.querySelectorAll('[data-bulk-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!form.querySelector('input[name="ids[]"]:checked')) {
        event.preventDefault(); window.alert('メールを選択してください。');
      }
      const action = form.querySelector('[name="action"]')?.value;
      if (action === 'delete' && !window.confirm('選択したメールを完全に削除しますか？')) event.preventDefault();
    });
  });
  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!window.confirm(button.dataset.confirm || '実行しますか？')) event.preventDefault();
    });
  });
})();
