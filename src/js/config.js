
function webapp() {
    const domain_approv = [
        "localhost",
        "192.168.1.10",
        "music.partitions.ovh"
    ];
    if (domain_approv.includes(window.location.host)) {
        // application web
        return true;

    } else {
        // Application smartphone
        return false;
    }
}

function get_url() {
    if (webapp()) {
        return "";
    }

    return "https://music.partitions.ovh/php/tools/";
}

function get_url_from_base() {
    if (webapp()) {
        return "";
    }

    return "https://music.partitions.ovh/";
}

// Fonction pour envoyer un message et attendre la réponse
function sendMessageAndWait(targetWindow, message, timeout = 5000) {
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
    targetWindow.postMessage({ message, messageId, type: "db" }, '*');

    // Timeout pour la réponse
    const timer = setTimeout(() => {
      window.removeEventListener('message', handleMessage);
      reject(new Error('Timeout: aucune réponse reçue'));
    }, timeout);
  });
}
