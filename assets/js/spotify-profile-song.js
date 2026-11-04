(() => {
    const endpoint = document.body.dataset.spotifyProfileSongEndpoint;
    const token = document.body.dataset.settingsToken;
    if (!endpoint || !token) return;

    const banner = document.querySelector('[data-profile-song-banner]');
    const bannerLink = banner?.querySelector('[data-profile-song-link]');
    const bannerName = banner?.querySelector('[data-profile-song-name]');
    const bannerArtist = banner?.querySelector('[data-profile-song-artist]');
    const bannerSeparator = banner?.querySelector('[data-profile-song-separator]');
    const bannerOpen = banner?.querySelector('[data-profile-song-picker-open]');
    const bannerIcon = banner?.querySelector('[data-profile-song-action-icon]');
    const playerHost = banner?.querySelector('[data-profile-song-browser-player]');
    let embedApi = null;
    let embedController = null;
    let currentTrackId = banner?.dataset.trackId || '';
    let playbackDismissed = false;
    let playing = false;

    const tryPlay = () => {
        if (!embedController || !currentTrackId || playbackDismissed || playing) return;
        try { embedController.play(); } catch (_) {
            banner.title = 'Press play in the profile song header to listen.';
        }
    };
    const createBrowserPlayer = () => {
        if (!embedApi || !playerHost || !currentTrackId) return;
        embedController?.destroy();
        embedController = null;
        playerHost.replaceChildren();
        const target = document.createElement('div');
        playerHost.append(target);
        const creatingTrackId = currentTrackId;
        embedApi.createController(target, {
            uri: `spotify:track:${creatingTrackId}`,
            width: '100%',
            height: 80,
        }, (controller) => {
            if (currentTrackId !== creatingTrackId || playbackDismissed) {
                controller.destroy();
                return;
            }
            embedController = controller;
            controller.addListener('ready', tryPlay);
            controller.addListener('playback_update', ({ data }) => {
                playing = !data.isPaused;
                banner.dataset.playbackState = playing ? 'playing' : 'paused';
            });
        });
    };
    const playProfileSong = (trackId) => {
        currentTrackId = trackId || '';
        playing = false;
        playbackDismissed = false;
        if (banner) banner.dataset.trackId = currentTrackId;
        if (!playerHost) return;
        playerHost.hidden = !currentTrackId;
        if (!currentTrackId) {
            embedController?.destroy();
            embedController = null;
            playerHost.replaceChildren();
        } else {
            createBrowserPlayer();
        }
    };
    if (playerHost) {
        window.onSpotifyIframeApiReady = (api) => {
            embedApi = api;
            createBrowserPlayer();
        };
        const script = document.createElement('script');
        script.src = 'https://open.spotify.com/embed/iframe-api/v1';
        script.async = true;
        document.body.append(script);
        window.addEventListener('click', tryPlay);
        window.addEventListener('keydown', tryPlay);
        window.addEventListener('pagehide', () => embedController?.destroy());
    }

    const updateBanner = (track) => {
        if (!banner) return;
        const hasSong = Boolean(track?.id);
        banner.dataset.hasSong = String(hasSong);
        banner.hidden = false;

        if (bannerName) bannerName.textContent = track?.name || '';
        if (bannerArtist) bannerArtist.textContent = track?.artist || '';
        if (bannerSeparator) bannerSeparator.hidden = !track?.artist;
        if (bannerLink) {
            if (track?.url) bannerLink.href = track.url;
            else bannerLink.removeAttribute('href');
            bannerLink.hidden = !hasSong;
        }
        if (bannerIcon) bannerIcon.textContent = hasSong ? '\u270E' : '+';
        if (bannerOpen) {
            const label = hasSong ? 'Edit profile song' : 'Add profile song';
            bannerOpen.setAttribute('aria-label', label);
            bannerOpen.title = label;
        }
        playProfileSong(track?.id || '');

    };

    const saveSelection = async (action, trackId = '') => {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, track_id: trackId, token }),
        });
        const data = await response.json();
        if (!response.ok || !data.saved) throw new Error(data.error || 'Your profile song could not be saved.');
        return data;
    };

    document.querySelectorAll('[data-spotify-profile-song-picker]').forEach((picker) => {
        const searchForm = picker.querySelector('[data-profile-song-search-form]');
        const searchInput = picker.querySelector('[data-profile-song-search]');
        const results = picker.querySelector('[data-profile-song-results]');
        const status = picker.querySelector('[data-profile-song-status]');
        const current = picker.querySelector('[data-profile-song-current]');
        const embed = picker.querySelector('[data-profile-song-embed]');
        const remove = picker.querySelector('[data-profile-song-remove]');
        const close = picker.querySelector('[data-profile-song-picker-close]');
        let requestController = null;
        let searchTimer = null;

        const setStatus = (message, isError = false) => {
            status.textContent = message;
            status.classList.toggle('is-error', isError);
        };

        const showSelectedTrack = (track) => {
            const trackId = track?.id || '';
            picker.dataset.trackId = trackId;
            if (embed && current) {
                if (trackId) {
                    embed.src = `https://open.spotify.com/embed/track/${encodeURIComponent(trackId)}?utm_source=generator&theme=0`;
                    current.hidden = false;
                } else {
                    embed.removeAttribute('src');
                    current.hidden = true;
                }
            }
            if (remove) remove.hidden = !trackId;
            updateBanner(track);
        };

        const renderResults = (tracks) => {
            results.replaceChildren();
            tracks.forEach((track) => {
                const item = document.createElement('li');
                item.dataset.profileStyle = 'profile-song.result-item';
                const image = document.createElement('img');
                image.dataset.profileStyle = 'profile-song.result-image';
                image.alt = '';
                image.loading = 'lazy';
                if (track.image) image.src = track.image;
                else image.hidden = true;

                const copy = document.createElement('span');
                copy.dataset.profileStyle = 'profile-song.result-copy';
                const name = document.createElement('strong');
                name.dataset.profileStyle = 'profile-song.result-title';
                const artist = document.createElement('span');
                artist.dataset.profileStyle = 'profile-song.result-artist';
                name.textContent = track.name;
                artist.textContent = track.artist;
                copy.append(name, artist);

                const select = document.createElement('button');
                select.dataset.profileStyle = 'profile-song.result-select-control';
                select.type = 'button';
                select.textContent = 'Select';
                select.setAttribute('aria-label', `Select ${track.name}${track.artist ? ` by ${track.artist}` : ''} as profile song`);
                select.disabled = track.id === picker.dataset.trackId;
                select.addEventListener('click', async () => {
                    select.disabled = true;
                    setStatus('Saving...');
                    try {
                        const data = await saveSelection('select', track.id);
                        showSelectedTrack(data.track);
                        renderResults(tracks);
                        setStatus('');
                        if (close) picker.hidden = true;
                    } catch (error) {
                        select.disabled = false;
                        setStatus(error.message, true);
                    }
                });
                item.append(image, copy, select);
                results.append(item);
            });
            results.hidden = tracks.length === 0;
        };

        const searchSpotify = async (query) => {
            requestController?.abort();
            requestController = new AbortController();
            results.hidden = true;
            setStatus('Searching...');
            try {
                const url = new URL(endpoint, window.location.href);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    signal: requestController.signal,
                });
                const data = await response.json();
                if (!response.ok || !Array.isArray(data.tracks)) throw new Error(data.error || 'Spotify search failed.');
                if (searchInput.value.trim() !== query) return;
                renderResults(data.tracks);
                setStatus(data.tracks.length ? '' : 'No songs found.');
            } catch (error) {
                if (error.name !== 'AbortError') setStatus(error.message, true);
            }
        };

        searchInput?.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            requestController?.abort();
            requestController = null;
            const query = searchInput.value.trim();
            if (query.length < 2) {
                results.replaceChildren();
                results.hidden = true;
                setStatus('');
                return;
            }
            searchTimer = window.setTimeout(() => searchSpotify(query), 300);
        });

        searchForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(searchTimer);
            const query = searchInput.value.trim();
            if (query.length >= 2) searchSpotify(query);
        });

        remove?.addEventListener('click', async () => {
            remove.disabled = true;
            setStatus('Removing...');
            try {
                await saveSelection('remove');
                showSelectedTrack(null);
                results.querySelectorAll('button').forEach((button) => { button.disabled = false; });
                setStatus('Profile song removed.');
            } catch (error) {
                setStatus(error.message, true);
            } finally {
                remove.disabled = false;
            }
        });

        close?.addEventListener('click', () => { picker.hidden = true; });
        bannerOpen?.addEventListener('click', () => {
            picker.hidden = false;
            searchInput.focus();
        });
    });

    banner?.querySelector('[data-profile-song-dismiss]')?.addEventListener('click', () => {
        embedController?.pause();
        playbackDismissed = true;
        banner.hidden = true;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-spotify-profile-song-picker]').forEach((picker) => { picker.hidden = true; });
    });
})();
