(() => {
    const root = document.getElementById('album-grid-menu-editor');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = 'true';

    const {menu: initial, categories, labels, maxGroups, automatic: initialAutomatic} =
        JSON.parse(root.querySelector('[data-menu-data]').textContent);
    const field = root.querySelector('[name="album_grid_menu"]');
    const form = field.form;
    const groupList = root.querySelector('[data-menu-groups]');
    const categoryList = root.querySelector('[data-menu-categories]');
    const preview = root.querySelector('[data-menu-preview]');
    const add = root.querySelector('[data-menu-add]');
    const titleById = new Map(categories.map(category => [Number(category.id), category.name]));
    let menu = initial;
    let automatic = initialAutomatic;

    function element(tag, text, className) {
        const item = document.createElement(tag);
        if (text !== null) item.textContent = text;
        if (className) item.className = className;
        return item;
    }

    function labelFor(group) {
        return group.label.trim() || labels.categories;
    }

    function refreshPreview() {
        field.value = automatic ? 'null' : JSON.stringify(menu);
        add.disabled = menu.groups.length >= maxGroups;
        preview.replaceChildren(...menu.links.map(id => element('li', titleById.get(id))));
        for (const group of menu.groups) {
            if (!group.ids.length) continue;
            const container = element('li', null);
            const disclosure = element('details', null);
            const summary = element('summary', labelFor(group), 'cursor-pointer');
            const links = element('ul', null, 'space-y-1 pl-4 mt-2');
            links.append(...group.ids.map(id => element('li', titleById.get(id))));
            disclosure.append(summary, links);
            container.append(disclosure);
            preview.append(container);
        }
    }

    function changed() {
        automatic = false;
        refreshPreview();
    }

    function render() {
        groupList.replaceChildren();
        categoryList?.replaceChildren();
        menu.groups.forEach((group, index) => {
            const row = element('div', null, 'flex flex-wrap items-end gap-2');
            const caption = element('label', labels.name, 'flex-1 text-sm');
            const name = element('input', null, 'form-input mt-1');
            name.maxLength = 80;
            name.value = group.label;
            name.placeholder = labels.categories;
            name.addEventListener('input', () => {
                group.label = name.value;
                categoryList?.querySelectorAll('select').forEach(select => {
                    select.options[index + 2].textContent = labelFor(group);
                });
                changed();
            });
            caption.append(name);
            const remove = element('button', labels.remove, 'btn-secondary');
            remove.type = 'button';
            remove.addEventListener('click', () => {
                menu.links.push(...group.ids);
                menu.groups.splice(index, 1);
                automatic = false;
                render();
                add.focus();
            });
            row.append(caption, remove);
            groupList.append(row);
        });

        for (const [id, title] of titleById) {
            const row = element('div', null, 'py-2 flex flex-wrap items-center gap-3');
            row.append(element('span', title, 'flex-1 min-w-32 text-sm font-medium'));
            const select = element('select', null, 'form-input w-auto');
            select.setAttribute('aria-label', labels.location + ' — ' + title);
            select.add(new Option(labels.hidden, 'hidden'));
            select.add(new Option(labels.top, 'top'));
            menu.groups.forEach((group, index) => {
                select.add(new Option(labelFor(group), String(index)));
            });
            const groupIndex = menu.groups.findIndex(group => group.ids.includes(id));
            select.value = menu.links.includes(id) ? 'top' : groupIndex < 0 ? 'hidden' : String(groupIndex);
            select.addEventListener('change', () => {
                menu.links = menu.links.filter(category => category !== id);
                menu.groups.forEach(group => { group.ids = group.ids.filter(category => category !== id); });
                if (select.value === 'top') menu.links.push(id);
                else if (select.value !== 'hidden') menu.groups[Number(select.value)].ids.push(id);
                changed();
            });
            const home = element('label', null, 'flex items-center gap-2 text-sm');
            const checkbox = element('input', null);
            checkbox.type = 'checkbox';
            checkbox.checked = menu.home_categories.includes(id);
            checkbox.setAttribute('aria-label', labels.home + ' — ' + title);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) menu.home_categories.push(id);
                else menu.home_categories = menu.home_categories.filter(category => category !== id);
                changed();
            });
            home.append(checkbox, labels.home);
            row.append(select, home);
            categoryList?.append(row);
        }
        refreshPreview();
    }

    add.addEventListener('click', () => {
        menu.groups.push({label: labels.newGroup, ids: []});
        automatic = false;
        render();
        groupList.lastElementChild.querySelector('input').select();
    });
    root.querySelector('[data-menu-reset]').addEventListener('click', () => {
        const parents = categories.filter(category => category.parent_id === null).map(category => Number(category.id));
        menu = {links: [], groups: [{label: '', ids: parents}], home_categories: []};
        automatic = true;
        render();
    });
    form.addEventListener('change', event => {
        if (event.target.name !== 'home_template') return;
        field.disabled = event.target.value !== 'album-grid';
        root.classList.toggle('hidden', field.disabled);
    });
    render();
})();
