
const CONNEXION_TYPE = Object.freeze({
    SERVER: "Server", CLIENT: "Client", MOBILE: "Mobile"
});


function GetConnexionType() {
    if(window.location.href.includes("file://")) {
        return CONNEXION_TYPE.CLIENT;
    }
    else if(window.location.href.includes("https://localhost/")) {
        return CONNEXION_TYPE.MOBILE;
    }
    else
    {
        const domain_approv = [
            "localhost",
            "192.168.1.10",
            "music.partitions.ovh"
        ];
        if (domain_approv.includes(window.location.host)) {
            // application web
            return CONNEXION_TYPE.SERVER;

        } else {
            // Application smartphone
            return CONNEXION_TYPE.MOBILE;
        }
    }
}

function get_url() {
    if (connexionType === CONNEXION_TYPE.SERVER) {
        return "";
    }

    return "https://music.partitions.ovh/php/tools/";
}

function get_url_from_base() {
    if (connexionType === CONNEXION_TYPE.SERVER) {
        return "";
    }

    return "https://music.partitions.ovh/";
}

function toCloneablePayload(value) {
  if (value instanceof URLSearchParams) {
    return Object.fromEntries(value.entries());
  }

  if (Array.isArray(value)) {
    return value.map((item) => toCloneablePayload(item));
  }

  if (value && typeof value === 'object') {
    const clone = {};
    Object.keys(value).forEach((key) => {
      clone[key] = toCloneablePayload(value[key]);
    });
    return clone;
  }

  return value;
}

// Fonction pour envoyer un message et attendre la réponse
function sendMessageAndWait(targetWindow, message, timeout = 10000) {
  return new Promise((resolve, reject) => {
    // Générer un ID unique pour cette requête
    const messageId = Date.now() + Math.random();

    // Listener pour la réponse
    function handleMessage(event) {
      const data = event.data;
      // Vérifier que la réponse correspond à notre message
      if (data && data.replyTo === messageId) {
        window.removeEventListener('message', handleMessage);
        clearTimeout(timer);
        resolve(data.response);
      }
    }

    // Ajouter l'écouteur d'événements
    window.addEventListener('message', handleMessage);

    // Envoyer le message avec un identifiant
  targetWindow.postMessage({ message: toCloneablePayload(message), messageId, type: "db" }, '*');

    // Timeout pour la réponse
    const timer = setTimeout(() => {
      window.removeEventListener('message', handleMessage);
      reject(new Error('Timeout: aucune réponse reçue'));
    }, timeout);
  });
}


const connexionType = GetConnexionType();