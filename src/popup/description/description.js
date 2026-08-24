const descriptionStatus = document.getElementById("descriptionStatus");
const descriptionList = document.getElementById("descriptionList");
const musicPlaylists = document.getElementById("musicPlaylists");
const editButton = document.getElementById("editButton");
const youtubeButton = document.getElementById("youtubeButton");
const downloadButton = document.getElementById("downloadButton");
let currentMusic = null;

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;");
}

function setStatus(message, isError = false) {
  descriptionStatus.textContent = message;
  descriptionStatus.style.color = isError ? "#fca5a5" : "#7dd3fc";
}

function postToParent(type, payload = {}) {
  window.parent.postMessage({ source: "description", type, ...payload }, "*");
}

function renderMusicDetails(music) {
  const orderedKeys = [
    "Id",
    "Titre",
    "Artiste",
    "Utilisateur",
    "Album",
    "Duree",
    "AnneeParution",
    "Genre",
    "NombreVue",
    "NombreVueInterne",
    "DateAjout",
  ];

  descriptionList.innerHTML = "";

  if (!music) {
    const item = document.createElement("li");
    item.className = "description-item";
    item.innerHTML = `
            <span class="description-key">Information</span>
            <span class="description-value">Aucune information disponible</span>
          `;
    descriptionList.appendChild(item);
    return;
  }

  orderedKeys.forEach((key) => {
    const item = document.createElement("li");
    item.className = "description-item";

    const rawValue = music[key];
    const value =
      rawValue === null ||
      rawValue === undefined ||
      String(rawValue).trim() === ""
        ? "-"
        : String(rawValue);

    item.innerHTML = `
            <span class="description-key">${escapeHtml(key)}</span>
            <span class="description-value">${escapeHtml(value)}</span>
          `;

    descriptionList.appendChild(item);
  });
}

function renderMusicPlaylists(playlists) {
  musicPlaylists.innerHTML = "";

  if (!Array.isArray(playlists) || playlists.length === 0) {
    const item = document.createElement("li");
    item.className = "playlist-item";
    item.innerHTML = `
            <div class="playlist-name">Aucune playlist</div>
            <div class="playlist-meta">Cette musique n'est dans aucune playlist pour le moment.</div>
          `;
    musicPlaylists.appendChild(item);
    return;
  }

  playlists.forEach((playlist) => {
    const playlistName = String(
      playlist && playlist.NomPlaylist
        ? playlist.NomPlaylist
        : "Playlist sans nom",
    );
    const owner = String(
      playlist && playlist.UtilisateurNom ? playlist.UtilisateurNom : "",
    ).trim();
    const position = Number(
      playlist && playlist.PositionLecture ? playlist.PositionLecture : 0,
    );

    const metaParts = [];
    if (owner) {
      metaParts.push(`Par ${owner}`);
    }
    if (position > 0) {
      metaParts.push(`Position ${position}`);
    }

    const item = document.createElement("li");
    item.className = "playlist-item";
    item.innerHTML = `
            <div class="playlist-name">${escapeHtml(playlistName)}</div>
            <div class="playlist-meta">${escapeHtml(metaParts.join(" • ") || "Playlist personnelle")}</div>
          `;

    musicPlaylists.appendChild(item);
  });
}

function sanitizeFileName(value) {
  return String(value || "")
    .trim()
    .replace(/[\\/:*?"<>|]+/g, "-")
    .replace(/\s+/g, " ")
    .trim();
}

function getDownloadExtension(downloadPayload) {
  const payloadPath = String(
    (downloadPayload && downloadPayload.path) || "",
  ).trim();
  const payloadFile = String(
    (downloadPayload && downloadPayload.file) || "",
  ).trim();
  const source = payloadPath || payloadFile;
  const match = source.match(/\.([a-zA-Z0-9]+)$/);
  return match ? `.${match[1]}` : ".mp3";
}

function resolveDownloadPath(downloadPayload) {
  const payloadPath = String(
    (downloadPayload && downloadPayload.path) || "",
  ).trim();
  if (payloadPath) {
    return payloadPath;
  }

  const payloadFile = String(
    (downloadPayload && downloadPayload.file) || "",
  ).trim();
  if (payloadFile) {
    return `../../data/temp/${payloadFile}`;
  }

  return "";
}

async function loadDescription() {
  const params = new URLSearchParams(window.location.search);
  const id = String(params.get("id") || "").trim();
  const title = String(params.get("title") || "").trim();
  const artist = String(params.get("artist") || "").trim();

  if (!id) {
    setStatus("Aucune musique selectionnee.", true);
    return;
  }

  setStatus(`Chargement des details pour ${id}...`);

  try {
    var query = {
      table: 'Musiques',
      select: ['Id', 'Titre', 'Artiste', 'Utilisateur', 'Album', 'Duree', 'AnneeParution', 'Genre', 'NombreVue', 'NombreVueInterne', 'DateAjout'],
      equals: {
        Id: id
      },
      limit: 1,
      page: 1
    };

    let musiques = [];

    try {
      const response = await sendMessageAndWait(window.parent, {action: 'description', query: query});
      musiques = Array.isArray(response.musiques) ? response.musiques : [];

      if (musiques.length === 0) {
        throw new Error("Musique non trouvee en base de donnees.");
      }
    } catch (databaseError) {
      console.warn("Description base de donnees indisponible ou introuvable, fallback vers yt_description.", databaseError);
      setStatus("Musique non trouvee en base. Tentative sur YouTube...");

      const fallbackResponse = await sendMessageAndWait(window.parent, {action: 'yt_description', query: id});
      musiques = Array.isArray(fallbackResponse.musiques) ? fallbackResponse.musiques : [];

      if (musiques.length === 0) {
        throw new Error("Aucune description trouvee dans la base ni sur YouTube.");
      }
    }

    currentMusic = musiques[0] || null;
    renderMusicDetails(currentMusic);

    query = {
      table: 'MyPlaylistMusiques',
      withPlaylistDetails: true,
      select: [
          'PlaylistId',
          'NomPlaylist',
          'Description',
          'Utilisateur',
          'UtilisateurNom',
          'PositionLecture'
      ],
      equals: {
          IdMusique: id
      },
      orderBy: 'NomPlaylist',
      order: 'ASC',
      limit: 1,
      page: 1
    };
    response = await sendMessageAndWait(window.parent, {action: 'description', query: query});
    var playlists = Array.isArray(response.myPlaylistMusiques) ? response.myPlaylistMusiques : [];
    renderMusicPlaylists(playlists);

    setStatus("Details charges.");

  } catch (error) {
    console.error(error);
    setStatus(error.message || "Erreur de chargement.", true);
  }
}

editButton.addEventListener("click", () => {
  const params = new URLSearchParams(window.location.search);
  const id = String(params.get("id") || "").trim();
  if (!id) {
    setStatus("Impossible d'ouvrir l'edition (Id manquant).", true);
    return;
  }

  postToParent("OPEN_EDITIONS", { id });
});

downloadButton.addEventListener("click", async () => {
  const params = new URLSearchParams(window.location.search);
  const id = String(params.get("id") || "").trim();

  if (!id) {
    setStatus("Impossible de telecharger (Id manquant).", true);
    return;
  }

  try {
    setStatus("Preparation du telechargement...");

    const response = await sendMessageAndWait(window.parent, {action: 'yt_description', query: id});

    const sourcePath = resolveDownloadPath(response);
    if (!sourcePath) {
      throw new Error("Le serveur n'a retourne aucun fichier telechargeable.");
    }

    const musicTitle =
      sanitizeFileName((currentMusic && currentMusic.Titre) || id) || id;
    const extension = getDownloadExtension(response);
    const downloadName = `${musicTitle}${extension}`;

    const link = document.createElement("a");
    link.href = sourcePath;
    link.download = downloadName;
    link.rel = "noopener";
    document.body.appendChild(link);
    link.click();
    link.remove();

    setStatus(`Telechargement lance: ${downloadName}`);
  } catch (error) {
    console.error(error);
    setStatus(error.message || "Erreur de telechargement.", true);
  }
});

youtubeButton.addEventListener("click", () => {
  const params = new URLSearchParams(window.location.search);
  const idFromParams = String(params.get("id") || "").trim();
  const id = String((currentMusic && currentMusic.Id) || idFromParams).trim();

  if (!id) {
    setStatus("Impossible d'ouvrir YouTube (Id manquant).", true);
    return;
  }

  const youtubeUrl = `https://www.youtube.com/watch?v=${encodeURIComponent(id)}`;
  const openedWindow = window.open(youtubeUrl, "_blank", "noopener,noreferrer");
  if (!openedWindow) {
    setStatus("Le navigateur a bloque l'ouverture du nouvel onglet.", true);
    return;
  }

  setStatus("Ouverture de YouTube dans un nouvel onglet.");
});

void loadDescription();
