(() => {
    const ownSteamEndpoint = document.body.dataset.steamActivityEndpoint;
    const refreshOwnSteam = () => {
        if (!ownSteamEndpoint || document.hidden) return;
        fetch(ownSteamEndpoint, { credentials: 'same-origin', cache: 'no-store' }).catch(() => {});
    };
    refreshOwnSteam();
    window.setInterval(refreshOwnSteam, 15 * 1000);

    const root = document.querySelector('[data-profile-now-playing]');
    if (!root) return;

    const profileId = document.body.dataset.profileUserId;
    const endpoint = document.body.dataset.profileSocialActivityEndpoint;
    const close = root.querySelector('[data-profile-now-playing-close]');
    const musicRow = document.querySelector('[data-profile-music-row]');
    const musicName = document.querySelector('[data-profile-music-name]');
    const musicArtist = document.querySelector('[data-profile-music-artist]');
    const nowPlayingName = root.querySelector('[data-profile-now-playing-name]');
    const nowPlayingArtist = root.querySelector('[data-profile-now-playing-artist]');
    const gameRow = document.querySelector('[data-profile-game-row]');
    const gameName = document.querySelector('[data-profile-game-name]');
    const gameDuration = document.querySelector('[data-profile-game-duration]');
    let gameStartedAt = gameRow.hidden ? null : Date.now() - (Number(gameDuration.dataset.elapsedSeconds) || 0) * 1000;
    let musicActive = !musicRow.hidden;
    let nowPlayingDismissed = false;

    const renderNowPlaying = () => {
        root.hidden = !musicActive || nowPlayingDismissed;
    };

    const dismissNowPlaying = () => {
        nowPlayingDismissed = true;
        renderNowPlaying();
    };

    renderNowPlaying();

    const formatDuration = (seconds) => {
        const totalMinutes = Math.max(0, Math.floor(seconds / 60));
        return `${String(Math.floor(totalMinutes / 60)).padStart(2, '0')}:${String(totalMinutes % 60).padStart(2, '0')}`;
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

            if (!data.music) {
                musicActive = false;
                musicRow.hidden = true;
            } else {
                musicActive = true;
                musicName.textContent = data.music.name;
                musicArtist.textContent = data.music.artist || '';
                nowPlayingName.textContent = data.music.name;
                nowPlayingArtist.textContent = data.music.artist || '';
                musicRow.hidden = false;
            }
            renderNowPlaying();

            if (!data.game) {
                gameStartedAt = null;
                gameRow.hidden = true;
                gameName.textContent = '';
                gameDuration.textContent = '';
                return;
            }

            const elapsed = Math.max(0, Number(data.game.elapsed_seconds) || 0);
            gameName.textContent = data.game.name;
            gameDuration.dataset.elapsedSeconds = String(elapsed);
            gameStartedAt = Date.now() - elapsed * 1000;
            gameRow.hidden = false;
            updateClock();
        } catch (error) {
            // Preserve the last known activity during a temporary failure.
        }
    };

    close.addEventListener('click', dismissNowPlaying);
    window.setInterval(updateClock, 1000);
    window.setInterval(refresh, 15 * 1000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    updateClock();
    refresh();
})();
