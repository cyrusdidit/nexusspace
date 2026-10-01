const bioInput = document.getElementById('profile-bio-input');
const bioCounter = document.getElementById('bio-limit');
const bioEditor = bioInput?.closest('details');

if (bioEditor && document.body.dataset.bioSaved === 'true') {
    const finishSave = () => {
        bioEditor.open = false;
        document.body.removeAttribute('data-bio-saved');
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('bio_saved');
        window.history.replaceState({}, '', cleanUrl);
    };
    bioEditor.open = false;
    window.addEventListener('pageshow', () => window.setTimeout(finishSave, 50), { once: true });
}

if (bioInput && bioCounter) {
    const updateBioEditor = () => {
        bioCounter.textContent = `${Array.from(bioInput.value).length}/160`;
    };
    bioInput.addEventListener('input', updateBioEditor);
    bioEditor.addEventListener('toggle', updateBioEditor);
    updateBioEditor();
}

const statusInput = document.getElementById('profile-status-input');
const statusForm = statusInput?.closest('[data-inline-status-form]');
const statusPencil = statusForm?.querySelector('[data-status-edit-pencil]');

if (statusInput && statusForm) {
    const savedStatus = statusInput.value.trim();
    let statusSubmitting = false;
    const saveStatus = () => {
        if (statusSubmitting || statusInput.value.trim() === savedStatus) return;
        statusSubmitting = true;
        statusForm.requestSubmit();
    };
    statusInput.addEventListener('input', () => { statusInput.title = statusInput.value.trim(); });
    statusInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        saveStatus();
        if (!statusSubmitting) statusInput.blur();
    });
    statusInput.addEventListener('blur', saveStatus);
    statusPencil?.addEventListener('pointerdown', (event) => event.preventDefault());
    statusPencil?.addEventListener('click', () => {
        statusInput.focus();
        const end = statusInput.value.length;
        statusInput.setSelectionRange(end, end);
    });
}
