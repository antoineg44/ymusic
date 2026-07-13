(function () {
  // Contrôleur principal du lecteur: communication iframe, lecture locale et téléchargement.
  function createLecteurController(deps) {
    const {
      state,
      playerFrame,
      setStatus,
      isValidVideoId,
      parseViewCount,
      saveLikedMusic,
      loadLibrary,
      onOpenDescription,
    } = deps;

    let preparedNextTrack = null;
    let preparingNextTrackPromise = null;
    let preparedForTrackKey = '';
    let nextTransitionInProgress = false;
    const CROSSFADE_SECONDS_KEY = 'ymusic.crossfadeSeconds';

    function readCrossfadeSecondsSetting() {
      try {
        const rawValue = window.localStorage.getItem(CROSSFADE_SECONDS_KEY);
        const numericValue = Number.parseInt(rawValue === null ? '0' : rawValue, 10);
        if (!Number.isFinite(numericValue)) {
          return 0;
        }

        return Math.min(12, Math.max(0, numericValue));
      } catch (error) {
        console.debug('Crossfade setting read failed:', error);
        return 0;
      }
    }

    async function sleep(milliseconds) {
      const safeDelay = Math.max(0, Number(milliseconds || 0));
      if (safeDelay <= 0) {
        return;
      }

      await new Promise((resolve) => {
        window.setTimeout(resolve, safeDelay);
      });
    }

    function getCurrentTrackPreparationKey() {
      const videoPart = String(state.currentVideoId || '').trim();
      const pathPart = state.currentTrack ? String(state.currentTrack.path || state.currentTrack.file || '').trim() : '';
      return `${videoPart}|${pathPart}`;
    }

    function clearPreparedNextTrack() {
      preparedNextTrack = null;
      preparingNextTrackPromise = null;
      preparedForTrackKey = '';
    }

    function sendPlayerMessage(type, payload) {
      // Envoie une commande au lecteur embarqué dans l'iframe lecteur.html.
      if (!playerFrame || !playerFrame.contentWindow) {
        return;
      }

      playerFrame.contentWindow.postMessage({ target: 'lecteur', type, ...(payload || {}) }, '*');
    }

    function resolveNextTrackTitle() {
      if (isValidVideoId(state.currentVideoId) && Array.isArray(state.queue) && state.queue.length) {
        let currentQueueIndex = state.queue.findIndex((entry) => entry && entry.videoId === state.currentVideoId);
        if (currentQueueIndex < 0) {
          currentQueueIndex = state.queueIndex;
        }

        if (currentQueueIndex >= 0 && currentQueueIndex < state.queue.length - 1) {
          for (let index = currentQueueIndex + 1; index < state.queue.length; index += 1) {
            const next = state.queue[index];
            if (next && isValidVideoId(next.videoId)) {
              return String(next.title || next.videoId || '').trim();
            }
          }
        }

        return '';
      }

      if (Array.isArray(state.library) && state.library.length) {
        const baseIndex = state.currentIndex >= 0 ? state.currentIndex : 0;
        const nextIndex = (baseIndex + 1) % state.library.length;
        const nextTrack = state.library[nextIndex];
        return nextTrack ? String(nextTrack.title || nextTrack.file || '').trim() : '';
      }

      return '';
    }

    function syncNextTrackPreview() {
      sendPlayerMessage('SET_NEXT_TRACK', {
        nextTitle: resolveNextTrackTitle(),
      });
    }

    function syncFavoriteState() {
      sendPlayerMessage('SET_FAVORITE_STATE', {
        isFavorite: Boolean(state.likedSaved),
      });
    }

    function updateTimeDisplay() {
      // Déclenche l'enregistrement en base quand 75% du morceau est écouté.
      if (!state.likedLogged && Number.isFinite(state.currentDuration) && state.currentDuration > 0) {
        const ratio = state.currentPlayedSeconds / state.currentDuration;
        if (ratio >= 0.75) {
          state.likedLogged = true;

          if (!state.likedSaved) {
            state.likedSaved = true;
            void saveLikedMusic(state.currentTrack);
            syncFavoriteState();
          }

          void prepareNextTrackForSeamlessPlayback();
        }
      }
    }

    function resolveNextQueueEntry() {
      if (!isValidVideoId(state.currentVideoId) || !Array.isArray(state.queue) || state.queue.length === 0) {
        return null;
      }

      let currentQueueIndex = state.queue.findIndex((entry) => entry && entry.videoId === state.currentVideoId);
      if (currentQueueIndex < 0) {
        currentQueueIndex = state.queueIndex;
      }

      if (currentQueueIndex < 0 || currentQueueIndex >= state.queue.length - 1) {
        return null;
      }

      for (let index = currentQueueIndex + 1; index < state.queue.length; index += 1) {
        const entry = state.queue[index];
        if (!entry || !isValidVideoId(entry.videoId)) {
          continue;
        }

        return { entry, index };
      }

      return null;
    }

    async function prepareNextTrackForSeamlessPlayback() {
      if (!isValidVideoId(state.currentVideoId)) {
        return;
      }

      const currentKey = getCurrentTrackPreparationKey();
      if (!currentKey) {
        return;
      }

      if (preparedForTrackKey === currentKey || preparingNextTrackPromise) {
        return;
      }

      preparingNextTrackPromise = (async () => {
        try {
          if (!state.queue.length || state.queueIndex < 0) {
            await loadPlaylistQueue(state.currentVideoId);
          }

          const nextQueue = resolveNextQueueEntry();
          if (!nextQueue) {
            preparedForTrackKey = currentKey;
            return;
          }

          const next = nextQueue.entry;
          const response = await fetch(`php/interface.php?musicId=${encodeURIComponent(next.videoId)}`);
          const payload = await response.json();

          if (!payload.success) {
            return;
          }

          const downloadPayload = payload.download || {};
          const downloadedFile = downloadPayload.file || (Array.isArray(downloadPayload) ? downloadPayload.find((entry) => typeof entry === 'string' && entry.trim()) : '');
          const downloadedPath = String(downloadPayload.path || '').trim();

          if (!downloadedFile) {
            return;
          }

          const preparedTrackDraft = {
            title: String(next.title || 'titre').trim(),
            artist: Array.isArray(next.artists) ? next.artists.join(', ') : '',
            albumId: String((next.album && next.album.id) || '').trim(),
            views: parseViewCount(next.views || 0),
            path: downloadedPath || `data/temp/${downloadedFile}`,
            file: downloadedFile,
            folder: 'temp',
            videoId: String(next.videoId || '').trim(),
          };

          if (downloadedPath) {
            const pathParts = downloadedPath.split('/');
            const folder = pathParts.length > 1 ? pathParts[pathParts.length - 2] : 'Bibliotheque';
            preparedTrackDraft.folder = folder;
          }

          await waitForMediaReady(preparedTrackDraft.path);
          await loadLibrary(preparedTrackDraft.path);

          const preparedTrack = state.library.find((entry) => entry.path === preparedTrackDraft.path || entry.file === preparedTrackDraft.file) || preparedTrackDraft;
          preparedTrack.title = preparedTrackDraft.title || preparedTrack.title;
          preparedTrack.artist = preparedTrackDraft.artist || preparedTrack.artist || '';
          preparedTrack.albumId = preparedTrackDraft.albumId || preparedTrack.albumId || '';
          if (preparedTrackDraft.views > 0) {
            preparedTrack.views = preparedTrackDraft.views;
          }
          preparedTrack.videoId = preparedTrackDraft.videoId || preparedTrack.videoId || '';

          if (getCurrentTrackPreparationKey() !== currentKey) {
            return;
          }

          preparedNextTrack = {
            videoId: preparedTrack.videoId,
            queueIndex: nextQueue.index,
            track: preparedTrack,
            forKey: currentKey,
          };
          preparedForTrackKey = currentKey;
        } catch (error) {
          console.debug('Next track preloading failed:', error);
        } finally {
          preparingNextTrackPromise = null;
        }
      })();

      await preparingNextTrackPromise;
    }

    async function waitForMediaReady(path, retries = 8, delayMs = 250) {
      // Attend que le fichier téléchargé soit effectivement accessible via HTTP.
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

    function playTrack(track, index, options) {
      if (!track) {
        return;
      }

      const settings = options || {};
      const fadeInSeconds = Math.max(0, Number(settings.fadeInSeconds || 0));

      clearPreparedNextTrack();

      const resolvedIndex = index >= 0 ? index : state.library.findIndex((candidate) => candidate.path === track.path);
      state.currentTrack = track;
      state.currentIndex = resolvedIndex;
      state.currentVideoId = track.videoId || '';
      state.currentDuration = 0;
      state.currentPlayedSeconds = 0;
      state.likedLogged = false;
      state.likedSaved = false;

      const cacheBust = track.folder === 'temp' ? `?v=${Date.now()}` : '';
      const source = `${encodeURI(track.path)}${cacheBust}`;
      sendPlayerMessage('LOAD_TRACK', {
        src: source,
        title: track.title,
        meta: (track.folder === 'temp' && track.artist) ? track.artist : (track.folder || 'Bibliotheque locale'),
        isFavorite: Boolean(state.likedSaved),
        fadeInSeconds,
      });
      syncFavoriteState();
      syncNextTrackPreview();
      
      // Notifier le parent pour mettre à jour l'affichage de la queue
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ source: 'lecteur', type: 'TRACK_CHANGED' }, '*');
      }
    }

    const musiqueSuivanteController = window.createMusiqueSuivanteController({
      state,
      isValidVideoId,
      setStatus,
      downloadAndPlay,
      playTrack,
    });

    function resetPlaylistQueue() {
      musiqueSuivanteController.resetPlaylistQueue();
      syncNextTrackPreview();
    }

    async function loadPlaylistQueue(videoId) {
      await musiqueSuivanteController.loadPlaylistQueue(videoId);
      syncNextTrackPreview();
    }

    async function downloadAndPlay(videoId, title, options) {
      const settings = options || {};
      const { skipQueueLoad = false, artist = '', albumId = '', views = 0, fadeInSeconds = 0 } = settings;

      if (!isValidVideoId(videoId)) {
        setStatus('Identifiant video invalide pour le telechargement.');
        return;
      }

      setStatus(`Téléchargement de "${title}"…`);
      sendPlayerMessage('SHOW_LOADING', {});

      try {
        if (!skipQueueLoad) {
          await loadPlaylistQueue(videoId);
        }

        const response = await fetch(`php/interface.php?musicId=${encodeURIComponent(videoId)}`);
        const payload = await response.json();

        if (!payload.success) {
          setStatus(payload.error || 'Le téléchargement a échoué.');
          sendPlayerMessage('HIDE_LOADING', {});
          return;
        }

        const downloadPayload = payload.download || {};
        const downloadedFile = downloadPayload.file || (Array.isArray(downloadPayload) ? downloadPayload.find((entry) => typeof entry === 'string' && entry.trim()) : '');
        const downloadedPath = String(downloadPayload.path || '').trim();

        if (!downloadedFile) {
          setStatus(downloadPayload.error || 'Le téléchargement n\'a pas produit de fichier audio.');
          sendPlayerMessage('HIDE_LOADING', {});
          return;
        }

        const track = {
          title,
          artist: String(artist || '').trim(),
          albumId: String(albumId || '').trim(),
          views: parseViewCount(views),
          path: downloadedPath || `data/temp/${downloadedFile}`,
          file: downloadedFile,
          folder: 'temp',
          videoId,
        };

        if (downloadedPath) {
          const pathParts = downloadedPath.split('/');
          const folder = pathParts.length > 1 ? pathParts[pathParts.length - 2] : 'Bibliotheque';
          track.folder = folder;
        }

        await waitForMediaReady(track.path);
        await loadLibrary(track.path);

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
        playTrack(downloadedTrack, state.library.findIndex((entry) => entry.path === downloadedTrack.path), {
          fadeInSeconds,
        });
        setStatus(`Lecture de “${title}” depuis le téléchargement.`);
      } catch (error) {
        console.error(error);
        setStatus('Le téléchargement et la lecture ont échoué.');
        sendPlayerMessage('HIDE_LOADING', {});
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

    async function deleteTempFile(filePath) {
      // Supprime un fichier temporaire au serveur.
      if (!filePath) {
        return;
      }

      try {
        const params = new URLSearchParams({ deleteFile: filePath });
        const response = await fetch(`php/interface.php?${params.toString()}`, {
          credentials: 'same-origin',
        });
        const payload = await response.json();
        
        if (payload.success) {
          console.debug('Fichier temporaire supprimé:', filePath);
        } else {
          console.debug('Erreur lors de la suppression du fichier:', payload.error);
        }
      } catch (error) {
        console.debug('Erreur lors de la suppression du fichier:', error);
      }
    }

    async function playNext(options) {
      const settings = options || {};
      const { autoChained = false, fadeSecondsOverride = null } = settings;

      if (nextTransitionInProgress) {
        return;
      }
      nextTransitionInProgress = true;

      const configuredFade = autoChained ? readCrossfadeSecondsSetting() : 0;
      const fadeSeconds = autoChained
        ? Math.max(0, Number.isFinite(Number(fadeSecondsOverride)) ? Number(fadeSecondsOverride) : configuredFade)
        : 0;

      // Avant de passer à la suivante, supprimer le fichier temp si la musique était aimée
      try {
        if (state.currentTrack && state.likedSaved && state.currentTrack.folder === 'temp' && state.currentTrack.path) {
          await deleteTempFile(state.currentTrack.path);
        }

        if (fadeSeconds > 0) {
          sendPlayerMessage('FADE_OUT', { durationSeconds: fadeSeconds });
          await sleep(Math.max(120, Math.floor(fadeSeconds * 1000)));
        }

        const nextQueue = resolveNextQueueEntry();
        if (
          preparedNextTrack
          && nextQueue
          && preparedNextTrack.forKey === getCurrentTrackPreparationKey()
          && preparedNextTrack.videoId === String(nextQueue.entry.videoId || '').trim()
        ) {
          state.queueIndex = nextQueue.index;
          state.currentVideoId = preparedNextTrack.videoId;
          const preparedTrack = preparedNextTrack.track;
          clearPreparedNextTrack();
          playTrack(preparedTrack, state.library.findIndex((entry) => entry.path === preparedTrack.path), {
            fadeInSeconds: fadeSeconds,
          });
          return;
        }

        await musiqueSuivanteController.playNext({
          fadeInSeconds: fadeSeconds,
        });
      } finally {
        nextTransitionInProgress = false;
      }
    }

    function togglePlayback() {
      sendPlayerMessage('TOGGLE');
    }

    async function toggleFavorite() {
      if (!state.currentTrack || state.likedSaved) {
        syncFavoriteState();
        return;
      }

      state.likedSaved = true;
      state.likedLogged = true;
      syncFavoriteState();
      await saveLikedMusic(state.currentTrack);
    }

    function handleMessage(message) {
      // Traite tous les événements remontés par l'UI du lecteur (suivant, précédent, erreurs...).
      if (!message || message.source !== 'lecteur') {
        return false;
      }

      if (message.type === 'PLAYER_READY') {
        state.playerReady = true;
        syncNextTrackPreview();
        return true;
      }

      if (message.type === 'REQUEST_PREV') {
        void playPrevious();
        return true;
      }

      if (message.type === 'REQUEST_NEXT') {
        void playNext({ autoChained: false });
        return true;
      }

      if (message.type === 'REQUEST_NEXT_AUTO') {
        void playNext({
          autoChained: true,
          fadeSecondsOverride: Number(message.fadeSeconds || 0),
        });
        return true;
      }

      if (message.type === 'REQUEST_PLAY_FALLBACK') {
        const fallbackTrack = state.library[0];
        if (fallbackTrack) {
          playTrack(fallbackTrack, 0);
        }
        return true;
      }

      if (message.type === 'TIME_UPDATE') {
        state.currentDuration = Number(message.duration || 0);
        state.currentPlayedSeconds = Number(message.playedSeconds || 0);
        updateTimeDisplay();
        return true;
      }

      if (message.type === 'TOGGLE_FAVORITE') {
        void toggleFavorite();
        return true;
      }

      if (message.type === 'PLAYER_ERROR' && message.error) {
        setStatus(String(message.error));
        return true;
      }

      if (message.type === 'OPEN_DESCRIPTION') {
        if (typeof onOpenDescription === 'function') {
          onOpenDescription();
        }
        return true;
      }

      return false;
    }

    return {
      handleMessage,
      playTrack,
      downloadAndPlay,
      resetPlaylistQueue,
      loadPlaylistQueue,
      playPrevious,
      playNext,
      togglePlayback,
      toggleFavorite,
      updateTimeDisplay,
    };
  }

  window.createLecteurController = createLecteurController;
})();
