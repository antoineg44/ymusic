
function get_event_file(event)
{
    const location = event.source.location.href.split("/");
    return location[location.length-1].substring(0,4);
}

async function sendResponse(source, messageId, url) {
    const response = await fetch(get_url_from_base() + url, {
    cache: 'no-store',
    credentials: 'include',
    });

    if (response.status === 401) {
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
        return;
    }

    const dataText = await response.json();
    if (!response.ok) {
        throw new Error(dataText.error || 'Error message');
    }

    source.postMessage({
        response: dataText,
        replyTo: messageId
    }, '*');
}


async function db_listener(event)
{
    console.log("db_listener");
    console.log(event);
    const data = event && event.data ? event.data : {};
    const message = data && data.message ? data.message : {};

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

    switch(message.action)
    {
        // Login part :
        case 'check':
            await sendResponse(event.source, data.messageId, "php/auth.php?action=" + message.action);
            break;

        case 'login':
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

        // Home part :
        case 'latest_musiques':
        case 'search':
            const jsonStr = JSON.stringify(message.query);
            await sendResponse(event.source, data.messageId, "php/database/interface.php?requete=" + encodeURIComponent(jsonStr));
            break;
        case 'getMusiques':
            await sendResponse(event.source, data.messageId, "pages/list/list.php?" + new URLSearchParams(message.params).toString());
            break;


        default:
            console.log("event message action unknown : " + message.action);
            break;
    }

}
