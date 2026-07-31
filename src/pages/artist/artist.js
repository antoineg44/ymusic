const artistsBody = document.getElementById('artistsBody');
const artistsStatus = document.getElementById('artistsStatus');
const artistSongsPanel = document.getElementById('artistSongsPanel');
const artistsTableWrap = document.querySelector('.artists-table-wrap');
const artistsHead = document.querySelector('.artists-head');
const songsTitle = document.getElementById('songsTitle');
const songsStatus = document.getElementById('songsStatus');
const songsList = document.getElementById('songsList');
const backToArtistsBtn = document.getElementById('backToArtistsBtn');
let activeArtistName = '';

function postToParent(type, payload = {}) {
    window.parent.postMessage({ source: 'artistes', type, ...payload }, '*');
}

function hideArtists() {
    artistsHead.classList.add('is-hidden');
    artistsTableWrap.classList.add('is-hidden');
}

function showArtists() {
    artistsHead.classList.remove('is-hidden');
    artistsTableWrap.classList.remove('is-hidden');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;');
}

function setStatus(message, isError = false) {
    artistsStatus.textContent = message;
    artistsStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function setSongsStatus(message, isError = false) {
    songsStatus.textContent = message;
    songsStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function formatDuration(value) {
    const seconds = Number(value || 0);
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return '-';
    }

    const total = Math.floor(seconds);
    const minutes = Math.floor(total / 60);
    const remaining = total % 60;
    return `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
}

async function loadArtistSongs(artistName) {
    const artist = String(artistName || '').trim();
    if (!artist) {
        return;
    }

    activeArtistName = artist;

    hideArtists();
    artistSongsPanel.classList.remove('is-hidden');
    songsTitle.textContent = `Musiques de ${artist}`;
    songsList.innerHTML = '';
    setSongsStatus('Chargement...');

    try {
        const response = await fetch(get_url() + `../../php/interface.php?artistSongs=1&artist=${encodeURIComponent(artist)}`, {
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (response.status === 401) {
            window.parent.postMessage({type: 'USER_LOGGED_OUT' }, '*');
            return;
        }

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Impossible de recuperer les musiques de cet artiste');
        }

        const songs = Array.isArray(payload.songs) ? payload.songs : [];
        songsList.innerHTML = '';

        if (!songs.length) {
            songsList.innerHTML = '<li>Aucune musique pour cet artiste</li>';
            setSongsStatus('Aucune musique trouvee pour cet artiste.');
            return;
        }

        songs.forEach((song, index) => {
            const preparedSong = {
                Id: String(song.Id || ''),
                Titre: String(song.Titre || ''),
                Artiste: activeArtistName || String(song.Artiste || ''),
                title: String(song.Titre || 'Musique inconnue'),
                artists: [activeArtistName || String(song.Artiste || 'Artiste inconnu')],
                duration: Number(song.Duree || 0),
                views: Number(song.NombreVueInterne || 0),
                showIndex: false,
                buttons: {
                    buttons: {
                        play: {
                            type: 'ARTIST_PLAY_SONG',
                            result: {
                                ...song,
                                Artiste: activeArtistName || String(song.Artiste || ''),
                            },
                            className: 'song-play-button',
                        },
                    },
                    source: 'artistes',
                },
            };

            const item = renderElement(preparedSong, index);
            songsList.appendChild(item);
        });

        setSongsStatus(`${songs.length} musique(s) chargee(s).`);
    } catch (error) {
        console.error(error);
        setSongsStatus(error.message || 'Erreur de chargement.', true);
    }
}

async function loadArtists() {
    try {
        const response = await fetch(get_url() + '../../php/interface.php?artists=1', {
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (response.status === 401) {
            window.parent.postMessage({type: 'USER_LOGGED_OUT' }, '*');
            return;
        }

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Impossible de recuperer la liste des artistes');
        }

        const artists = Array.isArray(payload.artists) ? payload.artists : [];

        artistsBody.innerHTML = '';
        if (!artists.length) {
            artistsBody.innerHTML = '<tr><td colspan="2">Aucun artiste en base</td></tr>';
            setStatus('Aucun artiste trouve.');
            return;
        }

        artists.forEach((artist) => {
            const row = document.createElement('tr');
            row.className = 'artist-row';
            const artistName = String(artist.Artiste || '').trim();
            row.innerHTML = `
                <td>${escapeHtml(artist.Artiste || '')}</td>
                <td>${Number(artist.TotalMusiques || 0)}</td>
            `;

            row.addEventListener('click', () => {
                void loadArtistSongs(artistName);
            });
            artistsBody.appendChild(row);
        });

        setStatus(`${artists.length} artiste(s) charge(s).`);
    } catch (error) {
        console.error(error);
        setStatus(error.message || 'Erreur de chargement.', true);
    }
}

void loadArtists();

backToArtistsBtn.addEventListener('click', () => {
    showArtists();
    artistSongsPanel.classList.add('is-hidden');
    songsList.innerHTML = '';
    activeArtistName = '';
});