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
    if (!isQueueRenderActive(renderRun.token)) {
      return;
    }

    displayTrack.isInDatabase = track.inDatabase;

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
