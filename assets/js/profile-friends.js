const friendActions = document.querySelector('.profile-friend-actions');

friendActions?.addEventListener('submit', (event) => {
    const confirmations = {
        unfriend: 'Unfriend this person? You can send them another friend request later.',
        cancel_request: 'Cancel this friend request?'
    };
    const message = confirmations[event.submitter?.value];
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
