'use strict';

function searchText(value) {
    return value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

for (const group of document.querySelectorAll('[data-compose-search]')) {
    const input = group.querySelector('input[type="search"]');
    const status = group.querySelector('[role="status"]');
    const selects = Array.from(input.form.querySelectorAll('select'))
        .filter(select => select.name === group.dataset.composeSearch)
        .map(select => ({select, options: Array.from(select.options)}));
    if (!selects.length) continue;

    const labels = new Map(selects[0].options.map(option => [option.value, searchText(option.textContent)]));
    input.addEventListener('input', () => {
        const query = searchText(input.value);
        const matches = new Set();
        for (const [key, label] of labels) {
            if (key && label.includes(query)) matches.add(key);
        }

        for (const {select, options} of selects) {
            const selected = select.value;
            const visible = document.createDocumentFragment();
            for (const option of options) {
                if (!option.value || option.value === selected || matches.has(option.value)) visible.append(option);
            }
            select.replaceChildren(visible);
            select.value = selected;
        }

        const noun = group.dataset.searchNoun;
        status.textContent = !query ? '' : !matches.size
            ? 'No matching ' + noun + ' in this catalog.'
            : matches.size === 1 ? '1 match in this catalog.' : matches.size + ' matches in this catalog.';
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            selects[0].select.focus();
        }
    });
    group.hidden = false;
}
