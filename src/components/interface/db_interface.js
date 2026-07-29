
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


async function db_listener(event)
{
  const data = event && event.data ? event.data : {};
  const message = data && data.message ? data.message : {};

  switch (get_event_file(event)) {
    case "home":
    // Envoyer la réponse en reply
    await sendResponse(event.source, data.messageId, "pages/home/home.php?" + String(message));
    return;

    default:
    break;
  }

  switch(message.action)
  {
    case 'check':
      await sendResponse(event.source, data.messageId, "php/auth.php?action=" + message.action);
      break;

    case 'login': {
      const response = await fetch(
        get_url_from_base() + `php/auth.php?action=` + message.action,
        {
        method: "POST",
        body: new URLSearchParams(message.body),
        },
      );
      const dataText = await response.text();
      event.source.postMessage({
        response: dataText,
        replyTo: data.messageId
      }, '*');
      break;
    }

    default:
      console.log("event message action unknown : " + message.action);
      break;
  }

}
