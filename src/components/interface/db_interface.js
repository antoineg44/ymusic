
function get_event_file(event)
{
    const location = event.source.location.href.split("/");
    return location[location.length-1].substring(0,4);
}

async function sendResponse(source, messageId, url) {
  const response = await fetch(get_url() + url, {
    cache: 'no-store',
  });

  if (response.status === 401) {
      window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
      return;
    }

    const dataText = await response.json();
    if (!response.ok || !dataText.success) {
      throw new Error(dataText.error || 'Error message');
    }

  source.postMessage({
      response: dataText,
      replyTo: messageId
  }, '*');
}


function db_listener(event)
{
   

    switch (get_event_file(event)) {
      case "home":
        // Envoyer la réponse en reply
        sendResponse(event.source, data.messageId, "pages/home/home.php?" + message.message);
        return;
        break;
    
      default:
        break;
    }


    switch(message.message.action)
    {
        case 'check':
            sendResponse(event.source, data.messageId, "php/auth.php?action=" + message.message.action);
            break;

        case 'login':
            const response = await fetch(
                get_url_from_base() + `php/auth.php?action=` + message.message.action,
                {
                method: "POST",
                body: new URLSearchParams(message.message.body),
                },
            );
            const dataText = await response.text();
            event.source.postMessage({
                response: dataText,
                replyTo: data.messageId
            }, '*');
            break;

        default:
            console.log("event message action unknown : " + message.message.type);
            break;
    }

}