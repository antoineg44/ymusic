const musiquesBody = document.getElementById('musiquesBody');
const musiquesStatus = document.getElementById('musiquesStatus');
const pagePrev = document.getElementById('pagePrev');
const pageNext = document.getElementById('pageNext');
const pageInfo = document.getElementById('pageInfo');
const sortableHeaders = Array.from(document.querySelectorAll('.musiques-table th.sortable'));

let currentSortBy = 'DateAjout';
let currentSortDir = 'desc';
let currentPage = 1;
let totalPages = 1;
let totalRows = 0;
const pageSize = 50;
const SINGLE_CLICK_DELAY_MS = 220;
let pendingPlayClickTimer = null;

function postToParent(type, payload = {}) {
    window.parent.postMessage({ source: 'liste', type, ...payload }, '*');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;');
}

function renderUtilisateurCell(row) {
    const userId = String(row.UtilisateurId ?? '').trim();
    const userName = String(row.Utilisateur ?? '').trim();

    if (!userId) {
        return escapeHtml(userName);
    }

    const href = `./../popup/users/users.html#user-${encodeURIComponent(userId)}`;
    const label = escapeHtml(userId);
    const title = userName ? ` title="${escapeHtml(userName)}"` : '';
    return `<a href="${href}"${title}>${label}</a>`;
}

function setStatus(message, isError = false) {
    musiquesStatus.textContent = message;
    musiquesStatus.style.color = isError ? '#fca5a5' : '#7dd3fc';
}

function updatePaginationControls() {
    const startIndex = totalRows > 0 ? (currentPage - 1) * pageSize + 1 : 0;
    const endIndex = totalRows > 0 ? Math.min(currentPage * pageSize, totalRows) : 0;
    pageInfo.textContent = `Resultats ${startIndex} - ${endIndex} / ${totalRows}`;
    pagePrev.disabled = currentPage <= 1;
    pageNext.disabled = currentPage >= totalPages;
}

function updateSortIndicators() {
    sortableHeaders.forEach((header) => {
        const isActive = header.dataset.sort === currentSortBy;
        header.classList.toggle('is-active', isActive);
        if (isActive) {
            header.textContent = `${header.dataset.label || header.textContent.replace(/\s+[▲▼]$/, '')} ${currentSortDir === 'asc' ? '▲' : '▼'}`;
        } else {
            header.textContent = header.dataset.label || header.textContent.replace(/\s+[▲▼]$/, '');
        }
    });
}

function renderMusiques(rows) {
    musiquesBody.innerHTML = '';

    if (!rows.length) {
        musiquesBody.innerHTML = '<tr><td colspan="12">Aucune musique en base</td></tr>';
        return;
    }

    rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(row.Id)}</td>
            <td>${escapeHtml(row.Titre)}</td>
            <td>${escapeHtml(row.Artiste)}</td>
            <td>${renderUtilisateurCell(row)}</td>
            <td>${escapeHtml(row.Album)}</td>
            <td>${escapeHtml(row.Duree)}</td>
            <td>${escapeHtml(row.AnneeParution)}</td>
            <td>${escapeHtml(row.Genre)}</td>
            <td>${Number(row.NombreVue || 0)}</td>
            <td>${Number(row.NombreVueInterne || 0)}</td>
            <td>${escapeHtml(row.DateAjout)}</td>
            <td><button type="button" class="play-btn">▶</button></td>
        `;

        tr.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('button')) {
                return;
            }

            if (pendingPlayClickTimer !== null) {
                clearTimeout(pendingPlayClickTimer);
            }

            pendingPlayClickTimer = window.setTimeout(() => {
                pendingPlayClickTimer = null;
                void playMusic(row);
            }, SINGLE_CLICK_DELAY_MS);
        });

        tr.addEventListener('dblclick', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('button')) {
                return;
            }

            if (pendingPlayClickTimer !== null) {
                clearTimeout(pendingPlayClickTimer);
                pendingPlayClickTimer = null;
            }

            postToParent('LIST_OPEN_DESCRIPTION', {
                song: row,
            });
            setStatus(`Description demandee: ${row.Titre || row.Id}`);
        });

        const playButton = tr.querySelector('.play-btn');
        playButton.addEventListener('click', () => {
            void playMusic(row);
        });
        musiquesBody.appendChild(tr);
    });
}

async function playMusic(row) {
    const id = String((row && row.Id) || '').trim();
    if (!id) {
        setStatus('Impossible de lire cette musique (Id manquant).', true);
        return;
    }

    postToParent('LIST_PLAY_SONG', {
        song: row,
    });
    setStatus(`Lecture envoyee au lecteur: ${row.Titre || id}`);
}

async function loadMusiques() {
    try {
        console.log("List.html : loadMusiques");
        const params = new URLSearchParams({
            musiques: '1',
            sortBy: currentSortBy,
            sortDir: currentSortDir,
            page: String(currentPage),
            perPage: String(pageSize),
        });

        const response = await fetch(get_url() + `../../pages/list/list.php?${params.toString()}`, {
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (response.status === 401) {
            window.parent.postMessage({type: 'USER_LOGGED_OUT' }, '*');
            return;
        }

        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Impossible de recuperer la liste des musiques');
        }

        currentSortBy = String(payload.sortBy || currentSortBy);
        currentSortDir = String(payload.sortDir || currentSortDir).toLowerCase() === 'asc' ? 'asc' : 'desc';
        currentPage = Math.max(1, Number(payload.page || currentPage));
        totalPages = Math.max(1, Number(payload.totalPages || 1));
        totalRows = Math.max(0, Number(payload.totalRows || 0));

        const musiques = Array.isArray(payload.musiques) ? payload.musiques : [];
        renderMusiques(musiques);
        updateSortIndicators();
        updatePaginationControls();
        setStatus(`${musiques.length} musique(s) chargee(s) sur ${totalRows} - page ${currentPage}/${totalPages} - tri ${currentSortBy} ${currentSortDir.toUpperCase()}.`);
    } catch (error) {
        console.error(error);
        setStatus(error.message || 'Erreur de chargement.', true);
    }
}

sortableHeaders.forEach((header) => {
    header.dataset.label = header.textContent;
    header.addEventListener('click', () => {
        const sortBy = String(header.dataset.sort || '').trim();
        if (!sortBy) {
            return;
        }

        if (sortBy === currentSortBy) {
            currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortBy = sortBy;
            currentSortDir = 'asc';
        }
        currentPage = 1;

        void loadMusiques();
    });
});

pagePrev.addEventListener('click', () => {
    if (currentPage <= 1) {
        return;
    }

    currentPage -= 1;
    void loadMusiques();
});

pageNext.addEventListener('click', () => {
    if (currentPage >= totalPages) {
        return;
    }

    currentPage += 1;
    void loadMusiques();
});

function initEvent() {
    window.addEventListener('message', (event) => {
        const message = event.data;
        if (!message || message.target !== 'liste') {
        return;
        }

        if (message.type === 'REFRESH_LIST') {
            void loadMusiques();
        }
    });
}

function init() {
    initEvent();
}

init();