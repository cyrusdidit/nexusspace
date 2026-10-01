(() => {
    const ownSteamEndpoint = document.body.dataset.steamActivityEndpoint;
    const refreshOwnSteam = async () => {
        if (!ownSteamEndpoint || document.hidden) return;
        try {
            await fetch(ownSteamEndpoint, { credentials: 'same-origin', cache: 'no-store' });
        } catch (error) {}
    };
    window.setInterval(refreshOwnSteam, 15 * 1000);

    const root = document.querySelector('[data-profile-now-playing]');
    const profileId = document.body.dataset.profileUserId;
    const endpoint = document.body.dataset.profileSocialActivityEndpoint;
    const activitySection = document.querySelector('.profile-sidebar-activity');
    const activityFeedback = document.querySelector('[data-profile-activity-feedback]');
    const writtenStatus = document.querySelector('[data-profile-written-status]');
    const close = root?.querySelector('[data-profile-now-playing-close]');
    const musicRow = document.querySelector('[data-profile-music-row]');
    const musicName = document.querySelector('[data-profile-music-name]');
    const musicArtist = document.querySelector('[data-profile-music-artist]');
    const musicImage = document.querySelector('[data-profile-music-image]');
    const musicLink = document.querySelector('[data-profile-music-link]');
    const musicAdd = document.querySelector('[data-profile-music-add]');
    const nowPlayingName = root?.querySelector('[data-profile-now-playing-name]');
    const nowPlayingArtist = root?.querySelector('[data-profile-now-playing-artist]');
    const gameRow = document.querySelector('[data-profile-game-row]');
    const gameName = document.querySelector('[data-profile-game-name]');
    const gameImage = document.querySelector('[data-profile-game-image]');
    const gameDuration = document.querySelector('[data-profile-game-duration]');
    let gameStartedAt = gameRow.hidden ? null : Date.now() - (Number(gameDuration.dataset.elapsedSeconds) || 0) * 1000;
    let musicActive = !musicRow.hidden;
    let nowPlayingDismissed = false;

    const renderNowPlaying = () => {
        if (!root) return;
        root.hidden = !musicActive || nowPlayingDismissed;
    };

    const dismissNowPlaying = () => {
        nowPlayingDismissed = true;
        renderNowPlaying();
    };

    renderNowPlaying();

    const formatDuration = (seconds) => {
        const totalSeconds = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor(totalSeconds / 60) % 60;
        const remainingSeconds = totalSeconds % 60;
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    };

    const updateClock = () => {
        if (gameStartedAt === null || gameRow.hidden) return;
        gameDuration.textContent = formatDuration((Date.now() - gameStartedAt) / 1000);
    };

    const refresh = async () => {
        if (!endpoint || document.hidden) return;
        try {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('user', profileId);
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) return;

            const statusText = typeof data.status === 'string' ? data.status.trim() : '';
            if (writtenStatus instanceof HTMLInputElement) {
                if (document.activeElement !== writtenStatus) writtenStatus.value = statusText;
            } else {
                writtenStatus.textContent = statusText;
                writtenStatus.hidden = statusText === '';
            }
            writtenStatus.title = statusText;

            if (!data.music) {
                musicActive = false;
                musicRow.hidden = true;
            } else {
                musicActive = true;
                musicName.textContent = data.music.name;
                musicArtist.textContent = data.music.artist || '';
                const fullTrackName = data.music.artist ? `${data.music.name} • ${data.music.artist}` : data.music.name;
                musicLink.title = fullTrackName;
                if (data.music.url) musicLink.href = data.music.url;
                else musicLink.removeAttribute('href');
                musicAdd.dataset.trackId = data.music.id || '';
                musicAdd.disabled = !data.music.id;
                musicAdd.textContent = '+';
                musicAdd.title = 'Add to Liked Songs';
                if (nowPlayingName) nowPlayingName.textContent = data.music.name;
                if (nowPlayingArtist) nowPlayingArtist.textContent = data.music.artist || '';
                if (data.music.image) {
                    musicImage.src = data.music.image;
                    musicImage.hidden = false;
                } else {
                    musicImage.hidden = true;
                    musicImage.removeAttribute('src');
                }
                musicRow.hidden = false;
            }
            renderNowPlaying();

            if (!data.game) {
                gameStartedAt = null;
                gameRow.hidden = true;
                gameName.textContent = '';
                gameDuration.textContent = '';
                gameImage.hidden = true;
                gameImage.removeAttribute('src');
                return;
            }

            const elapsed = Math.max(0, Number(data.game.elapsed_seconds) || 0);
            gameName.textContent = data.game.name;
            if (data.game.image) {
                gameImage.src = data.game.image;
                gameImage.hidden = false;
            } else {
                gameImage.hidden = true;
                gameImage.removeAttribute('src');
            }
            gameDuration.dataset.elapsedSeconds = String(elapsed);
            gameStartedAt = Date.now() - elapsed * 1000;
            gameRow.hidden = false;
            updateClock();
        } catch (error) {
            // Preserve the last known activity during a temporary failure.
        }
    };

    close?.addEventListener('click', dismissNowPlaying);
    musicImage.addEventListener('error', () => { musicImage.hidden = true; });
    gameImage.addEventListener('error', () => { gameImage.hidden = true; });
    musicAdd.addEventListener('click', async () => {
        if (!activitySection.dataset.spotifyLikeEndpoint || !musicAdd.dataset.trackId || musicAdd.disabled) return;
        musicAdd.disabled = true;
        musicAdd.textContent = '...';
        activityFeedback.hidden = true;
        try {
            const response = await fetch(activitySection.dataset.spotifyLikeEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: activitySection.dataset.spotifyActionToken,
                    track_id: musicAdd.dataset.trackId,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.saved) throw new Error(data.error || 'Spotify could not save that song.');
            musicAdd.textContent = '✓';
            musicAdd.title = 'Added to the top of Liked Songs';
            musicAdd.setAttribute('aria-label', 'Added to the top of Liked Songs');
            activityFeedback.textContent = '';
            activityFeedback.classList.remove('is-error');
            activityFeedback.hidden = true;
        } catch (error) {
            musicAdd.textContent = '+';
            musicAdd.title = error.message;
            musicAdd.setAttribute('aria-label', error.message);
            musicAdd.disabled = false;
            activityFeedback.textContent = error.message;
            activityFeedback.classList.add('is-error');
            activityFeedback.hidden = false;
        }
    });
    window.setInterval(updateClock, 1000);
    window.setInterval(refresh, 15 * 1000);
    window.addEventListener('focus', refresh);
    document.addEventListener('nexusspace:spotify-activity-updated', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    updateClock();
    if (document.body.dataset.currentUserId === profileId) {
        refreshOwnSteam().finally(refresh);
    } else {
        refreshOwnSteam();
        refresh();
    }
})();
