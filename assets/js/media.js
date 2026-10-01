document.addEventListener('change', (event) => {
    const speed = event.target.closest?.('[data-video-speed]');
    if (!speed) return;
    const video = speed.closest('.post-media-item')?.querySelector('video');
    if (video) video.playbackRate = Number(speed.value) === 2 ? 2 : 1;
});
