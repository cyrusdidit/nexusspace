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
    const playbackEndpoint = document.body.dataset.spotifyProfilePlaybackEndpoint;
    const profileUserId = document.body.dataset.profileUserId;
    const volumeControl = banner?.querySelector('[data-profile-song-volume]');
    const volumeSlider = banner?.querySelector('[data-profile-song-volume-slider]');
    const muteButton = banner?.querySelector('[data-profile-song-mute]');
    const volumeIcon = banner?.querySelector('[data-profile-song-volume-icon]');
    const volumeStorageKey = profileUserId ? `nexusspace:profile-song-volume:${profileUserId}` : '';
    let spotifyPlayer = null;
    let playbackDeviceId = '';
    let profileSongStarted = false;
    let currentTrackId = banner?.dataset.trackId || '';
    let previousVolume = 70;
    let muted = false;

    const readVolumePreference = () => {
        if (!volumeStorageKey) return;
        try {
            const preference = JSON.parse(localStorage.getItem(volumeStorageKey) || '{}');
            const savedVolume = Number(preference.volume);
            if (Number.isFinite(savedVolume)) volumeSlider.value = String(Math.min(100, Math.max(0, savedVolume)));
            previousVolume = Math.min(100, Math.max(1, Number(preference.previousVolume) || Number(volumeSlider.value) || 70));
            muted = Boolean(preference.muted) || Number(volumeSlider.value) === 0;
        } catch (_) {
            // Ignore invalid preferences and use the default volume.
        }
    };

    const saveVolumePreference = () => {
        if (!volumeStorageKey || !volumeSlider) return;
        try {
            localStorage.setItem(volumeStorageKey, JSON.stringify({
                volume: Number(volumeSlider.value),
                previousVolume,
                muted,
            }));
        } catch (_) {
            // Playback still works when browser storage is unavailable.
        }
    };

    const updateVolumeControl = () => {
        if (!volumeSlider || !muteButton || !volumeIcon) return;
        const effectiveVolume = muted ? 0 : Number(volumeSlider.value);
        volumeIcon.textContent = effectiveVolume === 0 ? '\uD83D\uDD07' : '\uD83D\uDD0A';
        muteButton.setAttribute('aria-label', effectiveVolume === 0 ? 'Unmute profile song' : 'Mute profile song');
        muteButton.title = effectiveVolume === 0 ? 'Unmute' : 'Mute';
        spotifyPlayer?.setVolume(effectiveVolume / 100);
    };

    const fetchPlaybackToken = async () => {
        const response = await fetch(playbackEndpoint, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok || !data.access_token) throw new Error(data.error || 'Spotify playback is unavailable.');
        return data.access_token;
    };

    const requestPlayback = async () => {
        if (!playbackEndpoint || !playbackDeviceId || !currentTrackId || profileSongStarted) return;
        const response = await fetch(playbackEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ token, track_id: currentTrackId, device_id: playbackDeviceId }),
        });
        const data = await response.json();
        if (!response.ok || !data.playing) throw new Error(data.error || 'Spotify could not start the profile song.');
        profileSongStarted = true;
    };

    const showPlaybackError = (message) => {
        if (!banner || !message) return;
        banner.title = message;
        volumeControl?.classList.add('is-unavailable');
    };

    const playProfileSong = (trackId) => {
        currentTrackId = trackId || '';
        if (banner) banner.dataset.trackId = currentTrackId;
        profileSongStarted = false;
        if (!currentTrackId) {
            spotifyPlayer?.pause();
            return;
        }
        requestPlayback().catch((error) => showPlaybackError(error.message));
    };

    if (banner && playbackEndpoint) {
        readVolumePreference();
        updateVolumeControl();
        window.onSpotifyWebPlaybackSDKReady = () => {
            spotifyPlayer = new Spotify.Player({
                name: 'NexusSpace Profile Song',
                getOAuthToken: (callback) => {
                    fetchPlaybackToken().then(callback).catch((error) => showPlaybackError(error.message));
                },
                volume: muted ? 0 : Number(volumeSlider?.value || 70) / 100,
            });
            spotifyPlayer.addListener('ready', ({ device_id: deviceId }) => {
                playbackDeviceId = deviceId;
                updateVolumeControl();
                requestPlayback().catch((error) => showPlaybackError(error.message));
            });
            spotifyPlayer.addListener('authentication_error', ({ message }) => showPlaybackError(message));
            spotifyPlayer.addListener('account_error', () => showPlaybackError('Spotify Premium is required to play profile songs here.'));
            spotifyPlayer.addListener('playback_error', ({ message }) => showPlaybackError(message));
            spotifyPlayer.connect();
        };
        const spotifySdkScript = document.createElement('script');
        spotifySdkScript.src = 'https://sdk.scdn.co/spotify-player.js';
        spotifySdkScript.async = true;
        document.body.append(spotifySdkScript);

        const retryPlayback = () => {
            if (profileSongStarted || !spotifyPlayer) return;
            spotifyPlayer.activateElement();
            requestPlayback().catch((error) => showPlaybackError(error.message));
        };
        window.addEventListener('pointerdown', retryPlayback, { passive: true });
        window.addEventListener('keydown', retryPlayback);
    }

    volumeSlider?.addEventListener('input', () => {
        const volume = Number(volumeSlider.value);
        muted = volume === 0;
        if (volume > 0) previousVolume = volume;
        updateVolumeControl();
        saveVolumePreference();
    });

    volumeSlider?.addEventListener('wheel', (event) => {
        event.preventDefault();
        const currentVolume = muted ? 0 : Number(volumeSlider.value);
        const direction = event.deltaY < 0 ? 1 : -1;
        const volume = Math.min(100, Math.max(0, currentVolume + (direction * 5)));
        volumeSlider.value = String(volume);
        muted = volume === 0;
        if (volume > 0) previousVolume = volume;
        updateVolumeControl();
        saveVolumePreference();
    }, { passive: false });

    muteButton?.addEventListener('click', () => {
        muted = !muted;
        if (!muted && Number(volumeSlider.value) === 0) volumeSlider.value = String(previousVolume);
        updateVolumeControl();
        saveVolumePreference();
    });

    const updateBanner = (track) => {
        if (!banner) return;
        const hasSong = Boolean(track?.id);
        banner.dataset.hasSong = String(hasSong);
        banner.hidden = false;
        if (volumeControl) volumeControl.hidden = !hasSong;
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
                const image = document.createElement('img');
                image.alt = '';
                image.loading = 'lazy';
                if (track.image) image.src = track.image;
                else image.hidden = true;

                const copy = document.createElement('span');
                const name = document.createElement('strong');
                const artist = document.createElement('span');
                name.textContent = track.name;
                artist.textContent = track.artist;
                copy.append(name, artist);

                const select = document.createElement('button');
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
        spotifyPlayer?.pause();
        banner.hidden = true;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-spotify-profile-song-picker]').forEach((picker) => { picker.hidden = true; });
    });
})();
