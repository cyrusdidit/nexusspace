const friendActions = document.querySelector('.profile-friend-actions');

friendActions?.addEventListener('submit', (event) => {
    const confirmations = {
        unfriend: 'Unfriend this person? You can send them another friend request later.',
        cancel_request: 'Cancel this friend request?',
        block: 'Block this person? They will disappear from your profile, search, messages, and notifications.'
    };
    const message = confirmations[event.submitter?.value];
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
