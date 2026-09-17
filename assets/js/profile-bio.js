const bioInput = document.getElementById('profile-bio-input');
const bioCounter = document.getElementById('bio-limit');

if (bioInput && bioCounter) {
    const updateBioEditor = () => {
        bioCounter.textContent = `${Array.from(bioInput.value).length}/160 characters used`;
        bioInput.style.height = 'auto';
        bioInput.style.height = `${bioInput.scrollHeight + 2}px`;
    };
    bioInput.addEventListener('input', updateBioEditor);
    bioInput.closest('details').addEventListener('toggle', updateBioEditor);
    window.addEventListener('resize', updateBioEditor);
    updateBioEditor();
}
