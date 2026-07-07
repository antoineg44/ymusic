(function () {
  // Gestionnaire d'affichage de la file d'attente
  function createDisplayController(deps) {
    const {
      queueList,
      queueEmpty,
    } = deps;

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;');
    }

    async function checkMusicInDatabase(track) {
      const id = String((track && track.videoId) || '').trim();
      if (!id) {
        return false;
      }

      const title = String((track && track.title) || '').trim();
      const artist = Array.isArray(track && track.artists)
        ? String(track.artists[0] || '').trim()
        : '';

      const requestParams = new URLSearchParams({
        musicDetails: '1',
        id,
      });

      if (title) {
        requestParams.set('title', title);
      }

      if (artist) {
        requestParams.set('artist', artist);
      }

      try {
        const response = await fetch(`php/interface.php?${requestParams.toString()}`, {
          credentials: 'same-origin',
          cache: 'no-store',
        });

        if (response.status === 401) {
          window.location.replace('login.html');
          return false;
        }

        const payload = await response.json();
        return Boolean(response.ok && payload.success && payload.found === true);
      } catch (error) {
        console.debug('Presence check failed for queue track:', error);
        return false;
      }
    }

    async function renderQueue(queue, currentIndex) {
      if (!queue || !Array.isArray(queue) || queue.length === 0) {
        queueList.innerHTML = '';
        queueEmpty.style.display = 'block';
        return;
      }

      queueEmpty.style.display = 'none';
      queueList.innerHTML = '';

      // Afficher seulement à partir de la musique actuelle
      const startIndex = Math.max(0, currentIndex >= 0 ? currentIndex : 0);

      for (let index = startIndex; index < queue.length; index += 1) {
        const track = queue[index];
        if (!track) {
          continue;
        }

        const title = String(track.title || track.videoId || 'Musique inconnue').trim();
        const artists = Array.isArray(track.artists)
          ? track.artists.join(', ')
          : String(track.artist || '').trim();

        // Recalculer l'index d'affichage à partir de la musique actuelle
        const displayIndex = index - startIndex;
        const isPlaying = (index === currentIndex);
        const indexClass = isPlaying ? 'queue-item-index playing' : 'queue-item-index';
        const indexContent = isPlaying
          ? '<div class="playing-icon"><span></span><span></span><span></span></div>'
          : String(displayIndex + 1);

        const item = document.createElement('li');
        item.innerHTML = `
          <div class="${indexClass}">${indexContent}</div>
          <div class="track-info">
            <strong>${escapeHtml(title)}</strong>
            <small>${escapeHtml(artists || 'Artiste inconnu')}</small>
          </div>
          <div class="actions">
            <button class="play-btn" type="button" data-action="play">▶</button>
            <button class="delete-btn" type="button" data-action="delete">✕</button>
          </div>
        `;

        // Vérifier si la musique existe en base
        const isInDatabase = await checkMusicInDatabase(track);
        if (isInDatabase) {
          item.classList.add('queue-item-present');
        } else {
          item.classList.add('queue-item-missing');
        }

        const buttons = item.querySelectorAll('button');
        buttons.forEach((button) => {
          button.addEventListener('click', () => {
            const action = button.dataset.action;
            if (action === 'play') {
              window.parent.postMessage(
                { source: 'queue', type: 'QUEUE_PLAY_TRACK', index },
                '*'
              );
            } else if (action === 'delete') {
              window.parent.postMessage(
                { source: 'queue', type: 'QUEUE_REMOVE_TRACK', index },
                '*'
              );
            }
          });
        });

        queueList.appendChild(item);
      }
    }

    return {
      renderQueue,
    };
  }

  window.createQueueController = createQueueController;
})();
