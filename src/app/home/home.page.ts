import { Component } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent } from '@ionic/angular/standalone';
import { AfterViewInit } from '@angular/core';
import { DataService } from 'data.service';
interface AppMessage {
  type: string;
  payload?: unknown;
}

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  imports: [IonHeader, IonToolbar, IonTitle, IonContent],
})

export class HomePage implements AfterViewInit {
  dataParameters: any;

  constructor(private dataService: DataService) {
    window.addEventListener("message", this.onMessage);
  }

  // Pour les message :
  private onMessage = (event: MessageEvent<AppMessage>): void => {
    console.log("Message reçu :", event.data);
    db_listener(event);
  };

  ngOnDestroy(): void {
    window.removeEventListener("message", this.onMessage);
  }


  // Pour l'initialisation
  ngAfterViewInit() {
    console.log('Vue initialisée');
    this.dataParameters = this.dataService.getData();
    window.postMessage({type:"INITIALIZATION_DONE"}, "*");
  }

  // For example for save parameters:
  saveData() {
    const data = { name: 'John', age: 30 };
    this.dataService.setData(data);
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
    return "https://music.partitions.ovh/";
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
  async function db_listener(event: Event): Promise<void> {
    console.log("db_listener");
    console.log(event);

    const data: EventData = event?.data ?? {};
    const message = data?.message ?? {};

    try {
      switch (message.action) {
        // Cas pour la partie login
        case 'check':
          await sendResponse(event.source, data.messageId, "php/auth.php?action=" + message.action);
          break;

        case 'login': {
          const response = await fetch(
            get_url_from_base() + `php/auth.php?action=` + message.action,
            {
              method: "POST",
              body: new URLSearchParams(message.body),
              credentials: 'include',
            }
          );
          const dataText = await response.json();
          event.source.postMessage({ response: dataText, replyTo: data.messageId }, '*');
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
          event.source.postMessage({ response: dataText, replyTo: data.messageId }, '*');
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
          await sendStructuredDbQuery(event.source, data.messageId, message.query);
          break;

        // Actions legacy GET
        case 'tempFilesCount':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?tempFilesCount=1");
          break;
        case 'myPlaylists':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?myPlaylists=1");
          break;
        case 'currentUser':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?currentUser=1");
          break;
        case 'playlistEdition':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?playlistEdition=1&id=" + encodeURIComponent(message.query));
          break;
        case 'musicFilesIntegrity':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?musicFilesIntegrity=1");
          break;
        case 'deleteFile':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?deleteFile=" + encodeURIComponent(message.query));
          break;
        case 'play':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?add=" + encodeURIComponent(message.query));
          break;
        case 'nextMusic':
          await sendResponse(event.source, data.messageId, "php/database/interface.php?next=" + encodeURIComponent(message.query));
          break;

        // Actions legacy POST
        case 'clearTempFiles':
          await postResponse(event.source, data.messageId, "php/database/interface.php", { clearTempFiles: '1' });
          break;
        case 'musicFilesIntegrityAction':
          await postResponse(event.source, data.messageId, "php/database/interface.php", {
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
        case 'savePlayedPlaylist': {
          const body = { ...(message.body ?? {}) };
          body[message.action] = '1';
          await postResponse(event.source, data.messageId, "php/database/interface.php", body);
          break;
        }

        // Interface YouTube
        case 'yt_suggestions':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?suggestions=" + encodeURIComponent(message.query));
          break;
        case 'yt_search':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?query=" + encodeURIComponent(message.query));
          break;
        case 'yt_description':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?description=" + encodeURIComponent(message.query));
          break;
        case 'yt_download':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?download=" + encodeURIComponent(message.query));
          break;
        case 'playlistQuery':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?playlistQuery=" + encodeURIComponent(message.query));
          break;
        case 'playlistItems':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?playlistItems=1&id=" + encodeURIComponent(message.query));
          break;
        case 'musicMetadata':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?musicMetadata=1&id=" + encodeURIComponent(message.query));
          break;
        case 'musicId':
          await sendResponse(event.source, data.messageId, "php/yt/interface.php?musicId=" + encodeURIComponent(message.query));
          break;

        default:
          console.log("event message action unknown : " + message.action);
          replyToSource(event.source, data.messageId, {
            success: false,
            error: "Action inconnue: " + message.action,
          });
          break;
      }
    } catch (error) {
      console.error('db_listener error:', error);
      replyToSource(event.source, data.messageId, {
        success: false,
        error: String((error as Error)?.message ?? error ?? 'Erreur serveur'),
      });
    }
  }
}
