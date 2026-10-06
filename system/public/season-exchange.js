document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-season-exchange]');
  if (!form) return;
  const choice = form.elements.incoming_mode;
  const update = () => {
    for (const mode of ['existing', 'new']) {
      const group = form.querySelector(`[data-incoming-${mode}]`);
      group.hidden = choice.value !== mode;
      group.querySelectorAll('input, select, textarea').forEach(field => { field.disabled = group.hidden; });
    }
  };
  choice.addEventListener('change', update);
  update();
});
