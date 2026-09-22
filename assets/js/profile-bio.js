const bioInput = document.getElementById('profile-bio-input');
const bioCounter = document.getElementById('bio-limit');

if (bioInput && bioCounter) {
    const updateBioEditor = () => {
        bioCounter.textContent = `${Array.from(bioInput.value).length}/160`;
    };
    bioInput.addEventListener('input', updateBioEditor);
    bioInput.closest('details').addEventListener('toggle', updateBioEditor);
    updateBioEditor();
}
