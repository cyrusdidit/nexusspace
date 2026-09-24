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
