const friendActions = document.querySelector('.profile-friend-actions');

friendActions?.addEventListener('submit', (event) => {
    if (event.submitter?.value !== 'unfriend') return;
    if (!window.confirm('Unfriend this person? You can send them another friend request later.')) {
        event.preventDefault();
    }
});
