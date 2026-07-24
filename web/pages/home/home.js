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
        
        const trimmedTitleQuery = String(titleQuery || '').trim();

        const response = await fetch(`home.php?search=1&titleQuery=${encodeURIComponent(trimmedTitleQuery)}`, {
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (response.status === 401) {
            window.location.replace('login.html');
            return;
        }

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Impossible de charger les musiques');
        }

        const musiques = Array.isArray(payload.musiques) ? payload.musiques : [];
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
    } catch (error) {
        setStatus(`Erreur: ${error && error.message ? error.message : error}`, true);
    }
}

async function loadLatestMusiques() {
    setStatus('Chargement...');
    homeResults.innerHTML = '';
    homeEmpty.style.display = 'none';

    try {
        const response = await fetch(`home.php?latest_musiques=1`, {
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (response.status === 401) {
            window.location.replace('login.html');
            return;
        }

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Impossible de charger les musiques');
        }

        const musiques = Array.isArray(payload.musiques) ? payload.musiques : [];
        if (musiques.length === 0) {
            setStatus('Aucune musique trouvee.');
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
    } catch (error) {
        setStatus(`Erreur: ${error && error.message ? error.message : error}`, true);
    }
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