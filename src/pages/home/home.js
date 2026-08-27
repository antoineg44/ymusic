const homeResults = document.getElementById('homeResults');
const homeStatus = document.getElementById('homeStatus');
const homeEmpty = document.getElementById('homeEmpty');
const homeSearchInput = document.getElementById('homeSearchInput');
const homeSearchButton = document.getElementById('homeSearchButton');
const homeResetButton = document.getElementById('homeResetButton');
const homeTitle = document.getElementById('homeTitle');
const homeModeMusiques = document.getElementById('homeModeMusiques');
const homeModeAuteurs = document.getElementById('homeModeAuteurs');
const homeArtistsView = document.getElementById('homeArtistsView');
const homeArtistsBody = document.getElementById('homeArtistsBody');
const homeArtistsTableWrap = homeArtistsView ? homeArtistsView.querySelector('.artists-table-wrap') : null;
const homeArtistSongsPanel = document.getElementById('homeArtistSongsPanel');
const homeSongsTitle = document.getElementById('homeSongsTitle');
const homeSongsStatus = document.getElementById('homeSongsStatus');
const homeSongsList = document.getElementById('homeSongsList');
const homePlayAllSongsBtn = document.getElementById('homePlayAllSongsBtn');
const homeBackToArtistsBtn = document.getElementById('homeBackToArtistsBtn');
const homeWhySection = document.querySelector('.home-why');

let currentMode = 'musiques';
let activeArtistName = '';
let openedArtistSongs = [];

function setStatus(message, isError = false) {
    homeStatus.textContent = message;
    homeStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function toDisplayDuration(rawDuration) {
    if (typeof rawDuration === 'number' && Number.isFinite(rawDuration)) {
        const safeSeconds = Math.max(0, Math.floor(rawDuration));
        const minutes = Math.floor(safeSeconds / 60);
        const seconds = safeSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    const asText = String(rawDuration || '').trim();
    return asText || '00:00';
}

function normalizeMusicRow(row) {
    const id = String((row && row.Id) || '').trim();
    const title = String((row && row.Titre) || '').trim();
    const artist = String((row && row.Artiste) || '').trim();

    return {
        Id: id,
        title,
        artists: artist ? [artist] : [],
        duration: toDisplayDuration(row && row.Duree),
        views: Number(row && row.NombreVue) || 0,
        showIndex: true,
        buttons: {
        source: 'liste',
        buttons: {
            play: {
            type: 'LIST_PLAY_SONG',
            payload: {
                song: {
                Id: id,
                Titre: title,
                Artiste: artist,
                },
            },
            },
        },
        },
    };
}

async function searchMusiques(titleQuery = '') {
    setStatus('Chargement...');
    homeResults.innerHTML = '';
    homeEmpty.style.display = 'none';

    try {
        if (!titleQuery || typeof titleQuery !== 'string') {
            setStatus('La requete de recherche est invalide.');
            return;
        }

        const normalizedQuery = String(titleQuery || '').trim();
        if (!normalizedQuery) {
            setStatus('La requete de recherche est invalide.');
            return;
        }

        const searchFields = ['Titre', 'Artiste'];

        const allResults = await Promise.all(searchFields.map(async (field) => {
            const query = {
                table: 'Musiques',
                select: ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue', 'DateAjout'],
                orderBy: 'Titre',
                order: 'ASC',
                limit: 20,
                page: 1,
                search: {
                    field,
                    value: normalizedQuery,
                }
            };

            const response = await sendMessageAndWait(window.parent, { action: 'search', query });
            return Array.isArray(response.musiques) ? response.musiques : [];
        }));

        const mergedById = new Map();
        allResults.flat().forEach((row) => {
            const id = String((row && row.Id) || '').trim();
            if (!id) {
                return;
            }
            if (!mergedById.has(id)) {
                mergedById.set(id, row);
            }
        });

        const musiques = Array.from(mergedById.values()).slice(0, 20);

        if (musiques.length === 0) {
            setStatus(`Aucun resultat pour "${normalizedQuery}".`);
            homeEmpty.style.display = 'block';
            return;
        }

        musiques.forEach((row, index) => {
            const preparedSong = normalizeMusicRow(row);
            preparedSong.showIndex = false;
            const item = renderElement(preparedSong, index);
            homeResults.appendChild(item);
        });

        setStatus(`${musiques.length} resultat${musiques.length > 1 ? 's' : ''} pour "${normalizedQuery}" (Titre + Auteur).`);
    } catch (error) {
        console.error(error);
        setStatus(`Erreur: ${error && error.message ? error.message : error}`, true);
    }
}

async function loadLatestMusiques() {
    setStatus('Chargement...');
    homeResults.innerHTML = '';
    homeEmpty.style.display = 'none';

    const query = {
        table: 'Musiques',
        select: ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue', 'DateAjout'],
        orderBy: 'DateAjout',
        order: 'DESC',
        limit: 5,
        page: 1
    };

    sendMessageAndWait(window.parent, {action: 'latest_musiques', query: query}).then(response => {

        const musiques = Array.isArray(response.musiques) ? response.musiques : [];
        if (musiques.length === 0) {
            setStatus('Aucun resultat pour les dernieres musiques.');
            homeEmpty.style.display = 'block';
            return;
        }

        musiques.forEach((row, index) => {
            const preparedSong = normalizeMusicRow(row);
            preparedSong.displayIndex = index + 1;
            const item = renderElement(preparedSong, index);
            homeResults.appendChild(item);
        });

        setStatus('5 dernieres musiques chargees.');
    }).catch(error => {
        console.error(error);
    });
}

function searchByTitle() {
    const query = homeSearchInput ? homeSearchInput.value : '';
    if (currentMode === 'auteurs') {
        void loadArtists(query);
    } else {
        void searchMusiques(query);
    }
}

function setSongsStatus(message, isError = false) {
    homeSongsStatus.textContent = message;
    homeSongsStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function showArtistsList() {
    if (homeArtistSongsPanel) {
        homeArtistSongsPanel.classList.add('is-hidden');
    }
    if (homeArtistsTableWrap) {
        homeArtistsTableWrap.classList.remove('is-hidden');
    }
    homeSongsList.innerHTML = '';
    openedArtistSongs = [];
    activeArtistName = '';
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

    window.parent.postMessage({ source: 'playlists', type: 'PLAYLIST_LOAD_ALL', tracks }, '*');
    setSongsStatus(`File de lecture remplacee par ${tracks.length} musique(s) de ${activeArtistName}.`);
}

async function loadArtistSongs(artistName) {
    const artist = String(artistName || '').trim();
    if (!artist) {
        return;
    }

    activeArtistName = artist;

    if (homeArtistsTableWrap) {
        homeArtistsTableWrap.classList.add('is-hidden');
    }
    homeArtistSongsPanel.classList.remove('is-hidden');
    homeSongsTitle.textContent = `Musiques de ${artist}`;
    homeSongsList.innerHTML = '';
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
        },
    };

    try {
        const response = await sendMessageAndWait(window.parent, { action: 'getMusiques', query });
        const musiques = Array.isArray(response.musiques) ? response.musiques : [];
        openedArtistSongs = musiques;

        if (musiques.length === 0) {
            homeSongsList.innerHTML = '<li>Aucune musique pour cet artiste</li>';
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
            homeSongsList.appendChild(item);
        });

        setSongsStatus(`${musiques.length} musique(s) chargee(s).`);
    } catch (error) {
        console.error(error);
        openedArtistSongs = [];
        setSongsStatus('Erreur pendant le chargement des musiques.', true);
    }
}

async function loadArtists(searchValue = '') {
    setStatus('Chargement...');
    homeArtistsBody.innerHTML = '';
    showArtistsList();

    const normalized = String(searchValue || '').trim();
    const query = {
        table: 'Musiques',
        count: 1,
        select: ['Artiste'],
        groupBy: 'Artiste',
        orderBy: 'Artiste',
        order: 'ASC',
        page: 1,
        limit: 200,
    };

    if (normalized) {
        query.search = { field: 'Artiste', value: normalized };
    }

    try {
        const response = await sendMessageAndWait(window.parent, { action: 'getMusiques', query });
        const musiques = Array.isArray(response.musiques) ? response.musiques : [];

        if (musiques.length === 0) {
            homeArtistsBody.innerHTML = '<tr><td colspan="2">Aucun artiste en base</td></tr>';
            setStatus(normalized ? `Aucun artiste pour "${normalized}".` : 'Aucun artiste trouve.');
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
            homeArtistsBody.appendChild(row);
        });

        setStatus(`${musiques.length} artiste(s)${normalized ? ` pour "${normalized}"` : ''} charge(s).`);
    } catch (error) {
        console.error(error);
        setStatus('Erreur pendant le chargement des artistes.', true);
    }
}

function setMode(mode) {
    currentMode = mode === 'auteurs' ? 'auteurs' : 'musiques';
    const isAuteurs = currentMode === 'auteurs';

    if (homeModeMusiques) {
        homeModeMusiques.classList.toggle('is-active', !isAuteurs);
    }
    if (homeModeAuteurs) {
        homeModeAuteurs.classList.toggle('is-active', isAuteurs);
    }

    homeResults.style.display = isAuteurs ? 'none' : '';
    homeEmpty.style.display = 'none';
    if (homeArtistsView) {
        homeArtistsView.classList.toggle('is-hidden', !isAuteurs);
    }
    if (homeWhySection) {
        homeWhySection.style.display = isAuteurs ? 'none' : '';
    }

    if (homeSearchInput) {
        homeSearchInput.value = '';
        homeSearchInput.placeholder = isAuteurs
            ? 'Rechercher un auteur dans la table Musiques'
            : 'Rechercher un titre dans la table Musiques';
    }

    if (homeTitle) {
        homeTitle.textContent = isAuteurs ? 'Artistes en base' : 'Dernieres musiques ajoutees';
    }

    if (isAuteurs) {
        void loadArtists();
    } else {
        void loadLatestMusiques();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (homeSearchButton) {
        homeSearchButton.addEventListener('click', searchByTitle);
    }

    if (homeSearchInput) {
        homeSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchByTitle();
        }
        });
    }

    if (homeResetButton) {
        homeResetButton.addEventListener('click', () => {
        if (homeSearchInput) {
            homeSearchInput.value = '';
        }
        if (currentMode === 'auteurs') {
            void loadArtists();
        } else {
            void loadLatestMusiques();
        }
        });
    }

    if (homeModeMusiques) {
        homeModeMusiques.addEventListener('click', () => setMode('musiques'));
    }
    if (homeModeAuteurs) {
        homeModeAuteurs.addEventListener('click', () => setMode('auteurs'));
    }

    if (homePlayAllSongsBtn) {
        homePlayAllSongsBtn.addEventListener('click', () => playAllArtistSongs());
    }
    if (homeBackToArtistsBtn) {
        homeBackToArtistsBtn.addEventListener('click', () => showArtistsList());
    }

    void loadLatestMusiques();
});
