import { Component } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent } from '@ionic/angular/standalone';
import { AfterViewInit } from '@angular/core';
import { DataService } from './data.service';
interface AppMessage {
  type: string;
  payload?: unknown;
}

// Interfaces pour typer l'événement et ses propriétés
interface EventData {
  messageId?: string;
  message?: any;
  [key: string]: any;
}

interface EventSource {
  postMessage: (message: any, targetOrigin: string) => void;
  location?: { href: string }; // Ajout pour get_event_file
}

interface Event {
  data?: EventData;
  source: EventSource;
}

// Fonction pour extraire le fichier depuis l'URL
function get_event_file(event: Event): string {
  const locationHref = event.source?.location?.href ?? '';
  const parts = locationHref.split('/');
  const filename = parts[parts.length - 1] ?? '';
  return filename.substring(0, 4);
}

// Fonction pour répondre à la source
function replyToSource(source: EventSource, messageId: string | undefined, response: any): void {
  if (!messageId) return;
  source.postMessage(
    {
      response,
      replyTo: messageId,
    },
    '*'
  );
}

function get_url_from_base() {
  return "https://musiques.partitions.ovh/";
}

// Base de donnees locale (IndexedDB) miroir des tables utilisateur pour un usage hors-ligne.
const LOCAL_DB_NAME = 'ymusic_local';
const LOCAL_DB_VERSION = 4;
const LOCAL_DB_STORES = ['Utilisateurs', 'Musiques', 'MusiquesAimees', 'Playlist', 'MyPlaylistMusiques', 'PlaylistsAimees', 'DernieresMusiquesLues'];
const LOCAL_AUDIO_STORE = 'AudioFiles';

let localSyncInProgress = false;
let localSyncCompleted = false;

function openLocalDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(LOCAL_DB_NAME, LOCAL_DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      for (const storeName of LOCAL_DB_STORES) {
        if (!db.objectStoreNames.contains(storeName)) {
          db.createObjectStore(storeName, { autoIncrement: true });
        }
      }
      if (!db.objectStoreNames.contains(LOCAL_AUDIO_STORE)) {
        db.createObjectStore(LOCAL_AUDIO_STORE, { keyPath: 'Id' });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function replaceStoreRows(db: IDBDatabase, storeName: string, rows: any[]): Promise<void> {
  return new Promise((resolve, reject) => {
    if (!db.objectStoreNames.contains(storeName)) {
      resolve();
      return;
    }

    const tx = db.transaction(storeName, 'readwrite');
    const store = tx.objectStore(storeName);
    store.clear();
    for (const row of rows) {
      store.add(row);
    }

    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

async function writeUserTablesToLocalDb(tables: Record<string, any[]>): Promise<void> {
  const db = await openLocalDatabase();
  try {
    for (const storeName of LOCAL_DB_STORES) {
      const rows = Array.isArray(tables[storeName]) ? tables[storeName] : [];
      await replaceStoreRows(db, storeName, rows);
    }

    await downloadMissingAudioFiles(db, Array.isArray(tables['Musiques']) ? tables['Musiques'] : []);
  } finally {
    db.close();
  }
}

function hasLocalAudioFile(db: IDBDatabase, id: string): Promise<boolean> {
  return new Promise((resolve) => {
    try {
      const tx = db.transaction(LOCAL_AUDIO_STORE, 'readonly');
      const request = tx.objectStore(LOCAL_AUDIO_STORE).get(id);
      request.onsuccess = () => resolve(request.result != null);
      request.onerror = () => resolve(false);
    } catch {
      resolve(false);
    }
  });
}

function putLocalAudioFile(db: IDBDatabase, record: any): Promise<void> {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(LOCAL_AUDIO_STORE, 'readwrite');
    tx.objectStore(LOCAL_AUDIO_STORE).put(record);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

// Telecharge et stocke localement les fichiers audio des musiques absentes du store local.
async function downloadMissingAudioFiles(db: IDBDatabase, musiques: any[]): Promise<void> {
  for (const music of musiques) {
    const id = String((music && music.Id) || '').trim();
    if (!id) {
      continue;
    }

    if (await hasLocalAudioFile(db, id)) {
      continue;
    }

    try {
      const response = await fetch(
        get_url_from_base() + 'php/database/interface.php?downloadAudio=' + encodeURIComponent(id),
        { cache: 'no-store', credentials: 'include' }
      );

      if (!response.ok) {
        continue;
      }

      const blob = await response.blob();
      await putLocalAudioFile(db, {
        Id: id,
        blob,
        mimeType: blob.type,
        savedAt: new Date().toISOString(),
      });
    } catch (error) {
      console.debug('Telechargement audio echoue pour', id, error);
    }
  }
}

function isOffline(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false;
}

// Detecte les erreurs reseau (serveur injoignable) pour basculer sur la base locale.
function isNetworkError(error: any): boolean {
  if (error instanceof TypeError) {
    return true;
  }
  const message = String((error && error.message) || error || '').toLowerCase();
  return message.includes('failed to fetch')
    || message.includes('networkerror')
    || message.includes('network request failed')
    || message.includes('load failed');
}

function readAllFromStore(db: IDBDatabase, storeName: string): Promise<any[]> {
  return new Promise((resolve) => {
    try {
      if (!db.objectStoreNames.contains(storeName)) {
        resolve([]);
        return;
      }
      const tx = db.transaction(storeName, 'readonly');
      const request = tx.objectStore(storeName).getAll();
      request.onsuccess = () => resolve(Array.isArray(request.result) ? request.result : []);
      request.onerror = () => resolve([]);
    } catch {
      resolve([]);
    }
  });
}

function getByKeyFromStore(db: IDBDatabase, storeName: string, key: any): Promise<any> {
  return new Promise((resolve) => {
    try {
      if (!db.objectStoreNames.contains(storeName)) {
        resolve(null);
        return;
      }
      const tx = db.transaction(storeName, 'readonly');
      const request = tx.objectStore(storeName).get(key);
      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
}

function normalizeSearchText(value: any): string {
  return String(value ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]/g, '');
}

// Reproduit localement (sur le store Musiques) le comportement de dMusique_get (filtres, tri, group, pagination).
function queryLocalMusiques(rows: any[], query: any): any {
  const q = query || {};
  let result = rows.slice();

  if (q.equals && typeof q.equals === 'object') {
    for (const [field, value] of Object.entries(q.equals)) {
      result = result.filter((row) => String(row[field] ?? '') === String(value));
    }
  }

  if (q.search && q.search.field) {
    const needle = normalizeSearchText(q.search.value);
    result = needle
      ? result.filter((row) => normalizeSearchText(row[q.search.field]).includes(needle))
      : [];
  }

  if (q.groupBy) {
    const groups = new Map<string, any>();
    for (const row of result) {
      const key = String(row[q.groupBy] ?? '');
      if (!groups.has(key)) {
        groups.set(key, { ...row, TotalMusiques: 0 });
      }
      groups.get(key).TotalMusiques += 1;
    }
    result = Array.from(groups.values());
  }

  if (q.orderBy) {
    const direction = String(q.order || 'ASC').toUpperCase() === 'DESC' ? -1 : 1;
    result.sort((a, b) => {
      const av = a[q.orderBy];
      const bv = b[q.orderBy];
      if (av == null && bv == null) return 0;
      if (av == null) return -direction;
      if (bv == null) return direction;
      if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * direction;
      return String(av).localeCompare(String(bv), 'fr', { sensitivity: 'base' }) * direction;
    });
  }

  const totalRows = result.length;
  const perPage = Number(q.limit) > 0 ? Number(q.limit) : 50;
  const page = Math.max(1, Number(q.page) || 1);
  const totalPages = totalRows > 0 ? Math.ceil(totalRows / perPage) : 1;
  const start = (page - 1) * perPage;

  return {
    success: true,
    musiques: result.slice(start, start + perPage),
    sortBy: q.orderBy ?? null,
    sortDir: q.order ?? null,
    page,
    perPage,
    totalRows,
    totalPages,
  };
}

// Sert une action depuis la base locale (mode hors-ligne), sans contacter le serveur distant.
async function handleOfflineAction(source: EventSource, messageId: string | undefined, message: any): Promise<void> {
  const action = message?.action;
  const reply = (response: any) => replyToSource(source, messageId, response);

  let db: IDBDatabase | null = null;
  try {
    db = await openLocalDatabase();
  } catch {
    reply({ success: false, error: 'Base locale indisponible (hors ligne)' });
    return;
  }

  try {
    switch (action) {
      case 'check':
      case 'currentUser': {
        const users = await readAllFromStore(db, 'Utilisateurs');
        const user = users[0];
        if (!user) {
          reply({ success: false, error: 'Aucun utilisateur local' });
          break;
        }
        reply({
          success: true,
          id: user.Id,
          username: user.NomUtilisateur,
          user: { id: user.Id, username: user.NomUtilisateur, role: user.RoleUtilisateur },
        });
        break;
      }

      case 'latest_musiques':
      case 'search':
      case 'getMusiques':
      case 'musiques': {
        const rows = await readAllFromStore(db, 'Musiques');
        reply(queryLocalMusiques(rows, message.query || {}));
        break;
      }

      case 'likedMusics': {
        const rows = await readAllFromStore(db, 'MusiquesAimees');
        reply({ success: true, musiques: rows, totalRows: rows.length });
        break;
      }

      case 'playedHistory': {
        const rows = await readAllFromStore(db, 'DernieresMusiquesLues');
        reply({ success: true, musiques: rows, totalRows: rows.length });
        break;
      }

      case 'favoriteState': {
        const id = String(message.query || '').trim();
        const rows = await readAllFromStore(db, 'MusiquesAimees');
        const isFavorite = rows.some((row) => String(row.IdMusique ?? row.Id ?? '') === id);
        reply({ success: true, isFavorite, IdMusique: id });
        break;
      }

      case 'myPlaylists':
      case 'dbPlaylists': {
        const rows = await readAllFromStore(db, 'Playlist');
        reply({ success: true, playlists: rows });
        break;
      }

      case 'likedPlaylists': {
        const rows = await readAllFromStore(db, 'PlaylistsAimees');
        const playlistIds = rows.map((row) => Number(row.IdPlaylist ?? 0)).filter((id) => id > 0);
        reply({ success: true, playlistIds });
        break;
      }

      case 'playlistSongs': {
        // La requete peut etre un objet structure (equals.IdPlaylist) ou un simple id.
        const q: any = message.query;
        let playlistId = '';
        let orderBy = '';
        let order = 'ASC';
        if (q && typeof q === 'object') {
          playlistId = String((q.equals && (q.equals.IdPlaylist ?? q.equals.PlaylistId)) ?? '').trim();
          orderBy = String(q.orderBy || '');
          order = String(q.order || 'ASC');
        } else {
          playlistId = String(q || '').trim();
        }

        const rows = await readAllFromStore(db, 'MyPlaylistMusiques');
        let filtered = rows.filter((row) => String(row.IdPlaylist ?? '') === playlistId);

        if (orderBy) {
          const direction = String(order).toUpperCase() === 'DESC' ? -1 : 1;
          filtered = filtered.slice().sort((a, b) => {
            const av = a[orderBy];
            const bv = b[orderBy];
            if (av == null && bv == null) return 0;
            if (av == null) return -direction;
            if (bv == null) return direction;
            if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * direction;
            return String(av).localeCompare(String(bv), 'fr', { sensitivity: 'base' }) * direction;
          });
        }

        reply({ success: true, myPlaylistMusiques: filtered, songs: filtered });
        break;
      }

      case 'musicId': {
        const id = String(message.query || '').trim();
        const record = id ? await getByKeyFromStore(db, LOCAL_AUDIO_STORE, id) : null;
        if (record && record.blob) {
          // Fichier audio local: on expose un object URL utilisable par le lecteur.
          const objectUrl = URL.createObjectURL(record.blob);
          reply({ success: true, download: { file: id, path: objectUrl, success: true }, music: { Id: id } });
        } else {
          reply({ success: false, error: 'Musique indisponible hors ligne' });
        }
        break;
      }

      default:
        reply({ success: false, error: 'Hors ligne: action indisponible (' + String(action) + ')' });
        break;
    }
  } finally {
    db.close();
  }
}

// Copie les tables de l'utilisateur courant depuis le serveur vers la base locale (une fois par demarrage).
async function syncUserDataToLocalDb(): Promise<void> {
  if (localSyncInProgress || localSyncCompleted) {
    return;
  }

  localSyncInProgress = true;
  try {
    const response = await fetch(get_url_from_base() + 'php/database/interface.php?exportUserData=1', {
      cache: 'no-store',
      credentials: 'include',
    });

    if (!response.ok) {
      return;
    }

    const payload = await response.json();
    if (!payload || payload.success === false || !payload.tables) {
      return;
    }

    await writeUserTablesToLocalDb(payload.tables as Record<string, any[]>);
    localSyncCompleted = true;
    console.log('Donnees utilisateur copiees dans la base locale.');
  } catch (error) {
    console.error('syncUserDataToLocalDb error:', error);
  } finally {
    localSyncInProgress = false;
  }
}

// Determine si une reponse d'authentification correspond a un utilisateur connecte.
function isAuthenticatedResponse(payload: any): boolean {
  return Boolean(payload && payload.success === true);
}

// Fonction pour faire une requête GET
async function sendResponse(source: EventSource, messageId: string | undefined, url: string): Promise<void> {
  const fullUrl = get_url_from_base() + url;
  const response = await fetch(fullUrl, {
    cache: 'no-store',
    credentials: 'include',
  });

  if (response.status === 401) {
    window.postMessage({ type: 'USER_LOGGED_OUT' }, '*');
    replyToSource(source, messageId, {
      success: false,
      error: 'Authentification requise',
    });
    return;
  }

  const dataText = await response.json();
  if (!response.ok) {
    throw new Error(dataText.error || 'Error message');
  }
  replyToSource(source, messageId, dataText);
}

// Fonction pour faire une requête GET
async function checkLogin(source: EventSource, messageId: string | undefined, url: string, dataService: DataService): Promise<void> {
  const fullUrl = get_url_from_base() + url;
  const response = await fetch(fullUrl, {
    cache: 'no-store',
    credentials: 'include',
  });

  if (response.status === 401) {
    window.postMessage({ type: 'USER_LOGGED_OUT' }, '*');
    replyToSource(source, messageId, {
      success: false,
      error: 'Authentification requise',
    });
    return;
  }

  const dataText = await response.json();
  if (!response.ok) {
    throw new Error(dataText.error || 'Error message');
  }

  if(dataText.success == false) {
    const loginData = dataService.getData();
    if(loginData.username && loginData.password) {
      const paramurl = {
        username: loginData.username,
        password: loginData.password
      };
      const loginResponse = await fetch(
        get_url_from_base() + `php/auth.php?action=login`,
        {
          method: "POST",
          body: new URLSearchParams(paramurl),
          credentials: 'include',
        }
      );
      const loginDataText = await loginResponse.json();
      replyToSource(source, messageId, loginDataText);
      if (isAuthenticatedResponse(loginDataText)) {
        void syncUserDataToLocalDb();
      }
      return;
    }
  }
  replyToSource(source, messageId, dataText);
  if (isAuthenticatedResponse(dataText)) {
    void syncUserDataToLocalDb();
  }
}

// Fonction pour faire une requête POST
async function postResponse(source: EventSource, messageId: string | undefined, url: string, body?: Record<string, any>): Promise<void> {
  const fullUrl = get_url_from_base() + url;
  const response = await fetch(fullUrl, {
    method: 'POST',
    cache: 'no-store',
    credentials: 'include',
    body: new URLSearchParams(body ?? {}),
  });

  if (response.status === 401) {
    window.postMessage({ type: 'USER_LOGGED_OUT' }, '*');
    replyToSource(source, messageId, {
      success: false,
      error: 'Authentification requise',
    });
    return;
  }

  const dataText = await response.json();
  if (!response.ok) {
    throw new Error(dataText.error || 'Error message');
  }
  replyToSource(source, messageId, dataText);
}

// Sert l'audio depuis le store local s'il est deja telecharge (utilise hors-ligne et en ligne).
async function serveLocalAudioIfAvailable(source: EventSource, messageId: string | undefined, query: any): Promise<boolean> {
  const id = String(query || '').trim();
  if (!id) {
    return false;
  }

  let db: IDBDatabase;
  try {
    db = await openLocalDatabase();
  } catch {
    return false;
  }

  try {
    const record = await getByKeyFromStore(db, LOCAL_AUDIO_STORE, id);
    if (record && record.blob) {
      const objectUrl = URL.createObjectURL(record.blob);
      replyToSource(source, messageId, {
        success: true,
        download: { file: id, path: objectUrl, success: true },
        music: { Id: id },
      });
      return true;
    }
    return false;
  } finally {
    db.close();
  }
}

// Vérification si la requête est structurée
function hasStructuredDbQuery(message: any): boolean {
  return Boolean(
    message &&
    message.query &&
    typeof message.query === 'object' &&
    !Array.isArray(message.query) &&
    typeof message.query.table === 'string' &&
    String(message.query.table).trim() !== ''
  );
}

// Fonction pour envoyer une requête structurée
async function sendStructuredDbQuery(source: EventSource, messageId: string | undefined, query: any): Promise<void> {
  const jsonStr = JSON.stringify(query ?? {});
  await sendResponse(source, messageId, "php/database/interface.php?requete=" + encodeURIComponent(jsonStr));
}

// La fonction principale
async function db_listener(event: MessageEvent<AppMessage>, dataService: DataService): Promise<void> {
  console.log("db_listener");
  console.log(event);

  const data: EventData = event?.data ?? {};
  const message = data?.message ?? {};

  if (!event.source) {
    console.error('db_listener error: event.source is null');
    return;
  }

  const source = event.source as Window;

  try {
    // Audio deja telecharge: on le lit depuis la base locale (hors-ligne comme en ligne).
    if (message.action === 'musicId' && await serveLocalAudioIfAvailable(source, data.messageId, message.query)) {
      return;
    }

    // Hors-ligne: on sert les lectures et l'audio depuis la base locale, sans contacter le serveur.
    if (isOffline()) {
      await handleOfflineAction(source, data.messageId, message);
      return;
    }

    switch (message.action) {
      // Cas pour la partie login
      case 'check':
        await checkLogin(source, data.messageId, "php/auth.php?action=" + message.action, dataService);
        break;

      case 'login':
      case 'register': {
        const dataToSave = { username: message.body.username, password: message.body.password };
        dataService.setData(dataToSave);
        const response = await fetch(
          get_url_from_base() + `php/auth.php?action=` + message.action,
          {
            method: "POST",
            body: new URLSearchParams(message.body),
            credentials: 'include',
          }
        );
        const dataText = await response.json();
        source.postMessage({ response: dataText, replyTo: data.messageId }, '*');
        if (isAuthenticatedResponse(dataText)) {
          void syncUserDataToLocalDb();
        }
        break;
      }

      case 'logout': {
        const response = await fetch(
          get_url_from_base() + `php/auth.php?action=` + message.action,
          {
            method: "POST",
            credentials: 'include',
          }
        );
        const dataText = await response.json();
        source.postMessage({ response: dataText, replyTo: data.messageId }, '*');
        localSyncCompleted = false;
        break;
      }

      // Actions pour la base de données
      case 'latest_musiques':
      case 'search':
      case 'getMusiques':
      case 'description':
      case 'playlistSongs':
      case 'dbPlaylists':
      case 'albums':
      case 'musicDetails':
      case 'musiques':
        if (!hasStructuredDbQuery(message)) {
          throw new Error('Requete de lecture invalide');
        }
        await sendStructuredDbQuery(source, data.messageId, message.query);
        break;

      // Actions legacy GET
      case 'tempFilesCount':
        await sendResponse(source, data.messageId, "php/database/interface.php?tempFilesCount=1");
        break;
      case 'myPlaylists':
        await sendResponse(source, data.messageId, "php/database/interface.php?myPlaylists=1");
        break;
      case 'currentUser':
        await sendResponse(source, data.messageId, "php/database/interface.php?currentUser=1");
        break;
      case 'playlistEdition':
        await sendResponse(source, data.messageId, "php/database/interface.php?playlistEdition=1&id=" + encodeURIComponent(message.query));
        break;
      case 'musicFilesIntegrity':
        await sendResponse(source, data.messageId, "php/database/interface.php?musicFilesIntegrity=1");
        break;
      case 'deleteFile':
        await sendResponse(source, data.messageId, "php/database/interface.php?deleteFile=" + encodeURIComponent(message.query));
        break;
      case 'play':
        await sendResponse(source, data.messageId, "php/database/interface.php?add=" + encodeURIComponent(message.query));
        break;
      case 'favoriteState':
          await sendResponse(source, data.messageId, "php/database/interface.php?favoriteState=1&id=" + encodeURIComponent(message.query));
          break;
      case 'likedMusics':
          await sendResponse(source, data.messageId, "php/database/interface.php?likedMusics=1");
          break;
      case 'nextMusic':
        await sendResponse(source, data.messageId, "php/database/interface.php?next=" + encodeURIComponent(message.query));
        break;

      // Actions legacy POST
      case 'clearTempFiles':
        await postResponse(source, data.messageId, "php/database/interface.php", { clearTempFiles: '1' });
        break;
      case 'musicFilesIntegrityAction':
        await postResponse(source, data.messageId, "php/database/interface.php", {
          musicFilesIntegrityAction: '1',
          action: String((message.body && message.body.action) || ''),
          musicId: String((message.body && message.body.musicId) || ''),
          filePath: String((message.body && message.body.filePath) || ''),
        });
        break;

      // Actions diverses
      case 'createPlaylist':
      case 'addPlaylistMusic':
      case 'incrementPlaylistView':
      case 'togglePlaylistShare':
      case 'reorderPlaylistSongs':
      case 'removePlaylistMusic':
      case 'updatePlaylist':
      case 'deletePlaylist':
      case 'updateMusic':
      case 'deleteMusic':
      case 'addMusic':
      case 'savePlayedPlaylist':
      case 'addFavoriteMusic':
      case 'removeFavoriteMusic':
      {
        const body = { ...(message.body ?? {}) };
        body[message.action] = '1';
        await postResponse(source, data.messageId, "php/database/interface.php", body);
        break;
      }

      // Interface YouTube
      case 'yt_suggestions':
        await sendResponse(source, data.messageId, "php/yt/interface.php?suggestions=" + encodeURIComponent(message.query));
        break;
      case 'yt_search':
        await sendResponse(source, data.messageId, "php/yt/interface.php?query=" + encodeURIComponent(message.query));
        break;
      case 'yt_description':
        await sendResponse(source, data.messageId, "php/yt/interface.php?description=" + encodeURIComponent(message.query));
        break;
      case 'yt_download':
        await sendResponse(source, data.messageId, "php/yt/interface.php?download=" + encodeURIComponent(message.query));
        break;
      case 'playlistQuery':
        await sendResponse(source, data.messageId, "php/yt/interface.php?playlistQuery=" + encodeURIComponent(message.query));
        break;
      case 'playlistItems':
        await sendResponse(source, data.messageId, "php/yt/interface.php?playlistItems=1&id=" + encodeURIComponent(message.query));
        break;
      case 'musicMetadata':
        await sendResponse(source, data.messageId, "php/yt/interface.php?musicMetadata=1&id=" + encodeURIComponent(message.query));
        break;
      case 'musicId':
        await sendResponse(source, data.messageId, "php/yt/interface.php?musicId=" + encodeURIComponent(message.query));
        break;

      default:
        console.log("event message action unknown : " + message.action);
        replyToSource(source, data.messageId, {
          success: false,
          error: "Action inconnue: " + message.action,
        });
        break;
    }
  } catch (error) {
    // Serveur injoignable: repli sur la base locale.
    if (isNetworkError(error)) {
      try {
        await handleOfflineAction(source, data.messageId, message);
        return;
      } catch (offlineError) {
        console.error('offline fallback error:', offlineError);
      }
    }

    console.error('db_listener error:', error);
    replyToSource(source, data.messageId, {
      success: false,
      error: String((error as Error)?.message ?? error ?? 'Erreur serveur'),
    });
  }
}

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  imports: [IonHeader, IonToolbar, IonTitle, IonContent],
})

export class HomePage implements AfterViewInit {

  constructor(private dataService: DataService) {
    window.addEventListener("message", this.onMessage);
  }

  // Pour les message :
  private onMessage = (event: MessageEvent<AppMessage>): void => {
    if(event.data.type === 'db') {
      db_listener(event, this.dataService);
    }
  };

  ngOnDestroy(): void {
    window.removeEventListener("message", this.onMessage);
  }


  // Pour l'initialisation
  ngAfterViewInit() {
    console.log('Vue initialisée');
    window.postMessage({type:"INITIALIZATION_DONE"}, "*");
  }
}
