// Contenu de la modale de connexion: ouvre le SSO central dans une fenetre popup (web)
// ou delegue au navigateur systeme via l'app (mobile), puis se referme une fois connecte.

const loginButton = document.getElementById("ssoLoginButton");
const loginSpinner = document.getElementById("ssoSpinner");
const loginMessage = document.getElementById("loginMessage");

let ssoWindow = null;
let popupPollTimer = null;

function setMessage(text, isError = false) {
  loginMessage.textContent = text;
  loginMessage.classList.toggle("is-error", isError);
}

function setLoading(isLoading) {
  loginSpinner.classList.toggle("is-hidden", !isLoading);
  loginButton.disabled = isLoading;
}

function appBaseUrl() {
  const base = get_url_from_base();
  return base && base.length ? base : window.location.origin + "/";
}

function isMobile() {
  return typeof connexionType !== "undefined" && connexionType === CONNEXION_TYPE.MOBILE;
}

// Revalide la session cote serveur; si connecte, notifie l'application parente.
async function recheckSession() {
  try {
    const response = await sendMessageAndWait(window.parent, { action: "check" });
    if (response && response.success) {
      window.parent.postMessage({ type: "USER_LOGGED_IN" }, "*");
      return true;
    }
  } catch (error) {
    console.error(error);
  }
  return false;
}

async function finishLogin() {
  window.removeEventListener("message", handleSsoMessage);
  if (popupPollTimer) {
    clearInterval(popupPollTimer);
    popupPollTimer = null;
  }

  setLoading(true);
  const ok = await recheckSession();
  setLoading(false);

  if (!ok) {
    setMessage("La connexion n'a pas abouti. Reessayez.", true);
  }
}

function handleSsoMessage(event) {
  if (!event.data || event.data.type !== "SSO_LOGIN_DONE") {
    return;
  }
  void finishLogin();
}

loginButton.addEventListener("click", () => {
  setMessage("");

  // Mobile: la connexion s'ouvre dans le navigateur systeme (retour par deep link).
  if (isMobile()) {
    window.parent.postMessage({ type: "SSO_LOGIN_REQUEST" }, "*");
    setMessage("Ouverture de la page de connexion...");
    return;
  }

  const url = appBaseUrl() + "php/sso/login.php?client=popup";
  ssoWindow = window.open(url, "ssoLogin", "width=480,height=680,menubar=no,toolbar=no");

  if (!ssoWindow) {
    setMessage("La fenetre de connexion a ete bloquee. Autorisez les popups.", true);
    return;
  }

  setMessage("Connexion en cours dans la fenetre ouverte...");
  window.addEventListener("message", handleSsoMessage);

  // Filet de securite: si la fenetre est fermee sans message, on reverifie la session.
  if (popupPollTimer) {
    clearInterval(popupPollTimer);
  }
  popupPollTimer = window.setInterval(() => {
    if (ssoWindow && ssoWindow.closed) {
      clearInterval(popupPollTimer);
      popupPollTimer = null;
      void finishLogin();
    }
  }, 800);
});

// Si une session est deja active a l'ouverture de la modale, on ferme directement.
(async () => {
  await recheckSession();
})();
