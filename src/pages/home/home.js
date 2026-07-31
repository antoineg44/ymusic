const homeResults = document.getElementById('homeResults');
const homeStatus = document.getElementById('homeStatus');
const homeEmpty = document.getElementById('homeEmpty');
const homeSearchInput = document.getElementById('homeSearchInput');
const homeSearchButton = document.getElementById('homeSearchButton');
const homeResetButton = document.getElementById('homeResetButton');

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
        
        const query = {
            table: 'Musiques',
            select: ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue', 'DateAjout'],
            orderBy: 'Titre',
            order: 'ASC',
            limit: 20,
            page: 1,
            search: {
                field: 'Titre',
                value: String(titleQuery || '').trim()
            }
        };

        sendMessageAndWait(window.parent, {action: 'search', query: query}).then(response => {

            const musiques = Array.isArray(response.musiques) ? response.musiques : [];
            if (musiques.length === 0) {
                setStatus(`Aucun resultat pour "${trimmedTitleQuery}".`);
                homeEmpty.style.display = 'block';
                return;
            }

            musiques.forEach((row, index) => {
                const preparedSong = normalizeMusicRow(row);
                preparedSong.showIndex = false;
                const item = renderElement(preparedSong, index);
                homeResults.appendChild(item);
            });

            setStatus(`20 resultats max pour "${trimmedTitleQuery}".`);
        }).catch(error => {
            console.error(error);
        });
    } catch (error) {
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
    void searchMusiques(query);
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
        void loadLatestMusiques();
        });
    }

    void loadLatestMusiques();
});