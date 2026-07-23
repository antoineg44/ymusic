// Point d'entrée frontend: initialise les contrôleurs et coordonne les iframes.
const state = {
  library: [],
  queue: [],
  queueIndex: -1,
  currentIndex: -1,
  currentTrack: null,
  currentVideoId: '',
  currentDuration: 0,
  currentPlayedSeconds: 0,
  likedLogged: false,
  currentTab: 'accueil',
  playerReady: false,
  searchReady: false,
};

const libraryPanel = document.getElementById('libraryPanel');
const searchPanel = document.getElementById('searchPanel');
const artistsPanel = document.getElementById('artistsPanel');
const albumsPanel = document.getElementById('albumsPanel');
const playlistsPanel = document.getElementById('playlistsPanel');
const myPlaylistsPanel = document.getElementById('myPlaylistsPanel');
const communityPlaylistsPanel = document.getElementById('communityPlaylistsPanel');
const settingsPanel = document.getElementById('settingsPanel');
const queuePanel = document.getElementById('queuePanel');
const listFrame = document.getElementById('listFrame');
const searchFrame = document.getElementById('searchFrame');
const artistsFrame = document.getElementById('artistsFrame');
const albumsFrame = document.getElementById('albumsFrame');
const playlistsFrame = document.getElementById('playlistsFrame');
const myPlaylistsFrame = document.getElementById('myPlaylistsFrame');
const communityPlaylistsFrame = document.getElementById('communityPlaylistsFrame');
const queueFrame = document.getElementById('queueFrame');
const settingsFrame = document.getElementById('settingsFrame');
const manageUsersLink = document.getElementById('manageUsersLink');
const statusBox = document.getElementById('status');
const heroSection = document.querySelector('.hero');
const menuFrame = document.getElementById('menuFrame');
const playerFrame = document.getElementById('playerFrame');
const logoutButton = document.getElementById('logoutButton');
const descriptionModal = document.getElementById('descriptionModal');
const descriptionBackdrop = document.getElementById('descriptionModalBackdrop');
const descriptionFrame = document.getElementById('descriptionFrame');
const descriptionCloseButton = document.getElementById('descriptionCloseButton');
const playlistMenuModal = document.getElementById('playlistMenuModal');
const playlistMenuBackdrop = document.getElementById('playlistMenuModalBackdrop');
const playlistMenuFrame = document.getElementById('playlistMenuFrame');
const playlistMenuCloseButton = document.getElementById('playlistMenuCloseButton');
let pendingQueueRefreshOnLoad = null;

const playerController = window.createLecteurController({
  state,
  playerFrame,
  setStatus,
  isValidVideoId,
  parseViewCount,
  saveLikedMusic,
  loadLibrary,
  onTrackChanged: updateQueueDisplay,
  onOpenDescription: openDescriptionPopup,
});

const rechercheController = window.createRechercheController({
  state,
  setStatus,
  parseViewCount,
  normalize,
  playerController,
  searchFrame,
});

const authController = window.createAuthController({
  state,
  manageUsersLink,
  logoutButton,
});

if (descriptionCloseButton) {
  descriptionCloseButton.addEventListener('click', closeDescriptionPopup);
}

if (playlistMenuCloseButton) {
  playlistMenuCloseButton.addEventListener('click', closePlaylistMenuPopup);
}

if (descriptionBackdrop) {
  descriptionBackdrop.addEventListener('click', closeDescriptionPopup);
}

if (playlistMenuBackdrop) {
  playlistMenuBackdrop.addEventListener('click', closePlaylistMenuPopup);
}

window.addEventListener('message', (event) => {
  // Route les messages cross-iframe vers le contrôleur concerné.
  const message = event.data;
  if (!message) {
    return;
  }

  if (message.type === 'DISPLAY_OPEN_DESCRIPTION') {
    const song = message.song || message.result || (message.payload && message.payload.song);
    if (song) {
      openDescriptionPopupForSong(song);
    }
    return;
  }

  if (message.source === 'recherche') {
    rechercheController.handleMessage(message);
    return;
  }

  if (message.source === 'artistes') {
    if (message.type === 'ARTIST_PLAY_SONG') {
      const song = message.song || message.result || (message.payload && message.payload.song);
      if (song) {
        void handleArtistPlaySong(song);
      }
    }
    return;
  }

  if (message.source === 'liste') {
    if (message.type === 'LIST_PLAY_SONG') {
      const song = message.song || message.result || (message.payload && message.payload.song);
      if (song) {
        void handleListPlaySong(song);
      }
    } else if (message.type === 'LIST_OPEN_DESCRIPTION') {
      const song = message.song || message.result || (message.payload && message.payload.song);
      if (song) {
        openDescriptionPopupForSong(song);
      }
    } else if (message.type === 'OPEN_PLAYLIST_EDITION') {
      openPlaylistEditionPopup(message.playlistId, message.playlistName);
    } else if (message.type === 'REFRESH_ALL_PLAYLISTS') {
      requestMyPlaylistsRefresh();
      requestCommunityPlaylistsRefresh();
    }
    return;
  }

  if (message.source === 'playlists') {
    if (message.type === 'PLAYLIST_PLAY_RESULT' && message.result) {
      rechercheController.handleMessage({
        type: 'SEARCH_PLAY_RESULT',
        result: message.result,
      });
    } else if (message.type === 'PLAYLIST_LOAD_ALL' && Array.isArray(message.tracks) && message.tracks.length > 0) {
      // Charger toute la playlist dans la queue
      state.queue = message.tracks;
      state.queueIndex = 0;
      const firstTrack = message.tracks[0];
      
      if (firstTrack && isValidVideoId(firstTrack.videoId)) {
        void playerController.downloadAndPlay(firstTrack.videoId, firstTrack.title, {
          skipQueueLoad: true,
          artist: Array.isArray(firstTrack.artists) ? firstTrack.artists.join(', ') : '',
          views: 0,
        });
      }
    }
    return;
  }

  if (message.source === 'queue') {
    if (message.type === 'QUEUE_PLAY_TRACK' && typeof message.index === 'number') {
      if (Array.isArray(state.queue) && state.queue[message.index]) {
        state.queueIndex = message.index;
        const track = state.queue[message.index];
        void playerController.downloadAndPlay(track.videoId, track.title, {
          artist: Array.isArray(track.artists) ? track.artists.join(', ') : '',
          views: 0,
        });
      }
    } else if (message.type === 'QUEUE_REMOVE_TRACK' && typeof message.index === 'number') {
      if (Array.isArray(state.queue) && message.index >= 0 && message.index < state.queue.length) {
        state.queue.splice(message.index, 1);
        if (state.currentTab === 'queue') {
          requestQueueRefresh();
        }
      }
    }
    return;
  }

  if (message.source === 'description') {
    if (message.type === 'OPEN_EDITIONS') {
      openEditionsPopup(String(message.id || '').trim());
    }
    return;
  }

  if (message.source === 'editions') {
    if (message.type === 'REFRESH_ALL_PLAYLISTS') {
      requestMyPlaylistsRefresh();
      requestCommunityPlaylistsRefresh();
    } else if (message.type === 'MUSIC_DELETED') {
      requestListRefresh();
      requestMyPlaylistsRefresh();
      requestCommunityPlaylistsRefresh();
    }
    return;
  }

  if (message.source === 'playlistMenu') {
    if (message.type === 'CLOSE_PLAYLIST_MENU') {
      closePlaylistMenuPopup();
    }
    return;
  }

  if (message.source === 'playlistEdition') {
    if (message.type === 'CLOSE_PLAYLIST_EDITION') {
      closeDescriptionPopup();
    } else if (message.type === 'PLAYLIST_EDITION_SAVED') {
      closeDescriptionPopup();
      requestMyPlaylistsRefresh();
      requestCommunityPlaylistsRefresh();
    }
    return;
  }

  if (message.source === 'menu') {
    if (message.type === 'MENU_TAB_SELECTED') {
      setActiveTab(String(message.tab || 'accueil'));
    }
    return;
  }

  if (message.source === 'lecteur') {
    if (message.type === 'OPEN_PLAYLIST_MENU') {
      openPlaylistMenuPopup(message.musicId);
      return;
    }
    if (message.type === 'CLOSE_PLAYLIST_MENU') {
      closePlaylistMenuPopup();
      return;
    }
    playerController.handleMessage(message);
    
    // Mettre à jour l'affichage de la queue quand la musique change
    if (message.type === 'TRACK_CHANGED') {
      updateQueueDisplay();
    }
    return;
  }
});

document.addEventListener('DOMContentLoaded', () => {
  void initializeApp();
});

async function initializeApp() {
  const authenticated = await authController.ensureAuthenticated();
  if (!authenticated) {
    return;
  }

  initializeSidebarMenu();
}

function setActiveTab(tab) {
  console.log("change tab to", tab);

  const isHomeTab = tab === 'accueil';
  const isSearchTab = tab === 'recherche';
  const isListTab = tab === 'listes';
  const isArtistsTab = tab === 'artists';
  const isAlbumsTab = tab === 'albums';
  const isPlaylistsTab = tab === 'playlists';
  const isMyPlaylistsTab = tab === 'mes-playlists';
  const isCommunityPlaylistsTab = tab === 'playlists-communaute';
  const isQueueTab = tab === 'queue';
  const isSettingsTab = tab === 'parametres';

  state.currentTab = tab;

  if (heroSection) {
    heroSection.hidden = !isHomeTab;
  }

  searchPanel.classList.toggle('is-hidden', !isSearchTab);
  libraryPanel.classList.toggle('is-hidden', !isListTab);
  artistsPanel.classList.toggle('is-hidden', !isArtistsTab);
  albumsPanel.classList.toggle('is-hidden', !isAlbumsTab);
  playlistsPanel.classList.toggle('is-hidden', !isPlaylistsTab);
  myPlaylistsPanel.classList.toggle('is-hidden', !isMyPlaylistsTab);
  communityPlaylistsPanel.classList.toggle('is-hidden', !isCommunityPlaylistsTab);
  queuePanel.classList.toggle('is-hidden', !isQueueTab);
  settingsPanel.classList.toggle('is-hidden', !isSettingsTab);

  ensureTabIframeLoaded(tab);

  if (isListTab) {
    requestListRefresh();
  }

  // Mettre à jour la queue si l'onglet queue est affiché
  if (isQueueTab) {
    requestQueueRefresh();
  }

  // Pour demander un changement de tab à l'iframe menu
  /*if (menuFrame && menuFrame.contentWindow) {
    menuFrame.contentWindow.postMessage({ target: 'menu', type: 'SET_ACTIVE_TAB', tab }, '*');
  }*/
}

function ensureIframeLoaded(iframe) {
  if (!iframe || iframe.dataset.loaded === '1') {
    return false;
  }

  const src = String(iframe.dataset.src || '').trim();
  if (!src) {
    return false;
  }

  iframe.src = src;
  iframe.dataset.loaded = '1';
  iframe.dataset.ready = '0';

  if (iframe.dataset.readyBound !== '1') {
    iframe.addEventListener('load', () => {
      iframe.dataset.ready = '1';
    });
    iframe.dataset.readyBound = '1';
  }

  return true;
}

function ensureTabIframeLoaded(tab) {
  if (tab === 'listes') {
    ensureIframeLoaded(listFrame);
    return;
  }

  if (tab === 'recherche') {
    ensureIframeLoaded(searchFrame);
    return;
  }

  if (tab === 'artists') {
    ensureIframeLoaded(artistsFrame);
    return;
  }

  if (tab === 'albums') {
    ensureIframeLoaded(albumsFrame);
    return;
  }

  if (tab === 'playlists') {
    ensureIframeLoaded(playlistsFrame);
    return;
  }

  if (tab === 'queue') {
    ensureIframeLoaded(queueFrame);
    return;
  }

  if (tab === 'mes-playlists') {
    ensureIframeLoaded(myPlaylistsFrame);
    return;
  }

  if (tab === 'playlists-communaute') {
    ensureIframeLoaded(communityPlaylistsFrame);
    return;
  }

  if (tab === 'parametres') {
    ensureIframeLoaded(settingsFrame);
  }
}

function initializeSidebarMenu() {
  setActiveTab('accueil');
}

function requestListRefresh() {
  if (!listFrame) {
    return;
  }

  if (listFrame.dataset.loaded === '1' && listFrame.dataset.ready === '1' && listFrame.contentWindow) {
    listFrame.contentWindow.postMessage({
      target: 'liste',
      type: 'REFRESH_LIST',
    }, '*');
    return;
  }

  const refreshOnLoad = () => {
    if (!listFrame.contentWindow) {
      return;
    }

    listFrame.contentWindow.postMessage({
      target: 'liste',
      type: 'REFRESH_LIST',
    }, '*');
  };

  listFrame.addEventListener('load', refreshOnLoad, { once: true });
}

function resolveCurrentQueueIndex() {
  let currentPlayingIndex = -1;
  if (state.currentVideoId && Array.isArray(state.queue)) {
    currentPlayingIndex = state.queue.findIndex(
      (track) => track && track.videoId === state.currentVideoId
    );
  }
  if (currentPlayingIndex < 0) {
    currentPlayingIndex = state.queueIndex;
  }
  return currentPlayingIndex;
}

function postQueueUpdate() {
  if (!queueFrame || !queueFrame.contentWindow) {
    return;
  }

  queueFrame.contentWindow.postMessage({
    target: 'queue',
    type: 'UPDATE_QUEUE',
    queue: state.queue || [],
    currentIndex: resolveCurrentQueueIndex(),
  }, '*');
}

function requestQueueRefresh() {
  if (!queueFrame) {
    return;
  }

  if (queueFrame.dataset.loaded === '1' && queueFrame.dataset.ready === '1' && queueFrame.contentWindow) {
    postQueueUpdate();
    return;
  }

  const refreshOnLoad = () => {
    postQueueUpdate();
  };

  if (pendingQueueRefreshOnLoad) {
    queueFrame.removeEventListener('load', pendingQueueRefreshOnLoad);
  }

  pendingQueueRefreshOnLoad = refreshOnLoad;
  queueFrame.addEventListener('load', refreshOnLoad, { once: true });
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

  const normalized = value.trim().replace(/\s+/g, ' ');
  const shortMatch = normalized.match(/([0-9]+(?:[.,][0-9]+)?)\s*(md|m|k)\b/i);

  if (shortMatch) {
    const numericPart = Number.parseFloat(shortMatch[1].replace(',', '.'));
    const suffix = shortMatch[2].toLowerCase();

    if (Number.isFinite(numericPart)) {
      const multipliers = {
        k: 1_000,
        m: 1_000_000,
        md: 1_000_000_000,
      };

      const multiplier = multipliers[suffix] || 1;
      return Math.max(0, Math.floor(numericPart * multiplier));
    }
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
  // Persiste les métadonnées d'écoute avec fallback API pour éviter les champs manquants.
  if (!track || !track.title) {
    return;
  }

  const rawViews = track.views ?? track.NombreVue ?? track.viewCount ?? '';
  let parsedViews = parseViewCount(rawViews);
  let persistedAlbumId = String(
    track.albumId
    || track.Album
    || (track.album && track.album.id)
    || ''
  ).trim();
  const persistedId = isValidVideoId(track.videoId)
    ? track.videoId
    : (isValidVideoId(state.currentVideoId) ? state.currentVideoId : '');

  if (persistedId && (!persistedAlbumId || parsedViews <= 0)) {
    try {
      const fallbackParams = new URLSearchParams({
        musicDetails: '1',
        id: persistedId,
      });
      const fallbackTitle = String(track.title || '').trim();
      const fallbackArtist = String(track.artist || '').trim();
      if (fallbackTitle) {
        fallbackParams.set('title', fallbackTitle);
      }
      if (fallbackArtist) {
        fallbackParams.set('artist', fallbackArtist);
      }

      const fallbackResponse = await fetch(`php/interface.php?${fallbackParams.toString()}`, {
        credentials: 'same-origin',
        cache: 'no-store',
      });

      if (fallbackResponse.ok) {
        const fallbackPayload = await fallbackResponse.json();
        const fallbackMusic = fallbackPayload && fallbackPayload.music ? fallbackPayload.music : null;
        if (fallbackMusic) {
          if (!persistedAlbumId) {
            persistedAlbumId = String(fallbackMusic.Album || '').trim();
          }

          if (parsedViews <= 0) {
            parsedViews = parseViewCount(fallbackMusic.NombreVue);
          }
        }
      }
    } catch (error) {
      console.debug('Fallback music details unavailable for addMusic payload:', error);
    }
  }

  if (persistedId && (!persistedAlbumId || parsedViews <= 0) && (!Array.isArray(state.queue) || state.queue.length === 0)) {
    try {
      const playlistResponse = await fetch(`php/interface.php?videoId=${encodeURIComponent(persistedId)}`, {
        credentials: 'same-origin',
        cache: 'no-store',
      });

      if (playlistResponse.ok) {
        const playlistPayload = await playlistResponse.json();
        const playlist = Array.isArray(playlistPayload.playlist) ? playlistPayload.playlist : [];
        const currentEntry = playlist.find((entry) => entry && entry.videoId === persistedId) || playlist[0] || null;

        if (currentEntry) {
          if (!persistedAlbumId) {
            persistedAlbumId = String((currentEntry.album && currentEntry.album.id) || '').trim();
          }

          if (parsedViews <= 0) {
            parsedViews = parseViewCount(currentEntry.views);
          }
        }
      }
    } catch (error) {
      console.debug('Fallback playlist metadata unavailable for addMusic payload:', error);
    }
  }

  const params = new URLSearchParams({
    addMusic: '1',
    Id: persistedId,
    Titre: String(track.title || ''),
    Artiste: String(track.artist || ''),
    Album: persistedAlbumId,
    Duree: Number.isFinite(state.currentDuration) && state.currentDuration > 0 ? String(Math.round(state.currentDuration)) : '',
    NombreVue: parsedViews > 0 ? String(parsedViews) : '',
    Utilisateur: String((state.currentUser && state.currentUser.username) || ''),
    DateAjout: new Date().toISOString().slice(0, 19).replace('T', ' '),
  });

  try {
    const response = await fetch(`php/interface.php?${params.toString()}`);
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

function resolveCurrentTrackId() {
  const track = state.currentTrack;
  if (!track) {
    return '';
  }

  if (isValidVideoId(track.videoId)) {
    return track.videoId;
  }

  const candidates = [track.file, track.path]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .map((value) => {
      const filename = value.split('/').pop() || '';
      return filename.replace(/\.[^.]+$/, '').trim();
    });

  const matched = candidates.find((candidate) => isValidVideoId(candidate));
  return matched || '';
}

function openDescriptionPopup() {
  // Ouvre la fiche de la piste courante dans la modale iframe.
  if (!descriptionModal || !descriptionFrame) {
    return;
  }

  const musicId = resolveCurrentTrackId();
  const track = state.currentTrack || {};
  const title = String(track.title || '').trim();
  const artist = String(track.artist || '').trim();
  if (!musicId) {
    setStatus('Impossible d\'ouvrir la description: identifiant de musique introuvable.');
    return;
  }

  const params = new URLSearchParams({ id: musicId });
  if (title) {
    params.set('title', title);
  }
  if (artist) {
    params.set('artist', artist);
  }

  descriptionFrame.src = `description.html?${params.toString()}`;
  descriptionModal.classList.remove('is-hidden');
  descriptionModal.setAttribute('aria-hidden', 'false');
}

function openDescriptionPopupForSong(song) {
  if (!descriptionModal || !descriptionFrame) {
    return;
  }

  const musicId = String((song && (song.Id || song.videoId)) || '').trim();
  const title = String((song && (song.Titre || song.title)) || '').trim();
  const artistFromArray = Array.isArray(song && song.artists) ? String((song.artists[0] || '')) : '';
  const artist = String((song && (song.Artiste || song.artist || artistFromArray)) || '').trim();

  if (!musicId) {
    setStatus('Impossible d\'ouvrir la description: identifiant de musique introuvable.');
    return;
  }

  const params = new URLSearchParams({ id: musicId });
  if (title) {
    params.set('title', title);
  }
  if (artist) {
    params.set('artist', artist);
  }

  descriptionFrame.src = `description.html?${params.toString()}`;
  descriptionModal.classList.remove('is-hidden');
  descriptionModal.setAttribute('aria-hidden', 'false');
}

function closeDescriptionPopup() {
  if (!descriptionModal || !descriptionFrame) {
    return;
  }

  descriptionModal.classList.add('is-hidden');
  descriptionModal.setAttribute('aria-hidden', 'true');
  descriptionFrame.src = 'about:blank';
}

function openEditionsPopup(musicId) {
  if (!descriptionModal || !descriptionFrame) {
    return;
  }

  const id = String(musicId || '').trim();
  if (!id) {
    setStatus('Impossible d\'ouvrir editions: identifiant de musique introuvable.');
    return;
  }

  descriptionFrame.src = `editions.html?id=${encodeURIComponent(id)}&popup=1`;
  descriptionModal.classList.remove('is-hidden');
  descriptionModal.setAttribute('aria-hidden', 'false');
}

function openPlaylistEditionPopup(playlistId, playlistName) {
  if (!descriptionModal || !descriptionFrame) {
    return;
  }

  const id = String(playlistId || '').trim();
  if (!id) {
    setStatus('Impossible d\'ouvrir edition playlist: identifiant introuvable.');
    return;
  }

  const params = new URLSearchParams({ id, popup: '1' });
  const name = String(playlistName || '').trim();
  if (name) {
    params.set('name', name);
  }

  descriptionFrame.src = `playlistEdition.html?${params.toString()}`;
  descriptionModal.classList.remove('is-hidden');
  descriptionModal.setAttribute('aria-hidden', 'false');
}

function requestMyPlaylistsRefresh() {
  if (!myPlaylistsFrame || !myPlaylistsFrame.contentWindow) {
    return;
  }

  myPlaylistsFrame.contentWindow.postMessage(
    {
      target: 'userPlaylists',
      type: 'REFRESH_USER_PLAYLISTS',
    },
    '*'
  );
}

function requestCommunityPlaylistsRefresh() {
  if (!communityPlaylistsFrame || !communityPlaylistsFrame.contentWindow) {
    return;
  }

  communityPlaylistsFrame.contentWindow.postMessage(
    {
      target: 'listePlaylists',
      type: 'REFRESH_LISTE_PLAYLISTS',
    },
    '*'
  );
}

function openPlaylistMenuPopup(musicId) {
  if (!playlistMenuModal || !playlistMenuFrame) {
    return;
  }

  const id = String(musicId || '').trim();
  playlistMenuFrame.src = `playlistMenu.html?musicId=${encodeURIComponent(id)}`;
  playlistMenuModal.classList.remove('is-hidden');
  playlistMenuModal.setAttribute('aria-hidden', 'false');
  
  // Envoyer un message à la iframe pour l'ouvrir
  if (playlistMenuFrame.contentWindow) {
    playlistMenuFrame.contentWindow.postMessage(
      { target: 'playlistMenu', type: 'OPEN_MENU', musicId: id },
      '*'
    );
  }
}

function closePlaylistMenuPopup() {
  if (!playlistMenuModal || !playlistMenuFrame) {
    return;
  }

  playlistMenuModal.classList.add('is-hidden');
  playlistMenuModal.setAttribute('aria-hidden', 'true');
  playlistMenuFrame.src = 'about:blank';
}

async function loadLibrary(filePath) {
  try {
    let url = 'php/list_library.php';
    
    // Si un chemin de fichier est fourni, on cherche juste cette musique
    if (filePath) {
      url = `php/list_library.php?file=${encodeURIComponent(filePath)}`;
      const response = await fetch(url, { credentials: 'same-origin' });

      if (!response.ok) {
        if (response.status === 401) {
          window.location.replace('login.html');
          return;
        }
        throw new Error(`HTTP ${response.status}`);
      }

      const track = await response.json();
      if (track) {
        // Ajouter ou mettre à jour la musique dans la bibliothèque
        const existingIndex = state.library.findIndex(
          (t) => t.file === track.file || t.path === track.path
        );
        
        if (existingIndex >= 0) {
          state.library[existingIndex] = track;
        } else {
          state.library.push(track);
        }
      }
      return;
    }

    // Charger toute la bibliothèque (comportement par défaut)
    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
      if (response.status === 401) {
        window.location.replace('login.html');
        return;
      }
      throw new Error(`HTTP ${response.status}`);
    }

    const tracks = await response.json();
    state.library = tracks || [];
    setStatus(`Bibliothèque chargée (${state.library.length} titres)`);
  } catch (error) {
    console.error(error);
    setStatus('Impossible de charger la bibliothèque locale.');
  }
}

function findLibraryTrackByMusicId(musicId) {
  const targetId = String(musicId || '').trim();
  if (!targetId) {
    return null;
  }

  return state.library.find((track) => {
    const fileStem = String(track.file || '').replace(/\.[^.]+$/, '');
    const pathName = String(track.path || '').split('/').pop() || '';
    const pathStem = pathName.replace(/\.[^.]+$/, '');
    return fileStem === targetId || pathStem === targetId;
  }) || null;
}

async function handleArtistPlaySong(song) {
  const musicId = String((song && song.Id) || '').trim();
  if (!musicId) {
    setStatus('Impossible de lire cette musique depuis Artistes (Id manquant).');
    return;
  }

  const libraryMatch = findLibraryTrackByMusicId(musicId);
  if (libraryMatch) {
    const playableMatch = {
      ...libraryMatch,
      musicId: musicId,
      Id: musicId,
      title: String(song.Titre || libraryMatch.title || ''),
      artist: String(song.Artiste || libraryMatch.artist || ''),
      albumId: String(song.Album || libraryMatch.albumId || ''),
      views: Number(song.NombreVue || libraryMatch.views || 0),
      videoId: isValidVideoId(musicId) ? musicId : String(libraryMatch.videoId || ''),
    };
    playerController.playTrack(playableMatch, state.library.findIndex((track) => track.path === libraryMatch.path));
    setStatus(`Lecture de "${playableMatch.title || musicId}" depuis Artistes.`);
    return;
  }

  if (isValidVideoId(musicId)) {
    await playerController.downloadAndPlay(musicId, String(song.Titre || 'titre'), {
      artist: String(song.Artiste || ''),
      albumId: String(song.Album || ''),
      views: Number(song.NombreVue || 0),
    });
    return;
  }

  setStatus('Lecture impossible depuis Artistes: Id non supporte pour telechargement.');
}

async function handleListPlaySong(song) {
  const musicId = String((song && song.Id) || '').trim();
  if (!musicId) {
    setStatus('Impossible de lire cette musique depuis Listes (Id manquant).');
    return;
  }

  const libraryMatch = findLibraryTrackByMusicId(musicId);
  if (libraryMatch) {
    const playableMatch = {
      ...libraryMatch,
      musicId: musicId,
      Id: musicId,
      title: String(song.Titre || libraryMatch.title || ''),
      artist: String(song.Artiste || libraryMatch.artist || ''),
      albumId: String(song.Album || libraryMatch.albumId || ''),
      views: Number(song.NombreVue || libraryMatch.views || 0),
      videoId: isValidVideoId(musicId) ? musicId : String(libraryMatch.videoId || ''),
    };
    playerController.playTrack(playableMatch, state.library.findIndex((track) => track.path === libraryMatch.path));
    setStatus(`Lecture de "${playableMatch.title || musicId}" depuis Listes.`);
    return;
  }

  if (isValidVideoId(musicId)) {
    await playerController.downloadAndPlay(musicId, String(song.Titre || 'titre'), {
      artist: String(song.Artiste || ''),
      albumId: String(song.Album || ''),
      views: Number(song.NombreVue || 0),
    });
    return;
  }

  setStatus('Lecture impossible depuis Listes: Id non supporte pour telechargement.');
}

async function downloadAndPlay(videoId, title, options = {}) {
  await playerController.downloadAndPlay(videoId, title, options);
}

function playTrack(track, index) {
  playerController.playTrack(track, index);
}

function resetPlaylistQueue() {
  playerController.resetPlaylistQueue();
}

async function loadPlaylistQueue(videoId) {
  await playerController.loadPlaylistQueue(videoId);
}

function togglePlayback() {
  playerController.togglePlayback();
}

async function playPrevious() {
  await playerController.playPrevious();
}

async function playNext() {
  await playerController.playNext();
}

function updateTimeDisplay() {
  playerController.updateTimeDisplay();
}

function updateQueueDisplay() {
  // Met à jour l'affichage de la queue chaque fois que la musique change
  requestQueueRefresh();
}
