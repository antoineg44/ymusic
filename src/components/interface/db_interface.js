
function get_event_file(event)
{
    const location = event.source.location.href.split("/");
    return location[location.length-1].substring(0,4);
}

function replyToSource(source, messageId, response) {
    source.postMessage({
        response,
        replyTo: messageId
    }, '*');
}

async function sendResponse(source, messageId, url) {
    const response = await fetch(get_url_from_base() + url, {
    cache: 'no-store',
    credentials: 'include',
    });

    if (response.status === 401) {
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
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

async function postResponse(source, messageId, url, body) {
    const response = await fetch(get_url_from_base() + url, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'include',
        body: new URLSearchParams(body || {}),
    });

    if (response.status === 401) {
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
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

function hasStructuredDbQuery(message) {
    return Boolean(
        message
        && message.query
        && typeof message.query === 'object'
        && !Array.isArray(message.query)
        && typeof message.query.table === 'string'
        && String(message.query.table).trim() !== ''
    );
}

async function sendStructuredDbQuery(source, messageId, query) {
    const jsonStr = JSON.stringify(query || {});
    await sendResponse(source, messageId, "php/database/interface.php?requete=" + encodeURIComponent(jsonStr));
}


async function db_listener(event)
{
    const data = event && event.data ? event.data : {};
    const message = data && data.message ? data.message : {};

    // En mode mobile, le relais (auth avec auto-login, offline, base locale) est gere par l'app Angular (home.page.ts).
    if (connexionType == CONNEXION_TYPE.MOBILE)
    {
        console.log("Mobile connexion type: relais gere par home.page.ts");
        return;
    }
    console.log("db_listener");
    console.log(event);

    /**
     * List action
     * 
     * For login
     *  - check (ensure connected)
     *  - login
     *  - logout
     * 
     * For home
     *  - search
     *  - latest_musiques
     * 
     * For list
     *  - getMusiques
     */

    try {
    switch(message.action)
    {
        // Login part :
        case 'check':
            await sendResponse(event.source, data.messageId, "php/auth.php?action=" + message.action);
            break;

        case 'login':
        case 'register':
        {
            const response = await fetch(
            get_url_from_base() + `php/auth.php?action=` + message.action,
            {
                method: "POST",
                body: new URLSearchParams(message.body),
                credentials: 'include',
            },
            );
            const dataText = await response.json();
            event.source.postMessage({
                response: dataText,
                replyTo: data.messageId
            }, '*');
            break;
        }

        case 'logout':
        {
            const response = await fetch(
            get_url_from_base() + `php/auth.php?action=` + message.action,
            {
                method: "POST",
                credentials: 'include',
            },
            );
            const dataText = await response.json();
            event.source.postMessage({
                response: dataText,
                replyTo: data.messageId
            }, '*');
            break;
        }

        // To database
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

        // Legacy database GET actions now handled by php/database/interface.php
        case 'tempFilesCount':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?tempFilesCount=1");
            break;
        case 'releaseFiles':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?releaseFiles=1");
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
            await sendResponse(event.source, data.messageId, "php/database/interface.php?play=" + encodeURIComponent(message.query));
            break;
        case 'favoriteState':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?favoriteState=1&id=" + encodeURIComponent(message.query));
            break;
        case 'likedMusics':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?likedMusics=1");
            break;
        case 'likedPlaylists':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?likedPlaylists=1");
            break;
        case 'playedHistory':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?playedHistory=1");
            break;
        case 'getUserSettings':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?getUserSettings=1");
            break;
        case 'nextMusic':
            await sendResponse(event.source, data.messageId, "php/database/interface.php?next=" + encodeURIComponent(message.query));
            break;


        // Legacy database POST actions now handled by php/database/interface.php
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
        case 'addLikedPlaylist':
        case 'removeLikedPlaylist':
        case 'recordPlayedMusic':
        case 'saveUserSettings':
        {
            const body = Object.assign({}, message.body || {});
            body[message.action] = '1';
            await postResponse(event.source, data.messageId, "php/database/interface.php", body);
            break;
        }
        
        // yt interface
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
            error: String((error && error.message) || error || 'Erreur serveur'),
        });
    }

}
