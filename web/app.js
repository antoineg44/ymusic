const state = {
  library: [],
  queue: [],
  queueIndex: -1,
  currentIndex: -1,
  currentTrack: null,
  currentVideoId: '',
  likedLogged: false,
  currentTab: 'accueil',
};

let suggestionTimer = null;

const libraryList = document.getElementById('libraryList');
const libraryPanel = document.getElementById('libraryPanel');
const searchRow = document.getElementById('searchRow');
const searchResultsPanel = document.getElementById('searchResultsPanel');
const settingsPanel = document.getElementById('settingsPanel');
const manageUsersLink = document.getElementById('manageUsersLink');
const searchResults = document.getElementById('searchResults');
const searchInput = document.getElementById('searchInput');
const suggestionsBox = document.getElementById('suggestions');
const statusBox = document.getElementById('status');
const sidebarLinks = Array.from(document.querySelectorAll('.sidebar-link'));
const playButton = document.getElementById('playButton');
const prevButton = document.getElementById('prevButton');
const nextButton = document.getElementById('nextButton');
const seekBar = document.getElementById('seekBar');
const timeLabel = document.getElementById('timeLabel');
const nowPlaying = document.getElementById('nowPlaying');
const nowPlayingMeta = document.getElementById('nowPlayingMeta');
const audio = document.getElementById('audioPlayer');
const logoutButton = document.getElementById('logoutButton');

if (logoutButton) {
  logoutButton.addEventListener('click', () => {
    void logout();
  });
}

document.getElementById('searchButton').addEventListener('click', searchMusic);
searchInput.addEventListener('keydown', (event) => {
  if (event.key === 'Enter') {
    searchMusic();
  }
});
searchInput.addEventListener('input', (event) => {
  const query = event.target.value.trim();

  if (!query) {
    suggestionsBox.innerHTML = '';
    return;
  }

  window.clearTimeout(suggestionTimer);
  suggestionTimer = window.setTimeout(() => {
    loadSuggestions(query);
  }, 250);
});

playButton.addEventListener('click', togglePlayback);
prevButton.addEventListener('click', () => { void playPrevious(); });
nextButton.addEventListener('click', () => { void playNext(); });
seekBar.addEventListener('input', seekPlayback);
audio.addEventListener('timeupdate', updateTimeDisplay);
audio.addEventListener('loadedmetadata', updateTimeDisplay);
audio.addEventListener('ended', () => { void playNext(); });

document.addEventListener('DOMContentLoaded', () => {
  void initializeApp();
});

async function initializeApp() {
  const authenticated = await ensureAuthenticated();
  if (!authenticated) {
    return;
  }

  initializeSidebarMenu();
  await loadLibrary();
}

async function ensureAuthenticated() {
  try {
    const response = await fetch('auth.php?action=check', {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const payload = await response.json();

    if (!payload.success) {
      window.location.replace('login.html');
      return false;
    }

    state.currentUser = payload.user || null;

    if (manageUsersLink && state.currentUser && state.currentUser.role !== 'admin') {
      manageUsersLink.style.display = 'none';
    }
    return true;
  } catch (error) {
    console.error(error);
    window.location.replace('login.html');
    return false;
  }
}

async function logout() {
  try {
    await fetch('auth.php?action=logout', {
      method: 'POST',
      credentials: 'same-origin',
    });
  } catch (error) {
    console.error(error);
  } finally {
    window.location.replace('login.html');
  }
}

function setActiveTab(tab) {
  const isSearchTab = tab === 'recherche';
  const isListTab = tab === 'listes';
  const isSettingsTab = tab === 'parametres';

  state.currentTab = tab;

  searchRow.hidden = !isSearchTab;
  suggestionsBox.hidden = !isSearchTab;
  searchResultsPanel.classList.toggle('is-hidden', !isSearchTab);
  libraryPanel.classList.toggle('is-hidden', !isListTab);
  settingsPanel.classList.toggle('is-hidden', !isSettingsTab);

  sidebarLinks.forEach((link) => {
    link.classList.toggle('is-active', (link.dataset.tab || '') === tab);
  });
}

function initializeSidebarMenu() {
  setActiveTab('accueil');

  sidebarLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      setActiveTab(link.dataset.tab || 'accueil');
    });
  });
}

function setStatus(message) {
  statusBox.textContent = message;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;');
}

function normalize(value) {
  return String(value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function parseViewCount(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.max(0, Math.floor(value));
  }

  if (typeof value !== 'string') {
    return 0;
  }

  const digits = value.replace(/[^0-9]/g, '');
  if (!digits) {
    return 0;
  }

  return Number.parseInt(digits, 10) || 0;
}

function isValidVideoId(value) {
  return typeof value === 'string' && /^[0-9A-Za-z_-]{11}$/.test(value.trim());
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

function getPlayedSeconds(media) {
  const ranges = media.played;
  let total = 0;

  for (let index = 0; index < ranges.length; index += 1) {
    total += Math.max(0, ranges.end(index) - ranges.start(index));
  }

  return total;
}

async function saveLikedMusic(track) {
  if (!track || !track.title) {
    return;
  }

  const parsedViews = parseViewCount(track.views);

  const params = new URLSearchParams({
    addMusic: '1',
    Titre: String(track.title || ''),
    Artiste: String(track.artist || ''),
    Album: String(track.albumId || track.folder || ''),
    Duree: Number.isFinite(audio.duration) ? String(Math.round(audio.duration)) : '',
    NombreVue: String(parsedViews),
    Utilisateur: 'ToComplete',
    DateAjout: new Date().toISOString().slice(0, 19).replace('T', ' '),
  });

  try {
    const response = await fetch(`interface.php?${params.toString()}`);
    const payload = await response.json();

    if (!payload.success) {
      console.error('Impossible d\'ajouter la musique en base:', payload.error || payload);
      return;
    }

    console.log('Musique ajoutee en base:', payload.music || track.title);
  } catch (error) {
    console.error('Erreur lors de l\'ajout en base:', error);
  }
}

async function loadLibrary() {
  try {
    const response = await fetch('list_library.php', { credentials: 'same-origin' });

    if (!response.ok) {
      if (response.status === 401) {
        window.location.replace('login.html');
        return;
      }
      throw new Error(`HTTP ${response.status}`);
    }

    const tracks = await response.json();
    state.library = tracks || [];
    renderLibrary();
    setStatus(`Bibliothèque chargée (${state.library.length} titres)`);
  } catch (error) {
    console.error(error);
    setStatus('Impossible de charger la bibliothèque locale.');
  }
}

function renderLibrary() {
  if (!state.library.length) {
    libraryList.innerHTML = '<li>Aucune piste locale n’a été trouvée.</li>';
    return;
  }

  libraryList.innerHTML = '';
  state.library.forEach((track, index) => {
    const item = document.createElement('li');
    item.innerHTML = `
      <div class="track-info">
        <strong>${escapeHtml(track.title)}</strong>
        <small>${escapeHtml(track.folder || 'Bibliothèque')}</small>
      </div>
      <div class="actions">
        <button class="track-action" data-index="${index}" type="button">▶</button>
      </div>`;
    item.querySelector('button').addEventListener('click', () => playTrack(track, index));
    libraryList.appendChild(item);
  });
}

async function loadSuggestions(query) {
  try {
    const response = await fetch(`interface.php?query=${encodeURIComponent(query)}`);
    const payload = await response.json();
    renderSuggestions(payload.suggestions || []);
  } catch (error) {
    console.error(error);
  }
}

async function searchMusic() {
  const query = searchInput.value.trim();

  if (!query) {
    setStatus('Saisissez un terme de recherche.');
    return;
  }

  setStatus(`Recherche de “${query}”…`);

  try {
    const response = await fetch(`interface.php?query=${encodeURIComponent(query)}`);
    const payload = await response.json();

    if (!payload.success) {
      setStatus(payload.error || 'Recherche impossible.');
      searchResults.innerHTML = '<li>Aucun résultat disponible.</li>';
      return;
    }

    const results = payload.results || [];
    const suggestions = payload.suggestions || [];
    renderSuggestions(suggestions);
    renderSearchResults(results);
    setStatus(`${results.length} résultat(s) trouvé(s) via YouTube Music.`);
  } catch (error) {
    console.error(error);
    setStatus('La recherche YouTube Music a échoué.');
  }
}

function renderSuggestions(suggestions) {
  if (!suggestions.length) {
    suggestionsBox.innerHTML = '';
    return;
  }

  suggestionsBox.innerHTML = '';
  suggestions.forEach((suggestion) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'suggestion-chip';
    button.textContent = suggestion;
    button.addEventListener('click', () => {
      searchInput.value = suggestion;
      searchMusic();
    });
    suggestionsBox.appendChild(button);
  });
}

function renderSearchResults(results) {
  if (!results.length) {
    searchResults.innerHTML = '<li>Aucun résultat trouvé.</li>';
    return;
  }

  searchResults.innerHTML = '';
  results.forEach((result) => {
    const match = findLocalMatch(result);
    const artists = Array.isArray(result.artists) ? result.artists.join(', ') : '';
    const albumId = String((result.album && result.album.id) || '').trim();
    const views = parseViewCount(result.views);
    const item = document.createElement('li');
    item.innerHTML = `
      <div class="track-info">
        <strong>${escapeHtml(result.title || 'Titre inconnu')}</strong>
        <small>${escapeHtml(artists || 'Artiste inconnu')}</small>
      </div>
      <div class="actions">
        <button class="queue-action" data-action="play" type="button">▶</button>
      </div>`;

    item.querySelector('button').addEventListener('click', async () => {
      console.log(`Action: play for "${result.title}"`);
      console.log(result);
      if (match) {
        if (result.videoId) {
          await loadPlaylistQueue(result.videoId);
        } else {
          resetPlaylistQueue();
        }
        const playableMatch = {
          ...match,
          artist: artists || match.artist || '',
          albumId: albumId || match.albumId || '',
          views: views || match.views || 0,
          videoId: result.videoId || match.videoId || '',
        };
        playTrack(playableMatch, state.library.findIndex((track) => track.path === match.path));
      } else if (result.videoId) {
        await downloadAndPlay(result.videoId, result.title || 'titre', {
          artist: artists,
          albumId,
          views,
        });
      } else {
        resetPlaylistQueue();
        setStatus('Aucun fichier local correspondant n’a été trouvé.');
      }
    });

    searchResults.appendChild(item);
  });
}

function findLocalMatch(result) {
  const target = normalize(`${result.title || ''} ${Array.isArray(result.artists) ? result.artists.join(' ') : ''}`);

  return state.library.find((track) => normalize(track.title).includes(target) || normalize(track.file).includes(target));
}

async function downloadAndPlay(videoId, title, options = {}) {
  const { skipQueueLoad = false, artist = '', albumId = '', views = 0 } = options;

  if (!isValidVideoId(videoId)) {
    setStatus('Identifiant video invalide pour le telechargement.');
    return;
  }

  setStatus(`Téléchargement de “${title}”…`);

  try {
    if (!skipQueueLoad) {
      await loadPlaylistQueue(videoId);
    }

    const response = await fetch(`interface.php?musicId=${encodeURIComponent(videoId)}`);
    const payload = await response.json();

    if (!payload.success) {
      setStatus(payload.error || 'Le téléchargement a échoué.');
      return;
    }

    const downloadPayload = payload.download || {};
    const downloadedFile = downloadPayload.file || (Array.isArray(downloadPayload) ? downloadPayload.find((entry) => typeof entry === 'string' && entry.trim()) : '');

    if (!downloadedFile) {
      setStatus(downloadPayload.error || 'Le téléchargement n’a pas produit de fichier audio.');
      return;
    }

    const track = {
      title,
      artist: String(artist || '').trim(),
      albumId: String(albumId || '').trim(),
      views: parseViewCount(views),
      path: `data/temp/${downloadedFile}`,
      file: downloadedFile,
      folder: 'temp',
      videoId,
    };

    await waitForMediaReady(track.path);
    await loadLibrary();

    const downloadedTrack = state.library.find((entry) => entry.path === track.path || entry.file === track.file) || track;
    const resolvedTitle = String(title || '').trim();

    if (resolvedTitle) {
      downloadedTrack.title = resolvedTitle;

      const libraryTrack = state.library.find((entry) => entry.path === downloadedTrack.path || entry.file === downloadedTrack.file);
      if (libraryTrack) {
        libraryTrack.title = resolvedTitle;
      }
    }

    if (track.artist) {
      downloadedTrack.artist = track.artist;

      const libraryTrack = state.library.find((entry) => entry.path === downloadedTrack.path || entry.file === downloadedTrack.file);
      if (libraryTrack) {
        libraryTrack.artist = track.artist;
      }
    }

    if (track.albumId) {
      downloadedTrack.albumId = track.albumId;

      const libraryTrack = state.library.find((entry) => entry.path === downloadedTrack.path || entry.file === downloadedTrack.file);
      if (libraryTrack) {
        libraryTrack.albumId = track.albumId;
      }
    }

    if (track.views > 0) {
      downloadedTrack.views = track.views;

      const libraryTrack = state.library.find((entry) => entry.path === downloadedTrack.path || entry.file === downloadedTrack.file);
      if (libraryTrack) {
        libraryTrack.views = track.views;
      }
    }

    downloadedTrack.videoId = videoId;
    playTrack(downloadedTrack, state.library.findIndex((entry) => entry.path === downloadedTrack.path));
    setStatus(`Lecture de “${title}” depuis le téléchargement.`);
  } catch (error) {
    console.error(error);
    setStatus('Le téléchargement et la lecture ont échoué.');
  }
}

async function waitForMediaReady(path, retries = 8, delayMs = 250) {
  for (let attempt = 0; attempt < retries; attempt += 1) {
    try {
      const response = await fetch(`${encodeURI(path)}?r=${Date.now()}`, { method: 'HEAD' });
      if (response.ok) {
        return;
      }
    } catch (error) {
      console.debug('Waiting for downloaded file...', error);
    }

    await new Promise((resolve) => {
      window.setTimeout(resolve, delayMs);
    });
  }

  throw new Error('Downloaded media is not reachable yet.');
}

function playTrack(track, index) {
  if (!track) {
    return;
  }

  const resolvedIndex = index >= 0 ? index : state.library.findIndex((candidate) => candidate.path === track.path);
  state.currentTrack = track;
  state.currentIndex = resolvedIndex;
  state.currentVideoId = track.videoId || '';
  state.likedLogged = false;
  state.likedSaved = false;
  const cacheBust = track.folder === 'temp' ? `?v=${Date.now()}` : '';
  audio.src = `${encodeURI(track.path)}${cacheBust}`;
  audio.load();

  nowPlaying.textContent = track.title;
  nowPlayingMeta.textContent = track.folder || 'Bibliothèque locale';

  audio.play().then(() => {
    playButton.textContent = '⏸';
  }).catch((error) => {
    console.error(error);
    setStatus('La lecture a été bloquée par le navigateur.');
  });
}

function resetPlaylistQueue() {
  state.queue = [];
  state.queueIndex = -1;
  state.currentVideoId = '';
}

async function loadPlaylistQueue(videoId) {
  if (!isValidVideoId(videoId)) {
    resetPlaylistQueue();
    return;
  }

  try {
    const response = await fetch(`interface.php?videoId=${encodeURIComponent(videoId)}`);
    const payload = await response.json();

    if (!payload.success || !Array.isArray(payload.playlist)) {
      resetPlaylistQueue();
      return;
    }

    const playlist = payload.playlist.filter((entry) => entry && isValidVideoId(entry.videoId));
    if (!playlist.length) {
      resetPlaylistQueue();
      return;
    }

    state.queue = playlist;
    const matchIndex = playlist.findIndex((entry) => entry.videoId === videoId);
    state.queueIndex = matchIndex >= 0 ? matchIndex : 0;
    state.currentVideoId = videoId;

    const nextVideo = playlist.slice(state.queueIndex + 1).find((entry) => entry && isValidVideoId(entry.videoId)) || null;
    console.log('Next video from playlist response:', nextVideo);
  } catch (error) {
    console.error(error);
    resetPlaylistQueue();
  }
}

function togglePlayback() {
  if (!audio.src) {
    const fallbackTrack = state.library[0];
    if (fallbackTrack) {
      playTrack(fallbackTrack, 0);
    }
    return;
  }

  if (audio.paused) {
    audio.play();
    playButton.textContent = '⏸';
  } else {
    audio.pause();
    playButton.textContent = '▶';
  }
}

async function playPrevious() {
  if (state.queue.length && state.queueIndex > 0) {
    for (let index = state.queueIndex - 1; index >= 0; index -= 1) {
      const previous = state.queue[index];
      if (!previous || !isValidVideoId(previous.videoId)) {
        continue;
      }

      state.queueIndex = index;
        await downloadAndPlay(previous.videoId, previous.title || 'titre', {
          skipQueueLoad: true,
          artist: Array.isArray(previous.artists) ? previous.artists.join(', ') : '',
          albumId: String((previous.album && previous.album.id) || ''),
          views: previous.views || 0,
        });
      return;
    }
  }

  if (!state.library.length) {
    return;
  }

  const previousIndex = (state.currentIndex - 1 + state.library.length) % state.library.length;
  playTrack(state.library[previousIndex], previousIndex);
}

async function playNext() {
  if (isValidVideoId(state.currentVideoId)) {
    if (!state.queue.length || state.queueIndex < 0) {
      await loadPlaylistQueue(state.currentVideoId);
    }

    if (state.queue.length) {
      const currentQueueIndex = state.queue.findIndex((entry) => entry && entry.videoId === state.currentVideoId);
      if (currentQueueIndex >= 0) {
        state.queueIndex = currentQueueIndex;
      }
    }

    if (state.queue.length && state.queueIndex >= 0 && state.queueIndex < state.queue.length - 1) {
      for (let index = state.queueIndex + 1; index < state.queue.length; index += 1) {
        const next = state.queue[index];
        if (!next || !isValidVideoId(next.videoId)) {
          continue;
        }

        state.queueIndex = index;
        await downloadAndPlay(next.videoId, next.title || 'titre', {
          skipQueueLoad: true,
          artist: Array.isArray(next.artists) ? next.artists.join(', ') : '',
          albumId: String((next.album && next.album.id) || ''),
          views: next.views || 0,
        });
        return;
      }
    }

    setStatus('Aucune piste suivante dans la playlist YouTube Music.');
    return;
  }

  if (!state.library.length) {
    return;
  }

  const nextIndex = (state.currentIndex + 1) % state.library.length;
  playTrack(state.library[nextIndex], nextIndex);
}

function seekPlayback(event) {
  if (!audio.duration) {
    return;
  }
  const ratio = Number(event.target.value) / 100;
  audio.currentTime = audio.duration * ratio;
}

function updateTimeDisplay() {
  seekBar.max = audio.duration || 100;
  seekBar.value = audio.currentTime || 0;
  timeLabel.textContent = `${formatTime(audio.currentTime)} / ${formatTime(audio.duration)}`;

  if (!state.likedLogged && Number.isFinite(audio.duration) && audio.duration > 0) {
    const listenedSeconds = getPlayedSeconds(audio);
    const ratio = listenedSeconds / audio.duration;
    if (ratio >= 0.75) {
      console.log('musique aimé');
      state.likedLogged = true;

      if (!state.likedSaved) {
        state.likedSaved = true;
        void saveLikedMusic(state.currentTrack);
      }
    }
  }
}
