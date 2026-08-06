const artistsBody = document.getElementById('artistsBody');
const artistsStatus = document.getElementById('artistsStatus');
const artistSongsPanel = document.getElementById('artistSongsPanel');
const artistsTableWrap = document.querySelector('.artists-table-wrap');
const artistsHead = document.querySelector('.artists-head');
const songsTitle = document.getElementById('songsTitle');
const songsStatus = document.getElementById('songsStatus');
const songsList = document.getElementById('songsList');
const playAllSongsBtn = document.getElementById('playAllSongsBtn');
const backToArtistsBtn = document.getElementById('backToArtistsBtn');
let activeArtistName = '';
let openedArtistSongs = [];

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

function buildArtistQueueTracks(rows) {
    return rows
        .map((row) => {
            const videoId = String((row && row.Id) || '').trim();
            if (!videoId) {
                return null;
            }

            const artist = activeArtistName || String((row && row.Artiste) || '').trim();
            return {
                videoId,
                title: String((row && row.Titre) || 'Musique inconnue').trim(),
                artists: artist ? [artist] : [],
                duration: Number((row && row.Duree) || 0),
                views: Number((row && row.NombreVue) || 0),
            };
        })
        .filter(Boolean);
}

function playAllArtistSongs() {
    if (!activeArtistName) {
        setSongsStatus('Aucun artiste ouvert.', true);
        return;
    }

    const tracks = buildArtistQueueTracks(openedArtistSongs);
    if (!tracks.length) {
        setSongsStatus('Aucune musique disponible pour cet artiste.', true);
        return;
    }

    window.parent.postMessage(
        {
            source: 'playlists',
            type: 'PLAYLIST_LOAD_ALL',
            tracks,
        },
        '*'
    );

    setSongsStatus(`File de lecture remplacee par ${tracks.length} musique(s) de ${activeArtistName}.`);
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
    openedArtistSongs = [];
    setSongsStatus('Chargement...');

    const query = {
        table: 'Musiques',
        select: ['Id', 'Titre', 'Album', 'Duree', 'NombreVue', 'NombreVueInterne', 'DateAjout'],
        orderBy: 'DateAjout',
        order: 'DESC',
        limit: 50,
        page: 1,
        equals: {
            'Artiste': artist,
        }
    };

    sendMessageAndWait(window.parent, {action: 'getMusiques', query: query}).then(response => {
        const musiques = Array.isArray(response.musiques) ? response.musiques : [];
        openedArtistSongs = musiques;
        if (musiques.length === 0) {
            songsList.innerHTML = '<li>Aucune musique pour cet artiste</li>';
            setSongsStatus('Aucune musique trouvee pour cet artiste.');
            return;
        }

        musiques.forEach((song, index) => {
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

        setSongsStatus(`${musiques.length} musique(s) chargee(s).`);

    }).catch(error => {
        console.error(error);
        openedArtistSongs = [];
        setSongsStatus('Erreur pendant le chargement des musiques.', true);
    });
}

async function loadArtists() {
    const query = {
        table: 'Musiques',
        count: 1,
        select: ['Artiste'],
        groupBy: 'Artiste',
        orderBy: 'Artiste',
        order: "ASC",
        page: 1,
        limit: 50,
    };

    sendMessageAndWait(window.parent, {action: 'getMusiques', query: query}).then(response => {
        const musiques = Array.isArray(response.musiques) ? response.musiques : [];
        if (musiques.length === 0) {
            artistsBody.innerHTML = '<tr><td colspan="2">Aucun artiste en base</td></tr>';
            setStatus('Aucun artiste trouve.');
            return;
        }

        musiques.forEach((artist) => {
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

        setStatus(`${musiques.length} artiste(s) charge(s).`);

    }).catch(error => {
        console.error(error);
    });
}

void loadArtists();

playAllSongsBtn.addEventListener('click', () => {
    playAllArtistSongs();
});

backToArtistsBtn.addEventListener('click', () => {
    showArtists();
    artistSongsPanel.classList.add('is-hidden');
    songsList.innerHTML = '';
    openedArtistSongs = [];
    activeArtistName = '';
});