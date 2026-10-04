document.addEventListener('DOMContentLoaded', () => {
    const tabs = [...document.querySelectorAll('[data-profile-appearance-tab]')];
    const sections = [...document.querySelectorAll('[data-profile-appearance-section]')];
    const simpleForm = document.querySelector('[data-profile-customization-inline]');
    const advancedForm = document.querySelector('[data-profile-customization-advanced]');
    if (!tabs.length || !simpleForm || !advancedForm) return;

    const settingsField = simpleForm.querySelector('[data-inline-simple-settings]');
    const advancedSettings = advancedForm.querySelector('[data-inline-advanced-settings]');
    const widthField = simpleForm.querySelector('[data-inline-sidebar-width-value]');
    const advancedWidth = advancedForm.querySelector('[data-inline-advanced-width]');
    const widthControl = simpleForm.querySelector('[data-inline-sidebar-width]');
    const widthOutput = simpleForm.querySelector('[data-inline-sidebar-width-output]');
    const targetField = simpleForm.querySelector('[data-inline-style-target]');
    const appearancePanel = document.querySelector('[data-profile-appearance-panel]');
    const propertyFields = [...simpleForm.querySelectorAll('[data-inline-style-property]')];
    const simpleStatus = simpleForm.querySelector('[data-inline-customization-status]');
    const advancedStatus = advancedForm.querySelector('[data-inline-advanced-status]');
    const templateField = advancedForm.elements.template_html;
    const cssField = advancedForm.elements.custom_css;
    const undoButtons = [...document.querySelectorAll('[data-inline-undo]')];
    const redoButtons = [...document.querySelectorAll('[data-inline-redo]')];
    let settings = {};
    let draftTimer;
    let draftRequest;
    let undoStack = [];
    let redoStack = [];
    let baseline;
    let lastHistoryKey = '';
    let lastHistoryTime = 0;
    let picking = false;
    let hoveredTarget = null;
    try { settings = JSON.parse(settingsField.value || '{}'); } catch { settings = {}; }

    const showSection = (name) => {
        tabs.forEach((tab) => tab.setAttribute('aria-pressed', String(tab.dataset.profileAppearanceTab === name)));
        sections.forEach((section) => { section.hidden = section.dataset.profileAppearanceSection !== name; });
        window.localStorage.setItem('nexus-profile-appearance-section', name);
    };
    tabs.forEach((tab) => tab.addEventListener('click', () => showSection(tab.dataset.profileAppearanceTab)));
    const savedSection = window.localStorage.getItem('nexus-profile-appearance-section');
    if (tabs.some((tab) => tab.dataset.profileAppearanceTab === savedSection)) showSection(savedSection);

    const ensureStyle = (attribute) => {
        let style = document.head.querySelector(`style[${attribute}]`);
        if (!style) {
            style = document.createElement('style');
            style.setAttribute(attribute, '');
            document.head.append(style);
        }
        return style;
    };
    const applyCss = (data) => {
        ensureStyle('data-profile-simple-css').textContent = data.simple_css || '';
        ensureStyle('data-profile-custom-css').textContent = data.custom_css || '';
    };
    const syncSettings = () => {
        const serialized = JSON.stringify(settings);
        settingsField.value = serialized;
        advancedSettings.value = serialized;
        advancedWidth.value = widthField.value;
        simpleForm.elements.template_html.value = templateField.value;
        simpleForm.elements.custom_css.value = cssField.value;
    };
    const snapshot = () => ({
        settings: JSON.parse(JSON.stringify(settings)),
        width: widthField.value,
        template: templateField.value,
        css: cssField.value,
    });
    const snapshotSignature = (value) => JSON.stringify(value);
    const updateHistoryButtons = () => {
        undoButtons.forEach((button) => { button.disabled = undoStack.length === 0; });
        redoButtons.forEach((button) => { button.disabled = redoStack.length === 0; });
    };
    const remember = (key) => {
        const now = Date.now();
        if (baseline && (key !== lastHistoryKey || now - lastHistoryTime > 400)) {
            if (snapshotSignature(undoStack.at(-1)) !== snapshotSignature(baseline)) undoStack.push(baseline);
            if (undoStack.length > 50) undoStack.shift();
        }
        redoStack = [];
        lastHistoryKey = key;
        lastHistoryTime = now;
        updateHistoryButtons();
    };
    propertyFields.forEach((field) => { field.dataset.defaultValue = field.value; });
    const renderFields = () => {
        const values = settings[targetField.value] || {};
        propertyFields.forEach((field) => {
            const property = field.dataset.inlineStyleProperty;
            const value = values[property];
            if (field.type === 'color' || field.type === 'range') field.value = value ?? field.dataset.defaultValue;
            else field.value = value ?? (property === 'font_family' ? 'system' : property === 'animation' ? 'none' : '');
            field.closest('label')?.classList.toggle('is-customized', value !== undefined);
            const output = simpleForm.querySelector(`[data-inline-property-output="${property}"]`);
            if (output) output.textContent = value === undefined ? 'Default' : `${value}%`;
        });
    };
    const clearTargetHighlights = () => {
        document.querySelectorAll('.is-profile-style-hovered, .is-profile-style-selected').forEach((element) => element.classList.remove('is-profile-style-hovered', 'is-profile-style-selected'));
        hoveredTarget = null;
    };
    const highlightSelectedTarget = () => {
        clearTargetHighlights();
        if (!picking || simpleForm.hidden) return;
        document.querySelectorAll(`[data-profile-style="${targetField.value}"]`).forEach((element) => {
            if (!element.closest('[data-profile-appearance-panel]') && !element.matches('[data-profile-sidebar-resize]')) element.classList.add('is-profile-style-selected');
        });
    };
    const setPicking = (active) => {
        picking = active;
        document.body.classList.toggle('is-picking-profile-style', active);
        if (!active) clearTargetHighlights();
    };
    const syncPicking = () => {
        setPicking(Boolean(appearancePanel && !appearancePanel.hidden && !simpleForm.hidden));
        if (picking) highlightSelectedTarget();
    };
    const setProperty = (property, value) => {
        const target = targetField.value;
        settings[target] ||= {};
        if (value === '' || value === 'system' || value === 'none') delete settings[target][property];
        else settings[target][property] = value;
        if (!Object.keys(settings[target]).length) delete settings[target];
        syncSettings();
    };

    const request = async (form, action, status) => {
        const body = new FormData(form);
        body.set('action', action);
        const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: action === 'draft' ? draftRequest.signal : undefined });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.saved) throw new Error(data.error || 'Your customization could not be saved.');
        applyCss(data);
        const warnings = [...(data.warnings?.html || []), ...(data.warnings?.css || [])];
        status.textContent = warnings[0] || (action === 'draft' ? 'Previewing draft' : 'Saved');
        status.classList.toggle('is-error', warnings.length > 0);
        return data;
    };
    const scheduleDraft = (form = simpleForm, status = simpleStatus) => {
        window.clearTimeout(draftTimer);
        draftRequest?.abort();
        status.textContent = 'Updating preview...';
        draftTimer = window.setTimeout(async () => {
            draftRequest = new AbortController();
            try { await request(form, 'draft', status); }
            catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
        }, 220);
    };

    propertyFields.forEach((field) => field.addEventListener('input', () => {
        remember(`simple:${field.dataset.inlineStyleProperty}`);
        const value = field.type === 'number' || field.type === 'range' ? (field.value === '' ? '' : Number(field.value)) : field.value;
        setProperty(field.dataset.inlineStyleProperty, value);
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    }));
    simpleForm.querySelectorAll('[data-inline-clear-property]').forEach((button) => button.addEventListener('click', () => {
        remember(`clear:${button.dataset.inlineClearProperty}`);
        setProperty(button.dataset.inlineClearProperty, '');
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    }));
    simpleForm.querySelector('[data-inline-reset-target]')?.addEventListener('click', () => {
        remember('reset-target');
        delete settings[targetField.value];
        syncSettings();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    simpleForm.querySelector('[data-inline-reset-all]')?.addEventListener('click', () => {
        remember('reset-all');
        settings = {};
        widthControl.value = '280';
        widthField.value = '280';
        widthOutput.textContent = '280px';
        document.querySelector('[data-profile-style="layout.root"]')?.style.setProperty('--profile-sidebar-width', '280px');
        syncSettings();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    targetField.addEventListener('change', () => {
        renderFields();
        highlightSelectedTarget();
    });
    tabs.forEach((tab) => tab.addEventListener('click', syncPicking));
    if (appearancePanel) new MutationObserver(syncPicking).observe(appearancePanel, { attributes: true, attributeFilter: ['hidden'] });
    document.addEventListener('mouseover', (event) => {
        if (!picking) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        if (!candidate || candidate.closest('[data-profile-appearance-panel]') || candidate.matches('[data-profile-sidebar-resize]')) return;
        if (hoveredTarget !== candidate) {
            hoveredTarget?.classList.remove('is-profile-style-hovered');
            hoveredTarget = candidate;
            hoveredTarget.classList.add('is-profile-style-hovered');
        }
    }, true);
    document.addEventListener('click', (event) => {
        if (!picking) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        const target = candidate?.dataset.profileStyle;
        if (!candidate || !target || candidate.closest('[data-profile-appearance-panel]') || candidate.matches('[data-profile-sidebar-resize]')) return;
        if (![...targetField.options].some((option) => option.value === target)) return;
        event.preventDefault();
        event.stopPropagation();
        targetField.value = target;
        renderFields();
        highlightSelectedTarget();
        simpleForm.querySelector('.profile-inline-target-picker')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, true);
    widthControl.addEventListener('input', () => {
        remember('sidebar-width');
        widthField.value = widthControl.value;
        widthOutput.textContent = `${widthControl.value}px`;
        document.querySelector('[data-profile-style="layout.root"]')?.style.setProperty('--profile-sidebar-width', `${widthControl.value}px`);
        syncSettings();
        baseline = snapshot();
        scheduleDraft();
    });
    simpleForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncSettings();
        simpleStatus.textContent = 'Saving...';
        try { await request(simpleForm, 'save', simpleStatus); }
        catch (error) { simpleStatus.textContent = error.message; }
    });
    advancedForm.addEventListener('input', (event) => {
        remember(`advanced:${event.target.name || event.target.id}`);
        syncSettings();
        baseline = snapshot();
        scheduleDraft(advancedForm, advancedStatus);
    });
    advancedForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        advancedStatus.textContent = 'Saving...';
        try {
            await request(advancedForm, 'save', advancedStatus);
            window.location.reload();
        } catch (error) { advancedStatus.textContent = error.message; }
    });

    const applySnapshot = (value) => {
        settings = JSON.parse(JSON.stringify(value.settings));
        widthField.value = value.width;
        widthControl.value = value.width;
        widthOutput.textContent = `${value.width}px`;
        templateField.value = value.template;
        cssField.value = value.css;
        document.querySelector('[data-profile-style="layout.root"]')?.style.setProperty('--profile-sidebar-width', `${value.width}px`);
        syncSettings();
        renderFields();
        baseline = snapshot();
        lastHistoryKey = '';
        updateHistoryButtons();
        scheduleDraft();
    };
    undoButtons.forEach((button) => button.addEventListener('click', () => {
        const previous = undoStack.pop();
        if (!previous) return;
        redoStack.push(snapshot());
        applySnapshot(previous);
    }));
    redoButtons.forEach((button) => button.addEventListener('click', () => {
        const next = redoStack.pop();
        if (!next) return;
        undoStack.push(snapshot());
        applySnapshot(next);
    }));
    syncSettings();
    renderFields();
    syncPicking();
    baseline = snapshot();
    updateHistoryButtons();
});
