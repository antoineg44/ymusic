(function () {
  // Orchestration des résultats de recherche et routage vers le lecteur central.
  function createRechercheController(deps) {
    const {
      state,
      setStatus,
      parseViewCount,
      normalize,
      playerController,
      searchFrame,
    } = deps;

    function handleMessage(message) {
      // Point d'entrée unique des messages provenant de l'iframe recherche.
      if (message.type === 'SEARCH_READY') {
        state.searchReady = true;
        return;
      }

      if (message.type === 'SEARCH_RESIZE') {
        const requestedHeight = Number(message.height || 0);
        if (Number.isFinite(requestedHeight) && requestedHeight > 0 && searchFrame) {
          const clampedHeight = Math.max(460, Math.ceil(requestedHeight));
          searchFrame.style.height = `${clampedHeight}px`;
        }
        return;
      }

      if (message.type === 'SEARCH_PLAY_RESULT' && message.result) {
        void handleSearchPlayResult(message.result);
      }
    }

    async function handleSearchPlayResult(result) {
      // Priorité: lecture locale si disponible, sinon téléchargement à la demande.
      const match = findLocalMatch(result);
      const artists = Array.isArray(result.artists) ? result.artists.join(', ') : '';
      const albumId = String((result.album && result.album.id) || '').trim();
      const views = parseViewCount(result.views);

      if (match) {
        if (result.videoId) {
          await playerController.loadPlaylistQueue(result.videoId);
        } else {
          playerController.resetPlaylistQueue();
        }

        const playableMatch = {
          ...match,
          artist: artists || match.artist || '',
          albumId: albumId || match.albumId || '',
          views: views || match.views || 0,
          videoId: result.videoId || match.videoId || '',
        };
        playerController.playTrack(playableMatch, state.library.findIndex((track) => track.path === match.path));
        return;
      }

      if (result.videoId) {
        await playerController.downloadAndPlay(result.videoId, result.title || 'titre', {
          artist: artists,
          albumId,
          views,
        });
        return;
      }

      playerController.resetPlaylistQueue();
      setStatus('Aucun fichier local correspondant n\'a ete trouve.');
    }

    function findLocalMatch(result) {
      const target = normalize(`${result.title || ''} ${Array.isArray(result.artists) ? result.artists.join(' ') : ''}`);

      return state.library.find((track) => normalize(track.title).includes(target) || normalize(track.file).includes(target));
    }

    return {
      handleMessage,
    };
  }

  window.createRechercheController = createRechercheController;
})();
