const historyBody = document.getElementById('historyBody');
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

function playMusic(row) {
    const id = String((row && row.Id) || '').trim();
    if (!id) {
        setStatus('Impossible de lire cette musique (Id manquant).', true);
        return;
    }

    window.parent.postMessage({ source: 'liste', type: 'LIST_PLAY_SONG', song: row }, '*');
    setStatus(`Lecture envoyée au lecteur: ${row.Titre || id}`);
}

function renderHistory(rows) {
    historyBody.innerHTML = '';

    if (!rows.length) {
        historyBody.innerHTML = '<tr><td colspan="5">Aucune musique lue pour le moment.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.className = 'artist-row';
        tr.innerHTML = `
            <td>${escapeHtml(row.Titre || row.IdMusique || '')}</td>
            <td>${escapeHtml(row.Artiste || '')}</td>
            <td>${escapeHtml(row.Album || '')}</td>
            <td>${escapeHtml(formatPlayedDate(row.DateLecture))}</td>
            <td><button type="button" class="song-play-button" aria-label="Lire">▶</button></td>
        `;

        tr.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('button')) {
                return;
            }
            playMusic(row);
        });

        const playButton = tr.querySelector('.song-play-button');
        playButton.addEventListener('click', () => playMusic(row));

        historyBody.appendChild(tr);
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