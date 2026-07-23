function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;');
}

function getDisplayHTMLButtons(item, infos, index) {
  const playClassName = infos.buttons.play && infos.buttons.play.className
    ? String(infos.buttons.play.className)
    : 'play-btn';
  const deleteClassName = infos.buttons.delete && infos.buttons.delete.className
    ? String(infos.buttons.delete.className)
    : 'delete-btn';

  item.innerHTML += `<div class="actions">`;
  if (infos.buttons.play) {
    item.innerHTML += `<button class="${playClassName}" type="button" data-action="play">▶</button>`;
  }
  if(infos.buttons.delete) {
    item.innerHTML += `<button class="${deleteClassName}" type="button" data-action="delete">✕</button>`;
  }
  item.innerHTML += `</div>`;

  const buttons = item.querySelectorAll('button');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const action = button.dataset.action;
      if (action === 'play') {
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

  getDisplayHTMLButtons(item, el.buttons, index);

  return item;
}