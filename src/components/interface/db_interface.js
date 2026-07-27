

// Écoute des messages entrants
window.addEventListener('message', (event) => {
  const data = event.data;
  if (data && data.message && data.messageId) {
    // Traiter le message reçu
    const responseMessage = `Réponse à: ${data.message}`;

    // Envoyer la réponse en reply
    event.source.postMessage({ response: responseMessage, replyTo: data.messageId }, event.origin);
  }
});

