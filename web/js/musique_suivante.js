(function () {
  // Gère la file YouTube Music pour avancer à la piste suivante.
  function createMusiqueSuivanteController(deps) {
    const {
      state,
      isValidVideoId,
      setStatus,
      downloadAndPlay,
      playTrack,
    } = deps;

    function resetPlaylistQueue() {
      state.queue = [];
      state.queueIndex = -1;
      state.currentVideoId = '';
    }

    async function loadPlaylistQueue(videoId) {
      // Charge et normalise la playlist liée à la vidéo courante.
      if (!isValidVideoId(videoId)) {
        resetPlaylistQueue();
        return;
      }

      try {
        const response = await fetch(`php/interface.php?videoId=${encodeURIComponent(videoId)}`);
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
      } catch (error) {
        console.error(error);
        resetPlaylistQueue();
      }
    }

    async function playNext() {
      // Lit la prochaine entrée de la queue, avec fallback sur la bibliothèque locale.
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

    return {
      resetPlaylistQueue,
      loadPlaylistQueue,
      playNext,
    };
  }

  window.createMusiqueSuivanteController = createMusiqueSuivanteController;
})();
