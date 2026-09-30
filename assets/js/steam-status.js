(() => {
    const endpoint = document.body.dataset.steamActivityEndpoint;
    const game = document.querySelector('[data-current-game]');
    if (!endpoint || !game) return;

    const image = game.querySelector('[data-current-game-image]');
    const name = game.querySelector('[data-current-game-name]');
    const duration = game.querySelector('[data-current-game-duration]');
    const initialElapsed = Math.max(0, Number(game.dataset.elapsedSeconds) || 0);
    let detectedAt = game.hidden ? null : Date.now() - initialElapsed * 1000;

    const formatDuration = (seconds) => {
        const totalMinutes = Math.max(0, Math.floor(seconds / 60));
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    };

    const updateClock = () => {
        if (detectedAt === null || game.hidden) return;
        duration.textContent = formatDuration((Date.now() - detectedAt) / 1000);
    };

    const hideGame = () => {
        detectedAt = null;
        game.hidden = true;
        name.textContent = '';
        duration.textContent = '';
        image.hidden = true;
        image.removeAttribute('src');
    };

    const refresh = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const data = await response.json();
            if (!response.ok) return;
            if (!data.active || !data.name) {
                hideGame();
                return;
            }

            const elapsed = Math.max(0, Number(data.elapsed_seconds) || 0);
            name.textContent = data.name;
            game.title = data.name;
            game.dataset.elapsedSeconds = String(elapsed);
            detectedAt = Date.now() - elapsed * 1000;
            if (data.image) {
                image.src = data.image;
                image.alt = '';
                image.hidden = false;
            } else {
                image.hidden = true;
                image.removeAttribute('src');
            }
            game.hidden = false;
            updateClock();
        } catch (error) {
            // Preserve the clock and last known game during a temporary failure.
        }
    };

    image.addEventListener('error', () => {
        image.hidden = true;
    });
    updateClock();
    refresh();
    window.setInterval(updateClock, 1000);
    window.setInterval(refresh, 15 * 1000);
    window.addEventListener('focus', refresh);
    window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
})();
