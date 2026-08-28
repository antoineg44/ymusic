const historyResults = document.getElementById('historyResults');
const historyStatus = document.getElementById('historyStatus');

function setStatus(message, isError = false) {
    historyStatus.textContent = message;
    historyStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function formatPlayedDate(value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return '-';
    }

    const parsed = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return raw;
    }

    return parsed.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
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
    const id = String((row && (row.Id || row.IdMusique)) || '').trim();
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

function renderHistory(rows) {
    historyResults.innerHTML = '';

    rows.forEach((row, index) => {
        const preparedSong = normalizeMusicRow(row);
        const item = renderElement(preparedSong, index);

        const trackInfo = item.querySelector('.track-info');
        if (trackInfo) {
            const dateLine = document.createElement('small');
            dateLine.className = 'history-played-date';
            dateLine.textContent = `Écouté le ${formatPlayedDate(row.DateLecture)}`;
            trackInfo.appendChild(dateLine);
        }

        historyResults.appendChild(item);
    });
}

async function loadHistory() {
    setStatus('Chargement...');

    try {
        const response = await sendMessageAndWait(window.parent, { action: 'playedHistory' });
        if (!response || response.success === false) {
            throw new Error((response && response.error) || 'Impossible de récupérer l\'historique');
        }

        const musiques = Array.isArray(response.musiques) ? response.musiques : [];
        renderHistory(musiques);

        if (!musiques.length) {
            setStatus('Aucune musique lue pour le moment.');
        } else {
            setStatus(`${musiques.length} musique(s) dans l'historique.`);
        }
    } catch (error) {
        console.error(error);
        setStatus(error.message || 'Erreur de chargement.', true);
    }
}

void loadHistory();

window.addEventListener('message', (event) => {
    const data = event && event.data ? event.data : {};
    if (data.target === 'artistes' && data.type === 'REFRESH_HISTORY') {
        void loadHistory();
    }
});