function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;');
}

const SINGLE_CLICK_DELAY_MS = 220;
let pendingDisplayPlayTimer = null;

function buildDescriptionSongPayload(el) {
  const playConfig = el && el.buttons && el.buttons.buttons && el.buttons.buttons.play
    ? el.buttons.buttons.play
    : null;
  const playResult = playConfig && playConfig.result ? playConfig.result : null;
  const playPayloadSong = playConfig && playConfig.payload && playConfig.payload.song
    ? playConfig.payload.song
    : null;
  const artists = Array.isArray(el && el.artists) ? el.artists : [];
  const firstArtist = artists.length > 0 ? String(artists[0] || '').trim() : '';

  return {
    Id: String((el && (
      el.Id
      || el.videoId
      || (el.result && (el.result.Id || el.result.videoId))
      || (playResult && (playResult.Id || playResult.videoId))
      || (playPayloadSong && (playPayloadSong.Id || playPayloadSong.videoId))
    )) || '').trim(),
    Titre: String((el && (
      el.Titre
      || el.title
      || (el.result && (el.result.Titre || el.result.title))
      || (playResult && (playResult.Titre || playResult.title))
      || (playPayloadSong && (playPayloadSong.Titre || playPayloadSong.title))
    )) || '').trim(),
    Artiste: String((el && (
      el.Artiste
      || el.artist
      || (el.result && (el.result.Artiste || el.result.artist))
      || (playResult && (playResult.Artiste || playResult.artist))
      || (playPayloadSong && (playPayloadSong.Artiste || playPayloadSong.artist))
      || firstArtist
    )) || '').trim(),
  };
}

function postPlayMessage(infos, index) {
  const message = {
    source: infos.source,
    type: infos.buttons.play.type,
    index,
  };

  if (Object.prototype.hasOwnProperty.call(infos.buttons.play, 'result')) {
    message.result = infos.buttons.play.result;
  }

  if (Object.prototype.hasOwnProperty.call(infos.buttons.play, 'payload')) {
    message.payload = infos.buttons.play.payload;
  }

  window.parent.postMessage(message, '*');
}

function getDisplayHTMLButtons(item, el, infos, index) {
  const infoClassName = infos.buttons.play && infos.buttons.play.className
    ? String(infos.buttons.play.className)
    : 'info-btn';
  const deleteClassName = infos.buttons.delete && infos.buttons.delete.className
    ? String(infos.buttons.delete.className)
    : 'delete-btn';

  item.innerHTML += `<div class="actions">`;
  if (infos.buttons.play) {
    item.innerHTML += `<button class="${infoClassName}" type="button" data-action="info">ℹ️</button>`;
  }
  if(infos.buttons.delete) {
    item.innerHTML += `<button class="${deleteClassName}" type="button" data-action="delete">✕</button>`;
  }
  item.innerHTML += `</div>`;

  const buttons = item.querySelectorAll('button');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const action = button.dataset.action;
      if (action === 'info') {
        const song = buildDescriptionSongPayload(el);
        if (song.Id) {
          window.parent.postMessage({
            source: infos && infos.source ? infos.source : 'display',
            type: 'DISPLAY_OPEN_DESCRIPTION',
            song,
          }, '*');
        }
      } else if (action === 'delete') {
        const message = {
          source: infos.source,
          type: infos.buttons.delete.type,
          index,
        };

        if (Object.prototype.hasOwnProperty.call(infos.buttons.delete, 'payload')) {
          message.payload = infos.buttons.delete.payload;
        }

        window.parent.postMessage(message, '*');
      }
    });
  });
}



function renderElement(el, index) {

  const title = String(el.title || el.videoId || 'Musique inconnue').trim();
  const artists = Array.isArray(el.artists)
    ? el.artists.join(', ')
    : String(el.artist || '').trim();

  const duration = (el.duration || 0);
  const views = (el.views || 0);
  const metadata = `${duration} • ${views} vues`;

  const showIndex = el.showIndex !== false;
  const displayIndex = Number.isFinite(el.displayIndex)
    ? Math.max(1, Math.floor(el.displayIndex))
    : (index + 1);

  // Recalculer l'index d'affichage à partir de la musique actuelle
  const indexClass = el.isPlaying ? 'queue-item-index playing' : 'queue-item-index';
  const indexContent = el.isPlaying
    ? '<div class="playing-icon"><span></span><span></span><span></span></div>'
    : String(displayIndex);

  const item = document.createElement('li');
  if (showIndex) {
    item.innerHTML = `
      <div class="${indexClass}">${indexContent}</div>
      <div class="track-info">
        <strong>${escapeHtml(title)}</strong>
        <small>${escapeHtml(artists || 'Artiste inconnu')}</small>
        <small style="font-size: 0.8em; opacity: 0.7;">${escapeHtml(metadata)}</small>
      </div>
    `;
  } else {
    item.innerHTML = `
      <div class="track-info">
        <strong>${escapeHtml(title)}</strong>
        <small>${escapeHtml(artists || 'Artiste inconnu')}</small>
        <small style="font-size: 0.8em; opacity: 0.7;">${escapeHtml(metadata)}</small>
      </div>
    `;
  }

  // Vérifier si la musique existe en base
  if(el.isInDatabase == true)
    item.classList.add('queue-item-present');
  else if(el.isInDatabase == false)
    item.classList.add('queue-item-missing');

  getDisplayHTMLButtons(item, el, el.buttons, index);

  item.addEventListener('click', (event) => {
    const target = event.target;
    if (target instanceof HTMLElement && target.closest('button')) {
      return;
    }

    if (!el.buttons || !el.buttons.buttons || !el.buttons.buttons.play) {
      return;
    }

    if (pendingDisplayPlayTimer !== null) {
      clearTimeout(pendingDisplayPlayTimer);
    }

    pendingDisplayPlayTimer = window.setTimeout(() => {
      pendingDisplayPlayTimer = null;
      postPlayMessage(el.buttons, index);
    }, SINGLE_CLICK_DELAY_MS);
  });

  item.addEventListener('dblclick', (event) => {
    const target = event.target;
    if (target instanceof HTMLElement && target.closest('button')) {
      return;
    }

    if (pendingDisplayPlayTimer !== null) {
      clearTimeout(pendingDisplayPlayTimer);
      pendingDisplayPlayTimer = null;
    }

    const song = buildDescriptionSongPayload(el);
    if (!song.Id) {
      return;
    }

    window.parent.postMessage({
      source: el.buttons && el.buttons.source ? el.buttons.source : 'display',
      type: 'DISPLAY_OPEN_DESCRIPTION',
      song,
    }, '*');
  });

  return item;
}