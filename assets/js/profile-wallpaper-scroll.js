document.addEventListener('DOMContentLoaded', () => {
    const profileContent = document.querySelector('.profile-posts');
    if (!profileContent) return;

    const syncWallpaperOffset = () => {
        profileContent.style.setProperty('--profile-wallpaper-scroll-offset', `${profileContent.scrollTop}px`);
    };

    profileContent.addEventListener('scroll', syncWallpaperOffset, { passive: true });
    syncWallpaperOffset();
});
