const playButton = document.getElementById('playButton');
const prevButton = document.getElementById('prevButton');
const nextButton = document.getElementById('nextButton');
const seekBar = document.getElementById('seekBar');
const timeLabel = document.getElementById('timeLabel');
const nowPlaying = document.getElementById('nowPlaying');
const nowPlayingMeta = document.getElementById('nowPlayingMeta');
const nextPlaying = document.getElementById('nextPlaying');
const loadingSpinner = document.getElementById('loadingSpinner');
const favoriteButton = document.getElementById('favoriteButton');
const addToPlaylistButton = document.getElementById('addToPlaylistButton');
const primaryAudio = document.getElementById('audioPlayer');
const secondaryAudio = document.getElementById('audioPlayerSecondary');
const playerCard = document.querySelector('.player-card');

const TRIM_SETTING_KEY = 'ymusic.trimLowIntroOutro';
const CROSSFADE_SECONDS_KEY = 'ymusic.crossfadeSeconds';

let currentMusicId = '';

function getMarqueeTextElement(container) {
    if (!container) {
        return null;
    }

    let textElement = container.querySelector('.marquee-text');
    if (!(textElement instanceof HTMLElement)) {
        textElement = document.createElement('span');
        textElement.className = 'marquee-text';
        textElement.textContent = container.textContent || '';
        container.textContent = '';
        container.appendChild(textElement);
    }

    return textElement;
}

function updateStatusOverflow(container) {
    const textElement = getMarqueeTextElement(container);
    if (!container || !textElement) {
        return;
    }

    container.classList.remove('is-overflow');
    container.style.removeProperty('--status-scroll-distance');
    container.style.removeProperty('--status-scroll-duration');

    const containerWidth = container.clientWidth;
    const textWidth = textElement.scrollWidth;

    if (textWidth <= containerWidth) {
        return;
    }

    const distance = textWidth + containerWidth;
    const speed = 45;
    const duration = Math.min(30, Math.max(8, distance / speed));

    container.style.setProperty('--status-scroll-distance', `${distance}px`);
    container.style.setProperty('--status-scroll-duration', `${duration}s`);
    container.classList.add('is-overflow');
}

function setStatusText(container, text) {
    const textElement = getMarqueeTextElement(container);
    if (!textElement) {
        return;
    }

    textElement.textContent = String(text || '');
    window.requestAnimationFrame(() => {
        updateStatusOverflow(container);
    });
}

function setFavoriteState(isFavorite) {
    favoriteButton.textContent = isFavorite ? '★' : '☆';
    favoriteButton.classList.toggle('is-active', Boolean(isFavorite));
    favoriteButton.setAttribute('aria-label', isFavorite ? 'Retirer des favoris' : 'Ajouter aux favoris');
}

function showLoadingSpinner() {
    loadingSpinner.classList.remove('hidden');
}

function hideLoadingSpinner() {
    loadingSpinner.classList.add('hidden');
}

function postToParent(type, payload = {}) {
    window.parent.postMessage({ source: 'lecteur', type, ...payload }, '*');
}

function formatTime(seconds) {
    if (!Number.isFinite(seconds)) {
        return '00:00';
    }

    const safeSeconds = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(safeSeconds / 60);
    const remaining = safeSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
}

const playController = typeof window.createPlayController === 'function'
    ? window.createPlayController({
        primaryAudio,
        secondaryAudio,
        onPlayStateChange: (isPlaying) => {
            playButton.textContent = isPlaying ? '⏸' : '▶';
        },
        onFadeIndicatorChange: (isActive) => {
            timeLabel.classList.toggle('is-fade-active', Boolean(isActive));
        },
        onTimeUpdate: (payload) => {
            seekBar.max = payload.duration || 100;
            seekBar.value = payload.currentTime || 0;
            timeLabel.textContent = `${formatTime(payload.currentTime)} / ${formatTime(payload.duration)}`;
            postToParent('TIME_UPDATE', payload);
        },
        onAutoNext: ({ fadeSeconds }) => {
            postToParent('REQUEST_NEXT_AUTO', { fadeSeconds });
        },
        onPlaybackError: (error) => {
            postToParent('PLAYER_ERROR', { error });
        },
    })
    : null;

function hasPlaybackController() {
    return Boolean(playController);
}

function toggleLocalPlayback() {
    if (!hasPlaybackController()) {
        postToParent('PLAYER_ERROR', { error: 'Moteur de lecture indisponible.' });
        return;
    }

    const result = playController.togglePlayback();
    if (result && result.missingSource) {
        postToParent('REQUEST_PLAY_FALLBACK');
    }
}

playButton.addEventListener('click', toggleLocalPlayback);
addToPlaylistButton.addEventListener('click', (event) => {
    event.stopPropagation();
    postToParent('OPEN_PLAYLIST_MENU', { musicId: currentMusicId });
});
favoriteButton.addEventListener('click', () => {
    postToParent('TOGGLE_FAVORITE');
});
prevButton.addEventListener('click', () => {
    postToParent('REQUEST_PREV');
});
nextButton.addEventListener('click', () => {
    postToParent('REQUEST_NEXT');
});
seekBar.addEventListener('input', () => {
    if (!hasPlaybackController()) {
        return;
    }

    const ratio = Number(seekBar.value) / Number(seekBar.max || 100);
    playController.seekToRatio(ratio);
});

playerCard.addEventListener('click', (event) => {
    const target = event.target;
    if (
        target instanceof Element
        && (target.closest('button') || target.closest('input') || target.closest('audio'))
    ) {
        return;
    }

    postToParent('OPEN_DESCRIPTION');
});

window.addEventListener('message', (event) => {
    const message = event.data;
    if (!message || message.target !== 'lecteur') {
        return;
    }

    if (message.type === 'SHOW_LOADING') {
        showLoadingSpinner();
        return;
    }

    if (message.type === 'HIDE_LOADING') {
        hideLoadingSpinner();
        return;
    }

    if (message.type === 'LOAD_TRACK') {
        hideLoadingSpinner();
        setStatusText(nowPlaying, String(message.title || 'Aucune lecture en cours'));
        setStatusText(nowPlayingMeta, String(message.meta || 'Bibliotheque locale'));
        setFavoriteState(Boolean(message.isFavorite));
        currentMusicId = String(message.musicId || '').trim();

        if (!hasPlaybackController()) {
            postToParent('PLAYER_ERROR', { error: 'Moteur de lecture indisponible.' });
            return;
        }

        playController.loadTrack({
            src: String(message.src || ''),
            fadeInSeconds: Number(message.fadeInSeconds || 0),
        });
        return;
    }

    if (message.type === 'FADE_OUT') {
        if (hasPlaybackController()) {
            playController.fadeOut(Number(message.durationSeconds || 0));
        }
        return;
    }

    if (message.type === 'TOGGLE') {
        toggleLocalPlayback();
        return;
    }

    if (message.type === 'SET_PLAY_PAUSE_ICON') {
        if (hasPlaybackController()) {
            playController.setExternalPlayState(Boolean(message.isPlaying));
        } else {
            playButton.textContent = message.isPlaying ? '⏸' : '▶';
        }
        return;
    }

    if (message.type === 'SET_NEXT_TRACK') {
        const nextTitle = String(message.nextTitle || '').trim();
        setStatusText(
            nextPlaying,
            nextTitle
                ? `Prochaine musique: ${nextTitle}`
                : 'Prochaine musique: aucune'
        );
        return;
    }

    if (message.type === 'SET_FAVORITE_STATE') {
        setFavoriteState(Boolean(message.isFavorite));
    }
});

window.addEventListener('storage', (event) => {
    if ((event.key === TRIM_SETTING_KEY || event.key === CROSSFADE_SECONDS_KEY) && hasPlaybackController()) {
        playController.refreshSettings();
    }
});

window.addEventListener('resize', () => {
    updateStatusOverflow(nowPlaying);
    updateStatusOverflow(nowPlayingMeta);
    updateStatusOverflow(nextPlaying);
});

setFavoriteState(false);
setStatusText(nowPlaying, 'Aucune lecture en cours');
setStatusText(nowPlayingMeta, 'Selectionnez un titre dans la bibliotheque');
setStatusText(nextPlaying, 'Prochaine musique: aucune');
if (hasPlaybackController()) {
    playController.refreshSettings();
}
postToParent('PLAYER_READY');
