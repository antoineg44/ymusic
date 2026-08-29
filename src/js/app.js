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
  likedSaved: false,
  favorite: false,
  currentTab: 'accueil',
  playerReady: false,
  searchReady: false,
};

// Variables déclarées mais initialisées après le chargement du HTML
let homePanel;
let libraryPanel;
let searchPanel;
let artistsPanel;
let albumsPanel;
let playlistsPanel;
let myPlaylistsPanel;
let communityPlaylistsPanel;
let settingsPanel;
let queuePanel;
let homeFrame;
let listFrame;
let searchFrame;
let artistsFrame;
let albumsFrame;
let playlistsFrame;
let myPlaylistsFrame;
let communityPlaylistsFrame;
let queueFrame;
let settingsFrame;
let manageUsersLink;
let statusBox;
let heroSection;
let menuFrame;
let playerFrame;
let logoutButton;
let descriptionModal;
let descriptionBackdrop;
let descriptionFrame;
let descriptionCloseButton;
let usersModal;
let usersBackdrop;
let usersFrame;
let usersCloseButton;
let loginModal;
let loginFrame;
let loginModalBackdrop;
let musicIntegrityModal;
let musicIntegrityBackdrop;
let musicIntegrityFrame;
let musicIntegrityCloseButton;
let playlistMenuModal;
let playlistMenuBackdrop;
let playlistMenuFrame;
let playlistMenuCloseButton;

// Fonction pour initialiser les références aux éléments DOM
function initializeDOMElements() {
  homePanel = document.getElementById('homePanel');
  libraryPanel = document.getElementById('libraryPanel');
  searchPanel = document.getElementById('searchPanel');
  artistsPanel = document.getElementById('artistsPanel');
  albumsPanel = document.getElementById('albumsPanel');
  playlistsPanel = document.getElementById('playlistsPanel');
  myPlaylistsPanel = document.getElementById('myPlaylistsPanel');
  communityPlaylistsPanel = document.getElementById('communityPlaylistsPanel');
  settingsPanel = document.getElementById('settingsPanel');
  queuePanel = document.getElementById('queuePanel');
  homeFrame = document.getElementById('homeFrame');
  listFrame = document.getElementById('listFrame');
  searchFrame = document.getElementById('searchFrame');
  artistsFrame = document.getElementById('artistsFrame');
  albumsFrame = document.getElementById('albumsFrame');
  playlistsFrame = document.getElementById('playlistsFrame');
  myPlaylistsFrame = document.getElementById('myPlaylistsFrame');
  communityPlaylistsFrame = document.getElementById('communityPlaylistsFrame');
  queueFrame = document.getElementById('queueFrame');
  settingsFrame = document.getElementById('settingsFrame');
  manageUsersLink = document.getElementById('manageUsersLink');
  statusBox = document.getElementById('status');
  heroSection = document.querySelector('.hero');
  menuFrame = document.getElementById('menuFrame');
  playerFrame = document.getElementById('playerFrame');
  logoutButton = document.getElementById('logoutButton');
  descriptionModal = document.getElementById('descriptionModal');
  descriptionBackdrop = document.getElementById('descriptionModalBackdrop');
  descriptionFrame = document.getElementById('descriptionFrame');
  descriptionCloseButton = document.getElementById('descriptionCloseButton');
  usersModal = document.getElementById('usersModal');
  usersBackdrop = document.getElementById('usersModalBackdrop');
  usersFrame = document.getElementById('usersFrame');
  usersCloseButton = document.getElementById('usersCloseButton');
  loginModal = document.getElementById('loginModal');
  loginFrame = document.getElementById('loginFrame');
  loginModalBackdrop = document.getElementById('loginModalBackdrop');
  musicIntegrityModal = document.getElementById('musicIntegrityModal');
  musicIntegrityBackdrop = document.getElementById('musicIntegrityModalBackdrop');
  musicIntegrityFrame = document.getElementById('musicIntegrityFrame');
  musicIntegrityCloseButton = document.getElementById('musicIntegrityCloseButton');
  playlistMenuModal = document.getElementById('playlistMenuModal');
  playlistMenuBackdrop = document.getElementById('playlistMenuModalBackdrop');
  playlistMenuFrame = document.getElementById('playlistMenuFrame');
  playlistMenuCloseButton = document.getElementById('playlistMenuCloseButton');
}
let pendingQueueRefreshOnLoad = null;
let playerController = null;
let rechercheController = null;
let authController = null;
let appIsOffline = false;

function initializeControllers() {
  if (playerController || rechercheController || authController) {
    return;
  }

  playerController = window.createLecteurController({
    state,
    playerFrame,
    setStatus,
    isValidVideoId,
    parseViewCount,
    saveLikedMusic,
    setFavoriteMusic,
    fetchFavoriteState,
    onTrackChanged: updateQueueDisplay,
    onOpenDescription: openDescriptionPopup,
  });

  rechercheController = window.createRechercheController({
    state,
    setStatus,
    parseViewCount,
    normalize,
    playerController,
    searchFrame,
  });

  authController = window.createAuthController({
    state,
    manageUsersLink,
    logoutButton,
  });
}

function attachModalEventListeners() {
  if (descriptionCloseButton) {
    descriptionCloseButton.addEventListener('click', closeDescriptionPopup);
  }

  if (playlistMenuCloseButton) {
    playlistMenuCloseButton.addEventListener('click', closePlaylistMenuPopup);
  }

  if (usersCloseButton) {
    usersCloseButton.addEventListener('click', closeUsersPopup);
  }

  if (musicIntegrityCloseButton) {
    musicIntegrityCloseButton.addEventListener('click', closeMusicIntegrityPopup);
  }

  if (descriptionBackdrop) {
    descriptionBackdrop.addEventListener('click', closeDescriptionPopup);
  }

  if (playlistMenuBackdrop) {
    playlistMenuBackdrop.addEventListener('click', closePlaylistMenuPopup);
  }

  if (usersBackdrop) {
    usersBackdrop.addEventListener('click', closeUsersPopup);
  }

  if (musicIntegrityBackdrop) {
    musicIntegrityBackdrop.addEventListener('click', closeMusicIntegrityPopup);
  }

  if (loginModalBackdrop) {
    loginModalBackdrop.addEventListener('click', closeLoginModal);
  }
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
    } else if (message.type === 'REFRESH_ALL_PLAYLISTS') {
      requestMyPlaylistsRefresh();
      requestCommunityPlaylistsRefresh();
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
    } else if (message.type === 'ADD_CURRENT_MUSIC_TO_PLAYLIST') {
      void addCurrentMusicToPlaylistFromMenu(message);
    }
    return;
  }

  if (message.source === 'parameters') {
    if (message.type === 'OPEN_USERS_MODAL') {
      openUsersPopup();
    } else if (message.type === 'OPEN_MUSIC_INTEGRITY_MODAL') {
      openMusicIntegrityPopup();
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
    } else if (message.type === 'MENU_READY') {
      sendOfflineStateToMenu();
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

  if (message.type === 'USER_LOGGED_OUT') {
    // Afficher le modal login au lieu de rediriger
    openLoginModal();
  }

  if (message.type === 'USER_LOGGED_IN') {
    // Cacher le modal login au lieu de rediriger
    closeLoginModal();
    initializeApp();
  }

  if (message.type === 'INITIALIZATION_DONE') {
    // Cacher le modal login au lieu de rediriger
    void initializeApp();
        setActiveTab('accueil');
  }

  const data = event.data;
  if(data && message && data.messageId && message.type === 'db')
  {
    db_listener(event);
  }
});

async function initializeApp() {
  // Initialiser les références DOM en premier
  initializeDOMElements();
  initializeControllers();
  attachModalEventListeners();
  
  // Attacher l'event listener au bouton logout (qui n'existait pas au moment de la création de authController)
  if (logoutButton && authController) {
    logoutButton.addEventListener('click', () => {
      void authController.logout();
      openLoginModal();
    });
  }
  
  authController.ensureAuthenticated();
  
  initializeSidebarMenu();

  // Detection fiable du mode hors ligne (navigator.onLine est peu fiable dans la WebView).
  appIsOffline = await detectOfflineStatus();
  if (appIsOffline) {
    showOfflinePopup();
  }
  sendOfflineStateToMenu();

  // En ligne: verifier si une version plus recente est disponible dans src/release.
  if (!appIsOffline) {
    void checkForNewVersion();
  }
}

// Expose explicitement le bootstrap pour les scripts inline (index.html).
window.initializeApp = initializeApp;

function setActiveTab(tab, searchQuery = '') {
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

  if (menuFrame && menuFrame.contentWindow) {
    menuFrame.contentWindow.postMessage({ target: 'menu', type: 'SET_ACTIVE_TAB', tab }, '*');
  }

  const normalizedQuery = String(searchQuery || '').trim();
  if (isSearchTab && normalizedQuery && searchFrame && searchFrame.contentWindow) {
    const sendSearchQuery = () => {
      searchFrame.contentWindow.postMessage({ target: 'search', type: 'SET_SEARCH_QUERY', query: normalizedQuery }, '*');
    };

    if (searchFrame.dataset.ready === '1') {
      sendSearchQuery();
    } else {
      searchFrame.addEventListener('load', sendSearchQuery, { once: true });
    }
  }

  if (heroSection) {
    heroSection.hidden = !isHomeTab;
  }

  homePanel.classList.toggle('is-hidden', !isHomeTab);
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

  if (isAlbumsTab) {
    requestFavoritesRefresh();
  }

  if (isArtistsTab) {
    requestHistoryRefresh();
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
  if (tab === 'accueil') {
    ensureIframeLoaded(homeFrame);
    return;
  }

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

function requestFavoritesRefresh() {
  if (!albumsFrame) {
    return;
  }

  const postRefresh = () => {
    if (albumsFrame.contentWindow) {
      albumsFrame.contentWindow.postMessage({
        target: 'favoris',
        type: 'REFRESH_FAVORITES',
      }, '*');
    }
  };

  if (albumsFrame.dataset.loaded === '1' && albumsFrame.dataset.ready === '1' && albumsFrame.contentWindow) {
    postRefresh();
    return;
  }

  albumsFrame.addEventListener('load', postRefresh, { once: true });
}

function requestHistoryRefresh() {
  if (!artistsFrame) {
    return;
  }

  const postRefresh = () => {
    if (artistsFrame.contentWindow) {
      artistsFrame.contentWindow.postMessage({
        target: 'artistes',
        type: 'REFRESH_HISTORY',
      }, '*');
    }
  };

  if (artistsFrame.dataset.loaded === '1' && artistsFrame.dataset.ready === '1' && artistsFrame.contentWindow) {
    postRefresh();
    return;
  }

  artistsFrame.addEventListener('load', postRefresh, { once: true });
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
  // Le compteur est incrémenté côté PHP lors de la lecture.
  if (!track || !track.title) {
    return;
  }

  const persistedId = isValidVideoId(track.videoId)
    ? track.videoId
    : (isValidVideoId(state.currentVideoId) ? state.currentVideoId : '');

  if (!persistedId) {
    return;
  }

  try {
    const response = await sendMessageAndWait(window, { action: 'play', query: persistedId });
    if (response && response.success) {
      console.log('NombreVueInterne incrémente côté PHP:', response.music || persistedId);
    }
  } catch (error) {
    console.error('saveLikedMusic error:', error);
  }
}

async function setFavoriteMusic(musicId, shouldFavorite) {
  // Ajoute ou retire la musique de la table MusiquesAimees; renvoie l'etat de favori resultant.
  const id = String(musicId || '').trim();
  if (!id) {
    return Boolean(shouldFavorite) === false;
  }

  try {
    const response = await sendMessageAndWait(window, {
      action: shouldFavorite ? 'addFavoriteMusic' : 'removeFavoriteMusic',
      body: { IdMusique: id },
    });

    if (response && response.success) {
      return Boolean(response.favorite);
    }
  } catch (error) {
    console.error('setFavoriteMusic error:', error);
  }

  return !shouldFavorite;
}

async function fetchFavoriteState(musicId) {
  const id = String(musicId || '').trim();
  if (!id) {
    return false;
  }

  try {
    const response = await sendMessageAndWait(window, { action: 'favoriteState', query: id });
    return Boolean(response && response.success && response.isFavorite);
  } catch (error) {
    console.error('fetchFavoriteState error:', error);
    return false;
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

  descriptionFrame.src = `popup/description/description.html?${params.toString()}`;
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

  descriptionFrame.src = `popup/description/description.html?${params.toString()}`;
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

function openLoginModal() {
  if (!loginModal || !loginFrame) {
    return;
  }

  // Hors-ligne: ne pas afficher la connexion (session/donnees locales utilisees).
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return;
  }

  loginModal.classList.remove('is-hidden');
  loginModal.setAttribute('aria-hidden', 'false');
}

function closeLoginModal() {
  if (!loginModal) {
    return;
  }

  loginModal.classList.add('is-hidden');
  loginModal.setAttribute('aria-hidden', 'true');
}

// Detecte de maniere fiable l'absence de connexion au serveur (navigator.onLine peu fiable en WebView).
async function detectOfflineStatus() {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return true;
  }

  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 4000);
    const response = await fetch(get_url_from_base() + 'php/auth.php?action=check', {
      method: 'GET',
      cache: 'no-store',
      credentials: 'include',
      signal: controller.signal,
    });
    clearTimeout(timer);
    return !response.ok;
  } catch (error) {
    // Erreur reseau (serveur injoignable) => hors ligne.
    return true;
  }
}

// Transmet l'etat hors ligne courant a l'iframe du menu (pour griser Recherche/Playlists).
function sendOfflineStateToMenu() {
  if (menuFrame && menuFrame.contentWindow) {
    menuFrame.contentWindow.postMessage({ target: 'menu', type: 'SET_OFFLINE_STATE', offline: appIsOffline }, '*');
  }
}

// Affiche un popup indiquant que l'application a demarre sans connexion Internet.
function showOfflinePopup() {
  if (document.getElementById('offlinePopup')) {
    return;
  }

  const overlay = document.createElement('div');
  overlay.id = 'offlinePopup';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Mode hors ligne');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,0.65);';

  const box = document.createElement('div');
  box.style.cssText = 'max-width:min(420px,90vw);background:#1e293b;color:#e2e8f0;border:1px solid rgba(148,163,184,0.35);border-radius:16px;padding:24px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.5);';

  const icon = document.createElement('div');
  icon.textContent = '\uD83D\uDCF6';
  icon.style.cssText = 'font-size:2.5rem;margin-bottom:8px;';

  const title = document.createElement('h2');
  title.textContent = 'Mode hors ligne';
  title.style.cssText = 'margin:0 0 8px;font-size:1.25rem;';

  const text = document.createElement('p');
  text.textContent = "L'application a demarre sans connexion Internet. La recherche et les playlists YouTube sont indisponibles. Vos musiques telechargees restent accessibles.";
  text.style.cssText = 'margin:0 0 20px;line-height:1.5;color:#cbd5e1;';

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.textContent = 'Compris';
  closeButton.style.cssText = 'background:#38bdf8;color:#0f172a;border:none;border-radius:10px;padding:10px 24px;font-size:1rem;font-weight:600;cursor:pointer;';

  const close = () => overlay.remove();
  closeButton.addEventListener('click', close);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      close();
    }
  });

  box.append(icon, title, text, closeButton);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

// Extrait la version depuis un nom de fichier APK de la forme app-<version>.apk.
function parseApkVersion(filename) {
  const match = /^app-(.+)\.apk$/i.exec(String(filename || '').trim());
  return match ? match[1] : null;
}

// Compare deux versions "x.y.z" (retourne >0 si a est plus recente que b).
function compareVersions(a, b) {
  const pa = String(a || '').split('.').map((n) => parseInt(n, 10) || 0);
  const pb = String(b || '').split('.').map((n) => parseInt(n, 10) || 0);
  const length = Math.max(pa.length, pb.length);
  for (let i = 0; i < length; i += 1) {
    const diff = (pa[i] || 0) - (pb[i] || 0);
    if (diff !== 0) {
      return diff;
    }
  }
  return 0;
}

// Affiche un popup proposant de telecharger une nouvelle version de l'application.
function showUpdatePopup(version, url) {
  if (document.getElementById('updatePopup')) {
    return;
  }

  const overlay = document.createElement('div');
  overlay.id = 'updatePopup';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Mise a jour disponible');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,0.65);';

  const box = document.createElement('div');
  box.style.cssText = 'max-width:min(420px,90vw);background:#1e293b;color:#e2e8f0;border:1px solid rgba(148,163,184,0.35);border-radius:16px;padding:24px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.5);';

  const icon = document.createElement('div');
  icon.textContent = '\u2B06\uFE0F';
  icon.style.cssText = 'font-size:2.5rem;margin-bottom:8px;';

  const title = document.createElement('h2');
  title.textContent = 'Nouvelle version disponible';
  title.style.cssText = 'margin:0 0 8px;font-size:1.25rem;';

  const text = document.createElement('p');
  text.textContent = `La version ${version} de l'application est disponible au telechargement.`;
  text.style.cssText = 'margin:0 0 20px;line-height:1.5;color:#cbd5e1;';

  const downloadLink = document.createElement('a');
  downloadLink.href = url;
  downloadLink.textContent = 'Telecharger';
  downloadLink.setAttribute('download', '');
  downloadLink.setAttribute('target', '_blank');
  downloadLink.setAttribute('rel', 'noopener');
  downloadLink.style.cssText = 'display:inline-block;background:#38bdf8;color:#0f172a;border-radius:10px;padding:10px 24px;font-size:1rem;font-weight:600;text-decoration:none;margin-right:8px;';

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.textContent = 'Plus tard';
  closeButton.style.cssText = 'background:transparent;color:#cbd5e1;border:1px solid rgba(148,163,184,0.5);border-radius:10px;padding:10px 24px;font-size:1rem;font-weight:600;cursor:pointer;';

  const close = () => overlay.remove();
  closeButton.addEventListener('click', close);
  downloadLink.addEventListener('click', close);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      close();
    }
  });

  const actions = document.createElement('div');
  actions.append(downloadLink, closeButton);
  box.append(icon, title, text, actions);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

// Verifie si src/release contient un APK plus recent que la version courante et propose la mise a jour.
async function checkForNewVersion() {
  const currentVersion = typeof APP_VERSION === 'string' ? APP_VERSION : '';
  if (!currentVersion) {
    return;
  }

  try {
    const payload = await sendMessageAndWait(window, { action: 'releaseFiles' });
    if (!payload || payload.success === false || !Array.isArray(payload.files)) {
      return;
    }

    let best = null;
    for (const file of payload.files) {
      const version = parseApkVersion(file && file.name);
      if (!version || compareVersions(version, currentVersion) <= 0) {
        continue;
      }
      if (!best || compareVersions(version, best.version) > 0) {
        best = { version, path: String((file && file.path) || '') };
      }
    }

    if (best && best.path) {
      showUpdatePopup(best.version, get_url_from_base() + best.path);
    }
  } catch (error) {
    console.debug('checkForNewVersion error:', error);
  }
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

  descriptionFrame.src = `popup/edition/edition.html?id=${encodeURIComponent(id)}&popup=1`;
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

  descriptionFrame.src = `popup/playlistEdition/playlistEdition.html?${params.toString()}`;
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

function postPlaylistMenuResult(payload) {
  if (!playlistMenuFrame || !playlistMenuFrame.contentWindow) {
    return;
  }

  playlistMenuFrame.contentWindow.postMessage(
    {
      target: 'playlistMenu',
      type: 'ADD_TO_PLAYLIST_RESULT',
      ...(payload || {}),
    },
    '*'
  );
}

async function addCurrentMusicToPlaylistFromMenu(message) {
  const playlistId = Number((message && message.playlistId) || 0);
  const playlistName = String((message && message.playlistName) || '').trim();
  let musicId = String((message && message.musicId) || '').trim();

  if (playlistId <= 0) {
    postPlaylistMenuResult({ success: false, error: 'Playlist invalide.' });
    return;
  }

  try {
    // Même comportement que le bouton favoris: sauvegarde/telechargement en base d'abord.
    if (playerController && typeof playerController.toggleFavorite === 'function') {
      await playerController.toggleFavorite();
    }

    if (!musicId) {
      musicId = resolveCurrentTrackId();
    }

    if (!musicId) {
      throw new Error('Musique courante introuvable.');
    }

    const payload = await sendMessageAndWait(window, {
      action: 'addPlaylistMusic',
      body: {
        IdPlaylist: String(playlistId),
        IdMusique: musicId,
      },
    });

    if (!payload || !payload.success) {
      throw new Error(payload.error || 'Impossible d\'ajouter la musique.');
    }

    postPlaylistMenuResult({
      success: true,
      message: String(payload.message || `Ajoute a ${playlistName || 'la playlist'}.`),
    });

    requestMyPlaylistsRefresh();
    requestCommunityPlaylistsRefresh();
  } catch (error) {
    console.error(error);
    postPlaylistMenuResult({
      success: false,
      error: String((error && error.message) || 'Erreur lors de l\'ajout.'),
    });
  }
}

function openPlaylistMenuPopup(musicId) {
  if (!playlistMenuModal || !playlistMenuFrame) {
    return;
  }

  const id = String(musicId || '').trim();
  playlistMenuFrame.src = `popup/playlistMenu/playlistMenu.html?musicId=${encodeURIComponent(id)}`;
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

function openUsersPopup() {
  if (!usersModal || !usersFrame) {
    return;
  }

  usersFrame.src = 'popup/users/users.html';
  usersModal.classList.remove('is-hidden');
  usersModal.setAttribute('aria-hidden', 'false');
}

function closeUsersPopup() {
  if (!usersModal || !usersFrame) {
    return;
  }

  usersModal.classList.add('is-hidden');
  usersModal.setAttribute('aria-hidden', 'true');
  usersFrame.src = 'about:blank';
}

function openMusicIntegrityPopup() {
  if (!musicIntegrityModal || !musicIntegrityFrame) {
    return;
  }

  musicIntegrityFrame.src = 'popup/musicIntegrity/musicIntegrity.html';
  musicIntegrityModal.classList.remove('is-hidden');
  musicIntegrityModal.setAttribute('aria-hidden', 'false');
}

function closeMusicIntegrityPopup() {
  if (!musicIntegrityModal || !musicIntegrityFrame) {
    return;
  }

  musicIntegrityModal.classList.add('is-hidden');
  musicIntegrityModal.setAttribute('aria-hidden', 'true');
  musicIntegrityFrame.src = 'about:blank';
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
