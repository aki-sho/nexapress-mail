(() => {
  'use strict';
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
