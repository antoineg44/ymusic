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
        }
      }
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

    function playTrack(track, index) {
      if (!track) {
        return;
      }

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
        meta: track.folder || 'Bibliotheque locale',
        isFavorite: Boolean(state.likedSaved),
      });
      syncFavoriteState();
      syncNextTrackPreview();
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
      const { skipQueueLoad = false, artist = '', albumId = '', views = 0 } = settings;

      if (!isValidVideoId(videoId)) {
        setStatus('Identifiant video invalide pour le telechargement.');
        return;
      }

      setStatus(`Téléchargement de “${title}”…`);

      try {
        if (!skipQueueLoad) {
          await loadPlaylistQueue(videoId);
        }

        const response = await fetch(`php/interface.php?musicId=${encodeURIComponent(videoId)}`);
        const payload = await response.json();

        if (!payload.success) {
          setStatus(payload.error || 'Le téléchargement a échoué.');
          return;
        }

        const downloadPayload = payload.download || {};
        const downloadedFile = downloadPayload.file || (Array.isArray(downloadPayload) ? downloadPayload.find((entry) => typeof entry === 'string' && entry.trim()) : '');
        const downloadedPath = String(downloadPayload.path || '').trim();

        if (!downloadedFile) {
          setStatus(downloadPayload.error || 'Le téléchargement n’a pas produit de fichier audio.');
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
      await musiqueSuivanteController.playNext();
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
        void playNext();
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
