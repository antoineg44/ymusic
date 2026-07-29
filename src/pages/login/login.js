const form = document.getElementById("authForm");
const loginButton = document.getElementById("loginButton");
const loginSpinner = document.getElementById("loginSpinner");
const registerButton = document.getElementById("registerButton");
const message = document.getElementById("authMessage");

function setMessage(text, isError = true) {
  message.textContent = text;
  message.classList.toggle("is-error", isError);
  message.classList.toggle("is-success", !isError);
}

function lockButtons(locked) {
  loginButton.disabled = locked;
  registerButton.disabled = locked;
}

function setLoginLoading(isLoading) {
  loginSpinner.classList.toggle("is-hidden", !isLoading);
  loginButton.setAttribute("aria-busy", isLoading ? "true" : "false");
}

async function callAuth(action, body) {
  sendMessageAndWait(window.parent, {action: encodeURIComponent(action), body: new URLSearchParams(body)}).then(response => {
    let payload = null;

    try {
      payload = response ? JSON.parse(response) : null;
    } catch (error) {
      throw new Error("Le serveur d'authentification a repondu avec un contenu invalide (HTTP). Le serveur d'authentification n'a pas retourne de JSON valide.");
    }

    return payload;
  }).catch(error => {
      console.error(error);
  });
  return null;
}

async function submitAuth(action) {
  const username = String(
    document.getElementById("username").value || "",
  ).trim();
  const password = String(document.getElementById("password").value || "");

  if (!username || !password) {
    setMessage("Veuillez remplir les deux champs.");
    return;
  }

  setMessage("");
  lockButtons(true);
  setLoginLoading(action === "login");

  try {
    const payload = await callAuth(action, { username, password });

    if (!payload.success) {
      setMessage(payload.message || "Operation impossible.");
      return;
    }

    setMessage(
      action === "login"
        ? "Connexion reussie."
        : "Compte cree, connexion reussie.",
      false,
    );
    window.setTimeout(() => {
      window.parent.postMessage({type: 'USER_LOGGED_IN',}, '*');
    }, 250);
  } catch (error) {
    console.error(error);
    setMessage(
      error instanceof Error ? error.message : "Erreur reseau, reessayez.",
    );
  } finally {
    setLoginLoading(false);
    lockButtons(false);
  }
}

form.addEventListener("submit", (event) => {
  event.preventDefault();
  void submitAuth("login");
});

registerButton.addEventListener("click", () => {
  void submitAuth("register");
});

(async () => {
  try {
    const response = await fetch(get_url_from_base() + `php/auth.php?action=check`, {
      cache: "no-store",
    });
    const payload = await response.json();
    if (payload.success) {
      window.parent.postMessage({type: 'USER_LOGGED_IN',}, '*');
    }
  } catch (error) {
    console.debug(error);
  }
})();
