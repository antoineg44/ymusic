const queueList = document.getElementById("queueList");
const queueStatus = document.getElementById("queueStatus");
const queueEmpty = document.getElementById("queueEmpty");
let queueRenderToken = 0;
let queueRenderAbortController = null;

function beginQueueRender() {
  queueRenderToken += 1;

  if (queueRenderAbortController) {
    queueRenderAbortController.abort();
  }

  queueRenderAbortController = new AbortController();
  return {
    token: queueRenderToken,
    signal: queueRenderAbortController.signal,
  };
}

function isQueueRenderActive(token) {
  return token === queueRenderToken;
}

async function checkMusicInDatabase(track, signal) {
  const id = String((track && track.videoId) || "").trim();
  if (!id) {
    return { found: false, duration: 0, views: 0 };
  }

  const title = String((track && track.title) || "").trim();
  const artist = Array.isArray(track && track.artists)
    ? String(track.artists[0] || "").trim()
    : "";

  const requestParams = new URLSearchParams({
    musicDetails: "1",
    id,
  });

  if (title) {
    requestParams.set("title", title);
  }

  if (artist) {
    requestParams.set("artist", artist);
  }

  try {
    const response = await fetch(
      `../../php/interface.php?${requestParams.toString()}`,
      {
        credentials: "same-origin",
        cache: "no-store",
        signal,
      },
    );

    if (response.status === 401) {
      window.parent.postMessage({type: 'USER_LOGGED_OUT' }, '*');
      return { found: false, duration: 0, views: 0 };
    }

    const payload = await response.json();
    const found = Boolean(
      response.ok && payload.success && payload.found === true,
    );
    const music = payload.music || {};

    return {
      found,
      duration: Number(music.Duree || 0),
      views: Number(music.NombreVue || 0),
    };
  } catch (error) {
    if (error && error.name === "AbortError") {
      return null;
    }

    console.debug("Presence check failed for queue track:", error);
    return { found: false, duration: 0, views: 0 };
  }
}

async function renderQueue(queue, currentIndex) {
  const renderRun = beginQueueRender();

  if (!queueList || !queueEmpty || !queueStatus) {
    return;
  }

  if (!queue || !Array.isArray(queue) || queue.length === 0) {
    if (!isQueueRenderActive(renderRun.token)) {
      return;
    }

    queueList.innerHTML = "";
    queueEmpty.style.display = "block";
    return;
  }

  if (!isQueueRenderActive(renderRun.token)) {
    return;
  }

  queueEmpty.style.display = "none";
  queueList.innerHTML = "";

  // Afficher seulement à partir de la musique actuelle
  const startIndex = Math.max(0, currentIndex >= 0 ? currentIndex : 0);

  for (let index = startIndex; index < queue.length; index += 1) {
    const track = queue[index];
    if (!track) {
      continue;
    }

    if (!isQueueRenderActive(renderRun.token)) {
      return;
    }

    const displayTrack = {
      ...track,
      isPlaying: index === currentIndex,
      displayIndex: index - startIndex + 1,
    };

    // Vérifier si la musique existe en base et récupérer les détails
    const musicInfo = await checkMusicInDatabase(
      displayTrack,
      renderRun.signal,
    );
    if (!isQueueRenderActive(renderRun.token)) {
      return;
    }

    if (musicInfo === null) {
      return;
    }

    displayTrack.isInDatabase = musicInfo.found;

    // Utiliser les durée et vues de la base si disponibles
    if (!displayTrack.duration) {
      displayTrack.duration = musicInfo.duration;
    }
    if (!displayTrack.views) {
      displayTrack.views = musicInfo.views;
    }

    // buttons configurations
    displayTrack.buttons = {
      buttons: {
        play: { type: "QUEUE_PLAY_TRACK" },
        delete: { type: "QUEUE_REMOVE_TRACK" },
      },
      source: "queue",
    };

    const item = renderElement(displayTrack, index);
    if (!isQueueRenderActive(renderRun.token)) {
      return;
    }

    queueList.appendChild(item);
  }

  if (isQueueRenderActive(renderRun.token)) {
    queueRenderAbortController = null;
  }
}

// Écouter les messages du parent
window.addEventListener("message", (event) => {
  const message = event.data;
  if (!message || message.target !== "queue") {
    return;
  }

  if (message.type === "UPDATE_QUEUE") {
    void renderQueue(message.queue, message.currentIndex);
    const count = message.queue ? message.queue.length : 0;
    queueStatus.textContent =
      count > 0 ? `${count} musique${count > 1 ? "s" : ""} en attente` : "";
  }
});
